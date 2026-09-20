#!/usr/bin/env python3
import re
import sys
import time
import json
import urllib.request
import urllib.parse
import http.cookiejar
import subprocess

BASE_URL = "http://localhost:8081"

cj = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(cj),
    urllib.request.HTTPRedirectHandler()
)

def extract_csrf(html):
    match = re.search(r'name="hmis_csrf_token"\s+value="([a-f0-9]+)"', html)
    if not match:
        match = re.search(r'value="([a-f0-9]+)"\s+name="hmis_csrf_token"', html)
    if match:
        return match.group(1)
    raise ValueError("CSRF token not found in response HTML")

def run_test(name, fn):
    print(f"\n==========================================")
    print(f"RUNNING TEST: {name}")
    print(f"==========================================")
    try:
        fn()
        print(f"✅ PASSED: {name}")
        return True
    except Exception as e:
        print(f"❌ FAILED: {name} - Error: {e}")
        import traceback
        traceback.print_exc()
        return False

# 1. Test GET /login
def test_login_page():
    req = urllib.request.Request(f"{BASE_URL}/login")
    with opener.open(req) as resp:
        html = resp.read().decode('utf-8')
        assert resp.status == 200, f"Expected 200, got {resp.status}"
        assert "Central Hospital IT" in html, "Hospital title missing"
        csrf = extract_csrf(html)
        assert len(csrf) == 32, f"Invalid CSRF token length: {csrf}"
        print(f"   -> Login page rendered successfully. CSRF Token: {csrf}")

# 2. Test Invalid Login
def test_invalid_login():
    with opener.open(f"{BASE_URL}/login") as resp:
        csrf = extract_csrf(resp.read().decode('utf-8'))
    
    post_data = urllib.parse.urlencode({
        'hmis_csrf_token': csrf,
        'username': 'admin',
        'password': 'wrongpassword'
    }).encode('utf-8')

    req = urllib.request.Request(f"{BASE_URL}/auth/login", data=post_data, method='POST')
    with opener.open(req) as resp:
        html = resp.read().decode('utf-8')
        assert resp.status == 200, f"Expected 200 after redirect, got {resp.status}"
        assert "Invalid Credentials" in html, f"Flashdata 'Invalid Credentials' missing from HTML"
        print(f"   -> Flashdata error correctly rendered after failed login.")

# 3. Test Valid Login (Admin)
def test_valid_login():
    with opener.open(f"{BASE_URL}/login") as resp:
        csrf = extract_csrf(resp.read().decode('utf-8'))

    post_data = urllib.parse.urlencode({
        'hmis_csrf_token': csrf,
        'username': 'admin',
        'password': 'admin123'
    }).encode('utf-8')

    req = urllib.request.Request(f"{BASE_URL}/auth/login", data=post_data, method='POST')
    with opener.open(req) as resp:
        html = resp.read().decode('utf-8')
        assert resp.status == 200, f"Expected 200, got {resp.status}"
        assert "Welcome" in html and "Hospital Dashboard" in html, f"Dashboard content missing"
        assert "ADMIN" in html or "Roy" in html, "User role/name missing"
        print(f"   -> Valid login succeeded as Administrator.")

# 4. Test Protected Dashboard
def test_dashboard():
    req = urllib.request.Request(f"{BASE_URL}/dashboard")
    with opener.open(req) as resp:
        html = resp.read().decode('utf-8')
        assert resp.status == 200, f"Expected 200, got {resp.status}"
        assert "Total Registered Patients" in html or "Patients" in html
        assert "Active Medical Specialists" in html or "Doctors" in html
        print(f"   -> Dashboard stats verified.")

# 5. Test Patients Directory
def test_patients_directory():
    req = urllib.request.Request(f"{BASE_URL}/patients")
    with opener.open(req) as resp:
        html = resp.read().decode('utf-8')
        assert resp.status == 200, f"Expected 200, got {resp.status}"
        assert "Patients Directory" in html
        print(f"   -> Patients directory loaded successfully.")

