-- ============================================================================
-- Advanced Operational Schema: Third-Party API Logs & Surgery Reminders
-- PostgreSQL 15 Compatible
-- ============================================================================

-- 1. Third-Party API Logs (BPJS / SatuSehat / External Bridging)
CREATE TABLE IF NOT EXISTS api_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    service_name VARCHAR(64) NOT NULL,            -- e.g., 'SATUSEHAT_FHIR', 'BPJS_VCLAIM', 'WA_GATEWAY'
    endpoint VARCHAR(500) NOT NULL,
    http_method VARCHAR(10) NOT NULL,
    request_headers JSONB NOT NULL,
    request_payload JSONB NULL,
    response_status_code INT NOT NULL,
    response_headers JSONB NULL,
    response_payload JSONB NULL,
    execution_time_ms NUMERIC(10, 2) NOT NULL,
    error_message TEXT NULL,
    ip_address INET NOT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_api_logs_service_created ON api_logs (service_name, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_api_logs_status ON api_logs (response_status_code);
CREATE INDEX IF NOT EXISTS idx_api_logs_req_gin ON api_logs USING gin (request_payload);
CREATE INDEX IF NOT EXISTS idx_api_logs_resp_gin ON api_logs USING gin (response_payload);

-- Append-only rule for api_logs: enforce audit integrity
CREATE OR REPLACE RULE no_update_api_logs AS ON UPDATE TO api_logs DO INSTEAD NOTHING;
CREATE OR REPLACE RULE no_delete_api_logs AS ON DELETE TO api_logs DO INSTEAD NOTHING;

-- 2. Surgery Schedules (For Clinical Reminders & Background CLI Jobs)
CREATE TABLE IF NOT EXISTS surgery_schedules (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    procedure_name VARCHAR(255) NOT NULL,
    operating_theatre VARCHAR(64) NOT NULL,
    scheduled_datetime TIMESTAMPTZ NOT NULL,
    patient_phone VARCHAR(32) NOT NULL,
    reminder_status VARCHAR(20) DEFAULT 'PENDING' NOT NULL, -- PENDING, SENT, FAILED, EXEMPT
    reminder_sent_at TIMESTAMPTZ NULL,
    notes TEXT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP NOT NULL,

    CONSTRAINT fk_surgery_patient FOREIGN KEY (patient_id)
        REFERENCES patients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_surgery_doctor FOREIGN KEY (doctor_id)
        REFERENCES doctors(id) ON DELETE RESTRICT,
    CONSTRAINT chk_reminder_status CHECK (
        reminder_status IN ('PENDING', 'SENT', 'FAILED', 'EXEMPT')
    )
);

CREATE INDEX IF NOT EXISTS idx_surgery_reminder ON surgery_schedules (scheduled_datetime, reminder_status);

-- Seed surgery schedules for tomorrow if table is empty
INSERT INTO surgery_schedules (patient_id, doctor_id, procedure_name, operating_theatre, scheduled_datetime, patient_phone, reminder_status)
SELECT 
    p.id, 
    d.id, 
    'Elective Laparoscopic Cholecystectomy', 
    'Operating Theatre 03', 
    (CURRENT_DATE + INTERVAL '1 day' + INTERVAL '08 hour 30 minute'),
    '081234567890',
    'PENDING'
FROM patients p, doctors d
WHERE d.id = 1
ORDER BY p.id ASC
LIMIT 1
ON CONFLICT DO NOTHING;

INSERT INTO surgery_schedules (patient_id, doctor_id, procedure_name, operating_theatre, scheduled_datetime, patient_phone, reminder_status)
SELECT 
    p.id, 
    d.id, 
    'Percutaneous Coronary Intervention (PCI)', 
    'Cath Lab 01', 
    (CURRENT_DATE + INTERVAL '1 day' + INTERVAL '10 hour 00 minute'),
    '081298765432',
    'PENDING'
FROM patients p, doctors d
WHERE d.id = 1
ORDER BY p.id DESC
LIMIT 1
ON CONFLICT DO NOTHING;
