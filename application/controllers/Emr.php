<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Enterprise Hospital Management Information System (HMIS)
 * Electronic Medical Record (EMR) Clinical Controller
 *
 * Handles AJAX clinical SOAP note submissions, digital record locking,
 * and high-performance server-side DataTables EMR query processing.
 *
 * Protected by Rbac_hook: accessible by 'doctor' and 'admin' roles.
 */
#[AllowDynamicProperties]
class Emr extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library('form_validation');
        $this->load->library('Datatables');
        $this->load->model('M_medical_record', 'm_emr');
    }

    /**
     * Polyclinic Waiting Queue UI for Attending Doctor
     * URI: GET /emr
     */
    public function index(): void {
        if (!$this->session->userdata('isLoggedIn')) {
            redirect('login');
            return;
        }

        // 1. Identify active doctor (from session id/user_id, doctor_id, or doctors table)
        $user_id   = $this->session->userdata('id');
        $user_name = $this->session->userdata('name');
        $user_role = strtolower((string)$this->session->userdata('role'));
        $doctor    = null;

        if ($this->session->userdata('doctor_id')) {
            $doctor = $this->db->get_where('doctors', ['id' => (int)$this->session->userdata('doctor_id')])->row_array();
        }
        if (!$doctor && $user_id) {
            $doctor = $this->db->get_where('doctors', ['user_id' => (int)$user_id])->row_array();
        }
        if (!$doctor && $user_name) {
            $doctor = $this->db->get_where('doctors', ['name' => $user_name])->row_array();
            if (!$doctor) {
                $doctor = $this->db->get_where('doctors', ['full_name' => $user_name])->row_array();
            }
        }
        if (!$doctor) {
            $doctor = $this->db->order_by('id', 'ASC')->get_where('doctors', ['is_active' => TRUE])->row_array();
        }
        if (!$doctor) {
            $doctor = $this->db->order_by('id', 'ASC')->get('doctors')->row_array();
        }

        // Only persist doctor_id in session for actual doctors, avoiding polluting admin sessions
        if ($doctor && $user_role === 'doctor' && !$this->session->userdata('doctor_id')) {
            $this->session->set_userdata('doctor_id', $doctor['id']);
        }

        $doctor_id = $doctor ? (int)$doctor['id'] : null;
        $today     = date('Y-m-d');

        // 2. Resolve polyclinic associated with this doctor or active visits
        $polyclinic = null;
        if ($doctor_id) {
            $poly_visit = $this->db->select('poly.*')
                ->from('visits v')
                ->join('polyclinics poly', 'poly.id = v.polyclinic_id', 'INNER')
                ->where('v.visit_date', $today)
                ->where('v.doctor_id', $doctor_id)
                ->limit(1)
                ->get()->row_array();
            if ($poly_visit) {
                $polyclinic = $poly_visit;
            }
        }
        if (!$polyclinic && $doctor && !empty($doctor['specialization'])) {
            $polyclinic = $this->db->like('name', $doctor['specialization'], 'both')
                ->where('is_active', TRUE)
                ->get('polyclinics')->row_array();
        }
        if (!$polyclinic) {
            $polyclinic = $this->db->order_by('name', 'ASC')->get_where('polyclinics', ['is_active' => TRUE])->row_array();
        }

        // 3. Fetch today's visits for this doctor/polyclinic where queue_status IN ('WAITING', 'CALLED', 'IN_CONSULTATION') sorted by queue_number ASC
        $this->db->select('v.id, v.visit_number, v.queue_number, v.queue_status, v.billing_status, v.check_in_time, ' .
                          'v.patient_id, v.polyclinic_id, v.doctor_id, v.visit_date, ' .
                          'COALESCE(p.full_name, p.name) AS patient_name, ' .
                          'COALESCE(p.mrn, p.medical_record_number) AS medical_record_number, ' .
                          'p.name, p.gender, p.date_of_birth, p.blood_type, ' .
                          'poly.name AS polyclinic_name, d.name AS doctor_name');
        $this->db->from('visits v');
        $this->db->join('patients p', 'p.id = v.patient_id', 'INNER');
        $this->db->join('polyclinics poly', 'poly.id = v.polyclinic_id', 'LEFT');
        $this->db->join('doctors d', 'd.id = v.doctor_id', 'LEFT');
        $this->db->where('v.visit_date', $today);
        if ($doctor_id) {
            $this->db->where('v.doctor_id', $doctor_id);
        }
        $this->db->where_in('v.queue_status', ['WAITING', 'CALLED', 'IN_CONSULTATION']);
        $this->db->order_by('v.queue_number', 'ASC');
        $visits = $this->db->get()->result_array();

        $data = [
            'doctor'          => $doctor,
            'polyclinic'      => $polyclinic,
            'visits'          => $visits,
            'csrf_token_name' => $this->security->get_csrf_token_name(),
            'csrf_hash'       => $this->security->get_csrf_hash()
        ];

        if (file_exists(APPPATH . 'views/emr/queue.php')) {
            $this->load->view('emr/queue', $data);
        } else {
            $this->output->set_content_type('text/html')->set_output('<!-- EMR Queue View -->');
        }
    }

    /**
     * AJAX Endpoint: Advance Patient Queue State to CALLED
     * URI: POST /emr/call
     */
    public function call_patient(): void {
        $this->output->set_content_type('application/json');

        if (!$this->session->userdata('isLoggedIn')) {
            $this->output
                ->set_status_header(401)
                ->set_output(json_encode([
                    'status'  => 'error',
                    'message' => 'Unauthorized staff session.'
                ], JSON_UNESCAPED_UNICODE));
            return;
        }

        $visit_id = (string)$this->input->post('visit_id', TRUE);
        if (!$visit_id) {
            $raw_input = json_decode($this->input->raw_input_stream, true);
            if (is_array($raw_input) && !empty($raw_input['visit_id'])) {
                $visit_id = trim((string)$raw_input['visit_id']);
            }
        }

        // Validate UUID format before database query
        if (empty($visit_id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $visit_id)) {
            $this->output
                ->set_status_header(400)
                ->set_output(json_encode([
                    'status'  => 'error',
                    'message' => 'Invalid UUID format for visit ID.'
                ], JSON_UNESCAPED_UNICODE));
            return;
        }

        $visit = $this->db->get_where('visits', ['id' => $visit_id])->row_array();
        if (!$visit) {
            $this->output
                ->set_status_header(404)
                ->set_output(json_encode([
                    'status'  => 'error',
                    'message' => 'Visit not found.'
                ], JSON_UNESCAPED_UNICODE));
            return;
        }

        // Only visits in WAITING queue status can be transitioned to CALLED
        if ($visit['queue_status'] !== 'WAITING') {
            $this->output
                ->set_status_header(409)
                ->set_output(json_encode([
                    'status'  => 'conflict',
                    'message' => 'Patient cannot be called. Current queue status: ' . $visit['queue_status']
                ], JSON_UNESCAPED_UNICODE));
            return;
        }

        $this->db->where('id', $visit_id)
            ->where('queue_status', 'WAITING')
            ->update('visits', [
                'queue_status' => 'CALLED',
                'updated_at'   => date('Y-m-d H:i:sP')
            ]);

        $this->output
            ->set_status_header(200)
            ->set_output(json_encode([
                'status'       => 'success',
                'message'      => 'Patient called.',
                'queue_status' => 'CALLED',
                'visit_id'     => $visit_id
            ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Clinical SOAP Consultation Workspace
     * URI: GET /emr/consult/{visit_id}
     */
    public function consultation(string $visit_id = ''): void {
        if (!$this->session->userdata('isLoggedIn')) {
            redirect('login');
            return;
        }

        $visit_id = trim($visit_id);
        // Validate UUID format before database query
        if (empty($visit_id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $visit_id)) {
            $this->session->set_flashdata('error', 'Invalid visit identifier format.');
            redirect('emr');
            return;
        }

        // 1. Fetch visit details joined with patients and polyclinics
        $this->db->select('v.*, ' .
                          'COALESCE(p.full_name, p.name) AS patient_name, ' .
                          'COALESCE(p.mrn, p.medical_record_number) AS medical_record_number, ' .
                          'p.name, p.date_of_birth, p.gender, p.blood_type, p.address, p.phone_number, ' .
                          'poly.name AS polyclinic_name, poly.code AS polyclinic_code, ' .
                          'd.name AS doctor_name, d.specialization AS doctor_specialization');
        $this->db->from('visits v');
        $this->db->join('patients p', 'p.id = v.patient_id', 'INNER');
        $this->db->join('polyclinics poly', 'poly.id = v.polyclinic_id', 'LEFT');
        $this->db->join('doctors d', 'd.id = v.doctor_id', 'LEFT');
        $this->db->where('v.id', $visit_id);
        $visit = $this->db->get()->row_array();

        // 2. Validate visit existence
        if (!$visit) {
            $this->session->set_flashdata('error', 'Encounter record not found.');
            redirect('emr');
            return;
        }

        // 3. Prevent reopening completed consultations
        if ($visit['queue_status'] === 'COMPLETED') {
            $this->session->set_flashdata('error', 'This consultation encounter has already been completed.');
            redirect('emr');
            return;
        }

        // 4. Advance queue status to IN_CONSULTATION if WAITING or CALLED
        if (in_array($visit['queue_status'], ['WAITING', 'CALLED'], true)) {
            $this->db->where('id', $visit_id)->update('visits', [
                'queue_status' => 'IN_CONSULTATION',
                'updated_at'   => date('Y-m-d H:i:sP')
            ]);
            $visit['queue_status'] = 'IN_CONSULTATION';
        }

        // 5. Structure view entities from joined query without redundant database lookups
        $patient = [
            'id'                    => $visit['patient_id'],
            'name'                  => $visit['name'],
            'full_name'             => $visit['patient_name'],
            'medical_record_number' => $visit['medical_record_number'],
            'date_of_birth'         => $visit['date_of_birth'],
            'gender'                => $visit['gender'],
            'blood_type'            => $visit['blood_type'],
            'address'               => $visit['address'],
            'phone_number'          => $visit['phone_number'],
        ];

        $doctor = [
            'id'             => $visit['doctor_id'],
            'name'           => $visit['doctor_name'],
            'full_name'      => $visit['doctor_name'],
            'specialization' => $visit['doctor_specialization'],
        ];

        $polyclinic = [
            'id'   => $visit['polyclinic_id'],
            'name' => $visit['polyclinic_name'],
            'code' => $visit['polyclinic_code'],
        ];

        // 6. Fetch active ICD-10 diagnostic codes
        $icd10_list = $this->db->select('code, description_en, category')
            ->from('icd10_codes')
            ->where('is_active', TRUE)
            ->order_by('code', 'ASC')
            ->get()->result_array();

        $data = [
            'visit'           => $visit,
            'patient'         => $patient,
            'doctor'          => $doctor,
            'polyclinic'      => $polyclinic,
            'icd10_list'      => $icd10_list,
            'csrf'            => [
                'name' => $this->security->get_csrf_token_name(),
                'hash' => $this->security->get_csrf_hash()
            ],
            'csrf_token_name' => $this->security->get_csrf_token_name(),
            'csrf_hash'       => $this->security->get_csrf_hash()
        ];

        if (file_exists(APPPATH . 'views/emr/consult.php')) {
            $this->load->view('emr/consult', $data);
        } else {
            $this->output->set_content_type('text/html')->set_output('<!-- EMR Consult View -->');
        }
    }

    /**
     * AJAX Endpoint: Server-Side DataTables Provider for Patient EMR History
     * URI: GET /emr/data
     */
    public function data(): void {
        $this->output->set_content_type('application/json');

        $response = $this->datatables
            ->from('medical_records mr')
            ->select('mr.id, mr.visit_id, mr.primary_icd10_code, mr.subjective_complaints, ' .
                     'mr.objective_vital_signs, mr.is_locked, mr.created_at, ' .
                     'p.name AS patient_name, p.medical_record_number AS patient_mrn, ' .
                     'd.name AS doctor_name, icd.description_en AS diagnosis_description')
            ->join('patients p', 'p.id = mr.patient_id', 'INNER')
            ->join('doctors d', 'd.id = mr.doctor_id', 'INNER')
            ->join('icd10_codes icd', 'icd.code = mr.primary_icd10_code', 'LEFT')
            ->searchable([
                'p.name',
                'p.medical_record_number',
                'mr.primary_icd10_code',
                'icd.description_en',
                'd.name'
            ])
            ->orderable([
                0 => 'mr.created_at',
                1 => 'p.medical_record_number',
                2 => 'p.name',
                3 => 'mr.primary_icd10_code',
                4 => 'd.name',
                5 => 'mr.is_locked'
            ])
            ->format_rows(function (array $row) {
                // Decode JSONB vital signs for clean UI consumption
                if (!empty($row['objective_vital_signs']) && is_string($row['objective_vital_signs'])) {
                    $row['objective_vital_signs'] = json_decode($row['objective_vital_signs'], true);
                }
                $row['badge_status'] = $row['is_locked'] ? '<span class="badge badge-success">LOCKED</span>' : '<span class="badge badge-warning">OPEN</span>';
                return $row;
            })
            ->generate();

        $this->output
            ->set_status_header(200)
            ->set_output(json_encode($response, JSON_UNESCAPED_UNICODE));
    }

    /**
     * AJAX Endpoint: Finalizes and Digitally Locks an Electronic Medical Record (EMR)
     * URI: POST /emr/save
     */
    public function save(): void {
        $this->output->set_content_type('application/json');

        // Parse JSON payload if sent with Content-Type: application/json
        $raw_input = json_decode($this->input->raw_input_stream, true);
        if (is_array($raw_input)) {
            $this->form_validation->set_data($raw_input);
            foreach ($raw_input as $k => $v) {
                if (!isset($_POST[$k])) {
                    $_POST[$k] = $v;
                }
            }
        }

        // 1. Strict Clinical Input Validation Rules
        $rules = [
            [
                'field' => 'visit_id',
                'label' => 'Outpatient Visit UUID',
                'rules' => 'required|trim|callback_validate_uuid'
            ],
            [
                'field' => 'patient_id',
                'label' => 'Patient Identifier',
                'rules' => 'required|trim|is_natural_no_zero'
            ],
            [
                'field' => 'doctor_id',
                'label' => 'Doctor Identifier',
                'rules' => 'required|trim|is_natural_no_zero'
            ],
            [
                'field' => 'primary_icd10_code',
                'label' => 'Primary ICD-10 Code',
                'rules' => 'required|trim|max_length[10]'
            ],
            [
                'field' => 'subjective_complaints',
                'label' => 'Subjective Anamnesis / Complaints',
                'rules' => 'required|trim'
            ],
            [
                'field' => 'assessment_notes',
                'label' => 'Assessment & Clinical Findings',
                'rules' => 'required|trim'
            ],
            [
                'field' => 'plan_therapy',
                'label' => 'Treatment Plan & Therapy',
                'rules' => 'required|trim'
            ]
        ];

        $this->form_validation->set_rules($rules);

        if ($this->form_validation->run() === FALSE) {
            $this->output
                ->set_status_header(422)
                ->set_output(json_encode([
                    'status'  => 'error',
                    'message' => 'EMR clinical validation failed.',
                    'errors'  => $this->form_validation->error_array()
                ], JSON_UNESCAPED_UNICODE));
            return;
        }

        // 2. Extract Sanitized Inputs
        $visit_id              = (string)$this->input->post('visit_id', TRUE);
        $patient_id            = (int)$this->input->post('patient_id', TRUE);
        $doctor_id             = (int)$this->input->post('doctor_id', TRUE);
        $primary_icd10_code    = strtoupper(trim((string)$this->input->post('primary_icd10_code', TRUE)));
        $subjective_complaints = (string)$this->input->post('subjective_complaints', TRUE);
        $assessment_notes      = (string)$this->input->post('assessment_notes', TRUE);
        $plan_therapy          = (string)$this->input->post('plan_therapy', TRUE);

        // 3. Assemble Structured JSONB Vital Signs (SOAP: Objective)
        $raw_vitals = $this->input->post('objective_vital_signs');
        if (is_array($raw_vitals)) {
            $vitals = $raw_vitals;
        } elseif (is_string($raw_vitals)) {
            $vitals = json_decode($raw_vitals, true) ?: [];
        } else {
            $vitals = [
                'bp'   => (string)($this->input->post('vital_bp', TRUE) ?: '120/80'),
                'hr'   => (int)($this->input->post('vital_hr', TRUE) ?: 75),
                'temp' => (float)($this->input->post('vital_temp', TRUE) ?: 36.5),
                'rr'   => (int)($this->input->post('vital_rr', TRUE) ?: 18),
                'spo2' => (int)($this->input->post('vital_spo2', TRUE) ?: 98)
            ];
        }

        // 4. Assemble Structured JSONB Prescriptions (SOAP: Plan)
        $raw_rx = $this->input->post('prescriptions');
        if (is_array($raw_rx)) {
            $prescriptions = $raw_rx;
        } elseif (is_string($raw_rx)) {
            $prescriptions = json_decode($raw_rx, true) ?: [];
        } else {
            $prescriptions = [];
        }

        // 5. Verify Visit Eligibility
        $visit = $this->db->get_where('visits', ['id' => $visit_id])->row_array();
        if (!$visit) {
            $this->output
                ->set_status_header(404)
                ->set_output(json_encode([
                    'status'  => 'error',
                    'message' => 'Associated outpatient visit not found.'
                ], JSON_UNESCAPED_UNICODE));
            return;
        }

        if ($visit['queue_status'] === 'COMPLETED') {
            $this->output
                ->set_status_header(409)
                ->set_output(json_encode([
                    'status'  => 'conflict',
                    'message' => 'This clinical encounter is already completed and finalized.'
                ], JSON_UNESCAPED_UNICODE));
            return;
        }

        // 6. Verify ICD-10 Code Validity
        $icd = $this->db->get_where('icd10_codes', ['code' => $primary_icd10_code])->row_array();
        if (!$icd) {
            $this->output
                ->set_status_header(422)
                ->set_output(json_encode([
                    'status'  => 'error',
                    'message' => "Invalid Primary ICD-10 Code '{$primary_icd10_code}'."
                ], JSON_UNESCAPED_UNICODE));
            return;
        }

        // 7. Persist and Digitally Lock the EMR Record
        try {
            $emr_record = [
                'visit_id'              => $visit_id,
                'patient_id'            => $patient_id,
                'doctor_id'             => $doctor_id,
                'primary_icd10_code'    => $primary_icd10_code,
                'secondary_icd10_codes' => json_encode([]),
                'subjective_complaints' => $subjective_complaints,
                'objective_vital_signs' => json_encode($vitals),
                'assessment_notes'      => $assessment_notes,
                'plan_therapy'          => $plan_therapy,
                'prescriptions'         => json_encode($prescriptions)
            ];

            $record_id = $this->m_emr->save_and_lock_emr($emr_record, $visit_id);

            $this->output
                ->set_status_header(201)
                ->set_output(json_encode([
                    'status'  => 'success',
                    'message' => 'Electronic Medical Record (EMR) successfully finalized and signed.',
                    'data'    => [
                        'record_id'           => $record_id,
                        'visit_id'            => $visit_id,
                        'primary_icd10_code'  => $primary_icd10_code,
                        'is_locked'           => true,
                        'finalized_timestamp' => date('c')
                    ]
                ], JSON_UNESCAPED_UNICODE));

        } catch (Throwable $e) {
            log_message('error', 'EMR Finalization Failure: ' . $e->getMessage());
            $this->output
                ->set_status_header(500)
                ->set_output(json_encode([
                    'status'  => 'error',
                    'message' => 'An internal clinical system error occurred while finalizing EMR.',
                    'debug'   => ENVIRONMENT === 'development' ? $e->getMessage() : null
                ], JSON_UNESCAPED_UNICODE));
        }
    }

    public function validate_uuid($val): bool {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string)$val)) {
            return TRUE;
        }
        $this->form_validation->set_message('validate_uuid', 'The {field} must be a valid UUID format.');
        return FALSE;
    }
}