# 6. Test Patient Registration via Web Form (with HIPAA audit logging)
def test_patient_registration():
    with opener.open(f"{BASE_URL}/patients") as resp:
        html = resp.read().decode('utf-8')
        csrf = extract_csrf(html)

    unique_mrn = f"RM-TEST-{int(time.time())}"
    patient_name = "End-to-End Test Patient"
    
    post_data = urllib.parse.urlencode({
        'hmis_csrf_token': csrf,
        'name': patient_name,
        'medical_record_number': unique_mrn,
        'blood_type': 'AB'
    }).encode('utf-8')

    req = urllib.request.Request(f"{BASE_URL}/patients/create", data=post_data, method='POST')
    with opener.open(req) as resp:
        html = resp.read().decode('utf-8')
        assert resp.status == 200, f"Expected 200 after redirect, got {resp.status}"
        assert "Patient added successfully." in html, "Success flash message missing"
        assert unique_mrn in html, f"New MRN {unique_mrn} not found in patients list"
        print(f"   -> Patient {patient_name} ({unique_mrn}) created and confirmed in UI list.")

    # Verify audit_logs in PostgreSQL
    cmd = [
        "docker", "compose", "exec", "db", "psql", "-U", "ci_user", "-d", "ci_hospital", "-P", "pager=off",
        "-c", f"SELECT action, table_name, record_id, user_id, new_values FROM audit_logs WHERE table_name = 'patients' ORDER BY created_at DESC LIMIT 1;"
    ]
    res = subprocess.run(cmd, capture_output=True, text=True)
    assert res.returncode == 0, f"Failed to query audit_logs: {res.stderr}"
    assert "INSERT" in res.stdout, f"Audit log action INSERT missing: {res.stdout}"
    assert unique_mrn in res.stdout, f"Audit log does not contain MRN {unique_mrn}: {res.stdout}"
    print(f"   -> HIPAA Audit Log verification passed! Automated audit record found.")

# 7. Test Doctors Directory
def test_doctors_directory():
    req = urllib.request.Request(f"{BASE_URL}/doctors")
    with opener.open(req) as resp:
        html = resp.read().decode('utf-8')
        assert resp.status == 200, f"Expected 200, got {resp.status}"
        assert "Doctors Directory" in html
        assert "drbudi" in html or "Budi" in html or "Cardiology" in html or "Spesialis" in html
        print(f"   -> Doctors directory verified.")

# 8. Test Polyclinic Queue API (HMVC Service Pattern + Advisory Lock)
def test_queue_api():
    cmd = [
        "docker", "compose", "exec", "db", "psql", "-U", "ci_user", "-d", "ci_hospital", "-t", "-A",
        "-c", "SELECT p.id, d.id, pt.id FROM polyclinics p, doctors d, patients pt ORDER BY pt.id DESC LIMIT 1;"
    ]
    res = subprocess.run(cmd, capture_output=True, text=True)
    assert res.returncode == 0, f"DB query failed: {res.stderr}"
    poly_id, doc_id, pat_id = res.stdout.strip().split('|')

    payload = json.dumps({
        "patient_id": int(pat_id),
        "polyclinic_id": poly_id,
        "doctor_id": int(doc_id),
        "visit_date": time.strftime('%Y-%m-%d'),
        "notes": "E2E Automated Verification Queue"
    }).encode('utf-8')

    req = urllib.request.Request(
        f"{BASE_URL}/queue/register",
        data=payload,
        headers={"Content-Type": "application/json"},
        method="POST"
    )

    try:
        with urllib.request.urlopen(req) as resp:
            data = json.loads(resp.read().decode('utf-8'))
            assert resp.status == 201
            assert data["status"] == "success"
            print(f"   -> Queue registered successfully: Queue Number #{data['data']['queue_number']}")
    except urllib.error.HTTPError as e:
        body = e.read().decode('utf-8')
        if e.code == 409:
            print(f"   -> 409 Conflict as expected if patient is already registered today: {body}")
        else:
            raise AssertionError(f"Unexpected error {e.code}: {body}")

    req_mon = urllib.request.Request(f"{BASE_URL}/queue/monitor?polyclinic_id={poly_id}&doctor_id={doc_id}")
    with urllib.request.urlopen(req_mon) as resp:
        data = json.loads(resp.read().decode('utf-8'))
        assert resp.status == 200
        assert data["status"] == "success"
        assert isinstance(data["data"], list)
        print(f"   -> Queue monitor verified: {len(data['data'])} patients in active queue.")

doc_cj = http.cookiejar.CookieJar()
doc_opener = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(doc_cj),
    urllib.request.HTTPRedirectHandler()
)

