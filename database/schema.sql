-- ============================================================================
-- Enterprise Hospital Management Information System (HMIS) Database Schema
-- Database Target: PostgreSQL 15
-- Standards: Strict Relational Integrity, HIPAA Audit Compliance & JSONB Vitals
-- ============================================================================

-- Enable Cryptographic Extension for UUID Generation
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ============================================================================
-- 1. Framework Session Storage (CodeIgniter 3 Database Driver for PostgreSQL)
-- ============================================================================
CREATE TABLE IF NOT EXISTS ci_sessions (
    id VARCHAR(128) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    timestamp BIGINT DEFAULT 0 NOT NULL,
    data TEXT DEFAULT '' NOT NULL,
    CONSTRAINT ci_sessions_pkey PRIMARY KEY (id, ip_address)
);

CREATE INDEX IF NOT EXISTS idx_ci_sessions_timestamp ON ci_sessions (timestamp);

-- ============================================================================
-- 2. Audit Trail (HIPAA Security Rule § 164.312(b) Immutable Access & CRUD Log)
-- ============================================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    table_name VARCHAR(64) NOT NULL,
    record_id VARCHAR(64) NOT NULL,
    action VARCHAR(10) NOT NULL, -- 'INSERT', 'UPDATE', 'DELETE', 'VIEW'
    user_id VARCHAR(64) NULL,
    ip_address INET NOT NULL,
    user_agent TEXT,
    old_values JSONB NULL,
    new_values JSONB NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL,

    CONSTRAINT chk_audit_action CHECK (action IN ('INSERT', 'UPDATE', 'DELETE', 'VIEW', 'DENIED', 'SECURITY'))
);

-- Ensure user_id can accommodate both integer and UUID user identifiers
ALTER TABLE audit_logs ALTER COLUMN user_id TYPE VARCHAR(64) USING user_id::VARCHAR(64);

CREATE INDEX IF NOT EXISTS idx_audit_table_record ON audit_logs (table_name, record_id);
CREATE INDEX IF NOT EXISTS idx_audit_created_at ON audit_logs (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_user_id ON audit_logs (user_id);
CREATE INDEX IF NOT EXISTS idx_audit_new_values_gin ON audit_logs USING gin (new_values);
CREATE INDEX IF NOT EXISTS idx_audit_old_values_gin ON audit_logs USING gin (old_values);

-- Enforce Immutability: Prevent modification or deletion of audit records
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_rules WHERE rulename = 'no_update_audit' AND tablename = 'audit_logs'
    ) THEN
        CREATE RULE no_update_audit AS ON UPDATE TO audit_logs DO INSTEAD NOTHING;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_rules WHERE rulename = 'no_delete_audit' AND tablename = 'audit_logs'
    ) THEN
        CREATE RULE no_delete_audit AS ON DELETE TO audit_logs DO INSTEAD NOTHING;
    END IF;
END $$;

-- ============================================================================
-- 3. Core Identity & User Accounts (compatible with existing table)
-- ============================================================================
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(30) DEFAULT 'doctor' NOT NULL,
    name VARCHAR(100) NOT NULL,
    status VARCHAR(20) DEFAULT 'active' NOT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- 4. Doctors & Clinical Staff (extending existing table)
