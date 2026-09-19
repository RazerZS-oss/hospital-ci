<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'modules/registration/services/Registration_service.php';

#[AllowDynamicProperties]
class Polyclinic extends MY_Controller {

    protected Registration_service $registration_service;

    public function __construct() {
        parent::__construct();
        $this->load->library('form_validation');
        $this->load->model('registration/Visit_model', 'visit_model');
        $this->registration_service = new Registration_service();
    }

    /**
     * HTTP POST Endpoint: Registers a patient into the polyclinic queue
     * URI: /registration/polyclinic/register_queue
     */
    public function register_queue(): void {
        // Enforce JSON API response format
        $this->output->set_content_type('application/json');

        // Parse JSON payload if Content-Type is application/json
        $raw_input = json_decode($this->input->raw_input_stream, true);
        if (is_array($raw_input)) {
            $this->form_validation->set_data($raw_input);
            foreach ($raw_input as $k => $v) {
                if (!isset($_POST[$k])) {
                    $_POST[$k] = $v;
                }
            }
        }

        // 1. Strict Input Validation Rules (UUID and Date formats via safe callbacks)
        $rules = [
            [
                'field' => 'patient_id',
                'label' => 'Patient Identifier',
                'rules' => 'required|trim|callback_validate_identifier'
            ],
            [
                'field' => 'polyclinic_id',
                'label' => 'Polyclinic UUID',
                'rules' => 'required|trim|callback_validate_uuid'
            ],
            [
                'field' => 'doctor_id',
                'label' => 'Doctor Identifier',
                'rules' => 'required|trim|callback_validate_identifier'
            ],
            [
                'field' => 'visit_date',
                'label' => 'Visit Date',
                'rules' => 'required|trim|callback_validate_date'
            ]
        ];

        $this->form_validation->set_rules($rules);

        if ($this->form_validation->run() === FALSE) {
            $this->output
                ->set_status_header(422)
                ->set_output(json_encode([
                    'status'  => 'error',
                    'message' => 'Input validation failed.',
                    'errors'  => $this->form_validation->error_array()
                ], JSON_UNESCAPED_UNICODE));
            return;
        }

        // 2. Extract Sanitized Inputs
        $patient_id    = (string)$this->input->post('patient_id', TRUE);
        $polyclinic_id = (string)$this->input->post('polyclinic_id', TRUE);
        $doctor_id     = (string)$this->input->post('doctor_id', TRUE);
        $visit_date    = (string)$this->input->post('visit_date', TRUE);

        // 3. Delegate to Domain Service Layer
        try {
            $registration = $this->registration_service->process_outpatient_registration(
                $patient_id,
                $polyclinic_id,
                $doctor_id,
                $visit_date
            );

            // Respond 201 Created with queue ticket payload
            $this->output
                ->set_status_header(201)
                ->set_output(json_encode([
                    'status'  => 'success',
                    'message' => 'Patient successfully queued.',
                    'data'    => [
                        'visit_id'     => $registration['visit_id'],
                        'visit_number' => $registration['visit_number'],
                        'queue_number' => $registration['queue_number'],
                        'visit_date'   => $registration['visit_date']
                    ]
                ], JSON_UNESCAPED_UNICODE));

        } catch (InvalidArgumentException $e) {
            // Business rule rejection (e.g. already registered today)
            $this->output
                ->set_status_header(409)
                ->set_output(json_encode([
                    'status'  => 'conflict',
                    'message' => $e->getMessage()
                ], JSON_UNESCAPED_UNICODE));

        } catch (Throwable $e) {
            // Unexpected database/concurrency error
            $this->output
                ->set_status_header(500)
                ->set_output(json_encode([
                    'status'  => 'error',
                    'message' => 'System unable to allocate queue token. Please retry.',
                    'debug'   => ENVIRONMENT === 'development' ? $e->getMessage() : null
                ], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * HTTP GET Endpoint: Fetch active queue for a polyclinic doctor monitor
     * URI: /registration/polyclinic/queue_monitor
     */
    public function queue_monitor(): void {
        $this->output->set_content_type('application/json');

        $polyclinic_id = (string)$this->input->get('polyclinic_id', TRUE);
        $doctor_id     = (string)$this->input->get('doctor_id', TRUE);
        $visit_date    = (string)($this->input->get('visit_date', TRUE) ?: date('Y-m-d'));

        if (!$polyclinic_id || !$doctor_id) {
            $this->output
                ->set_status_header(400)
                ->set_output(json_encode([
                    'status'  => 'error',
                    'message' => 'Missing required query parameters: polyclinic_id and doctor_id.'
                ], JSON_UNESCAPED_UNICODE));
            return;
        }

        $queue = $this->visit_model->get_active_queue($polyclinic_id, $doctor_id, $visit_date);

        $this->output
            ->set_status_header(200)
            ->set_output(json_encode([
                'status' => 'success',
                'data'   => $queue
            ], JSON_UNESCAPED_UNICODE));
    }

    public function validate_identifier($val): bool {
        if (ctype_digit((string)$val) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string)$val)) {
            return TRUE;
        }
        $this->form_validation->set_message('validate_identifier', 'The {field} must be a valid integer ID or UUID.');
        return FALSE;
    }

    public function validate_uuid($val): bool {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string)$val)) {
            return TRUE;
        }
        $this->form_validation->set_message('validate_uuid', 'The {field} must be a valid UUID format.');
        return FALSE;
    }

    public function validate_date($val): bool {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$val)) {
            return TRUE;
        }
        $this->form_validation->set_message('validate_date', 'The {field} must follow YYYY-MM-DD format.');
        return FALSE;
    }
}