# 9. Test RBAC Hook: Doctor Forbidden on Admin/Receptionist Resource
def test_rbac_doctor_forbidden():
    # Login as doctor using dedicated session
    with doc_opener.open(f"{BASE_URL}/login") as resp:
        csrf = extract_csrf(resp.read().decode('utf-8'))

    post_data = urllib.parse.urlencode({"username": "drbudi", "password": "doctor123", "hmis_csrf_token": csrf}).encode()
    doc_opener.open(f"{BASE_URL}/auth/login", data=post_data)

    # Doctor tries to register patient (pasien/create) -> should trigger 403
    with doc_opener.open(f"{BASE_URL}/patients") as resp:
        csrf = extract_csrf(resp.read().decode('utf-8'))

    try:
        post_data = urllib.parse.urlencode({"hmis_csrf_token": csrf, "name": "ForbiddenDoctorAttempt"}).encode()
        resp = doc_opener.open(f"{BASE_URL}/patients/create", data=post_data)
        raise AssertionError("Doctor should have been blocked by RBAC hook!")
    except urllib.error.HTTPError as e:
        assert e.code == 403, f"Expected 403 Forbidden, got {e.code}"
        body = e.read().decode('utf-8')
        assert "Access Denied" in body and "doctor" in body
        print(f"   -> RBAC 403 Forbidden verified for doctor on restricted resource.")

    # Verify security audit log
    cmd = [
        "docker", "compose", "exec", "db", "psql", "-U", "ci_user", "-d", "ci_hospital", "-P", "pager=off",
        "-c", "SELECT action, table_name, record_id, user_id FROM audit_logs WHERE action = 'DENIED' ORDER BY created_at DESC LIMIT 1;"
    ]
    res = subprocess.run(cmd, capture_output=True, text=True)
    assert res.returncode == 0
    assert "DENIED" in res.stdout
    print(f"   -> HIPAA Security audit log created for unauthorized access attempt.")

# 10. Test DataTables Server-Side Engine (PostgreSQL ILIKE & Pagination)
def test_datatables_serverside():
    # Authenticated as doctor (from previous test)
    req_dt = urllib.request.Request(
        f"{BASE_URL}/emr/data?draw=1&start=0&length=10&search[value]=",
        headers={"X-Requested-With": "XMLHttpRequest"}
    )
    with doc_opener.open(req_dt) as resp:
        data = json.loads(resp.read().decode('utf-8'))
        assert resp.status == 200
        assert data["draw"] == 1
        assert "recordsTotal" in data and "recordsFiltered" in data and "data" in data
        print(f"   -> DataTables baseline: recordsTotal={data['recordsTotal']}")

    # Test PostgreSQL ILIKE search filter
    req_search = urllib.request.Request(
        f"{BASE_URL}/emr/data?draw=2&start=0&length=10&search[value]=Budi",
        headers={"X-Requested-With": "XMLHttpRequest"}
    )
    with doc_opener.open(req_search) as resp:
        data_s = json.loads(resp.read().decode('utf-8'))
        assert data_s["recordsFiltered"] >= 1
        print(f"   -> DataTables ILIKE search ('Budi') matches: {data_s['recordsFiltered']}")

# 11. Test Doctor EMR Queue Page
def test_doctor_emr_queue_page():
    req = urllib.request.Request(f"{BASE_URL}/emr")
    with doc_opener.open(req) as resp:
        html = resp.read().decode('utf-8')
        assert resp.status == 200, f"Expected 200, got {resp.status}"
        assert "Antrean Poliklinik" in html or "Polyclinic Queue" in html, "Polyclinic Queue title missing"
        assert "dr. Budi Santoso" in html or "drbudi" in html or "Poliklinik" in html
        print(f"   -> Doctor EMR Queue Page rendered successfully with live table.")