-- ============================================================================
CREATE TABLE IF NOT EXISTS doctors (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    specialization VARCHAR(150) NOT NULL,
    phone VARCHAR(50),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE doctors ADD COLUMN IF NOT EXISTS user_id INT REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE doctors ADD COLUMN IF NOT EXISTS full_name VARCHAR(150);
ALTER TABLE doctors ADD COLUMN IF NOT EXISTS license_number VARCHAR(64);
ALTER TABLE doctors ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT TRUE NOT NULL;
UPDATE doctors SET full_name = name WHERE full_name IS NULL;

CREATE INDEX IF NOT EXISTS idx_doctors_active ON doctors (is_active);

-- ============================================================================
-- 5. Patients Master Data (extending existing table)
-- ============================================================================
CREATE TABLE IF NOT EXISTS patients (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    medical_record_number VARCHAR(100) UNIQUE NOT NULL,
    blood_type VARCHAR(10),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE patients ADD COLUMN IF NOT EXISTS mrn VARCHAR(32);
ALTER TABLE patients ADD COLUMN IF NOT EXISTS full_name VARCHAR(150);
ALTER TABLE patients ADD COLUMN IF NOT EXISTS nik_or_national_id VARCHAR(32);
ALTER TABLE patients ADD COLUMN IF NOT EXISTS date_of_birth DATE;
ALTER TABLE patients ADD COLUMN IF NOT EXISTS gender VARCHAR(10);
ALTER TABLE patients ADD COLUMN IF NOT EXISTS address TEXT;
ALTER TABLE patients ADD COLUMN IF NOT EXISTS phone_number VARCHAR(20);
ALTER TABLE patients ADD COLUMN IF NOT EXISTS emergency_contact JSONB DEFAULT '{}'::jsonb NOT NULL;
UPDATE patients SET full_name = name WHERE full_name IS NULL;
UPDATE patients SET mrn = medical_record_number WHERE mrn IS NULL;

CREATE INDEX IF NOT EXISTS idx_patients_name ON patients (name);
CREATE INDEX IF NOT EXISTS idx_patients_mrn ON patients (medical_record_number);

-- ============================================================================
-- 6. Polyclinics (Outpatient Clinics / Specialist Departments)
-- ============================================================================
CREATE TABLE IF NOT EXISTS polyclinics (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    code VARCHAR(16) UNIQUE NOT NULL, -- e.g. 'INT', 'PED', 'OBG', 'CARD'
    name VARCHAR(100) NOT NULL,
    building_floor INT DEFAULT 1 NOT NULL,
    is_active BOOLEAN DEFAULT TRUE NOT NULL,
    metadata JSONB DEFAULT '{}'::jsonb NOT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_polyclinics_active ON polyclinics (is_active);

-- ============================================================================
-- 7. ICD-10 Diagnostic Codes (WHO Standard Medical Diagnosis)
-- ============================================================================
CREATE TABLE IF NOT EXISTS icd10_codes (
    code VARCHAR(10) PRIMARY KEY, -- e.g. 'I10' (Hypertension), 'E11.9' (Type 2 DM)
    description_en TEXT NOT NULL,
    category VARCHAR(100) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE NOT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_icd10_category ON icd10_codes (category);

-- ============================================================================
-- 8. Visits (Outpatient Registration, Appointments & High-Concurrency Queue)
-- ============================================================================
CREATE TABLE IF NOT EXISTS visits (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    visit_number VARCHAR(32) UNIQUE NOT NULL, -- Format: VIS-YYYYMMDD-XXXX
    patient_id INT NOT NULL,
    polyclinic_id UUID NOT NULL,
    doctor_id INT NOT NULL,
    visit_date DATE NOT NULL,
    queue_number INT NOT NULL CHECK (queue_number > 0),
    queue_status VARCHAR(20) DEFAULT 'WAITING' NOT NULL,
    billing_status VARCHAR(20) DEFAULT 'UNPAID' NOT NULL,
    check_in_time TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL,
    completed_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL,

    CONSTRAINT fk_visit_patient FOREIGN KEY (patient_id)
        REFERENCES patients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_visit_poly FOREIGN KEY (polyclinic_id)
        REFERENCES polyclinics(id) ON DELETE RESTRICT,
    CONSTRAINT fk_visit_doctor FOREIGN KEY (doctor_id)
        REFERENCES doctors(id) ON DELETE RESTRICT,
    CONSTRAINT chk_queue_status CHECK (
        queue_status IN ('WAITING', 'CALLED', 'IN_CONSULTATION', 'SKIPPED', 'COMPLETED', 'CANCELLED')
    ),
    CONSTRAINT chk_billing_status CHECK (
        billing_status IN ('UNPAID', 'VERIFIED', 'PAID', 'EXEMPT')
    )
);

-- STRICT CONSTRAINT: Prevents duplicate queue numbers per polyclinic, doctor, and date
CREATE UNIQUE INDEX IF NOT EXISTS uq_poly_doctor_date_queue 
ON visits (polyclinic_id, doctor_id, visit_date, queue_number);

-- Queue monitor index
CREATE INDEX IF NOT EXISTS idx_visits_queue_monitor 
ON visits (polyclinic_id, doctor_id, visit_date, queue_status);

-- ============================================================================
-- 9. Medical Records (EHR Clinical SOAP Notes & Orders)
-- ============================================================================
CREATE TABLE IF NOT EXISTS medical_records (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    visit_id UUID UNIQUE NOT NULL, -- Strict 1-to-1 relationship with visit
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    primary_icd10_code VARCHAR(10) NOT NULL,
    secondary_icd10_codes JSONB DEFAULT '[]'::jsonb NOT NULL,

    -- Clinical SOAP Notes (Subjective, Objective, Assessment, Plan)
    subjective_complaints TEXT NOT NULL,
    objective_vital_signs JSONB NOT NULL, -- { bp: "120/80", hr: 78, temp: 36.7, rr: 18, spo2: 99 }
    assessment_notes TEXT NOT NULL,
    plan_therapy TEXT NOT NULL,
    prescriptions JSONB DEFAULT '[]'::jsonb NOT NULL,

    is_locked BOOLEAN DEFAULT FALSE NOT NULL, -- Finalized/signed EHR cannot be modified
    locked_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL,

    CONSTRAINT fk_mr_visit FOREIGN KEY (visit_id)
        REFERENCES visits(id) ON DELETE RESTRICT,
    CONSTRAINT fk_mr_patient FOREIGN KEY (patient_id)
        REFERENCES patients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_mr_doctor FOREIGN KEY (doctor_id)
        REFERENCES doctors(id) ON DELETE RESTRICT,
    CONSTRAINT fk_mr_icd10 FOREIGN KEY (primary_icd10_code)
        REFERENCES icd10_codes(code) ON DELETE RESTRICT
);

-- Deep JSONB Vital Signs Indexing
CREATE INDEX IF NOT EXISTS idx_mr_vitals_gin ON medical_records USING gin (objective_vital_signs);
CREATE INDEX IF NOT EXISTS idx_mr_patient_history ON medical_records (patient_id, created_at DESC);
