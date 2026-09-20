<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Enterprise Hospital Management Information System (HMIS)
 * Role-Based Access Control (RBAC) Security Middleware Hook
 * 
 * Intercepts HTTP requests at 'post_controller_constructor' to enforce
 * strict role-level authorization, session integrity, and HIPAA-compliant access denial logging.
 *
 * Compatible with PHP 8.4-FPM and CodeIgniter 3.1.13 HMVC.
 */
#[AllowDynamicProperties]
class Rbac_hook {

    /**
     * Unauthenticated public routes whitelist.
     * Requests matching these controllers/methods bypass authentication and RBAC checks.
     */
    private array $public_routes = [
        'auth'                    => ['*'],
        'registration/polyclinic' => ['register_queue', 'queue_monitor'],
        'patient_portal'          => ['*'],
        'api/v1/patient_portal'   => ['*'],
    ];

    /**
     * Role-to-Resource Authorization Matrix.
     * Defines accessible controllers and specific methods for each clinical and administrative role.
     * Wildcard '*' indicates unrestricted access to all methods within the controller.
     */
    private array $role_permissions = [
        'admin' => [
            '*' => ['*'] // Superuser: unrestricted access across all modules
        ],
        'doctor' => [
            'dashboard'               => ['*'],
            'emr'                     => ['*'],
            'medical_records'         => ['*'],
            'pasien'                  => ['index', 'detail'], // Clinical consultation view only
            'dokter'                  => ['index', 'detail'],
            'registration/polyclinic' => ['*']
        ],
        'nurse' => [
            'dashboard'               => ['*'],
            'pasien'                  => ['index', 'detail'],
            'emr'                     => ['vitals', 'triage'], // Triage and vital signs recording
            'registration/polyclinic' => ['queue_monitor']
        ],
        'receptionist' => [
            'dashboard'               => ['*'],
            'pasien'                  => ['*'],               // Patient directory & registration
            'registration/polyclinic' => ['*'],               // Outpatient queue tickets
            'dokter'                  => ['index']
        ],
        'pharmacist' => [
            'dashboard'               => ['*'],
            'pharmacy'                => ['*'],
            'prescriptions'           => ['*']
        ]
    ];

    /**
     * Primary Hook Handler: Enforces authentication and authorization guards.
     */
    public function check_access(): void {
        $CI =& get_instance();

        // 1. Bypass RBAC for CLI commands (handled via dedicated CLI guards)
        if ($CI->input->is_cli_request()) {
            return;
        }

        // 2. Identify requested target context
        $class  = strtolower((string)$CI->router->fetch_class());
        $method = strtolower((string)$CI->router->fetch_method());
        $module = method_exists($CI->router, 'fetch_module') ? strtolower((string)$CI->router->fetch_module()) : '';

        $resource_key = $module !== '' ? "{$module}/{$class}" : $class;

        // 3. Whitelist validation
        if ($this->is_public_route($resource_key, $class, $method)) {
            return;
        }

        // 4. Session Authentication Check
        $is_logged_in = (bool)$CI->session->userdata('isLoggedIn');
        if (!$is_logged_in) {
            $this->handle_unauthenticated($CI);
            return;
        }

        // 5. Role-Based Access Control Evaluation
        $user_role = strtolower((string)$CI->session->userdata('role'));
        $user_id   = (string)$CI->session->userdata('id');

        if (!$this->is_authorized($user_role, $resource_key, $class, $method)) {
            $this->log_security_violation($CI, $user_id, $user_role, $resource_key, $method);
            $this->handle_forbidden($CI, $user_role, $resource_key, $method);
            return;
        }
    }