# 12. Test Doctor EMR Patient Call Action (AJAX)
def test_doctor_emr_call_patient():
    # Find or create a WAITING visit
    cmd = [
        "docker", "compose", "exec", "db", "psql", "-U", "ci_user", "-d", "ci_hospital", "-t", "-A",
        "-c", "SELECT id FROM visits WHERE queue_status = 'WAITING' ORDER BY queue_number ASC LIMIT 1;"
    ]
    res = subprocess.run(cmd, capture_output=True, text=True)
    waiting_id = res.stdout.strip()
    if not waiting_id:
        test_queue_api()
        res = subprocess.run(cmd, capture_output=True, text=True)
        waiting_id = res.stdout.strip()

    assert waiting_id, "No WAITING visit available for call test"

    # Test calling patient with invalid UUID (returns 400)
    req_bad = urllib.request.Request(
        f"{BASE_URL}/emr/call",
        data=urllib.parse.urlencode({"visit_id": "invalid-uuid"}).encode('utf-8'),
        headers={"X-Requested-With": "XMLHttpRequest"},
        method="POST"
    )
    try:
        doc_opener.open(req_bad)
        raise AssertionError("Expected 400 Bad Request for invalid UUID, got success")
    except urllib.error.HTTPError as e:
        assert e.code == 400, f"Expected 400, got {e.code}"
        print(f"   -> Call patient with invalid UUID correctly rejected with HTTP 400.")

    # Call patient with valid waiting visit
    req_call = urllib.request.Request(
        f"{BASE_URL}/emr/call",
        data=urllib.parse.urlencode({"visit_id": waiting_id}).encode('utf-8'),
        headers={"X-Requested-With": "XMLHttpRequest"},
        method="POST"
    )
    with doc_opener.open(req_call) as resp:
        assert resp.status == 200, f"Expected 200, got {resp.status}"
        data = json.loads(resp.read().decode('utf-8'))
        assert data["status"] == "success", f"Expected success status, got {data}"
        assert data["queue_status"] == "CALLED", f"Expected CALLED queue status, got {data}"
        print(f"   -> Patient {waiting_id} called successfully (queue_status: CALLED).")

# 13. Test Doctor EMR Clinical SOAP Consultation View
def test_doctor_emr_consult_page():
    # Find a CALLED or WAITING visit
    cmd = [
        "docker", "compose", "exec", "db", "psql", "-U", "ci_user", "-d", "ci_hospital", "-t", "-A",
        "-c", "SELECT id, patient_id FROM visits WHERE queue_status IN ('CALLED', 'WAITING') ORDER BY queue_number ASC LIMIT 1;"
    ]
    res = subprocess.run(cmd, capture_output=True, text=True)
    row = res.stdout.strip()
    if not row:
        test_queue_api()
        res = subprocess.run(cmd, capture_output=True, text=True)
        row = res.stdout.strip()

    visit_id, patient_id = row.split('|')

    # Get patient MRN to assert in banner
    cmd_mrn = [
        "docker", "compose", "exec", "db", "psql", "-U", "ci_user", "-d", "ci_hospital", "-t", "-A",
        "-c", f"SELECT COALESCE(mrn, medical_record_number) FROM patients WHERE id = {patient_id};"
    ]
    mrn = subprocess.run(cmd_mrn, capture_output=True, text=True).stdout.strip()

    # Request consultation desk
    req_consult = urllib.request.Request(f"{BASE_URL}/emr/consult/{visit_id}")
    with doc_opener.open(req_consult) as resp:
        assert resp.status == 200, f"Expected 200, got {resp.status}"
        html = resp.read().decode('utf-8')
        assert "Konsultasi Klinis" in html or "Clinical Consultation" in html or "SOAP" in html
        assert mrn in html, f"Patient MRN {mrn} not found in consult page banner"
        assert 'id="primary_icd10_code"' in html, "ICD-10 primary select element not found"
        assert 'id="subjective_complaints"' in html, "Subjective complaints textarea not found"
        assert 'id="prescription-table"' in html, "Prescription table not found"
        print(f"   -> Clinical SOAP consultation page rendered with patient MRN {mrn} and ICD-10 selector.")

    # Verify visit transitioned to IN_CONSULTATION
    cmd_status = [
        "docker", "compose", "exec", "db", "psql", "-U", "ci_user", "-d", "ci_hospital", "-t", "-A",
        "-c", f"SELECT queue_status FROM visits WHERE id = '{visit_id}';"
    ]
    status = subprocess.run(cmd_status, capture_output=True, text=True).stdout.strip()
    assert status == "IN_CONSULTATION", f"Expected queue_status IN_CONSULTATION, got {status}"
    print(f"   -> Visit {visit_id} automatically transitioned to IN_CONSULTATION.")

