<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Enterprise Hospital Management Information System (HMIS)
 * Operational & Clinical Support: Inpatient Nutrition & Dietetics Model
 *
 * Joins active inpatient admissions, bed tracking, and EMR diagnoses
 * to filter specific dietary requirements, allergen contraindications,
 * and nutrient distributions.
 *
 * Compatible with PHP 8.4-FPM and PostgreSQL 15.
 */
#[AllowDynamicProperties]
class Nutrition_diet_model extends MY_Model {

    protected string $table = 'nutrition_diets';
    protected string $primary_key = 'id';

    /**
     * Allowed texture forms per database check constraint:
     * CHECK (texture_form IN ('REGULAR_SOLID', 'SOFT_FOOD', 'PUREED', 'FULL_LIQUID', 'CLEAR_LIQUID', 'NPO_FASTING'))
     */
    public const ALLOWED_TEXTURE_FORMS = [
        'REGULAR_SOLID',
        'SOFT_FOOD',
        'PUREED',
        'FULL_LIQUID',
        'CLEAR_LIQUID',
        'NPO_FASTING'
    ];

    /**
     * Allowed meal slots for daily tracking.
     */
    public const ALLOWED_MEAL_SLOTS = [
        'breakfast',
        'lunch',
        'dinner'
    ];

    /**
     * Allowed meal delivery statuses.
     */
    public const ALLOWED_MEAL_STATUSES = [
        'PENDING',
        'PREPARED',
        'DELIVERED',
        'CONSUMED',
        'REFUSED',
        'HELD'
    ];

    public function __construct() {
        parent::__construct();
    }

