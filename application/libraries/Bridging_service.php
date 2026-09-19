<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Enterprise Hospital Management Information System (HMIS)
 * Third-Party Bridging Integration Service
 *
 * Implements secure, audited API communications for national health systems
 * (e.g., BPJS Kesehatan VClaim HMAC-SHA256, SatuSehat FHIR OAuth2, WhatsApp Gateways).
 *
 * Automatically records structured telemetry and request/response payloads
 * into PostgreSQL 15 JSONB api_logs table.
 *
 * Compatible with PHP 8.4-FPM and CodeIgniter 3.1.13.
 */
#[AllowDynamicProperties]
class Bridging_service {

    protected CI_Controller $ci;
    protected CI_DB_query_builder $db;

    // Default configuration (can be overridden via environment variables)
    protected string $bpjs_cons_id;
    protected string $bpjs_secret_key;
    protected string $bpjs_user_key;
    protected string $bpjs_base_url;

    protected string $satusehat_client_id;
    protected string $satusehat_client_secret;
    protected string $satusehat_base_url;

    public function __construct() {
        $this->ci =& get_instance();
        $this->db =& $this->ci->db;

        // Load configuration with secure environment fallbacks
        $this->bpjs_cons_id       = (string)(getenv('BPJS_CONS_ID') ?: '12345');
        $this->bpjs_secret_key   = (string)(getenv('BPJS_SECRET_KEY') ?: 'secret_hmac_key_hospital');
        $this->bpjs_user_key     = (string)(getenv('BPJS_USER_KEY') ?: 'user_key_central_hospital');
        $this->bpjs_base_url     = (string)(getenv('BPJS_BASE_URL') ?: 'https://apijkn-dev.bpjs-kesehatan.go.id/vclaim-rest-dev');

        $this->satusehat_client_id     = (string)(getenv('SATUSEHAT_CLIENT_ID') ?: 'satusehat_client_id_demo');
        $this->satusehat_client_secret = (string)(getenv('SATUSEHAT_CLIENT_SECRET') ?: 'satusehat_secret_demo');
        $this->satusehat_base_url      = (string)(getenv('SATUSEHAT_BASE_URL') ?: 'https://api-satusehat-dev.kemkes.go.id');
    }

    /**
     * Generates standard BPJS Kesehatan VClaim authentication headers with HMAC-SHA256 signature.
     */
    public function get_bpjs_headers(): array {
        // UTC timestamp in seconds
        $timestamp = (string)time();

        // Signature: base64(hash_hmac('sha256', ConsID . "&" . Timestamp, SecretKey, true))
        $signature_data = $this->bpjs_cons_id . '&' . $timestamp;
        $signature = base64_encode(hash_hmac('sha256', $signature_data, $this->bpjs_secret_key, true));

        return [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-cons-id: ' . $this->bpjs_cons_id,
            'X-timestamp: ' . $timestamp,
            'X-signature: ' . $signature,
            'user_key: ' . $this->bpjs_user_key
        ];
    }

    /**
     * Example: Dispatches an Outpatient SEP Verification / Claim to BPJS Kesehatan.
     */
    public function send_bpjs_claim(array $claim_payload): array {
        $endpoint = $this->bpjs_base_url . '/SEP/1.1/insert';
        $headers  = $this->get_bpjs_headers();

        return $this->execute_http_request(
            'BPJS_VCLAIM',
            'POST',
            $endpoint,
            $headers,
            $claim_payload
        );
    }

    /**
     * Example: Dispatches a FHIR Encounter Bundle to Government Health Data Platform (SatuSehat).
     */
    public function sync_satusehat_encounter(string $encounter_uuid, array $fhir_bundle): array {
        $endpoint = $this->satusehat_base_url . '/fhir-r4/v1/Encounter';
        $headers  = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->get_satusehat_token()
        ];

