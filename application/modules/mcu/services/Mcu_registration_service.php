<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Enterprise Hospital Management Information System (HMIS)
 * Specialized Services: Corporate Medical Check-Up (MCU) Registration Service
 *
 * Implements high-volume, atomic bulk onboarding of corporate employee rosters
 * into MCU packages with automated patient matching, visit scheduling, and billing.
 *
 * Compatible with PHP 8.4-FPM and PostgreSQL 15 Transactions.
 */
#[AllowDynamicProperties]
class Mcu_registration_service {

    protected CI_Controller $ci;
    protected CI_DB_query_builder $db;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->database();
        $this->db =& $this->ci->db;
    }

    /**
     * Executes atomic batch registration of corporate employees into an MCU Package.
     *
     * @param int $corporate_client_id Corporate client primary key
     * @param string $package_uuid MCU Package UUID
     * @param array $employees List of employee profiles [['name' => '', 'badge_id' => '', 'nik' => '', 'phone' => '']]
     * @param string $scheduled_date Examination date (YYYY-MM-DD)
     * @return array Batch registration outcome with list of generated voucher/visit IDs
     * @throws InvalidArgumentException On invalid inputs, malformed UUID, empty roster, or duplicate NIK/badge in batch
     * @throws RuntimeException If corporate client or package is not found/inactive, duplicate badge exists for date, or DB transaction fails
     */
    public function register_corporate_bulk(
        int $corporate_client_id,
        string $package_uuid,
        array $employees,
        string $scheduled_date
    ): array {
        // 1. Validation: Scheduled Date
        $scheduled_date = trim($scheduled_date);
        if ($scheduled_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduled_date) || strtotime($scheduled_date) === false) {
            throw new InvalidArgumentException("Invalid examination date format (YYYY-MM-DD required): '{$scheduled_date}'.");
        }

        // 2. Validation: Empty Employee Roster
        if (empty($employees)) {
            throw new InvalidArgumentException("Employee roster cannot be empty.");
        }

        // 3. Validation: Duplicate NIK and Badge in the same batch & required fields
        $seen_badges = [];
        $seen_niks   = [];

        foreach ($employees as $idx => $emp) {
            if (!is_array($emp)) {
                throw new InvalidArgumentException("Employee entry at index {$idx} must be an associative array.");
            }

            $badge_id = trim((string)($emp['badge_id'] ?? ''));
            $nik      = trim((string)($emp['nik'] ?? ''));
            $name     = trim((string)($emp['name'] ?? ''));

            if ($badge_id === '' || $name === '') {
                throw new InvalidArgumentException("Each participant must provide at least a badge_id and full name.");
            }

            if (isset($seen_badges[$badge_id])) {
                throw new InvalidArgumentException("Duplicate employee badge ID '{$badge_id}' detected in the same batch.");
            }
            $seen_badges[$badge_id] = true;

            if ($nik !== '') {
                if (isset($seen_niks[$nik])) {
                    throw new InvalidArgumentException("Duplicate employee NIK '{$nik}' detected in the same batch.");
                }
                $seen_niks[$nik] = true;
            }
        }

        // 4. Validation: Corporate Client
        if ($corporate_client_id <= 0) {
            throw new InvalidArgumentException("Corporate client ID must be a positive integer: {$corporate_client_id}.");
        }

        $client = $this->db->get_where('mcu_corporate_clients', [
            'id' => $corporate_client_id
        ])->row_array();

        if (!$client) {
            throw new RuntimeException("Corporate client not found (ID: {$corporate_client_id}).");
        }

        if (!$this->is_truthy($client['is_active'])) {
            throw new RuntimeException("Corporate client is inactive (ID: {$corporate_client_id}).");
        }

        // 5. Validation: MCU Package UUID format & existence
        $package_uuid = trim($package_uuid);
        if ($package_uuid === '' || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $package_uuid)) {
            throw new InvalidArgumentException("Invalid MCU package ID format (UUID required): '{$package_uuid}'.");
        }

        $package = $this->db->get_where('mcu_packages', [
            'id' => $package_uuid
        ])->row_array();

        if (!$package) {
            throw new RuntimeException("Active MCU package not found (UUID: {$package_uuid}).");
        }

        if (!$this->is_truthy($package['is_active'])) {
            throw new RuntimeException("MCU package is inactive (UUID: {$package_uuid}).");
        }

        // 6. Validation: Check if any badge is already registered for this client on the scheduled date
        $badges = array_keys($seen_badges);
        $existing_registrations = $this->db
            ->select('employee_badge_id')
            ->from('mcu_registrations')
            ->where('corporate_client_id', $corporate_client_id)
            ->where('scheduled_date', $scheduled_date)
            ->where_in('employee_badge_id', $badges)
            ->get()
            ->result_array();

        if (!empty($existing_registrations)) {
            $existing_badges = array_column($existing_registrations, 'employee_badge_id');
            throw new RuntimeException("Employee badge(s) already registered for client ID {$corporate_client_id} on {$scheduled_date}: " . implode(', ', $existing_badges));
        }

        // Generate distinct batch reference code
        $batch_code = 'MCU-BATCH-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

        // Pricing calculations
        $total_package_price = (float)$package['base_price'];
        $discount_pct        = (float)$package['corporate_discount_pct'];
        $discount_amount     = round($total_package_price * ($discount_pct / 100), 2);
        $net_unit_price      = round($total_package_price - $discount_amount, 2);

        // 7. Begin Manual PostgreSQL 15 Transaction
        $orig_db_debug = $this->db->db_debug;
        $this->db->db_debug = FALSE;
        $this->db->trans_begin();

        $registered_participants = [];

        try {
            // Find dedicated MCU polyclinic and doctor
            $poly = $this->db->get_where('polyclinics', ['code' => 'INT'])->row_array()
                ?: $this->db->get('polyclinics')->row_array();
            $doctor = $this->db->get_where('doctors', ['id' => 1])->row_array()
                ?: $this->db->get('doctors')->row_array();

            if (!$poly || !$doctor) {
                throw new RuntimeException("Dedicated polyclinic or physician unavailable for MCU batch assignment.");
            }

            $poly_id   = $poly['id'];
            $doctor_id = (int)$doctor['id'];

            // Determine baseline queue number to guarantee unique sequential queues
            $queue_row = $this->db->query("
                SELECT COALESCE(MAX(queue_number), 0) AS max_q
                FROM visits
                WHERE polyclinic_id = ? AND doctor_id = ? AND visit_date = ?
            ", [$poly_id, $doctor_id, $scheduled_date])->row_array();
            $current_queue = (int)($queue_row['max_q'] ?? 0);

            foreach ($employees as $emp) {
                $badge_id = trim((string)($emp['badge_id'] ?? ''));
                $nik      = trim((string)($emp['nik'] ?? ''));
                $name     = trim((string)($emp['name'] ?? ''));
                $phone    = trim((string)($emp['phone'] ?? ''));

                // Step A: Find existing patient by NIK or Badge ID, or create new patient record
                $search_nik = ($nik !== '') ? $nik : substr('EMP-' . $badge_id, 0, 32);
                $patient = $this->db->get_where('patients', ['nik_or_national_id' => $search_nik])->row_array();

                if (!$patient) {
                    $mrn = $this->generate_unique_mrn();
                    $new_patient = [
                        'name'                  => $name,
                        'full_name'             => $name,
                        'medical_record_number' => $mrn,
                        'mrn'                   => substr($mrn, 0, 32),
                        'nik_or_national_id'    => $search_nik,
                        'phone_number'          => substr($phone, 0, 20),
                        'created_at'            => date('Y-m-d H:i:s'),
                        'updated_at'            => date('Y-m-d H:i:s')
                    ];
                    $this->db->insert('patients', $new_patient);
                    $patient_id = (int)$this->db->insert_id();
                } else {
                    $patient_id = (int)$patient['id'];
                }

                // Step B: Create Outpatient Encounter Visit with incremental queue
                $current_queue++;
                $visit_number = $this->generate_unique_visit_number();
                $visit_id     = $this->generate_uuid();

                $this->db->insert('visits', [
                    'id'             => $visit_id,
                    'visit_number'   => $visit_number,
                    'patient_id'     => $patient_id,
                    'polyclinic_id'  => $poly_id,
                    'doctor_id'      => $doctor_id,
                    'visit_date'     => $scheduled_date,
                    'queue_number'   => $current_queue,
                    'queue_status'   => 'WAITING',
                    'billing_status' => 'VERIFIED' // Corporate guaranteed
                ]);

                // Step C: Insert into mcu_registrations
                $mcu_reg_id = $this->generate_uuid();

                $this->db->insert('mcu_registrations', [
                    'id'                  => $mcu_reg_id,
                    'batch_code'          => $batch_code,
                    'corporate_client_id' => $corporate_client_id,
                    'mcu_package_id'      => $package_uuid,
                    'patient_id'          => $patient_id,
                    'visit_id'            => $visit_id,
                    'employee_badge_id'   => $badge_id,
                    'scheduled_date'      => $scheduled_date,
                    'attendance_status'   => 'SCHEDULED'
                ]);

                // Step D: Create Individual Billing Record
                $invoice_id  = $this->generate_uuid();
                $invoice_num = $this->generate_unique_invoice_number();

                $this->db->insert('billing_invoices', [
                    'id'                   => $invoice_id,
                    'invoice_number'       => $invoice_num,
                    'visit_id'             => $visit_id,
                    'patient_id'           => $patient_id,
                    'total_amount'         => $total_package_price,
                    'discount_amount'      => $discount_amount,
                    'tax_amount'           => 0.00,
                    'final_payable_amount' => $net_unit_price,
                    'payment_status'       => 'UNPAID'
                ]);

                $this->db->insert('billing_details', [
                    'id'               => $this->generate_uuid(),
                    'invoice_id'       => $invoice_id,
                    'service_category' => 'PROCEDURE',
                    'item_code'        => $package['package_code'],
                    'item_name'        => 'MCU Package: ' . $package['package_name'],
                    'quantity'         => 1,
                    'unit_price'       => $net_unit_price,
                    'subtotal'         => $net_unit_price
                ]);

                $registered_participants[] = [
                    'employee_badge_id' => $badge_id,
                    'name'              => $name,
                    'patient_id'        => $patient_id,
                    'visit_number'      => $visit_number,
                    'mcu_reg_id'        => $mcu_reg_id,
                    'invoice_number'    => $invoice_num
                ];
            }

            // Step E: Verify Transaction Integrity
            if ($this->db->trans_status() === FALSE) {
                $err = $this->db->error();
                $err_msg = !empty($err['message']) ? $err['message'] : "PostgreSQL transaction failed during bulk corporate registration.";
                throw new RuntimeException($err_msg);
            }

            // Commit atomic transaction
            $this->db->trans_commit();

            return [
                'status'                 => 'success',
                'batch_code'             => $batch_code,
                'corporate_client'       => $client['company_name'],
                'package_name'           => $package['package_name'],
                'scheduled_date'         => $scheduled_date,
                'total_registered'       => count($registered_participants),
                'total_contract_value'   => count($registered_participants) * $net_unit_price,
                'participants'           => $registered_participants
            ];

        } catch (Throwable $e) {
            $this->db->trans_rollback();
            throw $e;
        } finally {
            $this->db->db_debug = $orig_db_debug;
        }
    }

    /**
     * Retrieves all MCU registration records for a given batch code.
     *
     * @param string $batch_code
     * @return array
     */
    public function get_batch_registrations(string $batch_code): array {
        return $this->db
            ->select('r.*, p.name as patient_name, p.medical_record_number, v.visit_number, v.queue_number, bi.invoice_number, bi.final_payable_amount')
            ->from('mcu_registrations r')
            ->join('patients p', 'p.id = r.patient_id')
            ->join('visits v', 'v.id = r.visit_id')
            ->join('billing_invoices bi', 'bi.visit_id = v.id', 'left')
            ->where('r.batch_code', $batch_code)
            ->order_by('v.queue_number', 'ASC')
            ->get()
            ->result_array();
    }

    /**
     * Checks if a database boolean value is truthy.
     *
     * @param mixed $val
     * @return bool
     */
    protected function is_truthy(mixed $val): bool {
        return $val === true || $val === 't' || $val === 'true' || $val === 1 || $val === '1';
    }

    /**
     * Generates a Cryptographically Secure Version 4 UUID.
     *
     * @return string
     */
    protected function generate_uuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Generates a collision-free Medical Record Number (MRN).
     *
     * @return string
     */
    protected function generate_unique_mrn(): string {
        do {
            $mrn = 'RM-MCU-' . date('Ym') . '-' . str_pad((string)random_int(1000, 999999), 6, '0', STR_PAD_LEFT);
            $exists = $this->db->where('medical_record_number', $mrn)->or_where('mrn', $mrn)->get('patients')->row_array();
        } while (!empty($exists));

        return $mrn;
    }

    /**
     * Generates a collision-free visit number.
     *
     * @return string
     */
    protected function generate_unique_visit_number(): string {
        do {
            $visit_number = 'VIS-MCU-' . date('Ymd') . '-' . random_int(10000, 99999);
            $exists = $this->db->get_where('visits', ['visit_number' => $visit_number])->row_array();
        } while (!empty($exists));

        return $visit_number;
    }

    /**
     * Generates a collision-free invoice number.
     *
     * @return string
     */
    protected function generate_unique_invoice_number(): string {
        do {
            $invoice_num = 'INV-CORP-' . date('Ymd') . '-' . random_int(1000, 9999);
            $exists = $this->db->get_where('billing_invoices', ['invoice_number' => $invoice_num])->row_array();
        } while (!empty($exists));

        return $invoice_num;
    }
}