    /**
     * Fetches current daily dietary requirements for an active inpatient,
     * cross-referenced with their active EMR diagnosis (ICD-10) and vital signs.
     *
     * @param int $patient_id Target patient ID
     * @return array|null Complete clinical nutrition profile or null if not an active inpatient
     */
    public function get_inpatient_daily_dietary_profile(int $patient_id): ?array {
        if ($patient_id <= 0) {
            return null;
        }

        // Query active inpatient admission, ward bed, active diet, and primary EMR diagnosis
        $this->db->select([
            'ia.id AS admission_id',
            'ia.admitted_at',
            'p.id AS patient_id',
            'p.name AS patient_name',
            'p.medical_record_number AS mrn',
            'w.id AS ward_id',
            'w.ward_name',
            'w.class_level',
            'b.id AS bed_id',
            'b.bed_number',
            'v.id AS visit_id',
            'v.visit_number',
            'mr.id AS medical_record_id',
            'mr.primary_icd10_code',
            'icd.description_en AS diagnosis_name',
            'mr.objective_vital_signs',
            'nd.id AS diet_id',
            'nd.diet_category',
            'nd.calorie_target_kcal',
            'nd.nutrient_distribution',
            'nd.allergen_restrictions',
            'nd.texture_form',
            'nd.special_instructions',
            'nd.meal_status',
            'nd.is_active AS diet_is_active',
            'nd.valid_from AS diet_valid_from',
            'nd.valid_to AS diet_valid_to',
            'doc.name AS prescribing_physician'
        ]);
        $this->db->from('inpatient_admissions ia');
        $this->db->join('patients p', 'p.id = ia.patient_id', 'INNER');
        $this->db->join('hospital_beds b', 'b.id = ia.bed_id', 'INNER');
        $this->db->join('hospital_wards w', 'w.id = b.ward_id', 'INNER');
        $this->db->join('visits v', 'v.id = ia.visit_id', 'INNER');
        // Left join latest finalized EMR record for this visit
        $this->db->join('medical_records mr', 'mr.visit_id = v.id AND mr.is_locked IS TRUE', 'LEFT', FALSE);
        $this->db->join('icd10_codes icd', 'icd.code = mr.primary_icd10_code', 'LEFT');
        // Left join active diet prescription
        $this->db->join('nutrition_diets nd', 
            'nd.patient_id = ia.patient_id AND nd.is_active IS TRUE AND nd.valid_from <= CURRENT_DATE AND (nd.valid_to IS NULL OR nd.valid_to >= CURRENT_DATE)', 
            'LEFT',
            FALSE
        );
        $this->db->join('doctors doc', 'doc.id = nd.prescribed_by_doctor_id', 'LEFT');
        $this->db->where('ia.patient_id', $patient_id);
        $this->db->where('ia.admission_status', 'ACTIVE');
        // Prioritize active admission, active diet matching current admission visit, then latest created
        $this->db->order_by('ia.admitted_at', 'DESC');
        $this->db->order_by('(CASE WHEN nd.visit_id = ia.visit_id THEN 1 ELSE 0 END)', 'DESC', FALSE);
        $this->db->order_by('nd.created_at', 'DESC');
        $this->db->limit(1);

        $result = $this->db->get()->row_array();

        if (!$result || empty($result['admission_id'])) {
            return null;
        }

        // Safely decode PostgreSQL JSONB attributes, ensuring arrays even on invalid/malformed JSON
        $result['nutrient_distribution'] = $this->safe_json_decode($result['nutrient_distribution'] ?? null, []);
        $result['allergen_restrictions'] = $this->sanitize_allergen_list(
            $this->safe_json_decode($result['allergen_restrictions'] ?? null, [])
        );
        $result['meal_status'] = $this->safe_json_decode(
            $result['meal_status'] ?? null,
            ['breakfast' => 'PENDING', 'lunch' => 'PENDING', 'dinner' => 'PENDING']
        );
        $result['objective_vital_signs'] = $this->safe_json_decode($result['objective_vital_signs'] ?? null, []);

        // Cast calorie target to integer if present
        if ($result['calorie_target_kcal'] !== null && $result['calorie_target_kcal'] !== '') {
            $result['calorie_target_kcal'] = (int)$result['calorie_target_kcal'];
        }

        // Active diet presence flag
        $result['has_active_diet'] = !empty($result['diet_id']);
        $result['diet_status'] = !empty($result['diet_id']) ? 'ACTIVE' : 'NO_ACTIVE_DIET';

        // Automatic Clinical Cross-Validation: Check EMR diagnosis vs assigned diet
        $result['clinical_alerts'] = $this->evaluate_dietary_contraindications(
            $result['primary_icd10_code'] ?? '',
            $result['diet_category'] ?? '',
            $result['nutrient_distribution'],
            $result['allergen_restrictions']
        );

        return $result;
    }

