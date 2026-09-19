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
