<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Electronic Medical Record (EMR) Data Model
 * Extends MY_Model for automated HIPAA audit logging and PostgreSQL JSONB persistence.
 */
#[AllowDynamicProperties]
class M_medical_record extends MY_Model {

    protected string $table = 'medical_records';
    protected string $primary_key = 'id';

    public function __construct() {
        parent::__construct();
    }

    /**
     * Persists a finalized, digitally locked EMR record and marks visit as COMPLETED.
     */
    public function save_and_lock_emr(array $emr_data, string $visit_id): string {
        $this->db->trans_begin();

        try {
            // 1. Generate UUID for medical_records if not supplied
            if (empty($emr_data['id'])) {
                $query = $this->db->query("SELECT gen_random_uuid() AS new_uuid");
                $emr_data['id'] = $query->row()->new_uuid;
            }

            // 2. Mark record as digitally locked
            $emr_data['is_locked']  = 'TRUE';
            $emr_data['locked_at']   = date('Y-m-d H:i:s');
            $emr_data['created_at']  = date('Y-m-d H:i:s');
            $emr_data['updated_at']  = date('Y-m-d H:i:s');

            // 3. Insert into medical_records using tracked_insert for HIPAA audit log
            $success = $this->tracked_insert($emr_data);
            if (!$success) {
                throw new RuntimeException("Failed to persist clinical medical record.");
            }

            // 4. Update parent visit queue status to COMPLETED
            $this->db->where('id', $visit_id)
                     ->update('visits', [
                         'queue_status' => 'COMPLETED',
                         'updated_at'   => date('Y-m-d H:i:s')
                     ]);

            if ($this->db->trans_status() === FALSE) {
                $this->db->trans_rollback();
                throw new RuntimeException("Transaction error during EMR locking.");
            }

            $this->db->trans_commit();
            return (string)$emr_data['id'];

        } catch (Throwable $e) {
            $this->db->trans_rollback();
            throw $e;
        }
    }
}
