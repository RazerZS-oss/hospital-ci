-- ============================================================================
-- Enterprise HMIS Advanced Operational & Executive Modules Schema
-- PostgreSQL 15 Compatible
-- Domains: Nutrition & CSSD, MCU & Blood Bank, Ambulance Fleet, Portal & Analytics
-- ============================================================================

-- ============================================================================
-- DOMAIN 1: OPERATIONAL & CLINICAL SUPPORT (NUTRITION & CSSD)
-- ============================================================================

-- Inpatient Wards and Hospital Beds (Prerequisite for Nutrition & BOR Analytics)
CREATE TABLE IF NOT EXISTS hospital_wards (
    id SERIAL PRIMARY KEY,
    ward_code VARCHAR(32) UNIQUE NOT NULL,
    ward_name VARCHAR(100) NOT NULL,
    class_level VARCHAR(20) NOT NULL CHECK (class_level IN ('VVIP', 'VIP', 'CLASS_1', 'CLASS_2', 'CLASS_3', 'ICU', 'NICU', 'ISOLATION')),
    total_beds INT NOT NULL CHECK (total_beds > 0),
    is_active BOOLEAN DEFAULT TRUE NOT NULL
);

CREATE TABLE IF NOT EXISTS hospital_beds (
    id SERIAL PRIMARY KEY,
    ward_id INT NOT NULL REFERENCES hospital_wards(id) ON DELETE RESTRICT,
    bed_number VARCHAR(20) NOT NULL,
    is_occupied BOOLEAN DEFAULT FALSE NOT NULL,
    current_patient_id INT NULL REFERENCES patients(id) ON DELETE SET NULL,
    current_visit_id UUID NULL REFERENCES visits(id) ON DELETE SET NULL,
    CONSTRAINT uq_ward_bed UNIQUE (ward_id, bed_number)
);

CREATE TABLE IF NOT EXISTS inpatient_admissions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    visit_id UUID UNIQUE NOT NULL REFERENCES visits(id) ON DELETE RESTRICT,
    patient_id INT NOT NULL REFERENCES patients(id) ON DELETE RESTRICT,
    bed_id INT NOT NULL REFERENCES hospital_beds(id) ON DELETE RESTRICT,
    admitted_at TIMESTAMPTZ NOT NULL,
    discharged_at TIMESTAMPTZ NULL,
    admission_status VARCHAR(20) DEFAULT 'ACTIVE' NOT NULL CHECK (admission_status IN ('ACTIVE', 'DISCHARGED', 'TRANSFERRED', 'DECEASED'))
);

