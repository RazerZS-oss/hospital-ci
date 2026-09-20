<?php
/**
 * Test script for Ambulance_billing_service edge cases and transaction atomicity.
 */
defined('BASEPATH') OR define('BASEPATH', TRUE);

require_once __DIR__ . '/services/Ambulance_billing_service.php';

class Ambulance_billing_edge_cases_test {

    private CI_DB_query_builder $db;
    private Ambulance_billing_service $service;
    private array $results = [];

    public function __construct() {
        $ci =& get_instance();
        $this->db =& $ci->db;
        $this->service = new Ambulance_billing_service();
    }

    public function run_all(): bool {
        echo "\n=================================================================\n";
        echo "🚑 AMBULANCE BILLING SERVICE: EDGE CASE & ATOMICITY VERIFICATION\n";
        echo "=================================================================\n\n";

        $this->test_tariff_calculations();
        $this->test_edge_case_dispatch_not_found();
        $this->test_edge_case_invalid_distance();
        $this->test_edge_case_invalid_toll_fees();
        $this->test_edge_case_missing_patient_visit();
        $this->test_edge_case_cancelled_dispatch();
        $this->test_edge_case_already_billed();
        $this->test_edge_case_transaction_rollback();

        echo "\n-----------------------------------------------------------------\n";
        $failed = count(array_filter($this->results, fn($r) => !$r['passed']));
        $passed = count(array_filter($this->results, fn($r) => $r['passed']));
        echo "Summary: {$passed} PASSED, {$failed} FAILED out of " . count($this->results) . " test cases.\n";
        echo "=================================================================\n\n";

        return $failed === 0;
    }

    private function record(string $name, bool $passed, string $detail = ''): void {
        $this->results[] = ['name' => $name, 'passed' => $passed];
        $icon = $passed ? '✔' : '✖';
        echo "  {$icon} {$name}" . ($detail ? ": {$detail}" : "") . "\n";
    }

    private function test_tariff_calculations(): void {
        echo "[1/8] Testing Dynamic Tariff Calculations (Tiers & Surcharges)...\n";

        // Tier 0: <= 5km
        $fare0 = $this->service->calculate_fare(4.0, 'BASIC_TRANSPORT');
        $p0 = ($fare0['total_ambulance_charge'] === 350000.00 && $fare0['tiered_distance_fee'] === 0.00);
        $this->record("Tier 0 (<= 5km flagfall only)", $p0, "Rp " . number_format($fare0['total_ambulance_charge'], 0));

        // Tier 1: 5.1 - 20km (18.5km => 13.5km excess * 15000 = 202500)
        $fare1 = $this->service->calculate_fare(18.5, 'ADVANCED_LIFE_SUPPORT_ALS', 'CARDIAC_EMERGENCY', 25000.0);
        $p1 = ($fare1['tiered_distance_fee'] === 202500.00 &&
               $fare1['clinical_surcharge'] === 250000.00 &&
               $fare1['total_ambulance_charge'] === 827500.00);
        $this->record("Tier 1 with ALS & Cardiac Emergency", $p1, "Rp " . number_format($fare1['total_ambulance_charge'], 0));

        // Tier 2: > 20km (25km => 15km*15000 + 5km*20000 = 225000 + 100000 = 325000)
        $fare2 = $this->service->calculate_fare(25.0, 'BASIC_TRANSPORT', 'TRAUMA');
        $p2 = ($fare2['tiered_distance_fee'] === 325000.00 &&
               $fare2['clinical_surcharge'] === 150000.00 &&
               $fare2['total_ambulance_charge'] === (350000.00 + 325000.00 + 150000.00));
        $this->record("Tier 2 (> 20km) with Trauma Condition Surcharge", $p2, "Rp " . number_format($fare2['total_ambulance_charge'], 0));

        // Neonatal ICU
        $fare3 = $this->service->calculate_fare(5.0, 'NEONATAL_ICU');
        $p3 = ($fare3['clinical_surcharge'] === 350000.00 && $fare3['total_ambulance_charge'] === 700000.00);
        $this->record("Neonatal ICU Vehicle Surcharge", $p3, "Rp " . number_format($fare3['total_ambulance_charge'], 0));
    }

    private function test_edge_case_dispatch_not_found(): void {
        echo "\n[2/8] Testing Edge Case: Dispatch Log Not Found...\n";

        // Invalid UUID format
        $caught_format = false;
        try {
            $this->service->calculate_and_post_ambulance_billing('non-uuid-string');
        } catch (InvalidArgumentException $e) {
            $caught_format = true;
        }
        $this->record("Invalid UUID format triggers InvalidArgumentException", $caught_format);

        // Non-existent UUID
        $caught_missing = false;
        try {
            $this->service->calculate_and_post_ambulance_billing('00000000-0000-0000-0000-000000000000');
        } catch (RuntimeException $e) {
            $caught_missing = str_contains($e->getMessage(), 'not found');
        }
        $this->record("Non-existent dispatch UUID triggers RuntimeException", $caught_missing);
    }