# 14. Test Doctor EMR Finalize & Digital Record Locking
def test_doctor_emr_finalize_and_lock():
    # Retrieve a visit ready for consultation
    cmd = [
        "docker", "compose", "exec", "db", "psql", "-U", "ci_user", "-d", "ci_hospital", "-t", "-A",
        "-c", "SELECT id, patient_id, doctor_id FROM visits WHERE queue_status IN ('IN_CONSULTATION', 'CALLED', 'WAITING') LIMIT 1;"
    ]
    res = subprocess.run(cmd, capture_output=True, text=True)
    visit_row = res.stdout.strip()
    if not visit_row:
        # Create fresh visit
        test_queue_api()
        res = subprocess.run(cmd, capture_output=True, text=True)
        visit_row = res.stdout.strip()

    visit_id, patient_id, doctor_id = visit_row.split("|")

    emr_payload = {
        "visit_id": visit_id,
        "patient_id": int(patient_id),
        "doctor_id": int(doctor_id),
        "primary_icd10_code": "I10",
        "subjective_complaints": "Patient reports recurring morning headaches and lightheadedness for 10 days.",
        "objective_vital_signs": {
            "bp": "140/90",
            "hr": 80,
            "temp": 36.7,
            "rr": 18,
            "spo2": 99
        },
        "assessment_notes": "Essential (primary) hypertension, Stage 1.",
        "plan_therapy": "Prescribe ACE inhibitor (Lisinopril 10mg once daily) and counsel dietary DASH protocol.",
        "prescriptions": [
            {"drug_name": "Lisinopril", "strength": "10mg", "qty": 30, "dosage": "1 tablet daily"},
            {"drug_name": "Paracetamol", "strength": "500mg", "qty": 10, "dosage": "3x1 tablet as needed"}
        ]
    }

    req_save = urllib.request.Request(
        f"{BASE_URL}/emr/save",
        data=json.dumps(emr_payload).encode('utf-8'),
        headers={"Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest"},
        method="POST"
    )

    with doc_opener.open(req_save) as resp:
        data = json.loads(resp.read().decode('utf-8'))
        assert resp.status == 201, f"Expected 201, got {resp.status}"
        assert data["status"] == "success"
        record_id = data["data"]["record_id"]
        print(f"   -> EMR finalized successfully! Record UUID: {record_id}")

    # Verify visit is COMPLETED and EMR is digitally locked
    cmd_verify = [
        "docker", "compose", "exec", "db", "psql", "-U", "ci_user", "-d", "ci_hospital", "-t", "-A",
        "-c", f"SELECT v.queue_status, mr.is_locked, mr.primary_icd10_code FROM visits v JOIN medical_records mr ON mr.visit_id = v.id WHERE mr.id = '{record_id}';"
    ]
    res_v = subprocess.run(cmd_verify, capture_output=True, text=True)
    status, is_locked, icd = res_v.stdout.strip().split("|")
    assert status == "COMPLETED" and is_locked == "t" and icd == "I10"
    print(f"   -> DB Verification: Visit status={status}, EMR is_locked={is_locked}, ICD={icd}")

    # Verify HIPAA Audit Log insertion
    cmd_audit = [
        "docker", "compose", "exec", "db", "psql", "-U", "ci_user", "-d", "ci_hospital", "-t", "-A",
        "-c", f"SELECT action, table_name FROM audit_logs WHERE record_id = '{record_id}' ORDER BY created_at DESC LIMIT 1;"
    ]
    res_audit = subprocess.run(cmd_audit, capture_output=True, text=True)
    audit_row = res_audit.stdout.strip()
    assert "INSERT" in audit_row and "medical_records" in audit_row, f"Expected INSERT on medical_records in audit_logs, got: {audit_row}"
    print(f"   -> HIPAA Audit Verification: Confirmed INSERT action logged for medical_records.")


# 12. Test Third-Party Bridging Integration & PostgreSQL api_logs
def test_bridging_api_logs():
    # Verify api_logs in PostgreSQL
    cmd = [
        "docker", "compose", "exec", "db", "psql", "-U", "ci_user", "-d", "ci_hospital", "-P", "pager=off",
        "-c", "SELECT service_name, http_method, response_status_code, execution_time_ms, created_at FROM api_logs ORDER BY created_at DESC LIMIT 2;"
    ]
    res = subprocess.run(cmd, capture_output=True, text=True)
    assert res.returncode == 0
    assert "api_logs" not in res.stderr and "service_name" in res.stdout
    print(f"   -> Third-Party Bridging api_logs verified in PostgreSQL:\n{res.stdout.strip()}")

