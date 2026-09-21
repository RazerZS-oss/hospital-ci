# Hospital CI — Enterprise Hospital Management Information System (HMIS / SIMRS)

[![PHP](https://img.shields.io/badge/PHP-8.4--FPM-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Framework](https://img.shields.io/badge/CodeIgniter-3.1.13_HMVC-EE4623?logo=codeigniter&logoColor=white)](https://codeigniter.com/)
[![Database](https://img.shields.io/badge/PostgreSQL-15-4169E1?logo=postgresql&logoColor=white)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker&logoColor=white)](https://www.docker.com/)
[![Web Server](https://img.shields.io/badge/Nginx-Alpine-009639?logo=nginx&logoColor=white)](https://nginx.org/)
[![Security](https://img.shields.io/badge/Security-HIPAA_§_164.312(b)-success?logo=shield&logoColor=white)](#hipaa-compliance--security)

Hospital CI is a production-ready, modular **Hospital Management Information System (HMIS / SIMRS - Sistem Informasi Manajemen Rumah Sakit)** built with PHP 8.4-FPM, CodeIgniter 3.1.13 HMVC, and PostgreSQL 15. The system is engineered to handle clinical consultations, outpatients, inpatients, enterprise fleet logistics, corporate medical check-ups (MCU), nutritional diet management, and executive analytics under strict data integrity and regulatory compliance standards.

---

## Table of Contents

- [System Architecture](#system-architecture)
- [Core Clinical Modules](#core-clinical-modules)
  - [Doctor Clinical EMR & Consultation Desk](#1-doctor-clinical-emr--consultation-desk)
  - [Polyclinic Queue Engine with Advisory Locking](#2-polyclinic-queue-engine-with-advisory-locking)
  - [Patient Management & Specialists Directory](#3-patient-management--specialists-directory)
- [Enterprise Operations Modules](#enterprise-operations-modules)
  - [Clinical Nutrition & Inpatient Diet](#domain-1-clinical-nutrition--inpatient-diet)
  - [Corporate MCU (Medical Check-Up)](#domain-2-corporate-mcu-medical-check-up)
  - [Fleet Management & Smart Ambulance Billing](#domain-3-fleet-management--smart-ambulance-billing)
  - [Patient Portal RESTful API](#domain-4-patient-portal-restful-api)
  - [Executive Dashboard & Hospital KPI Analytics](#domain-5-executive-dashboard--hospital-kpi-analytics)
  - [Hospital Support Operations](#hospital-support-operations)
- [HIPAA Compliance & Security](#hipaa-compliance--security)
- [Background Workers & Automation](#background-workers--automation)
- [Quick Start with Docker](#quick-start-with-docker)
- [Automated Testing & Verification](#automated-testing--verification)
- [Production & Cloud Deployment](#production--cloud-deployment)
- [License](#license)

---

## System Architecture

The application adopts a decoupled **Hierarchical Model-View-Controller (HMVC)** modular architecture supported by high-performance PostgreSQL features:

```
 hospital-ci/
 ├── application/
 │   ├── config/              # App, Database, Session, Routes configuration
 │   ├── controllers/         # Web, API, and CLI Controller Entrypoints
 │   │   ├── api/v1/          # Secure RESTful API (Patient Portal)
 │   │   └── cli/             # Background jobs & enterprise testing CLI
 │   ├── core/                # Core extensions (MY_Controller, MY_Router)
 │   ├── hooks/               # RBAC security authorization hook
 │   ├── models/              # Global models (Executive dashboard, EMR, Auth)
 │   ├── modules/             # HMVC Modules (Independent domain services)
 │   │   ├── fleet/           # Ambulance dispatch & tariff calculation service
 │   │   ├── mcu/             # Corporate onboarding & bulk package management
 │   │   ├── nutrition/       # Clinical dietary planning & allergen alerts
 │   │   └── registration/    # Queue management & polyclinic routing
 │   └── views/               # Responsive clinical desk & administrative UI
 ├── database/                # Relational schemas & PostgreSQL audit rules
 │   ├── schema.sql           # Base HMIS schema (Patients, Visits, EMR, Audit)
 │   ├── schema_enterprise_modules.sql # Enterprise schema (Nutrition, Fleet, MCU, Lab)
 │   └── schema_advanced.sql  # Background scheduling & API integration logging
 ├── nginx/                   # Nginx reverse proxy configuration
 ├── docker-compose.yml       # Multi-container orchestration (App + DB)
 ├── Dockerfile               # Production container image (Alpine + PHP 8.4)
 └── test_app_e2e.py          # End-to-end automated verification test suite
```

### Key Technical Highlights

- **PHP 8.4-FPM Compatibility**: Built with attribute annotations (`#[AllowDynamicProperties]`) and typed method signatures.
- **PostgreSQL 15 Cryptographic UUIDs**: Primary keys and audit references leverage native `gen_random_uuid()` via `pgcrypto`.
- **JSONB Clinical Storage**: Flexible, indexed JSONB fields store objective vital signs, macronutrient distributions, and structured laboratory test metrics.
- **Advisory Locks**: PostgreSQL transaction-level advisory locks (`pg_advisory_xact_lock`) eliminate race conditions in ticket numbering and queue slot assignments.

---

## Core Clinical Modules

### 1. Doctor Clinical EMR & Consultation Desk
**Route:** `/emr/consult/{visit_id}`
- **Comprehensive SOAP Notes**:
  - **Subjective (S)**: Chief complaints, present illness history, and allergy notes.
  - **Objective (O)**: Vital signs capture (Blood Pressure, Heart Rate, Respiratory Rate, Body Temperature, Oxygen Saturation `SpO2`) stored in validated JSONB format.
  - **Assessment (A)**: Searchable ICD-10 diagnostic code lookup and primary/secondary diagnosis classification.
  - **Plan (P)**: Therapy orders, doctor notes, and dynamic digital prescription rows (drug name, strength, quantity, and dosage instructions).
- **Tamper-Proof Digital Record Locking**: Once a doctor finalizes a consultation, the medical record is flagged with `is_locked = TRUE`, terminating edit permissions and logging an immutable event in the audit trail.
- **Auto-Status Progression**: Opening a patient consultation automatically transitions their queue status from `CALLED` to `IN_CONSULTATION`.

### 2. Polyclinic Queue Engine with Advisory Locking
**Routes:** `/emr`, `/emr/queue`, `/queue/register`, `/queue/monitor`
- **Race-Condition-Free Dispensing**: Atomic queue ticket incrementing per polyclinic and doctor per day using PostgreSQL advisory locks.
- **Live Doctor Call Action**: Doctors can page waiting patients via AJAX (`/emr/call`), advancing the visit state to `CALLED`.
- **Duplicate Prevention**: Rejects duplicate active registrations for the same patient in the same clinic on the same calendar day with HTTP 409 Conflict.

### 3. Patient Management & Specialists Directory
**Routes:** `/patients`, `/doctors`
- **Unique MRN Generation**: Formats medical record numbers (`RM-YYYYMMDD-XXXX`) with deduplication checks.
- **Server-Side DataTables Engine**: High-speed pagination and fuzzy search powered by PostgreSQL `ILIKE` queries.
- **Audit-Logged Demographics**: Full capture of blood type, national identity, gender, and contact details.

---

## Enterprise Operations Modules

### Domain 1: Clinical Nutrition & Inpatient Diet
**Path:** `application/modules/nutrition/`
- **Inpatient Bed & Ward Tracking**: Bed allocation across Intensive Care, General Care, Pediatric, and VIP wards.
- **Dietary Profile Configuration**:
  - Calorie target calculation (kcal/day).
  - Macronutrient ratio splits (`carbohydrate_pct`, `protein_pct`, `fat_pct`, `sodium_max_mg`).
  - Texture form specifications (`REGULAR_SOLID`, `SOFT_DIET`, `PUREED`, `LIQUID_ENTERAL`).
  - Strict allergen restriction checking (Peanuts, Seafood, Dairy, Gluten).
- **Automated Clinical Contraindication Alerts**: Cross-references patient vitals/diagnoses against diet parameters (e.g. flagging sodium violations in hypertensive patients).

### Domain 2: Corporate MCU (Medical Check-Up)
**Path:** `application/modules/mcu/`
- **Corporate Client & Contract Management**: Tracks institutional partnerships, payment terms, and valid contracts.
- **Tiered Health Packages**: Standard, Executive, and Comprehensive screening packages.
- **Bulk Corporate Registration Service**:
  - High-throughput batch employee onboarding with unique corporate batch codes.
  - Automatic contract valuation and patient MRN resolution/creation in a single atomic transaction.

### Domain 3: Fleet Management & Smart Ambulance Billing
**Path:** `application/modules/fleet/`
- **Fleet & Driver Registry**: Real-time readiness status for emergency vehicles, paramedic crews, and certified drivers.
- **Dynamic Tariff Calculation Engine**:
  - **Flagfall Base Fee**: Standard vehicle dispatch mobilization fee.
  - **Tiered Distance Pricing**: Dynamic rates across graduated distance brackets (0–10 km, 10–25 km, >25 km).
  - **Clinical Urgency Surcharge**: Automated percentage adjustments for life-support cardiac/trauma emergencies.
  - **Toll & Parking Reimbursables**: Direct integration of transit receipts.
- **Universal Billing Integration**: Automatically posts line-item entries to patient invoices (`billing_invoices`, `billing_details`).

### Domain 4: Patient Portal RESTful API
**Endpoint:** `GET /api/v1/patients/lab-results`
- **Bearer Token Authentication**: Secure token verification via `patient_portal_tokens` with automatic token expiry checks.
- **Strict HIPAA Tenant Isolation**: Enforces tenant-level isolation ensuring authenticated patients can only access their own clinical records.
- **Structured Laboratory Panel Delivery**: Delivers complete lab panels with reference ranges, quantitative values, test unit measurements, and abnormal flags (`is_abnormal = TRUE`).

### Domain 5: Executive Dashboard & Hospital KPI Analytics
**Path:** `application/models/Executive_dashboard_model.php`
- **Monthly Bed Occupancy Rate (BOR)**:
  - Formulated as: $\text{BOR} = \left(\frac{\text{Total Inpatient Bed Days}}{\text{Available Beds} \times \text{Days in Month}}\right) \times 100\%$
  - Automated WHO benchmark compliance rating:
    - **60% – 85%**: Ideal clinical operational efficiency.
    - **< 60%**: Underutilized hospital capacity.
    - **> 85%**: Overcrowded / strain on medical staff.
- **Top 10 Morbidity (ICD-10) Diseases**: Aggregates month-to-date diagnoses and ranks morbidity prevalence rates.

### Hospital Support Operations
- **Blood Bank Inventory**: Tracks units by blood group, Rhesus factor, component type, and expiration dates.
- **CSSD Sterilization Logs**: Autoclave run tracking, biological indicator verification, and batch logging.
- **Hazardous Medical Waste Management**: Biohazard and sharps disposal tracking in compliance with environmental healthcare regulations.

---

## HIPAA Compliance & Security

1. **Immutable Audit Trail (§ 164.312(b))**:
   - Every patient demographic creation, medical record save, and security denial is recorded in `audit_logs`.
   - Protected by PostgreSQL database rules:
     ```sql
     CREATE RULE no_update_audit AS ON UPDATE TO audit_logs DO INSTEAD NOTHING;
     CREATE RULE no_delete_audit AS ON DELETE TO audit_logs DO INSTEAD NOTHING;
     ```
2. **Role-Based Access Control (RBAC)**:
   - Enforced by `application/hooks/Rbac_hook.php`.
   - Supported roles: `admin`, `doctor`, `receptionist`, `nurse`.
   - Unauthorized resource access attempts return `HTTP 403 Forbidden` and trigger an automated security audit entry with `action = 'DENIED'`.
3. **Database-Backed Session Driver**:
   - Stateful sessions persisted securely in PostgreSQL (`ci_sessions`).
4. **CSRF Protection**:
   - Active CSRF token verification across all state-changing HTTP POST requests.

---

## Background Workers & Automation

The application bundles an automated scheduling daemon powered by Alpine Linux `crond` inside the Docker container.

- **Surgery Reminder Dispatcher**:
  - **CLI Command:** `php index.php cli/surgery_reminder send_reminders`
  - Queries scheduled surgical procedures occurring within the next 24 hours and dispatches automated patient reminder notices.
- **HTTP Security Guard**:
  - All CLI controllers reject non-CLI requests (`$this->input->is_cli_request()`) with `HTTP 403 Forbidden`.
- **Third-Party Bridging Logging**:
  - External API calls and bridge requests log payloads, HTTP methods, response codes, and execution duration in `api_logs`.

---

## Quick Start with Docker

### Prerequisites
- [Docker](https://docs.docker.com/get-docker/) & [Docker Compose](https://docs.docker.com/compose/) (v2.0+)
- Python 3.8+ (for automated E2E test verification)

### 1. Clone and Launch Containers
```bash
# Clone the repository
git clone https://github.com/RazerZS-oss/hospital-ci.git
cd hospital-ci

# Spin up application and PostgreSQL database containers
docker compose up -d --build
```

The database schema and sample seed records are automatically initialized on startup.

### 2. Access the Application
- **Web UI:** [http://localhost:8081](http://localhost:8081)
- **PostgreSQL Database:** `localhost:5432` (`ci_hospital` / `ci_user` / `ci_password`)

### 3. Default Credentials

| Role | Username | Password | Default Landing Page |
| :--- | :--- | :--- | :--- |
| **Administrator** | `admin` | `admin123` | `/dashboard` |
| **Specialist Doctor** | `drbudi` | `doctor123` | `/emr` (Doctor Clinical Desk) |

---

## Automated Testing & Verification

### 1. End-to-End Test Suite (Python)
The project includes a comprehensive end-to-end test script verifying HTTP endpoints, CSRF tokens, session flow, RBAC denial guards, DataTables server-side filtering, SOAP consultation completion, EMR digital locking, and HIPAA audit immutability.

```bash
# Run the complete test suite against the live Docker environment
python3 test_app_e2e.py
```

Expected output:
```text
=======================================================
🎉 ALL 17/17 ADVANCED HMIS TESTS PASSED PERFECTLY!
=======================================================
```

### 2. Enterprise CLI Module Verification (PHP)
Run the automated test runner for the enterprise operational modules:

```bash
docker compose exec app php /var/www/html/index.php cli/test_enterprise run_tests
```

This verifies:
- Executive Dashboard monthly BOR and Top 10 ICD-10 ranking metrics.
- Clinical Inpatient Nutrition dietary profiles and contraindication checks.
- Corporate MCU bulk onboarding and contract valuation.
- Smart Ambulance dynamic tariff computation and universal invoice posting.

---

## Production & Cloud Deployment

The container image is engineered for cloud platforms (e.g. Render, Koyeb, AWS ECS, Google Cloud Run).

- **Dynamic Port Binding**: `docker-entrypoint.sh` automatically rewrites the Nginx listening port based on the `$PORT` environment variable.
- **Render Configuration**: Pre-configured in `render.yaml` with managed PostgreSQL attachment.

```yaml
services:
  - type: web
    name: hospital-ci
    env: docker
    plan: standard
    envVars:
      - key: CI_ENVIRONMENT
        value: production
```

---

## License

This project is released under the [MIT License](LICENSE).