    /**
     * Cross-evaluates ICD-10 diagnosis against nutrition plan.
     * E.g. Flags warning if diabetic patient is assigned standard diet with high carbs.
     *
     * @param string $icd_code Primary or relevant ICD-10 code
     * @param string $diet_category Prescribed diet category
     * @param array $nutrients Nutrient distribution array (carbohydrate_pct, protein_pct, fat_pct, sodium_max_mg)
     * @param array $allergens Allergen restrictions array
     * @return array List of clinical alerts/warnings
     */
    public function evaluate_dietary_contraindications(
        string $icd_code,
        string $diet_category,
        array $nutrients,
        array $allergens = []
    ): array {
        $alerts = [];
        $icd_code = strtoupper(trim($icd_code));
        $diet_category = trim($diet_category);

        // If no diet category is assigned (e.g. inactive diet or missing prescription)
        if ($diet_category === '') {
            if (str_starts_with($icd_code, 'E10') || str_starts_with($icd_code, 'E11')) {
                $alerts[] = "CRITICAL: Patient diagnosed with Diabetes ({$icd_code}) but no active diet prescription is assigned. Low-GI meal recommended.";
            } elseif (str_starts_with($icd_code, 'N18')) {
                $alerts[] = "WARNING: Patient diagnosed with Chronic Kidney Disease ({$icd_code}) but no active diet prescription is assigned. Renal diet recommended.";
            }
            return $alerts;
        }

        // Diabetes Mellitus check (ICD-10 starting with E10 or E11, or E12-E14)
        if (str_starts_with($icd_code, 'E10') || str_starts_with($icd_code, 'E11') ||
            str_starts_with($icd_code, 'E12') || str_starts_with($icd_code, 'E13') || str_starts_with($icd_code, 'E14')) {
            if ($diet_category !== 'DIABETIC_LOW_GI') {
                $alerts[] = "CRITICAL: Patient diagnosed with Diabetes ({$icd_code}) but current diet is '{$diet_category}'. Low-GI meal recommended.";
            }
            if (isset($nutrients['carbohydrate_pct']) && (float)$nutrients['carbohydrate_pct'] > 55) {
                $alerts[] = "WARNING: Carbohydrate intake exceeds recommended diabetic threshold (>55%).";
            }
        }

        // Essential & Secondary Hypertension check (ICD-10 I10, I11-I15)
        if ($icd_code === 'I10' || str_starts_with($icd_code, 'I10') || str_starts_with($icd_code, 'I11') ||
            str_starts_with($icd_code, 'I12') || str_starts_with($icd_code, 'I13') || str_starts_with($icd_code, 'I15')) {
            if (isset($nutrients['sodium_max_mg']) && (float)$nutrients['sodium_max_mg'] > 2000) {
                $alerts[] = "WARNING: Sodium restriction violated for Hypertensive patient (>2000mg/day).";
            }
        }

        // Chronic Kidney Disease check (ICD-10 N18)
        if (str_starts_with($icd_code, 'N18')) {
            if ($diet_category !== 'RENAL_LOW_PROTEIN' && $diet_category !== 'RENAL') {
                $alerts[] = "WARNING: Patient diagnosed with Chronic Kidney Disease ({$icd_code}) but current diet is '{$diet_category}'. Low-protein renal diet recommended.";
            }
            if (isset($nutrients['protein_pct']) && (float)$nutrients['protein_pct'] > 15) {
                $alerts[] = "WARNING: Protein intake exceeds recommended threshold for renal patient (>15%).";
            }
            if (isset($nutrients['sodium_max_mg']) && (float)$nutrients['sodium_max_mg'] > 2000) {
                $alerts[] = "WARNING: Sodium restriction violated for Renal patient (>2000mg/day).";
            }
        }

        return $alerts;
    }

