<?php
/**
 * Enterprise HMIS: Nutrition & Clinical Dietetics Edge Cases & Robustness Test Suite
 *
 * Verifies all edge cases:
 * 1. Missing or invalid admissions (non-existent patient, non-positive ID, discharged patient)
 * 2. Inactive or expired diet profiles
 * 3. Empty or malformed allergen restrictions
 * 4. Invalid or corrupted JSON in PostgreSQL JSONB fields
 * 5. Clinical contraindication evaluations (Diabetes E10/E11, Hypertension I10, CKD N18)
 * 6. Diet prescription validation (calorie bounds 500-5000, texture enums, macro boundaries)
 * 7. Diet prescription lifecycle & superseding active orders
 * 8. Meal delivery status updates (breakfast, lunch, dinner)
 * 9. Ward-level daily dietary summary retrieval
 */
defined('BASEPATH') OR define('BASEPATH', TRUE);

require_once __DIR__ . '/models/Nutrition_diet_model.php';

class Nutrition_diet_edge_cases_test {

    private CI_DB_query_builder $db;
    private Nutrition_diet_model $model;
    private array $results = [];

    public function __construct() {
        $ci =& get_instance();
        $this->db =& $ci->db;
        $this->model = new Nutrition_diet_model();
    }

    public function run_all(): bool {
        echo "\n=================================================================\n";
        echo "🥗 NUTRITION & CLINICAL DIETETICS: EDGE CASES & DOMAIN VERIFICATION\n";
        echo "=================================================================\n\n";

        $this->test_edge_case_missing_and_invalid_admission();
        $this->test_edge_case_inactive_and_expired_diets();
        $this->test_edge_case_empty_and_corrupt_allergens();
        $this->test_edge_case_invalid_json_robustness();
        $this->test_clinical_contraindications_evaluator();
        $this->test_diet_prescription_validation();
        $this->test_prescribe_diet_lifecycle();
        $this->test_meal_status_updates();
        $this->test_ward_active_diets_summary();

        echo "\n-----------------------------------------------------------------\n";
        $failed = count(array_filter($this->results, fn($r) => !$r['passed']));
        $passed = count(array_filter($this->results, fn($r) => $r['passed']));
        echo "Summary: {$passed} PASSED, {$failed} FAILED out of " . count($this->results) . " test assertions.\n";
        echo "=================================================================\n\n";

        return $failed === 0;
    }

    private function record(string $name, bool $passed, string $detail = ''): void {
        $this->results[] = ['name' => $name, 'passed' => $passed];
        $icon = $passed ? '✔' : '✖';
        echo "  {$icon} {$name}" . ($detail ? ": {$detail}" : "") . "\n";
    }

    /**
     * Edge Case 1: Missing and Invalid Admissions
     */
    private function test_edge_case_missing_and_invalid_admission(): void {
        echo "[1/9] Testing Edge Case: Missing & Invalid Inpatient Admissions...\n";

        // Non-positive patient IDs
        $resNeg = $this->model->get_inpatient_daily_dietary_profile(-1);
        $this->record("Negative patient ID (-1) returns null", $resNeg === null);

        $resZero = $this->model->get_inpatient_daily_dietary_profile(0);
        $this->record("Zero patient ID (0) returns null", $resZero === null);

        // Non-existent patient ID
        $resNonExistent = $this->model->get_inpatient_daily_dietary_profile(999999);
        $this->record("Non-existent patient ID (999999) returns null", $resNonExistent === null);

        // Patient exists but has only DISCHARGED admission
        $patient = $this->db->get_where('patients', ['id !=' => 1])->row_array();
        if ($patient) {
            $pId = (int)$patient['id'];
            $res = $this->model->get_inpatient_daily_dietary_profile($pId);
            $hasActive = $this->db->where('patient_id', $pId)->where('admission_status', 'ACTIVE')->count_all_results('inpatient_admissions') > 0;
            if (!$hasActive) {
                $this->record("Non-admitted patient ({$pId}) returns null", $res === null);
            }
        }
    }

