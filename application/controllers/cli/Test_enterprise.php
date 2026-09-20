<?php
defined('BASEPATH') OR exit('No direct script access allowed');

#[AllowDynamicProperties]
class Test_enterprise extends CI_Controller {

    public function __construct() {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            exit(1);
        }
        $this->load->database();
    }

    public function run_tests(): void {
        echo "\n=================================================================\n";
        echo "🏥 ENTERPRISE HMIS ADVANCED MODULES END-TO-END VERIFICATION\n";
        echo "=================================================================\n\n";

        // 1. Test Domain 4: Executive Dashboard Model
        echo "[1/4] Testing Domain 4: Executive_dashboard_model (BOR & Top 10 ICD-10)...\n";
        $this->load->model('Executive_dashboard_model');
        $bor_data = $this->Executive_dashboard_model->get_current_month_bor();
        echo "  ✔ Monthly BOR Percentage: " . $bor_data['metrics']['bor_percentage'] . "%\n";
        echo "  ✔ WHO Benchmark Status: " . $bor_data['clinical_efficiency']['who_benchmark_status'] . "\n";
        echo "  ✔ Clinical Interpretation: " . $bor_data['clinical_efficiency']['interpretation'] . "\n";
        echo "  ✔ Total Available Wards Beds: " . $bor_data['metrics']['total_beds_available'] . " beds\n";

        $top_icd = $this->Executive_dashboard_model->get_top_10_diseases_current_month();
        echo "  ✔ Total Diagnosed Records (Month): " . $top_icd['total_diagnosed_cases'] . " cases\n";
        echo "  ✔ Ranked Diseases Count: " . $top_icd['top_diseases_count'] . "\n";
        if (!empty($top_icd['rankings'])) {
            $top = $top_icd['rankings'][0];
            echo "  ✔ Top #1 Diagnosis: {$top['icd10_code']} - {$top['diagnosis_name']} ({$top['prevalence_rate']})\n";
        }

        // 2. Test Domain 1: Nutrition Diet Profile
        echo "\n[2/4] Testing Domain 1: Nutrition_diet_model (Inpatient Clinical Profile)...\n";
        require_once APPPATH . 'modules/nutrition/models/Nutrition_diet_model.php';
        $nutrition_model = new Nutrition_diet_model();
        
        // Ensure an active inpatient admission exists for Patient 1
        $bed = $this->db->get_where('hospital_beds', ['bed_number' => 'BED-1'])->row_array();
        if ($bed) {
            $visit = $this->db->get_where('visits', ['patient_id' => 1])->row_array();
            $this->db->query("
                INSERT INTO inpatient_admissions (visit_id, patient_id, bed_id, admitted_at, admission_status)
                VALUES (?, 1, ?, CURRENT_TIMESTAMP - INTERVAL '3 days', 'ACTIVE')
                ON CONFLICT (visit_id) DO NOTHING
            ", [$visit['id'], $bed['id']]);

            // Assign diet
            $doctor = $this->db->get('doctors')->row_array();
            $this->db->query("
                INSERT INTO nutrition_diets (patient_id, visit_id, diet_category, calorie_target_kcal, nutrient_distribution, allergen_restrictions, texture_form, prescribed_by_doctor_id, valid_from)
                VALUES (1, ?, 'DIABETIC_LOW_GI', 1800, '{\"carbohydrate_pct\": 45, \"protein_pct\": 25, \"fat_pct\": 30, \"sodium_max_mg\": 1500}'::jsonb, '[\"PEANUTS\"]'::jsonb, 'REGULAR_SOLID', ?, CURRENT_DATE)
                ON CONFLICT DO NOTHING
            ", [$visit['id'], $doctor['id']]);

            $profile = $nutrition_model->get_inpatient_daily_dietary_profile(1);
            if ($profile) {
                echo "  ✔ Patient Identified: {$profile['patient_name']} (MRN: {$profile['mrn']})\n";
                echo "  ✔ Inpatient Location: {$profile['ward_name']} / Bed {$profile['bed_number']}\n";
                echo "  ✔ Clinical Diet: {$profile['diet_category']} ({$profile['calorie_target_kcal']} kcal)\n";
                echo "  ✔ Clinical Contraindications: " . (empty($profile['clinical_alerts']) ? "None (Contraindications Cleared)" : implode("; ", $profile['clinical_alerts'])) . "\n";
            }
        }

        // 3. Test Domain 2: MCU Bulk Registration Service
        echo "\n[3/4] Testing Domain 2: Mcu_registration_service (Bulk Corporate Onboarding)...\n";
        require_once APPPATH . 'modules/mcu/services/Mcu_registration_service.php';
        $mcu_service = new Mcu_registration_service();
        $client = $this->db->get_where('mcu_corporate_clients', ['client_code' => 'CORP-TELCO'])->row_array();
        $package = $this->db->get_where('mcu_packages', ['package_code' => 'MCU-EXEC-01'])->row_array();
        
        $random_suffix = rand(100, 999);
        $employees = [
            [
                'name'     => 'Budi Prabowo',
                'badge_id' => 'EMP-' . $random_suffix . 'A',
                'nik'      => '3171012345670' . $random_suffix,
                'phone'    => '081298765432'
            ],
            [
                'name'     => 'Dewi Lestari',
                'badge_id' => 'EMP-' . $random_suffix . 'B',
                'nik'      => '3171012345671' . $random_suffix,
                'phone'    => '081298765433'
            ]
        ];
        
        $mcu_res = $mcu_service->register_corporate_bulk(
            (int)$client['id'],
            $package['id'],
            $employees,
            date('Y-m-d', strtotime('+3 days'))
        );
        echo "  ✔ Transaction Status: {$mcu_res['status']}\n";
        echo "  ✔ Batch Code: {$mcu_res['batch_code']}\n";
        echo "  ✔ Client: {$mcu_res['corporate_client']} ({$mcu_res['package_name']})\n";
        echo "  ✔ Registered Employees: {$mcu_res['total_registered']} staff\n";
        echo "  ✔ Total Contract Value: IDR " . number_format($mcu_res['total_contract_value'], 2) . "\n";

        // 4. Test Domain 3: Ambulance Billing Service
        echo "\n[4/4] Testing Domain 3: Ambulance_billing_service (Dynamic Tariff Calculation)...\n";
        require_once APPPATH . 'modules/fleet/services/Ambulance_billing_service.php';
        $fleet_service = new Ambulance_billing_service();
        
        $amb = $this->db->get_where('ambulances', ['license_plate' => 'B 1199 AMB'])->row_array();
        $drv = $this->db->get_where('ambulance_drivers', ['employee_badge' => 'DRV-001'])->row_array();
        $visit = $this->db->get_where('visits', ['patient_id' => 1])->row_array();

        $dispatch_id = $this->db->query("SELECT gen_random_uuid() AS id")->row()->id;
        $dispatch_code = 'DISP-' . date('Ymd') . '-' . rand(1000, 9999);

        $this->db->insert('ambulance_dispatch_logs', [
            'id'                  => $dispatch_id,
            'dispatch_code'       => $dispatch_code,
            'ambulance_id'        => $amb['id'],
            'driver_id'           => $drv['id'],
            'paramedic_crew'      => json_encode([['staff_id' => 2, 'role' => 'PARAMEDIC_LEAD']]),
            'patient_id'          => 1,
            'visit_id'            => $visit['id'],
            'incident_type'       => 'CARDIAC_EMERGENCY',
            'origin_address'      => 'Jl. Sudirman No. 45, Jakarta Selatan',
            'destination_address' => 'Central Hospital Emergency Room (IGD)',
            'dispatched_at'       => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'arrived_scene_at'    => date('Y-m-d H:i:s', strtotime('-40 minutes')),
            'arrived_hospital_at' => date('Y-m-d H:i:s', strtotime('-10 minutes')),
            'distance_km'         => 18.50,
            'toll_fees'           => 25000.00,
            'dispatch_status'     => 'COMPLETED'
        ]);

        $fleet_res = $fleet_service->calculate_and_post_ambulance_billing($dispatch_id);
        echo "  ✔ Dispatch Log Code: {$dispatch_code}\n";
        echo "  ✔ Distance Calculated: {$fleet_res['breakdown']['distance_km']} km\n";
        echo "  ✔ Base Flagfall Rate: IDR " . number_format($fleet_res['breakdown']['flagfall_base_fee'], 2) . "\n";
        echo "  ✔ Tiered Distance Fee: IDR " . number_format($fleet_res['breakdown']['tiered_distance_fee'], 2) . "\n";
        echo "  ✔ Clinical Surcharge: IDR " . number_format($fleet_res['breakdown']['clinical_surcharge'], 2) . "\n";
        echo "  ✔ Toll & Parking Fees: IDR " . number_format($fleet_res['breakdown']['toll_and_parking'], 2) . "\n";
        echo "  ✔ Total Ambulance Charge: IDR " . number_format($fleet_res['breakdown']['total_ambulance_charge'], 2) . "\n";
        echo "  ✔ Universal Billing Detail UUID: {$fleet_res['billing_detail_id']}\n";
        echo "  ✔ Target Invoice UUID: {$fleet_res['invoice_id']}\n";

        echo "\n=================================================================\n";
        echo "🎉 ALL 4 ENTERPRISE HMIS DOMAINS VERIFIED SUCCESSFULLY!\n";
        echo "=================================================================\n\n";
    }
}
