<?php
defined('BASEPATH') OR exit('No direct script access allowed');

#[AllowDynamicProperties]
class MY_Model extends CI_Model {

    protected string $table = '';
    protected string $primary_key = 'id';

    public function __construct() {
        parent::__construct();
        $this->load->database();
    }

    /**
     * Tracked Insert: Persists record and records INSERT action in audit_logs
     *
     * @param array $data Column-value associative array
     * @return string|int|false Inserted record ID or false on failure
     */
    public function tracked_insert(array $data): string|int|false {
        $success = $this->db->insert($this->table, $data);
        if (!$success) {
            return false;
        }

        $record_id = $data[$this->primary_key] ?? $this->db->insert_id();
        $this->write_audit('INSERT', (string)$record_id, null, $data);

        return $record_id;
    }

    /**
     * Tracked Update: Fetches baseline, updates record, and records UPDATE diff in audit_logs
     *
     * @param string|int $id Target record ID
     * @param array $data Updated fields
     * @return bool True on success, false otherwise
     */
    public function tracked_update(string|int $id, array $data): bool {
        // Fetch current state as baseline for old_values
        $old_record = $this->db->where($this->primary_key, $id)
                               ->get($this->table)
                               ->row_array();

        if (!$old_record) {
            return false;
        }

        $this->db->where($this->primary_key, $id);
        $updated = $this->db->update($this->table, $data);

        if ($updated) {
            $this->write_audit('UPDATE', (string)$id, $old_record, $data);
            return true;
        }

        return false;
    }

    /**
     * Tracked Delete: Fetches baseline, deletes record, and records DELETE action in audit_logs
     *
     * @param string|int $id Target record ID
     * @return bool True on success, false otherwise
     */
    public function tracked_delete(string|int $id): bool {
        $old_record = $this->db->where($this->primary_key, $id)
                               ->get($this->table)
                               ->row_array();

        if (!$old_record) {
            return false;
        }

        $this->db->where($this->primary_key, $id);
        $deleted = $this->db->delete($this->table);

        if ($deleted) {
            $this->write_audit('DELETE', (string)$id, $old_record, null);
            return true;
        }

        return false;
    }

    /**
     * Internal Auditor: Emits immutable entry into audit_logs table
     *
     * @param string $action 'INSERT', 'UPDATE', 'DELETE', or 'VIEW'
     * @param string $record_id Primary key identifier of the modified record
     * @param array|null $old_data Previous state before modification
     * @param array|null $new_data New state after modification
     */
    protected function write_audit(string $action, string $record_id, ?array $old_data, ?array $new_data): void {
        // Determine active user ID from session if available
        $user_id = null;
        if (isset($this->session)) {
            $user_id = $this->session->userdata('id') ?: ($this->session->userdata('user_id') ?: null);
        }

        $ip = $this->input->ip_address();
        $user_agent = $this->input->user_agent();

        $uuid_row = $this->db->query("SELECT gen_random_uuid() AS uuid")->row();
        $audit_id = $uuid_row ? $uuid_row->uuid : null;

        $audit_payload = [
            'id'          => $audit_id,
            'table_name'  => $this->table,
            'record_id'   => $record_id,
            'action'      => $action,
            'user_id'     => $user_id,
            'ip_address'  => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1',
            'user_agent'  => substr((string)$user_agent, 0, 500),
            'old_values'  => $old_data !== null ? json_encode($old_data, JSON_UNESCAPED_UNICODE) : null,
            'new_values'  => $new_data !== null ? json_encode($new_data, JSON_UNESCAPED_UNICODE) : null,
            'created_at'  => date('Y-m-d H:i:sP')
        ];

        // Insert audit log directly
        $this->db->insert('audit_logs', $audit_payload);
    }
}