    /**
     * Edge Case 2: Inactive & Expired Diet Profiles
     */
    private function test_edge_case_inactive_and_expired_diets(): void {
        echo "\n[2/9] Testing Edge Case: Inactive & Expired Diet Profiles...\n";

        // Create temporary admission with NO active diet
        $visitRow = $this->db->query("SELECT gen_random_uuid() AS id")->row();
        $visitId = $visitRow->id;
        $bed = $this->db->get_where('hospital_beds', ['is_occupied' => false])->row_array();
        if (!$bed) {
            $bed = $this->db->get('hospital_beds')->row_array();
        }

        // Use patient 2
        $testPatientId = 2;
        // Clean up previous test admission for patient 2
        $this->db->where('patient_id', $testPatientId)->delete('inpatient_admissions');

        // Insert fresh visit
        $this->db->insert('visits', [
            'id'           => $visitId,
            'visit_number' => 'V-TEST-' . rand(10000, 99999),
            'patient_id'   => $testPatientId,
            'polyclinic_id'=> $this->db->get('polyclinics')->row()->id,
            'doctor_id'    => 1,
            'visit_date'   => date('Y-m-d'),
            'queue_number' => rand(100, 999),
            'queue_status' => 'COMPLETED'
        ]);

        // Insert active admission
        $this->db->insert('inpatient_admissions', [
            'visit_id'         => $visitId,
            'patient_id'       => $testPatientId,
            'bed_id'           => $bed['id'],
            'admitted_at'      => date('Y-m-d H:i:sP'),
            'admission_status' => 'ACTIVE'
        ]);

        // Profile without any diet created yet
        $profileNoDiet = $this->model->get_inpatient_daily_dietary_profile($testPatientId);
        $p1 = ($profileNoDiet !== null &&
               $profileNoDiet['has_active_diet'] === false &&
               $profileNoDiet['diet_id'] === null &&
               $profileNoDiet['diet_status'] === 'NO_ACTIVE_DIET' &&
               is_array($profileNoDiet['nutrient_distribution']) &&
               is_array($profileNoDiet['allergen_restrictions']));
        $this->record("Admission with no diet returns profile with NO_ACTIVE_DIET status", $p1);

        // Insert an INACTIVE diet (is_active = FALSE)
        $doc = $this->db->get('doctors')->row_array();
        $inactUuid = $this->db->query("SELECT gen_random_uuid() AS id")->row()->id;
        $this->db->insert('nutrition_diets', [
            'id'                      => $inactUuid,
            'patient_id'              => $testPatientId,
            'visit_id'                => $visitId,
            'diet_category'           => 'REGULAR_NORMAL',
            'calorie_target_kcal'     => 2000,
            'nutrient_distribution'   => '{"carbohydrate_pct": 50, "protein_pct": 20, "fat_pct": 30, "sodium_max_mg": 2000}',
            'allergen_restrictions'   => '[]',
            'texture_form'            => 'REGULAR_SOLID',
            'prescribed_by_doctor_id' => $doc['id'],
            'is_active'               => FALSE,
            'valid_from'              => date('Y-m-d', strtotime('-5 days')),
            'valid_to'                => date('Y-m-d', strtotime('-1 days'))
        ]);

        $profileInactive = $this->model->get_inpatient_daily_dietary_profile($testPatientId);
        $p2 = ($profileInactive !== null &&
               $profileInactive['has_active_diet'] === false &&
               $profileInactive['diet_id'] === null);
        $this->record("Inactive diet (is_active=FALSE) is correctly excluded", $p2);

        // Insert an EXPIRED diet (valid_to < CURRENT_DATE but is_active = TRUE)
        $expUuid = $this->db->query("SELECT gen_random_uuid() AS id")->row()->id;
        $this->db->insert('nutrition_diets', [
            'id'                      => $expUuid,
            'patient_id'              => $testPatientId,
            'visit_id'                => $visitId,
            'diet_category'           => 'SOFT_GASTRIC',
            'calorie_target_kcal'     => 1700,
            'nutrient_distribution'   => '{"carbohydrate_pct": 50, "protein_pct": 20, "fat_pct": 30, "sodium_max_mg": 2000}',
            'allergen_restrictions'   => '[]',
            'texture_form'            => 'SOFT_FOOD',
            'prescribed_by_doctor_id' => $doc['id'],
            'is_active'               => TRUE,
            'valid_from'              => date('Y-m-d', strtotime('-10 days')),
            'valid_to'                => date('Y-m-d', strtotime('-2 days'))
        ]);

        $profileExpired = $this->model->get_inpatient_daily_dietary_profile($testPatientId);
        $p3 = ($profileExpired !== null &&
               $profileExpired['has_active_diet'] === false &&
               $profileExpired['diet_id'] === null);
        $this->record("Expired diet (valid_to < CURRENT_DATE) is correctly excluded", $p3);

        // Clean up test records
        $this->db->where('patient_id', $testPatientId)->delete('nutrition_diets');
        $this->db->where('patient_id', $testPatientId)->delete('inpatient_admissions');
    }