    /**
     * Safely decodes a JSON string or returns default if null, empty, or invalid JSON.
     * Prevents TypeErrors and exceptions from malformed database JSON fields.
     *
     * @param mixed $value Raw JSON string, array, or null
     * @param array $default Default array to return on empty or invalid input
     * @return array Decoded array
     */
    public function safe_json_decode(mixed $value, array $default = []): array {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || $value === '' || !is_string($value)) {
            return $default;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === 'null') {
            return $default;
        }
        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return $default;
        }
        return $decoded;
    }

    /**
     * Normalizes boolean values from PostgreSQL ('t'/'f', 1/0, true/false).
     *
     * @param mixed $value
     * @return bool
     */
    public function is_truthy_bool(mixed $value): bool {
        return in_array($value, [true, 't', 'true', 'TRUE', 1, '1'], true);
    }

    /**
     * Sanitizes and normalizes an allergen list into an array of trimmed uppercase strings.
     *
     * @param array $allergens Raw allergen array
     * @return array Sanitized list of unique allergen names
     */
    public function sanitize_allergen_list(array $allergens): array {
        $clean = [];
        foreach ($allergens as $item) {
            if (is_string($item)) {
                $val = trim($item);
                if ($val !== '') {
                    $clean[] = strtoupper($val);
                }
            }
        }
        return array_values(array_unique($clean));
    }

    /**
     * Validates diet prescription parameters against clinical business rules
     * and database check constraints.
     *
     * @param array $data Input diet prescription data
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validate_diet_prescription(array $data): array {
        $errors = [];

        // Required patient ID
        if (empty($data['patient_id']) || !is_numeric($data['patient_id']) || (int)$data['patient_id'] <= 0) {
            $errors[] = "Valid patient_id is required.";
        }

        // Required visit ID
        if (empty($data['visit_id'])) {
            $errors[] = "Valid visit_id is required.";
        }

        // Diet category
        if (empty($data['diet_category']) || !is_string($data['diet_category'])) {
            $errors[] = "Valid diet_category is required.";
        }

        // Calorie target constraint: CHECK (calorie_target_kcal BETWEEN 500 AND 5000)
        if (!isset($data['calorie_target_kcal']) || !is_numeric($data['calorie_target_kcal'])) {
            $errors[] = "calorie_target_kcal is required.";
        } else {
            $cal = (int)$data['calorie_target_kcal'];
            if ($cal < 500 || $cal > 5000) {
                $errors[] = "calorie_target_kcal must be between 500 and 5000 kcal.";
            }
        }

        // Texture form constraint: CHECK (texture_form IN ('REGULAR_SOLID', 'SOFT_FOOD', 'PUREED', 'FULL_LIQUID', 'CLEAR_LIQUID', 'NPO_FASTING'))
        if (empty($data['texture_form']) || !in_array($data['texture_form'], self::ALLOWED_TEXTURE_FORMS, true)) {
            $errors[] = "texture_form must be one of: " . implode(', ', self::ALLOWED_TEXTURE_FORMS) . ".";
        }

        // Prescribing doctor
        if (empty($data['prescribed_by_doctor_id']) || !is_numeric($data['prescribed_by_doctor_id'])) {
            $errors[] = "Valid prescribed_by_doctor_id is required.";
        }

        // Nutrient distribution check
        if (isset($data['nutrient_distribution'])) {
            $nutrients = is_array($data['nutrient_distribution']) 
                ? $data['nutrient_distribution'] 
                : $this->safe_json_decode($data['nutrient_distribution'], []);
            
            // Check percentage boundaries
            foreach (['carbohydrate_pct', 'protein_pct', 'fat_pct'] as $macro) {
                if (isset($nutrients[$macro]) && ((float)$nutrients[$macro] < 0 || (float)$nutrients[$macro] > 100)) {
                    $errors[] = "{$macro} must be between 0% and 100%.";
                }
            }
            if (isset($nutrients['sodium_max_mg']) && (float)$nutrients['sodium_max_mg'] < 0) {
                $errors[] = "sodium_max_mg cannot be negative.";
            }
        }

        // Valid dates
        if (!empty($data['valid_from']) && !strtotime($data['valid_from'])) {
            $errors[] = "valid_from must be a valid date format (YYYY-MM-DD).";
        }
        if (!empty($data['valid_to'])) {
            if (!strtotime($data['valid_to'])) {
                $errors[] = "valid_to must be a valid date format (YYYY-MM-DD).";
            } elseif (!empty($data['valid_from']) && strtotime($data['valid_to']) < strtotime($data['valid_from'])) {
                $errors[] = "valid_to cannot be earlier than valid_from.";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Prescribes a new inpatient diet, automatically superseding any previous active
     * diets for the same patient/visit to prevent conflicting active orders.
     *
     * @param array $diet_data Diet attributes
     * @return array ['success' => bool, 'diet_id' => ?string, 'errors' => array]
     */
    public function prescribe_diet(array $diet_data): array {
        $validation = $this->validate_diet_prescription($diet_data);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'diet_id' => null,
                'errors' => $validation['errors']
            ];
        }

        $patient_id = (int)$diet_data['patient_id'];
        $visit_id = $diet_data['visit_id'];

        $this->db->trans_start();

        // 1. Supersede any currently active diet orders for this patient/visit
        $this->db->where('patient_id', $patient_id);
        $this->db->where('is_active', TRUE);
        $this->db->update($this->table, [
            'is_active' => FALSE,
            'valid_to'  => date('Y-m-d')
        ]);

        // 2. Prepare JSONB payloads
        $nutrients = is_array($diet_data['nutrient_distribution'] ?? null)
            ? $diet_data['nutrient_distribution']
            : $this->safe_json_decode($diet_data['nutrient_distribution'] ?? null, []);

        $allergens = is_array($diet_data['allergen_restrictions'] ?? null)
            ? $diet_data['allergen_restrictions']
            : $this->safe_json_decode($diet_data['allergen_restrictions'] ?? null, []);
        $allergens = $this->sanitize_allergen_list($allergens);

        $meal_status = is_array($diet_data['meal_status'] ?? null)
            ? $diet_data['meal_status']
            : $this->safe_json_decode($diet_data['meal_status'] ?? null, [
                'breakfast' => 'PENDING',
                'lunch'     => 'PENDING',
                'dinner'    => 'PENDING'
            ]);

        // 3. Generate UUID for PostgreSQL PK
        $uuid_row = $this->db->query("SELECT gen_random_uuid() AS id")->row();
        $diet_id = $uuid_row->id;

        $insert_data = [
            'id'                      => $diet_id,
            'patient_id'              => $patient_id,
            'visit_id'                => $visit_id,
            'medical_record_id'       => $diet_data['medical_record_id'] ?? null,
            'diet_category'           => $diet_data['diet_category'],
            'calorie_target_kcal'     => (int)$diet_data['calorie_target_kcal'],
            'nutrient_distribution'   => json_encode($nutrients),
            'allergen_restrictions'   => json_encode($allergens),
            'texture_form'            => $diet_data['texture_form'],
            'special_instructions'    => $diet_data['special_instructions'] ?? null,
            'meal_status'             => json_encode($meal_status),
            'prescribed_by_doctor_id' => (int)$diet_data['prescribed_by_doctor_id'],
            'is_active'               => true,
            'valid_from'              => $diet_data['valid_from'] ?? date('Y-m-d'),
            'valid_to'                => $diet_data['valid_to'] ?? null
        ];

        $this->db->insert($this->table, $insert_data);
        $this->write_audit('INSERT', (string)$diet_id, null, $insert_data);

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return [
                'success' => false,
                'diet_id' => null,
                'errors' => ['Database transaction failed during diet prescription.']
            ];
        }

        return [
            'success' => true,
            'diet_id' => $diet_id,
            'errors' => []
        ];
    }

    /**
     * Updates the status of a specific meal slot (breakfast, lunch, dinner).
     *
     * @param string $diet_id Target diet UUID
     * @param string $meal_slot 'breakfast', 'lunch', or 'dinner'
     * @param string $new_status Status from ALLOWED_MEAL_STATUSES
     * @return bool True on success, false otherwise
     */
    public function update_meal_status(string $diet_id, string $meal_slot, string $new_status): bool {
        $meal_slot = strtolower(trim($meal_slot));
        $new_status = strtoupper(trim($new_status));

        if (!in_array($meal_slot, self::ALLOWED_MEAL_SLOTS, true)) {
            return false;
        }

        if (!in_array($new_status, self::ALLOWED_MEAL_STATUSES, true)) {
            return false;
        }

        $current = $this->db->select('id, meal_status')
                            ->where('id', $diet_id)
                            ->get($this->table)
                            ->row_array();

        if (!$current) {
            return false;
        }

        $status_map = $this->safe_json_decode($current['meal_status'] ?? null, [
            'breakfast' => 'PENDING',
            'lunch'     => 'PENDING',
            'dinner'    => 'PENDING'
        ]);

        $status_map[$meal_slot] = $new_status;

        return $this->tracked_update($diet_id, [
            'meal_status' => json_encode($status_map)
        ]);
    }

    /**
     * Deactivates a diet prescription, marking it inactive and terminating validity.
     *
     * @param string $diet_id Target diet UUID
     * @return bool True on success, false otherwise
     */
    public function deactivate_diet(string $diet_id): bool {
        $exists = $this->db->where('id', $diet_id)->count_all_results($this->table);
        if ($exists === 0) {
            return false;
        }

        return $this->tracked_update($diet_id, [
            'is_active' => FALSE,
            'valid_to'  => date('Y-m-d')
        ]);
    }

    /**
     * Checks if a patient currently has an active diet prescription.
     *
     * @param int $patient_id Target patient ID
     * @return bool True if active diet exists, false otherwise
     */
    public function has_active_diet(int $patient_id): bool {
        if ($patient_id <= 0) {
            return false;
        }

        $count = $this->db->where('patient_id', $patient_id)
                          ->where('is_active IS TRUE', null, false)
                          ->where('valid_from <= CURRENT_DATE', null, false)
                          ->group_start()
                              ->where('valid_to IS NULL', null, false)
                              ->or_where('valid_to >= CURRENT_DATE', null, false)
                          ->group_end()
                          ->count_all_results($this->table);

        return $count > 0;
    }

    /**
     * Fetches daily dietary requirements for all active inpatients in a specific ward.
     * Used by the hospital kitchen and clinical dietetics department for batch meal prep.
     *
     * @param int $ward_id Target hospital ward ID
     * @return array List of inpatient dietary profiles in the ward
     */
    public function get_active_diets_by_ward(int $ward_id): array {
        if ($ward_id <= 0) {
            return [];
        }

        $this->db->select([
            'ia.id AS admission_id',
            'ia.admitted_at',
            'p.id AS patient_id',
            'p.name AS patient_name',
            'p.medical_record_number AS mrn',
            'w.ward_name',
            'w.class_level',
            'b.bed_number',
            'nd.id AS diet_id',
            'nd.diet_category',
            'nd.calorie_target_kcal',
            'nd.texture_form',
            'nd.nutrient_distribution',
            'nd.allergen_restrictions',
            'nd.meal_status',
            'nd.special_instructions',
            'doc.name AS prescribing_physician'
        ]);
        $this->db->from('inpatient_admissions ia');
        $this->db->join('patients p', 'p.id = ia.patient_id', 'INNER');
        $this->db->join('hospital_beds b', 'b.id = ia.bed_id', 'INNER');
        $this->db->join('hospital_wards w', 'w.id = b.ward_id', 'INNER');
        $this->db->join('visits v', 'v.id = ia.visit_id', 'INNER');
        $this->db->join('nutrition_diets nd', 
            'nd.patient_id = ia.patient_id AND nd.is_active IS TRUE AND nd.valid_from <= CURRENT_DATE AND (nd.valid_to IS NULL OR nd.valid_to >= CURRENT_DATE)', 
            'LEFT',
            FALSE
        );
        $this->db->join('doctors doc', 'doc.id = nd.prescribed_by_doctor_id', 'LEFT');
        $this->db->where('w.id', $ward_id);
        $this->db->where('ia.admission_status', 'ACTIVE');
        $this->db->order_by('b.bed_number', 'ASC');
        $this->db->order_by('nd.created_at', 'DESC');

        $rows = $this->db->get()->result_array();

        $deduped = [];
        foreach ($rows as $row) {
            $adm_id = $row['admission_id'];
            if (!isset($deduped[$adm_id])) {
                $row['nutrient_distribution'] = $this->safe_json_decode($row['nutrient_distribution'] ?? null, []);
                $row['allergen_restrictions'] = $this->sanitize_allergen_list(
                    $this->safe_json_decode($row['allergen_restrictions'] ?? null, [])
                );
                $row['meal_status'] = $this->safe_json_decode($row['meal_status'] ?? null, []);
                $row['has_active_diet'] = !empty($row['diet_id']);
                if ($row['calorie_target_kcal'] !== null && $row['calorie_target_kcal'] !== '') {
                    $row['calorie_target_kcal'] = (int)$row['calorie_target_kcal'];
                }
                $deduped[$adm_id] = $row;
            }
        }

        return array_values($deduped);
    }
}
