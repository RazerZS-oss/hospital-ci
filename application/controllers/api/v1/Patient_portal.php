<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Enterprise Hospital Management Information System (HMIS)
 * Domain 4: Patient Portal RESTful API - Laboratory Investigations Endpoint
 *
 * Implements strict HIPAA tenant isolation and Bearer token authentication
 * for mobile and web patient portal applications to query verified lab results.
 *
 * Compatible with PHP 8.4-FPM and CodeIgniter 3.1.13.
 */
#[AllowDynamicProperties]
class Patient_portal extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->database();
    }

    /**
     * RESTful API Endpoint: GET /api/v1/patients/lab-results
     * Authenticates patient via Bearer token, enforces strict tenant isolation,
     * and streams structured laboratory panel results.
     */
    public function lab_results(): void {
        // Enforce HTTP GET method (RFC 7231 / 9110 Method Not Allowed)
        if ($this->input->method() !== 'get') {
            $this->respond_json([
                'status'  => 'error',
                'code'    => 405,
                'message' => 'Method Not Allowed. Only GET requests are accepted.'
            ], 405);
            return;
        }

        // 1. Authenticate via Bearer Token
        $token_data = $this->authenticate_bearer_token();
        if (!$token_data) {
            return; // respond_json() already called inside authenticate_bearer_token()
        }

        $patient_id = (int)$token_data['patient_id'];

        // 2. Fetch Patient Demographic Summary
        $patient = $this->db->select('id, medical_record_number, name, gender, date_of_birth')
            ->from('patients')
            ->where('id', $patient_id)
            ->get()
            ->row_array();

        if (!$patient) {
            $this->respond_json([
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Patient record associated with this token was not found.'
            ], 404);
            return;
        }

        // 3. Strict HIPAA Tenant Isolation Guard on Query Parameters
        $requested_patient_id = $this->input->get('patient_id', TRUE);
        if ($requested_patient_id !== null && $requested_patient_id !== '') {
            if ((int)$requested_patient_id !== $patient_id) {
                $this->respond_json([
                    'status'  => 'error',
                    'code'    => 403,
                    'message' => 'Forbidden. Access to another patient\'s records is strictly prohibited by HIPAA tenant isolation.'
                ], 403);
                return;
            }
        }

        // 4. Query Filter Parameters
        $category  = trim((string)$this->input->get('category', TRUE));
        $visit_id  = trim((string)$this->input->get('visit_id', TRUE));
        $result_id = trim((string)$this->input->get('result_id', TRUE));
        $limit     = min(100, max(1, (int)($this->input->get('limit') ?: 20)));
        $offset    = max(0, (int)($this->input->get('offset') ?: 0));

        // Visit ID Validation & Tenant Ownership Check
        if ($visit_id !== '') {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $visit_id)) {
                $this->respond_json([
                    'status'  => 'error',
                    'code'    => 400,
                    'message' => 'Invalid visit_id format. Standard UUID required.'
                ], 400);
                return;
            }

            $visit_check = $this->db->select('id, patient_id')
                ->from('visits')
                ->where('id', $visit_id)
                ->get()
                ->row_array();

            if (!$visit_check) {
                $this->respond_json([
                    'status'  => 'error',
                    'code'    => 404,
                    'message' => 'Requested visit record not found.'
                ], 404);
                return;
            }

            if ((int)$visit_check['patient_id'] !== $patient_id) {
                $this->respond_json([
                    'status'  => 'error',
                    'code'    => 403,
                    'message' => 'Forbidden. You do not have authorization to view clinical records for this visit (HIPAA Tenant Isolation).'
                ], 403);
                return;
            }
        }

        // Result ID Validation & Tenant Ownership Check
        if ($result_id !== '') {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $result_id)) {
                $this->respond_json([
                    'status'  => 'error',
                    'code'    => 400,
                    'message' => 'Invalid result_id format. Standard UUID required.'
                ], 400);
                return;
            }

            $result_check = $this->db->select('id, patient_id')
                ->from('laboratory_results')
                ->where('id', $result_id)
                ->get()
                ->row_array();

            if (!$result_check) {
                $this->respond_json([
                    'status'  => 'error',
                    'code'    => 404,
                    'message' => 'Requested laboratory result record not found.'
                ], 404);
                return;
            }

            if ((int)$result_check['patient_id'] !== $patient_id) {
                $this->respond_json([
                    'status'  => 'error',
                    'code'    => 403,
                    'message' => 'Forbidden. You do not have authorization to view this laboratory result (HIPAA Tenant Isolation).'
                ], 403);
                return;
            }
        }

        // 5. Query Laboratory Results with Joins (laboratory_results, visits, patients, doctors)
        // Multi-layered HIPAA isolation constraining both laboratory_results and visits to patient_id
        $this->db->select([
            'lr.id AS result_id',
            'lr.visit_id',
            'v.visit_number',
            'v.visit_date',
            'lr.patient_id',
            'p.medical_record_number',
            'COALESCE(p.full_name, p.name) AS patient_name',
            'v.doctor_id',
            'COALESCE(d.full_name, d.name) AS doctor_name',
            'd.specialization AS doctor_specialization',
            'lr.test_code',
            'lr.test_name',
            'lr.test_category',
            'lr.sample_collected_at',
            'lr.result_finalized_at',
            'lr.specimen_type',
            'lr.panel_results',
            'lr.clinical_interpretation',
            'lr.signing_pathologist_name',
            'lr.is_verified'
        ], FALSE);
        $this->db->from('laboratory_results lr');
        $this->db->join('visits v', 'v.id = lr.visit_id', 'inner');
        $this->db->join('patients p', 'p.id = lr.patient_id AND p.id = v.patient_id', 'inner');
        $this->db->join('doctors d', 'd.id = v.doctor_id', 'left');
        $this->db->where('lr.patient_id', $patient_id);
        $this->db->where('v.patient_id', $patient_id);

        if ($category !== '') {
            $this->db->where('UPPER(lr.test_category)', strtoupper($category));
        }

        if ($visit_id !== '') {
            $this->db->where('lr.visit_id', $visit_id);
        }

        if ($result_id !== '') {
            $this->db->where('lr.id', $result_id);
        }

        $this->db->order_by('lr.result_finalized_at', 'DESC');
        $this->db->limit($limit, $offset);

        $results = $this->db->get()->result_array();

        // 6. Transform and Decode JSONB Panel Results
        $formatted_results = array_map(function(array $row): array {
            $panels = !empty($row['panel_results']) 
                ? (is_string($row['panel_results']) ? json_decode($row['panel_results'], true) : $row['panel_results'])
                : [];

            $is_verified = in_array(strtolower((string)($row['is_verified'] ?? '')), ['t', 'true', '1', 'yes'], true);

            return [
                'result_id'                => $row['result_id'],
                'visit_id'                 => $row['visit_id'],
                'visit_number'             => $row['visit_number'] ?? null,
                'visit_date'               => $row['visit_date'] ?? null,
                'doctor_name'              => $row['doctor_name'] ?? null,
                'doctor_specialization'    => $row['doctor_specialization'] ?? null,
                'test_code'                => $row['test_code'],
                'test_name'                => $row['test_name'],
                'test_category'            => $row['test_category'],
                'specimen_type'            => $row['specimen_type'],
                'sample_collected_at'      => $row['sample_collected_at'],
                'result_finalized_at'      => $row['result_finalized_at'],
                'signing_pathologist_name' => $row['signing_pathologist_name'],
                'is_verified'              => $is_verified,
                'clinical_interpretation'  => $row['clinical_interpretation'],
                'panel_results'            => $panels ?: []
            ];
        }, $results);

        // 7. Return Structured RESTful Response
        $this->respond_json([
            'status' => 'success',
            'code'   => 200,
            'patient' => [
                'id'                    => (int)$patient['id'],
                'medical_record_number' => $patient['medical_record_number'],
                'name'                  => $patient['name'],
                'gender'                => $patient['gender'],
                'date_of_birth'         => $patient['date_of_birth']
            ],
            'pagination' => [
                'limit'         => $limit,
                'offset'        => $offset,
                'count_fetched' => count($formatted_results)
            ],
            'data' => $formatted_results
        ], 200);
    }

    /**
     * Extracts and validates Bearer token from HTTP Authorization header.
     * Enforces token expiration, revocation state, and binds to patient entity.
     *
     * @return array|null Token record row or null if unauthorized
     */
    private function authenticate_bearer_token(): ?array {
        $auth_header = $this->extract_authorization_header();

        if (empty($auth_header)) {
            $this->respond_json([
                'status'  => 'error',
                'code'    => 401,
                'message' => 'Authorization header missing. Required format: Authorization: Bearer <token>'
            ], 401);
            return null;
        }

        if (!preg_match('/Bearer\s+(\S+)/i', $auth_header, $matches)) {
            $this->respond_json([
                'status'  => 'error',
                'code'    => 401,
                'message' => 'Malformed Authorization header. Format must be Bearer <token>'
            ], 401);
            return null;
        }

        $raw_token  = $matches[1];
        $token_hash = hash('sha256', $raw_token);

        // Fetch token record matching either hashed token or raw token
        $sql = "
            SELECT id, patient_id, token_hash, device_fingerprint, expires_at, is_revoked
            FROM patient_portal_tokens
            WHERE (token_hash = ? OR token_hash = ?)
            LIMIT 1
        ";

        $query = $this->db->query($sql, [$token_hash, $raw_token]);
        $token = $query ? $query->row_array() : null;

        if (!$token) {
            $this->respond_json([
                'status'  => 'error',
                'code'    => 401,
                'message' => 'Invalid patient portal session token.'
            ], 401);
            return null;
        }

        // Check if token has been revoked (PostgreSQL boolean 't'/'f' or PHP bool/int)
        $is_revoked = in_array(strtolower((string)($token['is_revoked'] ?? '')), ['t', 'true', '1', 'yes'], true);
        if ($is_revoked) {
            $this->respond_json([
                'status'  => 'error',
                'code'    => 401,
                'message' => 'Patient portal session token has been revoked.'
            ], 401);
            return null;
        }

        // Check if token has expired
        if (strtotime($token['expires_at']) <= time()) {
            $this->respond_json([
                'status'  => 'error',
                'code'    => 401,
                'message' => 'Patient portal session token has expired.'
            ], 401);
            return null;
        }

        return $token;
    }

    /**
     * Extracts Authorization header resiliently across diverse server SAPI environments.
     */
    private function extract_authorization_header(): string {
        $header = (string)$this->input->get_request_header('Authorization', TRUE);
        if ($header !== '') {
            return $header;
        }

        if (isset($_SERVER['HTTP_AUTHORIZATION']) && $_SERVER['HTTP_AUTHORIZATION'] !== '') {
            return (string)$_SERVER['HTTP_AUTHORIZATION'];
        }

        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] !== '') {
            return (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                return (string)$headers['Authorization'];
            }
            if (isset($headers['authorization'])) {
                return (string)$headers['authorization'];
            }
        }

        return '';
    }

    /**
     * Emits JSON response with proper HTTP response code and headers.
     */
    private function respond_json(array $payload, int $status_code = 200): void {
        set_status_header($status_code);
        if ($status_code === 405) {
            $this->output->set_header('Allow: GET');
        }
        $this->output
            ->set_content_type('application/json', 'utf-8')
            ->set_header('Cache-Control: no-store, no-cache, must-revalidate')
            ->set_output(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
            ->_display();
        exit;
    }
}