    /**
     * Edge Case 3: Empty and Malformed Allergen Restrictions
     */
    private function test_edge_case_empty_and_corrupt_allergens(): void {
        echo "\n[3/9] Testing Edge Case: Empty & Malformed Allergen Restrictions...\n";

        // Test safe_json_decode with various inputs
        $d1 = $this->model->safe_json_decode('[]', []);
        $this->record("safe_json_decode('[]') returns []", $d1 === []);

        $d2 = $this->model->safe_json_decode('', []);
        $this->record("safe_json_decode('') returns []", $d2 === []);

        $d3 = $this->model->safe_json_decode(null, []);
        $this->record("safe_json_decode(null) returns []", $d3 === []);

        $d4 = $this->model->safe_json_decode('null', []);
        $this->record("safe_json_decode('null') returns []", $d4 === []);

        $d5 = $this->model->safe_json_decode('["PEANUTS", "SEAFOOD"]', []);
        $this->record("safe_json_decode valid JSON array returns array", $d5 === ['PEANUTS', 'SEAFOOD']);

        // Test sanitize_allergen_list
        $s1 = $this->model->sanitize_allergen_list(['peanuts', '  SEAFOOD ', '', null, 123, 'peanuts']);
        $this->record("sanitize_allergen_list cleans, trims, uppercases, and deduplicates", $s1 === ['PEANUTS', 'SEAFOOD']);

        $s2 = $this->model->sanitize_allergen_list([]);
        $this->record("sanitize_allergen_list([]) returns []", $s2 === []);
    }

    /**
     * Edge Case 4: Invalid and Corrupted JSON Handling
     */
    private function test_edge_case_invalid_json_robustness(): void {
        echo "\n[4/9] Testing Edge Case: Corrupted & Malformed JSON Protection...\n";

        // Corrupted JSON strings
        $corrupt1 = "{malformed_json: true,";
        $r1 = $this->model->safe_json_decode($corrupt1, ['default' => 1]);
        $this->record("Malformed JSON does not throw, returns default", $r1 === ['default' => 1]);

        // Non-object non-array JSON (e.g. primitive '123' or 'true')
        $r2 = $this->model->safe_json_decode('true', []);
        $this->record("JSON boolean literal returns default array", $r2 === []);

        $r3 = $this->model->safe_json_decode('12345', []);
        $this->record("JSON integer literal returns default array", $r3 === []);

        // Array already passed in
        $r4 = $this->model->safe_json_decode(['a' => 1], []);
        $this->record("Direct array input is preserved intact", $r4 === ['a' => 1]);
    }