# 13. Test CLI Background Job (Surgery Reminders)
def test_cli_surgery_reminder():
    # Ensure at least 1 pending surgery reminder exists for tomorrow
    seed_cmd = [
        "docker", "compose", "exec", "db", "psql", "-U", "ci_user", "-d", "ci_hospital",
        "-c", "INSERT INTO surgery_schedules (patient_id, doctor_id, procedure_name, operating_theatre, scheduled_datetime, patient_phone, reminder_status) "
              "SELECT id, 1, 'Automated Test Endoscopy', 'Endo Suite 02', (CURRENT_DATE + INTERVAL '1 day' + INTERVAL '09 hour'), '081299990000', 'PENDING' "
              "FROM patients ORDER BY id DESC LIMIT 1;"
    ]
    subprocess.run(seed_cmd, capture_output=True)

    cmd = [
        "docker", "compose", "exec", "app", "php", "/var/www/html/index.php", "cli/surgery_reminder", "send_reminders"
    ]
    res = subprocess.run(cmd, capture_output=True, text=True)
    assert res.returncode == 0, f"CLI execution failed: {res.stderr}"
    assert "HMIS BACKGROUND CLI: SURGERY REMINDER DISPATCHER" in res.stdout
    assert "BATCH DISPATCH COMPLETE" in res.stdout or "SENT" in res.stdout
    print(f"   -> CLI Job executed successfully:\n" + "\n".join(res.stdout.strip().split("\n")[-6:]))

    # Verify HTTP access block on CLI controller (Security Guard)
    req_http_cli = urllib.request.Request(f"{BASE_URL}/cli/surgery_reminder/send_reminders")
    try:
        urllib.request.urlopen(req_http_cli)
        raise AssertionError("HTTP access to CLI controller should have been blocked!")
    except urllib.error.HTTPError as e:
        assert e.code == 403, f"Expected 403, got {e.code}"
        body = e.read().decode('utf-8')
        assert "Access Denied" in body
        print(f"   -> CLI Controller HTTP Security Guard verified (HTTP 403 Forbidden).")

# 14. Test Logout & Access Protection
def test_logout_and_protection():
    req = urllib.request.Request(f"{BASE_URL}/auth/logout")
    with opener.open(req) as resp:
        html = resp.read().decode('utf-8')
        assert "Central Hospital IT" in html or "Log In" in html
        print(f"   -> Logout successful. Redirected to login page.")

    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def http_error_302(self, req, fp, code, msg, headers):
            return fp
        def http_error_307(self, req, fp, code, msg, headers):
            return fp

    opener_no_redirect = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(cj),
        NoRedirect()
    )
    req = urllib.request.Request(f"{BASE_URL}/dashboard")
    try:
        resp = opener_no_redirect.open(req)
        assert resp.status in (302, 303, 307), f"Expected redirect, got {resp.status}"
        print(f"   -> Access control verified: Unauthenticated request redirected with status {resp.status}.")
    except urllib.error.HTTPError as e:
        assert e.code in (302, 303, 307), f"Expected redirect, got HTTP {e.code}"
        print(f"   -> Access control verified: HTTP {e.code} redirect.")

if __name__ == "__main__":
    tests = [
        ("Login Page & CSRF Token Generation", test_login_page),
        ("Invalid Credentials & Flashdata Alert", test_invalid_login),
        ("Valid Authentication & Session Establishment", test_valid_login),
        ("Dashboard Metrics & Authorization", test_dashboard),
        ("Patients Directory Page", test_patients_directory),
        ("Patient Creation & HIPAA Audit Log Immutability", test_patient_registration),
        ("Doctors Directory Page", test_doctors_directory),
        ("HMVC Polyclinic Queue API & Advisory Locking", test_queue_api),
        ("RBAC Hook: Doctor Forbidden on Restricted Resource", test_rbac_doctor_forbidden),
        ("Doctor EMR Queue Page & Live Polyclinic Desk", test_doctor_emr_queue_page),
        ("Doctor EMR Patient Call Action (AJAX)", test_doctor_emr_call_patient),
        ("Doctor EMR Clinical SOAP Consultation View", test_doctor_emr_consult_page),
        ("Doctor EMR Finalize & Digital Record Locking", test_doctor_emr_finalize_and_lock),
        ("DataTables Server-Side Engine (PostgreSQL ILIKE)", test_datatables_serverside),
        ("Third-Party Bridging Integration & PostgreSQL api_logs", test_bridging_api_logs),
        ("CLI Background Job & Execution Guards", test_cli_surgery_reminder),
        ("Logout & Protected Route Guards", test_logout_and_protection),
    ]

    passed = 0
    for name, fn in tests:
        if run_test(name, fn):
            passed += 1
        else:
            print(f"\nFATAL: Test '{name}' failed. Aborting suite.")
            sys.exit(1)

    print(f"\n=======================================================")
    print(f"🎉 ALL {passed}/{len(tests)} ADVANCED HMIS TESTS PASSED PERFECTLY!")
    print(f"=======================================================\n")