-- Nutrition Diets Table (Linked with medical_records & EMR diagnosis)
CREATE TABLE IF NOT EXISTS nutrition_diets (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    patient_id INT NOT NULL REFERENCES patients(id) ON DELETE RESTRICT,
    visit_id UUID NOT NULL REFERENCES visits(id) ON DELETE RESTRICT,
    medical_record_id UUID NULL REFERENCES medical_records(id) ON DELETE SET NULL,
    diet_category VARCHAR(64) NOT NULL, -- e.g., 'DIABETIC_LOW_GI', 'LOW_SODIUM_DASH', 'RENAL_LOW_PROTEIN', 'POST_OP_LIQUID'
    calorie_target_kcal INT NOT NULL CHECK (calorie_target_kcal BETWEEN 500 AND 5000),
    nutrient_distribution JSONB NOT NULL, -- {"carbohydrate_pct": 50, "protein_pct": 20, "fat_pct": 30, "sodium_max_mg": 1500}
    allergen_restrictions JSONB DEFAULT '[]'::jsonb NOT NULL, -- ["PEANUTS", "SEAFOOD", "GLUTEN"]
    texture_form VARCHAR(32) NOT NULL CHECK (texture_form IN ('REGULAR_SOLID', 'SOFT_FOOD', 'PUREED', 'FULL_LIQUID', 'CLEAR_LIQUID', 'NPO_FASTING')),
    special_instructions TEXT NULL,
    meal_status JSONB DEFAULT '{"breakfast": "PENDING", "lunch": "PENDING", "dinner": "PENDING"}'::jsonb NOT NULL,
    prescribed_by_doctor_id INT NOT NULL REFERENCES doctors(id) ON DELETE RESTRICT,
    is_active BOOLEAN DEFAULT TRUE NOT NULL,
    valid_from DATE NOT NULL,
    valid_to DATE NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_nutrition_patient ON nutrition_diets (patient_id, is_active);
CREATE INDEX IF NOT EXISTS idx_nutrition_diet_category ON nutrition_diets (diet_category);
CREATE INDEX IF NOT EXISTS idx_nutrition_nutrients_gin ON nutrition_diets USING gin (nutrient_distribution);

-- CSSD (Central Sterile Services Department) Sterilization Logs
CREATE TABLE IF NOT EXISTS sterilization_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    batch_number VARCHAR(64) UNIQUE NOT NULL,
    autoclave_machine_id VARCHAR(32) NOT NULL,
    sterilization_method VARCHAR(32) NOT NULL CHECK (sterilization_method IN ('STEAM_AUTOCLAVE', 'ETHYLENE_OXIDE', 'HYDROGEN_PEROXIDE_PLASMA')),
    cycle_number INT NOT NULL,
    temperature_celsius NUMERIC(5, 2) NOT NULL,
    pressure_bar NUMERIC(4, 2) NOT NULL,
    holding_time_minutes INT NOT NULL,
    biological_indicator_passed BOOLEAN NOT NULL,
    chemical_indicator_passed BOOLEAN NOT NULL,
    tool_set_items JSONB NOT NULL, -- [{"set_code": "LAP-01", "name": "Major Laparotomy Set", "item_count": 36}]
    sterilized_at TIMESTAMPTZ NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    operator_user_id INT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    status VARCHAR(20) DEFAULT 'STERILE' NOT NULL CHECK (status IN ('STERILE', 'EXPIRED', 'CONTAMINATED', 'RECALLED')),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_cssd_batch ON sterilization_logs (batch_number);
CREATE INDEX IF NOT EXISTS idx_cssd_expiry ON sterilization_logs (expires_at, status);
CREATE INDEX IF NOT EXISTS idx_cssd_tools_gin ON sterilization_logs USING gin (tool_set_items);

-- Medical Waste Disposal Logs
CREATE TABLE IF NOT EXISTS medical_waste_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    manifest_code VARCHAR(64) UNIQUE NOT NULL,
    waste_category VARCHAR(32) NOT NULL CHECK (waste_category IN ('INFECTIOUS_SHARPS', 'PATHOLOGICAL', 'PHARMACEUTICAL', 'CYTOTOXIC', 'RADIOACTIVE', 'GENERAL_NON_HAZARDOUS')),
    origin_department VARCHAR(64) NOT NULL,
    weight_kg NUMERIC(8, 2) NOT NULL CHECK (weight_kg > 0),
    storage_location VARCHAR(64) NOT NULL,
    packaged_at TIMESTAMPTZ NOT NULL,
    dispatched_at TIMESTAMPTZ NULL,
    licensed_contractor VARCHAR(150) NULL,
    officer_user_id INT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_waste_manifest ON medical_waste_logs (manifest_code);
CREATE INDEX IF NOT EXISTS idx_waste_category ON medical_waste_logs (waste_category, packaged_at);

-- ============================================================================
-- DOMAIN 2: SPECIALIZED MEDICAL SERVICES (MCU & BLOOD BANK)
-- ============================================================================

CREATE TABLE IF NOT EXISTS mcu_corporate_clients (
    id SERIAL PRIMARY KEY,
    client_code VARCHAR(32) UNIQUE NOT NULL,
    company_name VARCHAR(255) NOT NULL,
    industry_sector VARCHAR(100) NOT NULL,
    billing_contact_email VARCHAR(150) NOT NULL,
    contract_number VARCHAR(100) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE NOT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS mcu_packages (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    package_code VARCHAR(32) UNIQUE NOT NULL,
    package_name VARCHAR(150) NOT NULL,
    base_price NUMERIC(12, 2) NOT NULL CHECK (base_price >= 0),
    corporate_discount_pct NUMERIC(5, 2) DEFAULT 0.00 NOT NULL CHECK (corporate_discount_pct BETWEEN 0 AND 100),
    included_examinations JSONB NOT NULL, -- [{"category": "LAB", "tests": ["CBC", "LIPID", "FASTING_GLUCOSE"]}, {"category": "RAD", "tests": ["CHEST_XRAY"]}]
    fasting_hours INT DEFAULT 10 NOT NULL,
    is_active BOOLEAN DEFAULT TRUE NOT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS mcu_registrations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    batch_code VARCHAR(64) NOT NULL,
    corporate_client_id INT NOT NULL REFERENCES mcu_corporate_clients(id) ON DELETE RESTRICT,
    mcu_package_id UUID NOT NULL REFERENCES mcu_packages(id) ON DELETE RESTRICT,
    patient_id INT NOT NULL REFERENCES patients(id) ON DELETE RESTRICT,
    visit_id UUID NOT NULL REFERENCES visits(id) ON DELETE RESTRICT,
    employee_badge_id VARCHAR(64) NOT NULL,
    scheduled_date DATE NOT NULL,
    attendance_status VARCHAR(20) DEFAULT 'SCHEDULED' NOT NULL CHECK (attendance_status IN ('SCHEDULED', 'ATTENDED', 'COMPLETED', 'NO_SHOW')),
    fit_to_work_conclusion VARCHAR(32) NULL CHECK (fit_to_work_conclusion IN ('FIT', 'FIT_WITH_RESTRICTION', 'TEMPORARILY_UNFIT', 'UNFIT')),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT uq_client_employee_batch UNIQUE (corporate_client_id, employee_badge_id, scheduled_date)
);

CREATE INDEX IF NOT EXISTS idx_mcu_batch ON mcu_registrations (batch_code);
CREATE INDEX IF NOT EXISTS idx_mcu_patient ON mcu_registrations (patient_id, scheduled_date);

-- Blood Bank Inventory (Strict FEFO - First Expired, First Out)
CREATE TABLE IF NOT EXISTS blood_inventory (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    bag_barcode VARCHAR(64) UNIQUE NOT NULL,
    blood_type VARCHAR(2) NOT NULL CHECK (blood_type IN ('A', 'B', 'AB', 'O')),
    rhesus VARCHAR(8) NOT NULL CHECK (rhesus IN ('POSITIVE', 'NEGATIVE')),
    component_type VARCHAR(32) NOT NULL CHECK (component_type IN ('WHOLE_BLOOD', 'PACKED_RED_CELLS', 'FRESH_FROZEN_PLASMA', 'THROMBOCYTE_CONCENTRATE', 'CRYOPRECIPITATE')),
    volume_ml INT NOT NULL CHECK (volume_ml > 0),
    donation_date DATE NOT NULL,
    storage_temp_celsius NUMERIC(4, 1) NOT NULL,
    expiry_datetime TIMESTAMPTZ NOT NULL,
    screening_hiv_passed BOOLEAN NOT NULL,
    screening_hep_b_passed BOOLEAN NOT NULL,
    screening_hep_c_passed BOOLEAN NOT NULL,
    screening_syphilis_passed BOOLEAN NOT NULL,
    crossmatch_status VARCHAR(20) DEFAULT 'UNMATCHED' NOT NULL CHECK (crossmatch_status IN ('UNMATCHED', 'CROSSMATCHED', 'RESERVED', 'TRANSFUSED', 'DISCARDED')),
    reserved_patient_id INT NULL REFERENCES patients(id) ON DELETE SET NULL,
    reserved_visit_id UUID NULL REFERENCES visits(id) ON DELETE SET NULL,
    status VARCHAR(20) DEFAULT 'AVAILABLE' NOT NULL CHECK (status IN ('AVAILABLE', 'RESERVED', 'QUARANTINED', 'TRANSFUSED', 'EXPIRED')),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL
);

-- Crucial FEFO Index: Ensures fastest retrieval of nearest expiring compatible units
CREATE INDEX IF NOT EXISTS idx_blood_fefo_compatible 
ON blood_inventory (blood_type, rhesus, component_type, expiry_datetime ASC) 
WHERE status = 'AVAILABLE';

-- ============================================================================
-- DOMAIN 3: FLEET MANAGEMENT (AMBULANCE) & BILLING INTEGRATION
-- ============================================================================

CREATE TABLE IF NOT EXISTS ambulances (
    id SERIAL PRIMARY KEY,
    license_plate VARCHAR(20) UNIQUE NOT NULL,
    call_sign VARCHAR(32) UNIQUE NOT NULL, -- e.g., 'MEDIC-01'
    vehicle_type VARCHAR(32) NOT NULL CHECK (vehicle_type IN ('BASIC_TRANSPORT', 'ADVANCED_LIFE_SUPPORT_ALS', 'NEONATAL_ICU')),
    fuel_level_pct INT DEFAULT 100 CHECK (fuel_level_pct BETWEEN 0 AND 100),
    odometer_km NUMERIC(10, 2) DEFAULT 0.00 NOT NULL,
    equipment_inventory JSONB DEFAULT '{}'::jsonb NOT NULL,
    current_status VARCHAR(20) DEFAULT 'STANDBY' NOT NULL CHECK (current_status IN ('STANDBY', 'DISPATCHED', 'ON_SCENE', 'TRANSPORTING', 'MAINTENANCE')),
    is_active BOOLEAN DEFAULT TRUE NOT NULL
);

CREATE TABLE IF NOT EXISTS ambulance_drivers (
    id SERIAL PRIMARY KEY,
    employee_badge VARCHAR(32) UNIQUE NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    license_number VARCHAR(64) NOT NULL,
    license_expiry DATE NOT NULL,
    phone_number VARCHAR(20) NOT NULL,
    duty_status VARCHAR(20) DEFAULT 'ON_DUTY' NOT NULL CHECK (duty_status IN ('ON_DUTY', 'DISPATCHED', 'OFF_DUTY'))
);

CREATE TABLE IF NOT EXISTS ambulance_dispatch_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    dispatch_code VARCHAR(64) UNIQUE NOT NULL,
    ambulance_id INT NOT NULL REFERENCES ambulances(id) ON DELETE RESTRICT,
    driver_id INT NOT NULL REFERENCES ambulance_drivers(id) ON DELETE RESTRICT,
    paramedic_crew JSONB NOT NULL, -- [{"staff_id": 1, "role": "PARAMEDIC_LEAD"}]
    patient_id INT NULL REFERENCES patients(id) ON DELETE SET NULL,
    visit_id UUID NULL REFERENCES visits(id) ON DELETE SET NULL,
    incident_type VARCHAR(64) NOT NULL,
    origin_address TEXT NOT NULL,
    destination_address TEXT NOT NULL,
    dispatched_at TIMESTAMPTZ NOT NULL,
    arrived_scene_at TIMESTAMPTZ NULL,
    arrived_hospital_at TIMESTAMPTZ NULL,
    distance_km NUMERIC(6, 2) NOT NULL CHECK (distance_km >= 0),
    toll_fees NUMERIC(10, 2) DEFAULT 0.00 NOT NULL,
    dispatch_status VARCHAR(20) DEFAULT 'COMPLETED' NOT NULL CHECK (dispatch_status IN ('DISPATCHED', 'ON_SCENE', 'TRANSPORTING', 'COMPLETED', 'CANCELLED')),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL
);

-- Billing Invoices & Details (Universal Hospital Billing Integration)
CREATE TABLE IF NOT EXISTS billing_invoices (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    invoice_number VARCHAR(64) UNIQUE NOT NULL,
    visit_id UUID NOT NULL REFERENCES visits(id) ON DELETE RESTRICT,
    patient_id INT NOT NULL REFERENCES patients(id) ON DELETE RESTRICT,
    total_amount NUMERIC(12, 2) DEFAULT 0.00 NOT NULL,
    discount_amount NUMERIC(12, 2) DEFAULT 0.00 NOT NULL,
    tax_amount NUMERIC(12, 2) DEFAULT 0.00 NOT NULL,
    final_payable_amount NUMERIC(12, 2) DEFAULT 0.00 NOT NULL,
    payment_status VARCHAR(20) DEFAULT 'UNPAID' NOT NULL CHECK (payment_status IN ('UNPAID', 'PARTIALLY_PAID', 'PAID', 'WAIVED')),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS billing_details (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    invoice_id UUID NOT NULL REFERENCES billing_invoices(id) ON DELETE CASCADE,
    service_category VARCHAR(32) NOT NULL CHECK (service_category IN ('CONSULTATION', 'PHARMACY', 'LABORATORY', 'RADIOLOGY', 'AMBULANCE', 'ROOM_BOARD', 'PROCEDURE')),
    item_code VARCHAR(64) NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    quantity INT DEFAULT 1 NOT NULL CHECK (quantity > 0),
    unit_price NUMERIC(12, 2) NOT NULL CHECK (unit_price >= 0),
    subtotal NUMERIC(12, 2) NOT NULL CHECK (subtotal >= 0),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_billing_invoice ON billing_details (invoice_id);

-- ============================================================================
-- DOMAIN 4: PATIENT PORTAL & LABORATORY INVESTIGATIONS
-- ============================================================================

CREATE TABLE IF NOT EXISTS patient_portal_tokens (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    patient_id INT NOT NULL REFERENCES patients(id) ON DELETE CASCADE,
    token_hash VARCHAR(128) UNIQUE NOT NULL,
    device_fingerprint VARCHAR(255) NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    is_revoked BOOLEAN DEFAULT FALSE NOT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_portal_token ON patient_portal_tokens (token_hash, is_revoked);

CREATE TABLE IF NOT EXISTS laboratory_results (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    visit_id UUID NOT NULL REFERENCES visits(id) ON DELETE RESTRICT,
    patient_id INT NOT NULL REFERENCES patients(id) ON DELETE RESTRICT,
    test_code VARCHAR(32) NOT NULL, -- e.g. 'CBC', 'LIPID_PANEL', 'GLU_FASTING'
    test_name VARCHAR(150) NOT NULL,
    test_category VARCHAR(64) NOT NULL, -- 'HEMATOLOGY', 'CLINICAL_CHEMISTRY', 'IMMUNOLOGY'
    sample_collected_at TIMESTAMPTZ NOT NULL,
    result_finalized_at TIMESTAMPTZ NOT NULL,
    specimen_type VARCHAR(64) NOT NULL, -- 'VENOUS_BLOOD', 'SERUM', 'PLASMA', 'URINE'
    panel_results JSONB NOT NULL, -- [{"parameter": "Hemoglobin", "value": 14.2, "unit": "g/dL", "ref_low": 13.5, "ref_high": 17.5, "flag": "NORMAL"}]
    clinical_interpretation TEXT NULL,
    signing_pathologist_name VARCHAR(150) NOT NULL,
    is_verified BOOLEAN DEFAULT TRUE NOT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_lab_patient ON laboratory_results (patient_id, result_finalized_at DESC);
CREATE INDEX IF NOT EXISTS idx_lab_panel_gin ON laboratory_results USING gin (panel_results);

-- ============================================================================
-- SEED FOUNDATIONAL DATA (Beds, Wards, Corporates, Ambulances, Lab Results)
-- ============================================================================

INSERT INTO hospital_wards (ward_code, ward_name, class_level, total_beds)
VALUES 
    ('WARD-ICU', 'Intensive Care Unit (ICU)', 'ICU', 10),
    ('WARD-VIP-A', 'Pavilion Wijaya Kusuma (VIP)', 'VIP', 20),
    ('WARD-MED-3', 'Flamboyan Inpatient Ward (Class 3)', 'CLASS_3', 50)
ON CONFLICT (ward_code) DO NOTHING;

INSERT INTO hospital_beds (ward_id, bed_number, is_occupied)
SELECT w.id, 'BED-' || s.i, FALSE
FROM hospital_wards w, generate_series(1, 10) AS s(i)
WHERE w.ward_code = 'WARD-VIP-A'
ON CONFLICT DO NOTHING;

INSERT INTO mcu_corporate_clients (client_code, company_name, industry_sector, billing_contact_email, contract_number)
VALUES ('CORP-TELCO', 'PT Nusantara Digital Telecom', 'Telecommunications', 'finance@nusantaratelecom.co.id', 'CTR-2026-0042')
ON CONFLICT (client_code) DO NOTHING;

INSERT INTO mcu_packages (package_code, package_name, base_price, corporate_discount_pct, included_examinations, fasting_hours)
VALUES (
    'MCU-EXEC-01', 
    'Executive Platinum Medical Check-Up', 
    2500000.00, 
    15.00, 
    '[{"category": "LABORATORY", "tests": ["Complete Blood Count", "Lipid Panel", "HbA1c", "Uric Acid", "Liver Function (SGOT/SGPT)", "Kidney Function (Urea/Creatinine)"]}, {"category": "DIAGNOSTIC", "tests": ["Resting 12-Lead ECG", "Digital Chest X-Ray", "Abdominal Ultrasonography (USG)"]}, {"category": "PHYSICAL", "tests": ["Audiometry", "Spirometry", "Ophthalmology Slit-Lamp"]}]'::jsonb,
    10
)
ON CONFLICT (package_code) DO NOTHING;

INSERT INTO ambulances (license_plate, call_sign, vehicle_type, fuel_level_pct, odometer_km, equipment_inventory, current_status)
VALUES (
    'B 1199 AMB',
    'AMB-ALS-01',
    'ADVANCED_LIFE_SUPPORT_ALS',
    95,
    14250.50,
    '{"defibrillator": "Zoll X-Series", "transport_ventilator": "Hamilton-T1", "syringe_pump": "B.Braun Perfusor", "o2_cylinder_bar": 150}'::jsonb,
    'STANDBY'
)
ON CONFLICT (license_plate) DO NOTHING;

INSERT INTO ambulance_drivers (employee_badge, full_name, license_number, license_expiry, phone_number, duty_status)
VALUES ('DRV-001', 'Supriyadi Santoso', 'SIM-B1-99887766', '2028-12-31', '081399881122', 'ON_DUTY')
ON CONFLICT (employee_badge) DO NOTHING;