    /**
     * Clinical Contraindications Evaluator Tests
     */
    private function test_clinical_contraindications_evaluator(): void {
        echo "\n[5/9] Testing Clinical Contraindications Evaluator Rules...\n";

        // Diabetic patient with DIABETIC_LOW_GI and carbs <= 55% -> PASS
        $a1 = $this->model->evaluate_dietary_contraindications('E11.9', 'DIABETIC_LOW_GI', ['carbohydrate_pct' => 45]);
        $this->record("Diabetic with DIABETIC_LOW_GI (45% carbs): Cleared", empty($a1));

        // Diabetic patient with REGULAR_NORMAL -> CRITICAL alert
        $a2 = $this->model->evaluate_dietary_contraindications('E11.9', 'REGULAR_NORMAL', ['carbohydrate_pct' => 45]);
        $hasCrit = !empty(array_filter($a2, fn($m) => str_contains($m, 'CRITICAL') && str_contains($m, 'Low-GI meal recommended')));
        $this->record("Diabetic with REGULAR_NORMAL triggers CRITICAL alert", $hasCrit);

        // Diabetic patient with excessive carbs (> 55%) -> WARNING alert
        $a3 = $this->model->evaluate_dietary_contraindications('E10.1', 'DIABETIC_LOW_GI', ['carbohydrate_pct' => 65]);
        $hasCarbWarn = !empty(array_filter($a3, fn($m) => str_contains($m, 'Carbohydrate intake exceeds recommended diabetic threshold')));
        $this->record("Diabetic with >55% carbs triggers WARNING alert", $hasCarbWarn);

        // Hypertensive patient (I10) with sodium <= 2000mg -> PASS
        $a4 = $this->model->evaluate_dietary_contraindications('I10', 'LOW_SODIUM_DASH', ['sodium_max_mg' => 1500]);
        $this->record("Hypertensive with sodium 1500mg: Cleared", empty($a4));

        // Hypertensive patient (I10) with sodium > 2000mg -> WARNING alert
        $a5 = $this->model->evaluate_dietary_contraindications('I10', 'REGULAR_NORMAL', ['sodium_max_mg' => 2800]);
        $hasSodWarn = !empty(array_filter($a5, fn($m) => str_contains($m, 'Sodium restriction violated for Hypertensive patient')));
        $this->record("Hypertensive with >2000mg sodium triggers WARNING alert", $hasSodWarn);

        // Chronic Kidney Disease (N18) with high protein (> 15%) -> WARNING alert
        $a6 = $this->model->evaluate_dietary_contraindications('N18.3', 'REGULAR_NORMAL', ['protein_pct' => 25, 'sodium_max_mg' => 1500]);
        $hasProteinWarn = !empty(array_filter($a6, fn($m) => str_contains($m, 'Protein intake exceeds recommended threshold for renal patient')));
        $this->record("Renal CKD with >15% protein triggers WARNING alert", $hasProteinWarn);

        // Missing diet prescription for diabetic patient -> CRITICAL alert
        $a7 = $this->model->evaluate_dietary_contraindications('E11.9', '', []);
        $hasNoDietCrit = !empty(array_filter($a7, fn($m) => str_contains($m, 'no active diet prescription is assigned')));
        $this->record("Diabetic with empty diet category triggers unassigned CRITICAL alert", $hasNoDietCrit);
    }