    private function test_edge_case_invalid_distance(): void {
        echo "\n[3/8] Testing Edge Case: Invalid Distance (< 0)...\n";

        $caught_tiered = false;
        try {
            $this->service->calculate_tiered_distance_fee(-5.0);
        } catch (InvalidArgumentException $e) {
            $caught_tiered = str_contains($e->getMessage(), 'cannot be negative');
        }
        $this->record("Negative distance in calculate_tiered_distance_fee rejected", $caught_tiered);

        $caught_fare = false;
        try {
            $this->service->calculate_fare(-12.5, 'BASIC_TRANSPORT');
        } catch (InvalidArgumentException $e) {
            $caught_fare = str_contains($e->getMessage(), 'cannot be negative');
        }
        $this->record("Negative distance in calculate_fare rejected", $caught_fare);
    }

    private function test_edge_case_invalid_toll_fees(): void {
        echo "\n[4/8] Testing Edge Case: Invalid Toll Fees (< 0)...\n";

        $caught = false;
        try {
            $this->service->calculate_fare(10.0, 'BASIC_TRANSPORT', '', -5000.0);
        } catch (InvalidArgumentException $e) {
            $caught = str_contains($e->getMessage(), 'cannot be negative');
        }

        $this->record("Negative toll fees rejected with InvalidArgumentException", $caught);
    }

    private function test_edge_case_missing_patient_visit(): void {
        echo "\n[5/8] Testing Edge Case: Missing Patient/Visit Reference...\n";

        $amb = $this->db->get('ambulances')->row_array();
        $drv = $this->db->get('ambulance_drivers')->row_array();

        $disp_id = $this->db->query("SELECT gen_random_uuid() AS id")->row()->id;
        $disp_code = 'TEST-DISP-NO-VISIT-' . rand(1000, 9999);

        // Insert dispatch with NULL patient_id and NULL visit_id
        $this->db->insert('ambulance_dispatch_logs', [
            'id'                  => $disp_id,
            'dispatch_code'       => $disp_code,
            'ambulance_id'        => $amb['id'],
            'driver_id'           => $drv['id'],
            'paramedic_crew'      => json_encode([]),
            'patient_id'          => null,
            'visit_id'            => null,
            'incident_type'       => 'TRAUMA',
            'origin_address'      => 'Street Scene',
            'destination_address' => 'Hospital ER',
            'dispatched_at'       => date('Y-m-d H:i:s'),
            'distance_km'         => 12.00,
            'toll_fees'           => 0.00,
            'dispatch_status'     => 'COMPLETED'
        ]);

        $caught = false;
        try {
            $this->service->calculate_and_post_ambulance_billing($disp_id);
        } catch (InvalidArgumentException $e) {
            $caught = str_contains($e->getMessage(), 'Missing visit reference');
        }

        $this->db->delete('ambulance_dispatch_logs', ['id' => $disp_id]);

        $this->record("Missing visit_id without target invoice rejected with InvalidArgumentException", $caught);
    }

    private function test_edge_case_cancelled_dispatch(): void {
        echo "\n[6/8] Testing Edge Case: Cancelled Dispatch Log...\n";

        $amb = $this->db->get('ambulances')->row_array();
        $drv = $this->db->get('ambulance_drivers')->row_array();
        $visit = $this->db->get('visits')->row_array();

        $disp_id = $this->db->query("SELECT gen_random_uuid() AS id")->row()->id;
        $disp_code = 'TEST-DISP-CANCELLED-' . rand(1000, 9999);

        $this->db->insert('ambulance_dispatch_logs', [
            'id'                  => $disp_id,
            'dispatch_code'       => $disp_code,
            'ambulance_id'        => $amb['id'],
            'driver_id'           => $drv['id'],
            'paramedic_crew'      => json_encode([]),
            'patient_id'          => $visit['patient_id'],
            'visit_id'            => $visit['id'],
            'incident_type'       => 'ROUTINE',
            'origin_address'      => 'Origin',
            'destination_address' => 'Dest',
            'dispatched_at'       => date('Y-m-d H:i:s'),
            'distance_km'         => 5.00,
            'toll_fees'           => 0.00,
            'dispatch_status'     => 'CANCELLED'
        ]);

        $caught = false;
        try {
            $this->service->calculate_and_post_ambulance_billing($disp_id);
        } catch (RuntimeException $e) {
            $caught = str_contains($e->getMessage(), 'CANCELLED');
        }

        $this->db->delete('ambulance_dispatch_logs', ['id' => $disp_id]);

        $this->record("Billing a CANCELLED dispatch rejected with RuntimeException", $caught);
    }