        return $this->execute_http_request(
            'SATUSEHAT_FHIR',
            'POST',
            $endpoint,
            $headers,
            $fhir_bundle
        );
    }

    /**
     * Obtains or generates a mock/live SatuSehat OAuth2 token.
     */
    protected function get_satusehat_token(): string {
        // In live production, this exchanges Client ID/Secret at /oauth2/v1/accesstoken
        // and caches the Bearer token in PostgreSQL or Redis.
        return 'mock_token_' . md5($this->satusehat_client_id . date('YmdH'));
    }

    /**
     * Core cURL Transport Engine: Executes HTTP transmission, calculates latency,
     * masks credentials, and logs telemetry into PostgreSQL api_logs.
     */
    public function execute_http_request(
        string $service_name,
        string $method,
        string $url,
        array $headers = [],
        ?array $payload = null,
        int $timeout_seconds = 15
    ): array {
        $start_time = microtime(true);
        $ch = curl_init();

        $method = strtoupper($method);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout_seconds);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HEADER, true);

        // Attach Payload
        $json_payload = null;
        if ($payload !== null) {
            $json_payload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
            if (!in_array('Content-Type: application/json', $headers, true)) {
                $headers[] = 'Content-Type: application/json';
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Execute cURL transaction
        $response_raw = curl_exec($ch);
        $curl_error   = curl_error($ch);
        $http_code    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $execution_time_ms = round((microtime(true) - $start_time) * 1000, 2);

        // Separate Response Headers and Body
        $response_headers = [];
        $response_body = '';
        if ($response_raw !== false) {
            $header_text = substr($response_raw, 0, $header_size);
            $response_body = substr($response_raw, $header_size);
            $response_headers = $this->parse_headers($header_text);
        }

        // Decode JSON response if applicable
        $parsed_response = json_decode($response_body, true);
        if ($parsed_response === null && $response_body !== '') {
            $parsed_response = ['raw_text' => $response_body];
        }

        // Sanitize headers to protect secrets in audit logs
        $sanitized_headers = $this->sanitize_headers($headers);

        // If network error occurred (e.g. offline mock or host unreachable)
        if ($response_raw === false) {
            $http_code = 504; // Gateway Timeout
            $parsed_response = [
                'status'  => 'error',
                'message' => 'Third-party bridging connection failed: ' . $curl_error
            ];
        }

        // Persist Telemetry to PostgreSQL 15 api_logs
        $this->log_api_transaction([
            'service_name'         => $service_name,
            'endpoint'             => $url,
            'http_method'          => $method,
            'request_headers'      => json_encode($sanitized_headers),
            'request_payload'      => $payload !== null ? json_encode($payload) : null,
            'response_status_code' => $http_code,
            'response_headers'     => json_encode($response_headers),
            'response_payload'     => $parsed_response !== null ? json_encode($parsed_response) : null,
            'execution_time_ms'    => $execution_time_ms,
            'error_message'        => $curl_error ?: null,
            'ip_address'           => $this->ci->input->ip_address() ?: '127.0.0.1'
        ]);

        return [
            'http_code'         => $http_code,
            'execution_time_ms' => $execution_time_ms,
            'data'              => $parsed_response,
            'error'             => $curl_error ?: null
        ];
    }

    /**
     * Sanitizes sensitive credentials (keys, tokens, signatures) before recording to database.
     */
    protected function sanitize_headers(array $headers): array {
        $sanitized = [];
        $sensitive_keys = ['authorization', 'user_key', 'x-signature', 'x-cons-id'];

        foreach ($headers as $h) {
            $parts = explode(':', $h, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $val = trim($parts[1]);
                if (in_array(strtolower($key), $sensitive_keys, true)) {
                    $val = substr($val, 0, 4) . '****' . substr($val, -4);
                }
                $sanitized[$key] = $val;
            } else {
                $sanitized[] = $h;
            }
        }
        return $sanitized;
    }

    /**
     * Parses raw HTTP header text into key-value array.
     */
    protected function parse_headers(string $header_text): array {
        $headers = [];
        foreach (explode("\r\n", $header_text) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }
        return $headers;
    }

    /**
     * Inserts API transaction into PostgreSQL 15 api_logs table.
     */
    protected function log_api_transaction(array $log_entry): void {
        try {
            $this->db->insert('api_logs', $log_entry);
        } catch (Throwable $e) {
            log_message('error', 'Failed to record api_logs: ' . $e->getMessage());
        }
    }
}