    /**
     * Diet Prescription Validation Tests
     */
    private function test_diet_prescription_validation(): void {
        echo "\n[6/9] Testing Diet Prescription Validation Business Rules...\n";

        // Valid payload
        $validData = [
            'patient_id'              => 1,
            'visit_id'                => 'fc298a43-bfe7-4437-b544-7cc0343837a6',
            'diet_category'           => 'DIABETIC_LOW_GI',
            'calorie_target_kcal'     => 1800,
            'texture_form'            => 'REGULAR_SOLID',
            'nutrient_distribution'   => ['carbohydrate_pct' => 45, 'protein_pct' => 25, 'fat_pct' => 30, 'sodium_max_mg' => 1500],
            'allergen_restrictions'   => ['PEANUTS'],
            'prescribed_by_doctor_id' => 1,
            'valid_from'              => date('Y-m-d')
        ];
        $v1 = $this->model->validate_diet_prescription($validData);
        $this->record("Valid diet prescription passes validation", $v1['valid'] && empty($v1['errors']));

        // Calorie target below 500 kcal
        $lowCal = $validData;
        $lowCal['calorie_target_kcal'] = 300;
        $v2 = $this->model->validate_diet_prescription($lowCal);
        $this->record("Calorie target < 500 kcal is rejected", !$v2['valid']);

        // Calorie target above 5000 kcal
        $highCal = $validData;
        $highCal['calorie_target_kcal'] = 6000;
        $v3 = $this->model->validate_diet_prescription($highCal);
        $this->record("Calorie target > 5000 kcal is rejected", !$v3['valid']);

        // Invalid texture form enum
        $badTexture = $validData;
        $badTexture['texture_form'] = 'INVALID_TEXTURE_CRUNCHY';
        $v4 = $this->model->validate_diet_prescription($badTexture);
        $this->record("Invalid texture form enum is rejected", !$v4['valid']);

        // Invalid macro percentage (> 100%)
        $badMacro = $validData;
        $badMacro['nutrient_distribution'] = ['carbohydrate_pct' => 120];
        $v5 = $this->model->validate_diet_prescription($badMacro);
        $this->record("Carbohydrate percentage > 100% is rejected", !$v5['valid']);
    }

    /**
     * Diet Prescription Lifecycle & Superseding
     */
    private function test_prescribe_diet_lifecycle(): void {
        echo "\n[7/9] Testing Prescribe Diet Lifecycle & Auto-Superseding...\n";

        $visit = $this->db->get_where('visits', ['patient_id' => 1])->row_array();
        $doctor = $this->db->get('doctors')->row_array();

        // Prescribe Diet #1
        $res1 = $this->model->prescribe_diet([
            'patient_id'              => 1,
            'visit_id'                => $visit['id'],
            'diet_category'           => 'POST_OP_LIQUID',
            'calorie_target_kcal'     => 1200,
            'texture_form'            => 'CLEAR_LIQUID',
            'nutrient_distribution'   => ['carbohydrate_pct' => 60, 'protein_pct' => 10, 'fat_pct' => 30, 'sodium_max_mg' => 1000],
            'allergen_restrictions'   => ['SHELLFISH'],
            'prescribed_by_doctor_id' => $doctor['id'],
            'valid_from'              => date('Y-m-d')
        ]);
        $this->record("Prescribe Diet #1 (CLEAR_LIQUID) succeeds", $res1['success'] && !empty($res1['diet_id']));

        // Prescribe Diet #2 (should supersede Diet #1)
        $res2 = $this->model->prescribe_diet([
            'patient_id'              => 1,
            'visit_id'                => $visit['id'],
            'diet_category'           => 'DIABETIC_LOW_GI',
            'calorie_target_kcal'     => 1800,
            'texture_form'            => 'REGULAR_SOLID',
            'nutrient_distribution'   => ['carbohydrate_pct' => 45, 'protein_pct' => 25, 'fat_pct' => 30, 'sodium_max_mg' => 1500],
            'allergen_restrictions'   => ['PEANUTS'],
            'prescribed_by_doctor_id' => $doctor['id'],
            'valid_from'              => date('Y-m-d')
        ]);
        $this->record("Prescribe Diet #2 (DIABETIC_LOW_GI) succeeds", $res2['success'] && !empty($res2['diet_id']));

        // Check that Diet #1 is now inactive
        $d1Row = $this->db->get_where('nutrition_diets', ['id' => $res1['diet_id']])->row_array();
        $this->record("Diet #1 was automatically superseded (is_active = FALSE)", !$this->model->is_truthy_bool($d1Row['is_active']));

        // Check that Diet #2 is currently active
        $d2Row = $this->db->get_where('nutrition_diets', ['id' => $res2['diet_id']])->row_array();
        $this->record("Diet #2 is currently active (is_active = TRUE)", $this->model->is_truthy_bool($d2Row['is_active']));

        // Verify has_active_diet helper
        $hasActive = $this->model->has_active_diet(1);
        $this->record("has_active_diet(1) returns true", $hasActive);
    }

