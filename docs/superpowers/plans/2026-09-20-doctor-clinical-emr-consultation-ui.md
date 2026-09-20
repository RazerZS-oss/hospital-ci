# Doctor Clinical EMR Consultation UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an interactive, clinical-grade Doctor Consultation and Polyclinic Queue UI in CodeIgniter 3 and PostgreSQL 15, connecting the waiting queue to a full-page SOAP examination desk with digital record locking.

**Architecture:** Two-page clinical workflow: `GET /emr` renders the doctor's polyclinic waiting queue with patient status management (`WAITING` -> `CALLED`), and `GET /emr/consult/{visit_id}` renders a dedicated SOAP consultation desk featuring patient demographics, vital signs, preloaded ICD-10 diagnosis picker, dynamic multi-item prescription formulation, and AJAX digital lock finalization via `POST /emr/save`.

**Tech Stack:** PHP 8.4-FPM, CodeIgniter 3.1.13, PostgreSQL 15, Vanilla JavaScript, HTML5/CSS3.

**Spec:** [`docs/superpowers/specs/2026-09-20-doctor-clinical-emr-consultation-ui-design.md`](file:///Users/roy/project/ci-project/docs/superpowers/specs/2026-09-20-doctor-clinical-emr-consultation-ui-design.md)

## Global Constraints
- CodeIgniter 3.1.13 syntax compatibility with PHP 8.4 (using `#[AllowDynamicProperties]` where necessary).
- All `/emr/*` routes must remain protected under `Rbac_hook.php` for `doctor` and `admin` roles.
- Form submissions must supply CSRF tokens (`hmis_csrf_token`).
- Database mutations must comply with existing HIPAA immutable `audit_logs` rules.
- Pure vanilla JavaScript for client-side dynamics (no external CDNs or heavy frameworks).

---

### Task 1: Routes & Controller Backend Methods for Queue & Consultation

**Files:**
- Modify: `application/config/routes.php`
- Modify: `application/controllers/Emr.php`
- Test: `test_app_e2e.py`

**Interfaces:**
- Consumes: `visits`, `patients`, `doctors`, `polyclinics`, `icd10_codes` PostgreSQL tables.
- Produces: 
  - `Emr::index()` rendering `emr/queue` view with `$visits`, `$doctor`, `$polyclinic`.
  - `Emr::call_patient()` returning JSON `{"status": "success", "queue_status": "CALLED"}`.
  - `Emr::consultation(string $visit_id)` rendering `emr/consult` view with `$visit`, `$patient`, `$icd10_list`, `$csrf`.

- [ ] **Step 1: Update routes in `application/config/routes.php`**

Add the routing definitions for the consultation workflow:
```php
$route['emr']                      = 'emr/index';
$route['emr/call']                 = 'emr/call_patient';
$route['emr/consult/(:any)']       = 'emr/consultation/$1';
```

- [ ] **Step 2: Add `index`, `call_patient`, and `consultation` methods to `Emr.php`**

Implement:
1. `index()`:
   - Check authentication session (`isLoggedIn`).
   - Identify active doctor (from session `id`/`user_id` or query `doctors` table).
   - Fetch today's visits for this doctor/polyclinic where `queue_status IN ('WAITING', 'CALLED', 'IN_CONSULTATION')` sorted by `queue_number ASC`.
   - Load view `emr/queue`.
2. `call_patient()`:
   - Accept POST `visit_id`.
   - Update `visits` table set `queue_status = 'CALLED'` where `id = $visit_id` and `queue_status = 'WAITING'`.
   - Return JSON response `{ status: 'success', message: 'Patient called.' }`.
3. `consultation(string $visit_id)`:
   - Fetch visit details joined with `patients` and `polyclinics`.
   - If not found, redirect to `/emr` with error.
   - If `queue_status === 'COMPLETED'`, redirect to `/emr` with conflict notice.
   - If `queue_status === 'WAITING' || queue_status === 'CALLED'`, update to `'IN_CONSULTATION'`.
   - Fetch active ICD-10 list: `SELECT code, description_en, category FROM icd10_codes WHERE is_active = TRUE ORDER BY code ASC`.
   - Load view `emr/consult`.

- [ ] **Step 3: Verify controller syntax and database queries**

Run PHP CLI syntax check:
```bash
docker compose exec app php -l /var/www/html/application/controllers/Emr.php
```
Expected: `No syntax errors detected in /var/www/html/application/controllers/Emr.php`

- [ ] **Step 4: Commit**

```bash
git add application/config/routes.php application/controllers/Emr.php
git commit -m "feat(emr): add queue and consultation controller methods"
```

---

### Task 2: Polyclinic Waiting Queue View Template

**Files:**
- Create: `application/views/emr/queue.php`
- Modify: `application/views/dashboard.php`

**Interfaces:**
- Consumes: `$doctor`, `$polyclinic`, `$visits` array passed from `Emr::index()`.
- Produces: Polyclinic Waiting Queue UI with interactive Call and Examine actions.

- [ ] **Step 1: Create `application/views/emr/queue.php`**

Write the responsive queue management interface:
- Navbar linking to Dashboard, Patients, Doctors, EMR Queue, and Logout.
- Doctor status header: "Poliklinik Spesialis" + Doctor Full Name.
- Live Queue Table:
  - Columns: Queue #, Visit Code, Patient Name & MRN, Gender & Age, Queue Status badge.
  - Action buttons:
    - Button "Panggil (Call)" with `onclick="callPatient('<?= $v['id'] ?>')"` sending AJAX `POST /emr/call`.
    - Button "Periksa (Examine)" with `href="<?= base_url('emr/consult/' . $v['id']) ?>"` styled with primary blue badge.
  - Empty state when all patients for the day have been completed.
- Vanilla JavaScript function `callPatient(visitId)` updating the row badge smoothly without full page refresh.

- [ ] **Step 2: Update `application/views/dashboard.php` with direct EMR Navigation**

Add an "EMR Clinical Queue" navigation link in the top navbar and an actionable quick-card in the dashboard grid for Doctors:
```html
<div class="stat-card" style="border-left-color: #8b5cf6;">
    <div class="stat-title">Clinical Workspace</div>
    <div class="stat-number">EMR</div>
    <div style="margin-top: 15px;">
        <a href="<?= base_url('emr') ?>" class="btn btn-primary" style="background: #8b5cf6; font-size: 12px; padding: 6px 12px;">Doctor Queue &rarr;</a>
    </div>
</div>
```

- [ ] **Step 3: Verify rendering via CLI / cURL**

Test with cURL from doctor session:
```bash
python3 -c "import urllib.request; print(urllib.request.urlopen('http://localhost:8081/login').status)"
```

- [ ] **Step 4: Commit**

```bash
git add application/views/emr/queue.php application/views/dashboard.php
git commit -m "feat(emr): add polyclinic waiting queue view template"
```

---

### Task 3: Clinical SOAP Consultation View Template

**Files:**
- Create: `application/views/emr/consult.php`

**Interfaces:**
- Consumes: `$visit`, `$patient`, `$doctor`, `$icd10_list`, CSRF token from `Emr::consultation()`.
- Produces: SOAP consultation form dispatching to `POST /emr/save`.

- [ ] **Step 1: Create `application/views/emr/consult.php`**

Implement the full clinical consultation desk:
1. **Patient Banner Card**:
   - Patient Name, Medical Record Number (`$patient['medical_record_number']`), Date of Birth, Gender, Blood Type, Queue Ticket Number (`#<?= $visit['queue_number'] ?>`), Visit Code (`<?= $visit['visit_number'] ?>`).
2. **Clinical SOAP Form**:
   - Hidden inputs: `visit_id`, `patient_id`, `doctor_id`, and `hmis_csrf_token`.
   - **S (Subjective)**: Textarea `subjective_complaints` (placeholder: "Keluhan utama pasien, riwayat penyakit sekarang...").
   - **O (Objective - Vital Signs)**:
     - 5 input fields in a flex/grid row:
       - Tekanan Darah (`vital_bp`, default `120/80`)
       - Nadi (`vital_hr`, default `75 bpm`)
       - Suhu Tubuh (`vital_temp`, default `36.5 °C`)
       - Laju Pernapasan (`vital_rr`, default `18 x/menit`)
       - SpO2 (`vital_spo2`, default `98 %`)
   - **A (Assessment)**:
     - Dropdown `<select id="primary_icd10_code" name="primary_icd10_code">` preloaded with `$icd10_list` options (`code` - `description_en`).
     - Textarea `assessment_notes` for clinical evaluation and diagnosis notes.
   - **P (Plan & Prescriptions)**:
     - Textarea `plan_therapy` for non-pharmacological instructions & treatment notes.
     - Dynamic Multi-Row Prescription Table:
       - Table with headers: `Nama Obat (Drug Name)`, `Dosis / Sediaan`, `Jumlah (Qty)`, `Signa / Aturan Pakai`, `Aksi`.
       - Button `+ Tambah Resep (+ Add Medication)`.
3. **Vanilla JavaScript Client**:
   - `addPrescriptionRow()` appending a new row with delete action.
   - Form submit interceptor: gathers vitals and prescriptions into structured JSON, sends `POST /emr/save` with CSRF headers, handles response codes:
     - HTTP 201: Displays digital lock confirmation modal and redirects to `/emr`.
     - HTTP 422: Displays validation error messages inline.
     - HTTP 409 / 500: Displays alert banner.

- [ ] **Step 2: Syntax and template sanity check**

```bash
docker compose exec app php -l /var/www/html/application/views/emr/consult.php
```
Expected: `No syntax errors detected in /var/www/html/application/views/emr/consult.php`

- [ ] **Step 3: Commit**

```bash
git add application/views/emr/consult.php
git commit -m "feat(emr): create clinical SOAP consultation view template"
```

---

### Task 4: End-to-End Automated Verification Suite

**Files:**
- Modify: `test_app_e2e.py`

**Interfaces:**
- Consumes: EMR consultation endpoints, visits, and database records.
- Produces: Automated test pass certifying complete clinical workflow.

- [ ] **Step 1: Add new test functions to `test_app_e2e.py`**

Add the following end-to-end tests:
1. `test_doctor_emr_queue_page()`: Authenticates as doctor (`drbudi`), requests `GET /emr`, asserts HTTP 200, asserts "Polyclinic Queue" or "Antrean Poliklinik" in HTML.
2. `test_doctor_emr_call_patient()`: Calls `POST /emr/call` for a test waiting visit, asserts HTTP 200 and status `CALLED`.
3. `test_doctor_emr_consult_page()`: Requests `GET /emr/consult/{visit_id}`, asserts HTTP 200, patient MRN present in banner, ICD-10 select options present.
4. `test_doctor_emr_finalize_and_lock()`: Posts complete SOAP payload with vitals and 2 prescription items to `/emr/save`, asserts HTTP 201, verifies database records:
   - `visits.queue_status = 'COMPLETED'`
   - `medical_records.is_locked = true`
   - `audit_logs` has `INSERT` action for `medical_records`.

- [ ] **Step 2: Execute test suite inside Docker environment**

Run the complete test suite:
```bash
python3 test_app_e2e.py
```
Expected: All tests pass with exit code 0 and `🎉 ALL ADVANCED HMIS TESTS PASSED PERFECTLY!`.

- [ ] **Step 3: Commit**

```bash
git add test_app_e2e.py
git commit -m "test(emr): add end-to-end automated verification for doctor consultation workflow"
```
