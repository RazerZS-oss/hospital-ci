# Design Specification: Doctor Clinical EMR Consultation UI & Queue Workflow

## 1. Overview
This specification details the end-to-end clinical workflow for physicians using the Central Hospital Information Management System (HMIS). It transitions the current headless EMR backend into an interactive, clinical-grade consultation interface featuring a two-page workflow:
1. **Polyclinic Waiting Queue**: A doctor queue management screen displaying waiting and called patients.
2. **Clinical Consultation Workspace**: A comprehensive SOAP examination page enabling anamnesis recording, vital signs entry, preloaded ICD-10 diagnosis selection, multi-item prescription formulation, and immutable digital record locking.

---

## 2. Architecture & Routing

### 2.1 HTTP Endpoints

| Method | URI | Controller Action | Description | Access Control |
|---|---|---|---|---|
| `GET` | `/emr` | `Emr::index` | Displays active outpatient queue for the doctor's polyclinic | `doctor`, `admin` |
| `POST` | `/emr/call` | `Emr::call_patient` | Transitions a visit status from `WAITING` to `CALLED` | `doctor`, `admin` |
| `GET` | `/emr/consult/{visit_id}` | `Emr::consultation` | Full-page clinical SOAP examination form | `doctor`, `admin` |
| `POST` | `/emr/save` | `Emr::save` | Validates, persists, signs, and locks the medical record | `doctor`, `admin` |
| `GET` | `/emr/data` | `Emr::data` | Server-side DataTables provider for EMR archives | `doctor`, `admin` |

### 2.2 RBAC & Security Guard
- Governed by `application/hooks/Rbac_hook.php`.
- Requests from unauthenticated users redirect to `/login`.
- Requests from non-authorized roles (e.g. `receptionist`) return HTTP 403 Forbidden and record an immutable `DENIED` entry in `audit_logs`.
- All form submissions require valid CSRF tokens (`hmis_csrf_token`).

---

## 3. UI Components & Detailed Specifications

### 3.1 Doctor Queue Screen (`application/views/emr/queue.php`)
- **Doctor Context Header**: Displays the logged-in physician's name, specialization, and current active date.
- **Queue Table**:
  - Columns:
    - **Queue #**: Integer queue ticket sequence (e.g. `1`, `2`, `3`).
    - **Visit Code**: Unique identifier (e.g. `VIS-20260920-0001`).
    - **Patient**: Full Name and Medical Record Number (MRN).
    - **Demographics**: Gender and calculated age from Date of Birth.
    - **Status Badge**:
      - `WAITING` (Yellow/Warning)
      - `CALLED` (Blue/Info)
      - `IN_CONSULTATION` (Purple/Primary)
    - **Actions**:
      - `Panggil` (Call Patient): Triggers AJAX request to `/emr/call` to notify/mark patient as called.
      - `Periksa` (Examine): Navigation button opening `/emr/consult/{visit_id}`.

### 3.2 Clinical Consultation Desk (`application/views/emr/consult.php`)
- **Patient Demographic Header**:
  - Sticky/highlighted banner showing: Patient Name, MRN, Gender, Age/DOB, Blood Type, Queue Ticket #, and Visit Code.
- **Clinical SOAP Sections**:
  - **S (Subjective - Anamnesis)**:
    - Textarea for chief complaints, symptom duration, and clinical history (`subjective_complaints`).
  - **O (Objective - Vital Signs)**:
    - Grid of 5 standardized clinical vitals:
      - Blood Pressure (`vital_bp`, e.g., `120/80 mmHg`)
      - Heart Rate (`vital_hr`, e.g., `78 bpm`)
      - Body Temperature (`vital_temp`, e.g., `36.8 °C`)
      - Respiratory Rate (`vital_rr`, e.g., `18 breaths/min`)
      - Oxygen Saturation (`vital_spo2`, e.g., `99 %`)
  - **A (Assessment - Diagnosis)**:
    - Primary ICD-10 Diagnosis Dropdown (`primary_icd10_code`):
      - Preloaded directly from `icd10_codes` database table (e.g., `I10 - Essential (primary) hypertension`, `E11.9 - Type 2 diabetes mellitus`, `J06.9 - Acute upper respiratory infection`).
    - Clinical Assessment & Differential Diagnosis notes (`assessment_notes`).
  - **P (Plan & Prescriptions)**:
    - Non-pharmacological and clinical therapy plan notes (`plan_therapy`).
    - **Dynamic Prescription Item Table**:
      - Columns: Drug Name (`drug_name`), Dosage / Strength (`strength`), Quantity (`qty`), Signa / Instructions (`dosage`), and Remove button.
      - `+ Tambah Resep` button: Adds a new item row dynamically via JavaScript.
- **Action Buttons & Submission Handling**:
  - "Kembali ke Antrean" (Back to Queue) button with unsaved change confirmation.
  - "Simpan & Kunci Rekam Medis" (Finalize & Lock EMR) primary button.
  - Client-side validation for required fields before dispatching.
  - Submits JSON payload to `POST /emr/save` via AJAX.
  - Upon HTTP 201 response:
    - Shows digital signature badge.
    - Disables inputs to prevent duplicate entry.
    - Prompts success alert and redirects to `/emr`.

---

## 4. Database Interactions & State Transitions

1. When doctor clicks "Panggil" (Call):
   - Updates `visits.queue_status = 'CALLED'` where `id = :visit_id`.
2. When doctor opens `/emr/consult/{visit_id}`:
   - If visit is currently `CALLED` or `WAITING`, updates `visits.queue_status = 'IN_CONSULTATION'`.
3. When doctor submits SOAP consultation:
   - Inserts record into `medical_records` with `is_locked = TRUE`.
   - Updates `visits.queue_status = 'COMPLETED'` and `visits.completed_at = CURRENT_TIMESTAMP`.
   - Inserts `audit_logs` record for HIPAA compliance.

---

## 5. Error Handling & Validation
- **404 Not Found**: If visit UUID does not exist, redirects to `/emr` with error flash message.
- **409 Conflict**: If visit is already `COMPLETED`, renders view in read-only mode or redirects with warning.
- **422 Unprocessable Entity**: If any required field is missing (empty anamnesis, invalid ICD-10 code), returns clear field error alerts.

---

## 6. Verification & Automated Testing Plan
Extend `test_app_e2e.py` with the following automated assertions:
1. `test_emr_queue_view`: Authenticates as doctor, requests `GET /emr`, verifies status 200 and table headers.
2. `test_emr_call_patient`: Submits `POST /emr/call` for a waiting visit, verifies queue status updated to `CALLED`.
3. `test_emr_consult_view`: Requests `GET /emr/consult/{visit_id}`, verifies patient demographic banner, ICD-10 options populated, and SOAP form rendered.
4. `test_emr_full_submission_and_lock`: Submits consultation form with vitals and multi-item prescriptions, verifies:
   - HTTP 201 status code
   - `visits.queue_status` is `COMPLETED`
   - `medical_records.is_locked` is `TRUE`
   - `audit_logs` has an `INSERT` entry for `medical_records`.