    private function test_edge_case_already_billed(): void {
        echo "\n[7/8] Testing Edge Case: Already Billed Dispatch Log (Double-Billing Prevention)...\n";

        $amb = $this->db->get('ambulances')->row_array();
        $drv = $this->db->get('ambulance_drivers')->row_array();
        $visit = $this->db->get('visits')->row_array();

        $disp_id = $this->db->query("SELECT gen_random_uuid() AS id")->row()->id;
        $disp_code = 'TEST-DISP-DBL-' . rand(1000, 9999);

        $this->db->insert('ambulance_dispatch_logs', [
            'id'                  => $disp_id,
            'dispatch_code'       => $disp_code,
            'ambulance_id'        => $amb['id'],
            'driver_id'           => $drv['id'],
            'paramedic_crew'      => json_encode([]),
            'patient_id'          => $visit['patient_id'],
            'visit_id'            => $visit['id'],
            'incident_type'       => 'CARDIAC_EMERGENCY',
            'origin_address'      => 'Origin A',
            'destination_address' => 'Hospital ER',
            'dispatched_at'       => date('Y-m-d H:i:s'),
            'distance_km'         => 8.00,
            'toll_fees'           => 10000.00,
            'dispatch_status'     => 'COMPLETED'
        ]);

        // First billing: Must succeed
        $res1 = $this->service->calculate_and_post_ambulance_billing($disp_id);
        $first_success = ($res1['status'] === 'success');
        $this->record("Initial ambulance billing succeeds", $first_success);

        // Second billing: Must be blocked
        $caught_double = false;
        try {
            $this->service->calculate_and_post_ambulance_billing($disp_id);
        } catch (RuntimeException $e) {
            $caught_double = str_contains($e->getMessage(), 'already been billed');
        }
        $this->record("Second billing on same dispatch rejected (Double-Billing Prevention)", $caught_double);

        // Cleanup: remove line item and revert invoice amount
        $this->db->delete('billing_details', ['id' => $res1['billing_detail_id']]);
        $this->db->query("
            UPDATE billing_invoices
            SET total_amount = total_amount - ?, final_payable_amount = final_payable_amount - ?
            WHERE id = ?
        ", [$res1['breakdown']['total_ambulance_charge'], $res1['breakdown']['total_ambulance_charge'], $res1['invoice_id']]);
        $this->db->delete('ambulance_dispatch_logs', ['id' => $disp_id]);
    }

    private function test_edge_case_transaction_rollback(): void {
        echo "\n[8/8] Testing Edge Case: Transaction Rollback on Database Failure...\n";

        $amb = $this->db->get('ambulances')->row_array();
        $drv = $this->db->get('ambulance_drivers')->row_array();
        $visit = $this->db->get('visits')->row_array();

        $disp_id = $this->db->query("SELECT gen_random_uuid() AS id")->row()->id;
        $disp_code = 'TEST-DISP-ROLLBACK-' . rand(1000, 9999);

        $this->db->insert('ambulance_dispatch_logs', [
            'id'                  => $disp_id,
            'dispatch_code'       => $disp_code,
            'ambulance_id'        => $amb['id'],
            'driver_id'           => $drv['id'],
            'paramedic_crew'      => json_encode([]),
            'patient_id'          => $visit['patient_id'],
            'visit_id'            => $visit['id'],
            'incident_type'       => 'TRAUMA',
            'origin_address'      => 'Scene X',
            'destination_address' => 'Hospital ER',
            'dispatched_at'       => date('Y-m-d H:i:s'),
            'distance_km'         => 14.00,
            'toll_fees'           => 15000.00,
            'dispatch_status'     => 'COMPLETED'
        ]);

        // Non-existent target invoice UUID forces failure
        $fake_invoice_uuid = 'ffffffff-ffff-ffff-ffff-ffffffffffff';

        $caught_rollback = false;
        try {
            $this->service->calculate_and_post_ambulance_billing($disp_id, $fake_invoice_uuid);
        } catch (RuntimeException $e) {
            $caught_rollback = str_contains($e->getMessage(), 'Target billing invoice not found');
        }

        // Verify that NO line item exists in billing_details
        $check_detail = $this->db->get_where('billing_details', [
            'item_code' => 'AMB-' . $disp_code
        ])->row_array();

        $clean_rollback = ($check_detail === null);

        $this->db->delete('ambulance_dispatch_logs', ['id' => $disp_id]);

        $this->record("Database failure triggers exception", $caught_rollback);
        $this->record("Transaction atomicity confirmed: No partial or orphaned line items written", $clean_rollback);
    }
}