    /**
     * Meal Delivery Status Updates
     */
    private function test_meal_status_updates(): void {
        echo "\n[8/9] Testing Inpatient Meal Delivery Status Transitions...\n";

        // Get active diet for patient 1
        $diet = $this->db->where('patient_id', 1)->where('is_active', true)->order_by('created_at', 'DESC')->get('nutrition_diets')->row_array();
        $dietId = $diet['id'];

        // Update breakfast to PREPARED
        $u1 = $this->model->update_meal_status($dietId, 'breakfast', 'PREPARED');
        $this->record("Update breakfast status to PREPARED", $u1);

        // Update lunch to DELIVERED
        $u2 = $this->model->update_meal_status($dietId, 'lunch', 'DELIVERED');
        $this->record("Update lunch status to DELIVERED", $u2);

        // Update dinner to CONSUMED
        $u3 = $this->model->update_meal_status($dietId, 'dinner', 'CONSUMED');
        $this->record("Update dinner status to CONSUMED", $u3);

        // Invalid meal slot
        $u4 = $this->model->update_meal_status($dietId, 'midnight_snack', 'CONSUMED');
        $this->record("Invalid meal slot is rejected", !$u4);

        // Invalid status
        $u5 = $this->model->update_meal_status($dietId, 'breakfast', 'INVALID_STATUS');
        $this->record("Invalid delivery status is rejected", !$u5);

        // Reset all to PENDING
        $this->model->update_meal_status($dietId, 'breakfast', 'PENDING');
        $this->model->update_meal_status($dietId, 'lunch', 'PENDING');
        $this->model->update_meal_status($dietId, 'dinner', 'PENDING');
    }

    /**
     * Ward-Level Dietary Summary
     */
    private function test_ward_active_diets_summary(): void {
        echo "\n[9/9] Testing Ward-Level Daily Dietary Summary (Batch Kitchen Prep)...\n";

        // Find ward with active inpatients (e.g. Ward 2 / Pavilion Wijaya Kusuma)
        $activeAdm = $this->db->query("
            SELECT b.ward_id, w.ward_name
            FROM inpatient_admissions ia
            JOIN hospital_beds b ON b.id = ia.bed_id
            JOIN hospital_wards w ON w.id = b.ward_id
            WHERE ia.admission_status = 'ACTIVE'
            LIMIT 1
        ")->row_array();
        $wardId = $activeAdm ? (int)$activeAdm['ward_id'] : 2;

        $wardDiets = $this->model->get_active_diets_by_ward($wardId);
        $this->record("Ward {$wardId} ({$activeAdm['ward_name']}) dietary roster retrieved", is_array($wardDiets) && count($wardDiets) > 0);

        if (!empty($wardDiets)) {
            $first = $wardDiets[0];
            $valid = isset($first['patient_name'], $first['bed_number'], $first['diet_category'], $first['calorie_target_kcal'], $first['nutrient_distribution'], $first['allergen_restrictions']);
            $this->record("Ward roster item contains all necessary meal distribution attributes", $valid);
        }

        // Invalid ward ID
        $emptyWard = $this->model->get_active_diets_by_ward(99999);
        $this->record("Non-existent ward returns empty array", $emptyWard === []);
    }
}