    /**
     * Checks if requested target is publicly accessible without authentication.
     */
    private function is_public_route(string $resource_key, string $class, string $method): bool {
        // Check by module/controller key first
        if (isset($this->public_routes[$resource_key])) {
            $allowed = $this->public_routes[$resource_key];
            if (in_array('*', $allowed, true) || in_array($method, $allowed, true)) {
                return true;
            }
        }

        // Check by base class name
        if (isset($this->public_routes[$class])) {
            $allowed = $this->public_routes[$class];
            if (in_array('*', $allowed, true) || in_array($method, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluates whether user's role grants permission to access target controller and action.
     */
    private function is_authorized(string $role, string $resource_key, string $class, string $method): bool {
        if (!isset($this->role_permissions[$role])) {
            return false;
        }

        $permissions = $this->role_permissions[$role];

        // Global wildcard permission (e.g. admin)
        if (isset($permissions['*']) && in_array('*', $permissions['*'], true)) {
            return true;
        }

        // Check module/class permissions
        foreach ([$resource_key, $class] as $key) {
            if (isset($permissions[$key])) {
                $methods = $permissions[$key];
                if (in_array('*', $methods, true) || in_array($method, $methods, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Handles unauthenticated requests with appropriate format (JSON 401 or Web 302/307 Redirect).
     */
    private function handle_unauthenticated($CI): void {
        if ($this->is_api_or_ajax($CI)) {
            set_status_header(401);
            $CI->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'status'  => 'error',
                    'code'    => 401,
                    'message' => 'Authentication required. Active staff session expired or not found.'
                ], JSON_UNESCAPED_UNICODE))
                ->_display();
            exit;
        }

        redirect('login');
        exit;
    }

    /**
     * Handles unauthorized access attempts with HTTP 403 Forbidden.
     */
    private function handle_forbidden($CI, string $role, string $resource, string $method): void {
        set_status_header(403);
        if ($this->is_api_or_ajax($CI)) {
            $CI->output
                ->set_content_type('application/json')
                ->set_output(json_encode([
                    'status'  => 'error',
                    'code'    => 403,
                    'message' => "Access Denied: Role '{$role}' is not authorized to access '{$resource}/{$method}'."
                ], JSON_UNESCAPED_UNICODE))
                ->_display();
            exit;
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>403 Forbidden - Central Hospital Security</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background-color: #0f172a; color: #f8fafc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; border: 1px solid #334155; padding: 40px; border-radius: 12px; max-width: 500px; text-align: center; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5); }
        h1 { color: #ef4444; font-size: 48px; margin: 0 0 12px 0; }
        h2 { margin: 0 0 16px 0; font-size: 20px; font-weight: 600; }
        p { color: #94a3b8; font-size: 14px; line-height: 1.6; margin-bottom: 24px; }
        .badge { background: #7f1d1d; color: #fca5a5; padding: 4px 10px; border-radius: 6px; font-weight: bold; font-family: monospace; font-size: 13px; }
        .btn { display: inline-block; background: #2563eb; color: white; padding: 10px 24px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 14px; }
        .btn:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="card">
        <h1>403</h1>
        <h2>Access Denied (RBAC Protected)</h2>
        <p>Your current role <span class="badge">{$role}</span> does not have sufficient privileges to access the resource <code>{$resource}/{$method}</code>.</p>
        <p style="font-size: 12px; color: #64748b;">This unauthorized attempt has been recorded in the HIPAA audit ledger.</p>
        <a href="/dashboard" class="btn">Return to Hospital Dashboard</a>
    </div>
</body>
</html>
HTML;
        $CI->output
            ->set_content_type('text/html')
            ->set_output($html)
            ->_display();
        exit;
    }

    /**
     * Determines whether current request is AJAX, expects JSON, or targets API route.
     */
    private function is_api_or_ajax($CI): bool {
        if ($CI->input->is_ajax_request()) {
            return true;
        }

        $accept = (string)$CI->input->server('HTTP_ACCEPT');
        if (stripos($accept, 'application/json') !== false) {
            return true;
        }

        $content_type = (string)$CI->input->server('CONTENT_TYPE');
        if (stripos($content_type, 'application/json') !== false) {
            return true;
        }

        if (str_starts_with((string)$CI->uri->uri_string(), 'api/')) {
            return true;
        }

        return false;
    }

    /**
     * Records security violation in immutable PostgreSQL audit_logs table.
     */
    private function log_security_violation($CI, string $user_id, string $role, string $resource, string $method): void {
        try {
            $CI->db->insert('audit_logs', [
                'table_name' => 'rbac_security',
                'record_id'  => "{$resource}/{$method}",
                'action'     => 'DENIED',
                'user_id'    => $user_id ?: null,
                'ip_address' => $CI->input->ip_address() ?: '127.0.0.1',
                'user_agent' => substr((string)$CI->input->user_agent(), 0, 500),
                'new_values' => json_encode([
                    'role'     => $role,
                    'uri'      => $CI->uri->uri_string(),
                    'resource' => $resource,
                    'method'   => $method,
                    'method_http' => $CI->input->method()
                ], JSON_UNESCAPED_UNICODE)
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Failed to log RBAC violation: ' . $e->getMessage());
        }
    }
}
