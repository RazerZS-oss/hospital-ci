<?php
defined('BASEPATH') OR exit('No direct script access allowed');

#[AllowDynamicProperties]
class Visit_model extends MY_Model {

    public function __construct() {
        parent::__construct();
        $this->table = 'visits';
        $this->primary_key = 'id';
    }

    /**
     * Atomically registers a patient visit and allocates the next sequential queue number.
     *
     * Prevents race conditions during high concurrency using PostgreSQL Transaction-Level
     * Advisory Locks (pg_advisory_xact_lock) and row locking.
     *
     * @param string $patient_id    UUID of the patient
     * @param string $polyclinic_id UUID of the polyclinic
     * @param string $doctor_id     UUID of the scheduled doctor
     * @param string $visit_date    Date in 'YYYY-MM-DD' format
     * @return array Contains visit_id, visit_number, and queue_number
     * @throws RuntimeException On transaction failure or conflict
     */
    public function register_patient_queue(
        string|int $patient_id,
        string $polyclinic_id,
        string|int $doctor_id,
        string $visit_date
    ): array {
        // 1. Begin manual transaction
        $this->db->trans_begin();

        try {
            /*
             * 2. CONCURRENCY SERIALIZATION:
             * Generate a unique logical lock key based on polyclinic + doctor + date.
             * pg_advisory_xact_lock acquires an exclusive lock scoped strictly to this transaction.
             * If 20 patients register at the exact same millisecond, requests for the same clinic+doctor
             * will queue sequentially; other clinics/doctors run completely parallel without delay.
             * The lock is automatically released by PostgreSQL on COMMIT or ROLLBACK.
             */
            $lock_key = "queue:{$polyclinic_id}:{$doctor_id}:{$visit_date}";
            $lock_query = "SELECT pg_advisory_xact_lock(hashtext(?))";
            $this->db->query($lock_query, [$lock_key]);

            /*
             * 3. Row-Level Lock verification on the target polyclinic:
             * Ensures polyclinic exists and is active, while establishing physical row lock.
             */
            $poly = $this->db->query(
                "SELECT id, is_active FROM polyclinics WHERE id = ? FOR UPDATE",
                [$polyclinic_id]
            )->row();

            if (!$poly || !$poly->is_active) {
                throw new RuntimeException("Target polyclinic is inactive or does not exist.");
            }

            // 4. Calculate the next queue number safely under serialized isolation
            $calc_sql = "
                SELECT COALESCE(MAX(queue_number), 0) + 1 AS next_queue
                FROM visits
                WHERE polyclinic_id = ?
                  AND doctor_id = ?
                  AND visit_date = ?
            ";
            $queue_result = $this->db->query($calc_sql, [$polyclinic_id, $doctor_id, $visit_date])->row();
            $next_queue   = (int)($queue_result->next_queue ?? 1);

            // 5. Generate human-readable visit token (e.g. VIS-20260919-0001)
            $formatted_date = str_replace('-', '', $visit_date);
            $padded_queue   = str_pad((string)$next_queue, 4, '0', STR_PAD_LEFT);
            $visit_number   = "VIS-{$formatted_date}-{$padded_queue}";

            // 6. Generate UUID for the visit
            $uuid_row = $this->db->query("SELECT gen_random_uuid() AS uuid")->row();
            $visit_id = $uuid_row->uuid;

            $visit_data = [
                'id'             => $visit_id,
                'visit_number'   => $visit_number,
                'patient_id'     => $patient_id,
                'polyclinic_id'  => $polyclinic_id,
                'doctor_id'      => $doctor_id,
                'visit_date'     => $visit_date,
                'queue_number'   => $next_queue,
                'queue_status'   => 'WAITING',
                'billing_status' => 'UNPAID',
                'check_in_time'  => date('Y-m-d H:i:sP'),
                'created_at'     => date('Y-m-d H:i:sP'),
                'updated_at'     => date('Y-m-d H:i:sP')
            ];

            // 7. Persist record using tracked insert (automatically generates HIPAA audit log)
            $this->tracked_insert($visit_data);

            // 8. Verify transaction status
            if ($this->db->trans_status() === FALSE) {
                throw new RuntimeException("Database error encountered during visit registration.");
            }

            // 9. Commit transaction and release lock
            $this->db->trans_commit();

            return [
                'visit_id'     => $visit_id,
                'visit_number' => $visit_number,
                'queue_number' => $next_queue,
                'visit_date'   => $visit_date
            ];

        } catch (Throwable $e) {
            // Revert transaction state on any error
            $this->db->trans_rollback();
            log_message('error', '[Visit_model::register_patient_queue] Transaction rolled back: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Retrieve current queue list for display or consultation monitor
     */
    public function get_active_queue(string $polyclinic_id, string|int $doctor_id, string $visit_date): array {
        $sql = "
            SELECT v.id, v.visit_number, v.queue_number, v.queue_status, v.check_in_time,
                   COALESCE(p.mrn, p.medical_record_number) AS mrn,
                   COALESCE(p.full_name, p.name) AS patient_name,
                   p.gender
            FROM visits v
            JOIN patients p ON p.id = v.patient_id
            WHERE v.polyclinic_id = ?
              AND v.doctor_id = ?
              AND v.visit_date = ?
            ORDER BY v.queue_number ASC
        ";

        return $this->db->query($sql, [$polyclinic_id, $doctor_id, $visit_date])->result_array();
    }
}
