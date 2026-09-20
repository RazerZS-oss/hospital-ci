<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$dob = !empty($patient['date_of_birth']) ? new DateTime($patient['date_of_birth']) : null;
$now = new DateTime();
$age = $dob ? $now->diff($dob)->y . ' thn' : '-';

$gender_label = '-';
if (!empty($patient['gender'])) {
    if (in_array(strtoupper($patient['gender']), ['M', 'L'])) {
        $gender_label = 'Laki-laki (Male)';
    } elseif (in_array(strtoupper($patient['gender']), ['F', 'P'])) {
        $gender_label = 'Perempuan (Female)';
    } else {
        $gender_label = htmlspecialchars($patient['gender']);
    }
}

$user_name = $this->session->userdata('name') ?? $this->session->userdata('username') ?? 'Staff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konsultasi Klinis EMR - <?= htmlspecialchars($patient['name'] ?? 'Pasien') ?> | Central Hospital IT</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #f1f5f9;
            margin: 0;
            padding: 0;
            color: #1e293b;
            line-height: 1.5;
        }
        .navbar {
            background-color: #0f172a;
            color: white;
            padding: 16px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .nav-brand {
            font-size: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .badge-ci3 {
            background-color: #dc2626;
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
        }
        .nav-links a {
            color: #94a3b8;
            text-decoration: none;
            margin-left: 20px;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.15s ease-in-out;
        }
        .nav-links a:hover, .nav-links a.active {
            color: #ffffff;
        }
        .nav-logout {
            color: #f87171 !important;
        }
        .nav-logout:hover {
            color: #fca5a5 !important;
        }

        .container {
            max-width: 1200px;
            margin: 28px auto 60px auto;
            padding: 0 24px;
        }

        /* Top Breadcrumb Bar */
        .breadcrumb-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #64748b;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.15s;
        }
        .back-link:hover {
            color: #0f172a;
        }
        .desk-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #dbeafe;
            color: #1e40af;
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 13px;
            font-weight: 600;
        }
        .desk-status-pulse {
            width: 8px;
            height: 8px;
            background: #2563eb;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.7);
            animation: pulse-ring 1.8s infinite cubic-bezier(0.66, 0, 0, 1);
        }
        @keyframes pulse-ring {
            0% { box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.7); }
            70% { box-shadow: 0 0 0 8px rgba(37, 99, 235, 0); }
            100% { box-shadow: 0 0 0 0 rgba(37, 99, 235, 0); }
        }

        /* Patient Header Banner */
        .patient-banner {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 24px 28px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 24px;
        }
        .patient-main {
            display: flex;
            gap: 20px;
            align-items: flex-start;
        }
        .patient-avatar {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            font-weight: 700;
            flex-shrink: 0;
        }
        .patient-details h1 {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 6px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .mrn-badge {
            background: #f1f5f9;
            color: #475569;
            font-size: 13px;
            padding: 2px 10px;
            border-radius: 6px;
            font-weight: 600;
            letter-spacing: 0.5px;
            border: 1px solid #cbd5e1;
        }
        .patient-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            font-size: 13px;
            color: #64748b;
            margin-top: 4px;
        }
        .meta-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .meta-item strong {
            color: #334155;
        }
        .patient-ticket {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 14px 20px;
            text-align: right;
            min-width: 220px;
        }
        .ticket-queue {
            font-size: 28px;
            font-weight: 800;
            color: #0284c7;
            line-height: 1;
            margin-bottom: 4px;
        }
        .ticket-code {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            letter-spacing: 0.5px;
        }
        .ticket-clinic {
            font-size: 12px;
            color: #0f172a;
            font-weight: 600;
            margin-top: 6px;
        }

        /* SOAP Section Cards */
        .soap-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .soap-card-header {
            padding: 16px 24px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
        }
        .soap-section-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .soap-letter {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 800;
            color: #ffffff;
        }
        .letter-s { background: #0284c7; }
        .letter-o { background: #059669; }
        .letter-a { background: #d97706; }
        .letter-p { background: #7c3aed; }

        .soap-subtitle {
            font-size: 12px;
            color: #64748b;
            font-weight: 400;
            margin-left: 4px;
        }
        .soap-card-body {
            padding: 24px;
        }

        /* Form Controls */
        .form-group {
            margin-bottom: 20px;
        }
        .form-group:last-child {
            margin-bottom: 0;
        }
        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }
        .form-label span.req {
            color: #ef4444;
        }
        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            color: #1e293b;
            background-color: #ffffff;
            transition: border-color 0.15s, box-shadow 0.15s;
            font-family: inherit;
        }
        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        textarea.form-control {
            resize: vertical;
            min-height: 90px;
        }

        /* Vitals Grid */
        .vitals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
        }
        .vital-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 14px;
            position: relative;
        }
        .vital-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .vital-name {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .vital-unit {
            font-size: 11px;
            color: #94a3b8;
            font-weight: 600;
        }
        .vital-input-wrapper {
            display: flex;
            align-items: center;
        }
        .vital-input {
            width: 100%;
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 6px 10px;
            background: #ffffff;
        }
        .vital-input:focus {
            outline: none;
            border-color: #059669;
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15);
        }

        /* Prescription Table */
        .rx-table-container {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow-x: auto;
            margin-top: 12px;
            margin-bottom: 16px;
        }
        .rx-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .rx-table th {
            background: #f8fafc;
            text-align: left;
            padding: 10px 14px;
            font-weight: 600;
            color: #475569;
            border-bottom: 1px solid #e2e8f0;
        }
        .rx-table td {
            padding: 8px 14px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .rx-table tr:last-child td {
            border-bottom: none;
        }
        .rx-input {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
            background: #ffffff;
        }
        .rx-input:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 2px rgba(124, 58, 237, 0.15);
        }
        .btn-add-rx {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f5f3ff;
            color: #6d28d9;
            border: 1px solid #ddd6fe;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-add-rx:hover {
            background: #ede9fe;
            color: #5b21b6;
        }
        .btn-remove-rx {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-remove-rx:hover {
            background: #fecaca;
            color: #991b1b;
        }

        /* Action Bar */
        .bottom-action-bar {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 20px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
            flex-wrap: wrap;
            gap: 16px;
        }
        .action-notes {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #64748b;
        }
        .action-buttons {
            display: flex;
            gap: 12px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 22px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s;
            border: none;
        }
        .btn-secondary {
            background: #ffffff;
            color: #475569;
            border: 1px solid #cbd5e1;
        }
        .btn-secondary:hover {
            background: #f8fafc;
            color: #1e293b;
        }
        .btn-lock {
            background: #059669;
            color: #ffffff;
            box-shadow: 0 2px 4px rgba(5, 150, 105, 0.2);
        }
        .btn-lock:hover {
            background: #047857;
            box-shadow: 0 4px 6px rgba(5, 150, 105, 0.3);
        }
        .btn-lock:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            box-shadow: none;
        }

        /* Confirmation Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-dialog {
            background: #ffffff;
            border-radius: 16px;
            max-width: 520px;
            width: 100%;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2), 0 10px 10px -5px rgba(0,0,0,0.1);
            overflow: hidden;
            animation: modalPop 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes modalPop {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .modal-header {
            padding: 24px 24px 16px 24px;
            text-align: center;
        }
        .modal-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: #dcfce7;
            color: #166534;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 12px;
        }
        .modal-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 6px 0;
        }
        .modal-desc {
            font-size: 14px;
            color: #64748b;
            margin: 0;
            line-height: 1.5;
        }
        .modal-body {
            padding: 0 24px 20px 24px;
        }
        .modal-summary-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 14px 16px;
            font-size: 13px;
        }
        .modal-summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .modal-summary-row:last-child {
            margin-bottom: 0;
        }
        .modal-summary-label {
            color: #64748b;
        }
        .modal-summary-val {
            font-weight: 600;
            color: #1e293b;
        }
        .modal-footer {
            padding: 16px 24px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        /* Toast Notifications */
        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            padding: 14px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            color: #ffffff;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            z-index: 1100;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .toast-show {
            transform: translateY(0);
            opacity: 1;
        }
        .toast-success {
            background-color: #059669;
        }
        .toast-error {
            background-color: #dc2626;
        }
    </style>
</head>
<body>
    <!-- Main Top Navigation -->
    <div class="navbar">
        <div class="nav-brand">
            <span>Central Hospital IT</span>
            <span class="badge-ci3">HMIS</span>
        </div>
        <div class="nav-links">
            <a href="<?= base_url('dashboard') ?>">Dashboard</a>
            <a href="<?= base_url('patients') ?>">Patients</a>
            <a href="<?= base_url('doctors') ?>">Doctors</a>
            <a href="<?= base_url('emr') ?>" class="active">EMR Queue</a>
            <a href="<?= base_url('auth/logout') ?>" class="nav-logout">Logout (<?= htmlspecialchars($user_name) ?>)</a>
        </div>
    </div>

    <div class="container">
        <!-- Breadcrumbs & Status Bar -->
        <div class="breadcrumb-bar">
            <a href="<?= base_url('emr') ?>" class="back-link">
                &larr; Kembali ke Antrean Poliklinik
            </a>
            <div class="desk-status-pill">
                <span class="desk-status-pulse"></span>
                <span>Pemeriksaan Klinis Sedang Berlangsung</span>
            </div>
        </div>

        <!-- Flash Message Alerts -->
        <?php if ($this->session->flashdata('error')): ?>
            <div style="background: #fee2e2; color: #991b1b; padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #fecaca; font-size: 14px;">
                <?= htmlspecialchars($this->session->flashdata('error')) ?>
            </div>
        <?php endif; ?>

        <!-- Patient Demographic Banner -->
        <div class="patient-banner">
            <div class="patient-main">
                <div class="patient-avatar">
                    <?= strtoupper(substr($patient['name'] ?? 'P', 0, 1)) ?>
                </div>
                <div class="patient-details">
                    <h1>
                        <?= htmlspecialchars($patient['name'] ?? 'Pasien') ?>
                        <span class="mrn-badge">RM: <?= htmlspecialchars($patient['medical_record_number'] ?? '-') ?></span>
                    </h1>
                    <div class="patient-meta">
                        <div class="meta-item">
                            <span>Jenis Kelamin:</span>
                            <strong><?= $gender_label ?></strong>
                        </div>
                        <div class="meta-item">
                            <span>Usia:</span>
                            <strong><?= $age ?> (<?= !empty($patient['date_of_birth']) ? date('d M Y', strtotime($patient['date_of_birth'])) : '-' ?>)</strong>
                        </div>
                        <div class="meta-item">
                            <span>Gol. Darah:</span>
                            <strong><?= !empty($patient['blood_type']) ? htmlspecialchars($patient['blood_type']) : '-' ?></strong>
                        </div>
                        <?php if (!empty($patient['phone_number'])): ?>
                            <div class="meta-item">
                                <span>No. Telp:</span>
                                <strong><?= htmlspecialchars($patient['phone_number']) ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="patient-ticket">
                <div class="ticket-queue">#<?= htmlspecialchars((string)$visit['queue_number']) ?></div>
                <div class="ticket-code"><?= htmlspecialchars($visit['visit_number'] ?? $visit['id']) ?></div>
                <div class="ticket-clinic">
                    <?= htmlspecialchars($polyclinic['name'] ?? 'Poliklinik') ?> &bull; <?= htmlspecialchars($doctor['name'] ?? 'Dokter') ?>
                </div>
            </div>
        </div>

        <!-- Clinical SOAP Form -->
        <form id="emr-consultation-form" onsubmit="handleFormSubmit(event)">
            <input type="hidden" name="visit_id" id="visit_id" value="<?= htmlspecialchars($visit['id']) ?>">
            <input type="hidden" name="patient_id" id="patient_id" value="<?= htmlspecialchars((string)$patient['id']) ?>">
            <input type="hidden" name="doctor_id" id="doctor_id" value="<?= htmlspecialchars((string)$doctor['id']) ?>">
            <input type="hidden" name="<?= $csrf_token_name ?>" id="csrf_token" value="<?= $csrf_hash ?>">

            <!-- Section S: Subjective -->
            <div class="soap-card">
                <div class="soap-card-header">
                    <h2 class="soap-section-title">
                        <span class="soap-letter letter-s">S</span>
                        <span>Subjective <span class="soap-subtitle">(Keluhan Utama & Anamnesis)</span></span>
                    </h2>
                </div>
                <div class="soap-card-body">
                    <div class="form-group">
                        <label for="subjective_complaints" class="form-label">
                            Keluhan Utama & Riwayat Penyakit Sekarang (RPS) <span class="req">*</span>
                        </label>
                        <textarea name="subjective_complaints" id="subjective_complaints" class="form-control" rows="4" required placeholder="Tuliskan keluhan utama pasien, onset, durasi, karakteristik nyeri/gejala, riwayat alergi obat/makanan, serta riwayat penyakit terdahulu..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Section O: Objective (Vital Signs) -->
            <div class="soap-card">
                <div class="soap-card-header">
                    <h2 class="soap-section-title">
                        <span class="soap-letter letter-o">O</span>
                        <span>Objective <span class="soap-subtitle">(Tanda-Tanda Vital & Pemeriksaan Fisik)</span></span>
                    </h2>
                </div>
                <div class="soap-card-body">
                    <div class="vitals-grid">
                        <div class="vital-box">
                            <div class="vital-header">
                                <span class="vital-name">Tekanan Darah</span>
                                <span class="vital-unit">mmHg</span>
                            </div>
                            <div class="vital-input-wrapper">
                                <input type="text" name="vital_bp" id="vital_bp" class="vital-input" value="120/80" placeholder="120/80" required>
                            </div>
                        </div>

                        <div class="vital-box">
                            <div class="vital-header">
                                <span class="vital-name">Frekuensi Nadi</span>
                                <span class="vital-unit">bpm</span>
                            </div>
                            <div class="vital-input-wrapper">
                                <input type="number" name="vital_hr" id="vital_hr" class="vital-input" value="75" min="30" max="250" placeholder="75" required>
                            </div>
                        </div>

                        <div class="vital-box">
                            <div class="vital-header">
                                <span class="vital-name">Suhu Tubuh</span>
                                <span class="vital-unit">°C</span>
                            </div>
                            <div class="vital-input-wrapper">
                                <input type="number" step="0.1" name="vital_temp" id="vital_temp" class="vital-input" value="36.5" min="30" max="45" placeholder="36.5" required>
                            </div>
                        </div>

                        <div class="vital-box">
                            <div class="vital-header">
                                <span class="vital-name">Laju Pernapasan</span>
                                <span class="vital-unit">x / mnt</span>
                            </div>
                            <div class="vital-input-wrapper">
                                <input type="number" name="vital_rr" id="vital_rr" class="vital-input" value="18" min="8" max="60" placeholder="18" required>
                            </div>
                        </div>

                        <div class="vital-box">
                            <div class="vital-header">
                                <span class="vital-name">Saturasi O2</span>
                                <span class="vital-unit">%</span>
                            </div>
                            <div class="vital-input-wrapper">
                                <input type="number" name="vital_spo2" id="vital_spo2" class="vital-input" value="98" min="50" max="100" placeholder="98" required>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section A: Assessment -->
            <div class="soap-card">
                <div class="soap-card-header">
                    <h2 class="soap-section-title">
                        <span class="soap-letter letter-a">A</span>
                        <span>Assessment <span class="soap-subtitle">(Diagnosis ICD-10 & Evaluasi Temuan Klinis)</span></span>
                    </h2>
                </div>
                <div class="soap-card-body">
                    <div class="form-group">
                        <label for="primary_icd10_code" class="form-label">
                            Diagnosis Utama (ICD-10 Code) <span class="req">*</span>
                        </label>
                        <select name="primary_icd10_code" id="primary_icd10_code" class="form-control" required style="font-weight: 600;">
                            <option value="">-- Pilih Diagnosis Utama (ICD-10) --</option>
                            <?php if (!empty($icd10_list)): ?>
                                <?php foreach ($icd10_list as $icd): ?>
                                    <option value="<?= htmlspecialchars($icd['code']) ?>">
                                        <?= htmlspecialchars($icd['code']) ?> - <?= htmlspecialchars($icd['description_en']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-top: 18px;">
                        <label for="assessment_notes" class="form-label">
                            Catatan Klinis & Temuan Evaluasi Fisik <span class="req">*</span>
                        </label>
                        <textarea name="assessment_notes" id="assessment_notes" class="form-control" rows="3" required placeholder="Tuliskan temuan pemeriksaan fisik objektif, status lokalis, pertimbangan diagnosis banding..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Section P: Plan -->
            <div class="soap-card">
                <div class="soap-card-header">
                    <h2 class="soap-section-title">
                        <span class="soap-letter letter-p">P</span>
                        <span>Plan <span class="soap-subtitle">(Rencana Terapi & Resep Elektronik)</span></span>
                    </h2>
                </div>
                <div class="soap-card-body">
                    <div class="form-group">
                        <label for="plan_therapy" class="form-label">
                            Rencana Terapi Non-Farmakologi & Instruksi Edukasi <span class="req">*</span>
                        </label>
                        <textarea name="plan_therapy" id="plan_therapy" class="form-control" rows="3" required placeholder="Instruksi perawatan rumahan, istirahat tirah baring, diet nutrisi, pantangan, dan jadwal kontrol kembali..."></textarea>
                    </div>

                    <div class="form-group" style="margin-top: 24px;">
                        <label class="form-label" style="display: flex; justify-content: space-between; align-items: center;">
                            <span>Resep Obat & Terapi Farmakologi (e-Prescription)</span>
                            <button type="button" class="btn-add-rx" onclick="addPrescriptionRow()">
                                + Tambah Resep Obat
                            </button>
                        </label>

                        <div class="rx-table-container">
                            <table class="rx-table" id="prescription-table">
                                <thead>
                                    <tr>
                                        <th style="width: 35%;">Nama Obat / Formularium</th>
                                        <th style="width: 20%;">Dosis / Sediaan</th>
                                        <th style="width: 15%;">Jumlah (Qty)</th>
                                        <th style="width: 22%;">Signa / Aturan Pakai</th>
                                        <th style="width: 8%; text-align: center;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody id="prescription-tbody">
                                    <!-- Dynamic rows will be inserted here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Action Bar -->
            <div class="bottom-action-bar">
                <div class="action-notes">
                    <span style="font-size: 18px;">ℹ️</span>
                    <span>Menekan tombol <strong>Kunci Rekam Medis</strong> akan menyimpan seluruh rekam medis secara permanen dan menyelesaikan kunjungan antrean.</span>
                </div>
                <div class="action-buttons">
                    <a href="<?= base_url('emr') ?>" class="btn btn-secondary">
                        Batal
                    </a>
                    <button type="submit" id="btn-submit-soap" class="btn btn-lock">
                        🔒 Finalisasi & Kunci Rekam Medis
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirm-modal" class="modal-overlay">
        <div class="modal-dialog">
            <div class="modal-header">
                <div class="modal-icon">🔐</div>
                <h3 class="modal-title">Konfirmasi Digital Lock Rekam Medis</h3>
                <p class="modal-desc">
                    Pastikan seluruh temuan SOAP dan peresepan obat telah lengkap dan akurat.
                </p>
            </div>
            <div class="modal-body">
                <div class="modal-summary-box">
                    <div class="modal-summary-row">
                        <span class="modal-summary-label">Nama Pasien:</span>
                        <span class="modal-summary-val" id="summary-patient"><?= htmlspecialchars($patient['name'] ?? '-') ?></span>
                    </div>
                    <div class="modal-summary-row">
                        <span class="modal-summary-label">No. Rekam Medis:</span>
                        <span class="modal-summary-val"><?= htmlspecialchars($patient['medical_record_number'] ?? '-') ?></span>
                    </div>
                    <div class="modal-summary-row">
                        <span class="modal-summary-label">Diagnosis ICD-10:</span>
                        <span class="modal-summary-val" id="summary-icd10">-</span>
                    </div>
                    <div class="modal-summary-row">
                        <span class="modal-summary-label">Jumlah Obat Diresepkan:</span>
                        <span class="modal-summary-val" id="summary-rx-count">0 item</span>
                    </div>
                </div>
                <p style="font-size: 12px; color: #dc2626; margin: 14px 0 0 0; text-align: center; font-weight: 500;">
                    ⚠️ Setelah difinalisasi, rekam medis akan terkunci (read-only) dan status kunjungan menjadi SELESAI.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeConfirmModal()">
                    Periksa Kembali
                </button>
                <button type="button" id="btn-confirm-save" class="btn btn-lock" onclick="executeSoapSave()">
                    Ya, Simpan & Kunci Permanen
                </button>
            </div>
        </div>
    </div>

    <!-- Dynamic Toast Notification Container -->
    <div id="toast-notification" class="toast"></div>

    <script>
        (function() {
            let currentCsrfHash = '<?= $csrf_hash ?>';
            const csrfTokenName = '<?= $csrf_token_name ?>';
            const csrfCookieName = '<?= config_item('csrf_cookie_name') ?: 'hmis_csrf_cookie' ?>';

            function getCsrfToken() {
                const match = document.cookie.match(new RegExp('(^|;\\s*)' + csrfCookieName + '=([^;]*)'));
                return match ? decodeURIComponent(match[2]) : currentCsrfHash;
            }

            function showToast(message, type) {
                const toast = document.getElementById('toast-notification');
                if (!toast) return;
                toast.textContent = message;
                toast.className = 'toast toast-' + (type === 'error' ? 'error' : 'success') + ' toast-show';
                setTimeout(function() {
                    toast.className = 'toast';
                }, 4000);
            }

            // Prescription Table Row Management
            let rxRowCounter = 0;
            window.addPrescriptionRow = function(name, strength, qty, dosage) {
                rxRowCounter++;
                const tbody = document.getElementById('prescription-tbody');
                const tr = document.createElement('tr');
                tr.id = 'rx-row-' + rxRowCounter;

                tr.innerHTML = `
                    <td>
                        <input type="text" class="rx-input rx-drug-name" placeholder="Misal: Paracetamol Tab" value="${name || ''}">
                    </td>
                    <td>
                        <input type="text" class="rx-input rx-strength" placeholder="Misal: 500 mg" value="${strength || ''}">
                    </td>
                    <td>
                        <input type="number" class="rx-input rx-qty" min="1" placeholder="10" value="${qty || '10'}">
                    </td>
                    <td>
                        <input type="text" class="rx-input rx-dosage" placeholder="Misal: 3x1 tab sesudah makan" value="${dosage || ''}">
                    </td>
                    <td style="text-align: center;">
                        <button type="button" class="btn-remove-rx" onclick="removePrescriptionRow('${tr.id}')" title="Hapus baris obat">
                            ✕
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            };

            window.removePrescriptionRow = function(rowId) {
                const row = document.getElementById(rowId);
                if (row) {
                    row.remove();
                }
            };

            // Initialize with 1 default empty prescription row
            addPrescriptionRow();

            // Modal Controls
            window.openConfirmModal = function() {
                const icdSelect = document.getElementById('primary_icd10_code');
                const selectedText = icdSelect.options[icdSelect.selectedIndex] ? icdSelect.options[icdSelect.selectedIndex].text : '-';
                document.getElementById('summary-icd10').textContent = selectedText;

                // Count non-empty prescription rows
                let count = 0;
                document.querySelectorAll('#prescription-tbody tr').forEach(function(row) {
                    const drugName = row.querySelector('.rx-drug-name');
                    if (drugName && drugName.value.trim() !== '') count++;
                });
                document.getElementById('summary-rx-count').textContent = count + ' item obat';

                document.getElementById('confirm-modal').classList.add('active');
            };

            window.closeConfirmModal = function() {
                document.getElementById('confirm-modal').classList.remove('active');
            };

            // Form Submit Interceptor
            window.handleFormSubmit = function(e) {
                e.preventDefault();
                const form = document.getElementById('emr-consultation-form');
                if (!form.checkValidity()) {
                    form.reportValidity();
                    return;
                }
                openConfirmModal();
            };

            // Final Save Execution via AJAX
            window.executeSoapSave = function() {
                const btnConfirm = document.getElementById('btn-confirm-save');
                btnConfirm.disabled = true;
                btnConfirm.textContent = 'Menyimpan & Mengunci...';

                // Collect Prescriptions
                const prescriptions = [];
                document.querySelectorAll('#prescription-tbody tr').forEach(function(row) {
                    const drugName = row.querySelector('.rx-drug-name')?.value.trim() || '';
                    const strength = row.querySelector('.rx-strength')?.value.trim() || '';
                    const qty = parseInt(row.querySelector('.rx-qty')?.value.trim() || '0', 10);
                    const dosage = row.querySelector('.rx-dosage')?.value.trim() || '';

                    if (drugName !== '') {
                        prescriptions.push({
                            drug_name: drugName,
                            strength: strength,
                            qty: qty > 0 ? qty : 1,
                            dosage: dosage
                        });
                    }
                });

                // Structured Objective Vital Signs
                const vitals = {
                    bp: document.getElementById('vital_bp').value.trim() || '120/80',
                    hr: parseInt(document.getElementById('vital_hr').value.trim() || '75', 10),
                    temp: parseFloat(document.getElementById('vital_temp').value.trim() || '36.5'),
                    rr: parseInt(document.getElementById('vital_rr').value.trim() || '18', 10),
                    spo2: parseInt(document.getElementById('vital_spo2').value.trim() || '98', 10)
                };

                const payload = {
                    visit_id: document.getElementById('visit_id').value,
                    patient_id: parseInt(document.getElementById('patient_id').value, 10),
                    doctor_id: parseInt(document.getElementById('doctor_id').value, 10),
                    primary_icd10_code: document.getElementById('primary_icd10_code').value,
                    subjective_complaints: document.getElementById('subjective_complaints').value,
                    assessment_notes: document.getElementById('assessment_notes').value,
                    plan_therapy: document.getElementById('plan_therapy').value,
                    objective_vital_signs: vitals,
                    prescriptions: prescriptions
                };

                payload[csrfTokenName] = getCsrfToken();

                fetch('<?= base_url('emr/save') ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(payload)
                })
                .then(function(response) {
                    return response.json().then(function(data) {
                        return { status: response.status, data: data };
                    });
                })
                .then(function(res) {
                    currentCsrfHash = getCsrfToken();

                    if (res.status === 201 && res.data.status === 'success') {
                        closeConfirmModal();
                        showToast('Rekam medis berhasil dikunci secara digital! Mengalihkan...', 'success');
                        setTimeout(function() {
                            window.location.href = '<?= base_url('emr') ?>';
                        }, 1200);
                    } else if (res.status === 422) {
                        btnConfirm.disabled = false;
                        btnConfirm.textContent = 'Ya, Simpan & Kunci Permanen';
                        closeConfirmModal();

                        let errMessage = res.data.message || 'Validasi klinis gagal.';
                        if (res.data.errors && typeof res.data.errors === 'object') {
                            const errList = Object.values(res.data.errors).join(', ');
                            if (errList) errMessage += ' ' + errList;
                        }
                        showToast(errMessage, 'error');
                    } else if (res.status === 409) {
                        btnConfirm.disabled = false;
                        btnConfirm.textContent = 'Ya, Simpan & Kunci Permanen';
                        closeConfirmModal();
                        showToast(res.data.message || 'Kunjungan ini sudah diselesaikan sebelumnya.', 'error');
                    } else {
                        btnConfirm.disabled = false;
                        btnConfirm.textContent = 'Ya, Simpan & Kunci Permanen';
                        closeConfirmModal();
                        showToast(res.data.message || 'Gagal menyimpan rekam medis.', 'error');
                    }
                })
                .catch(function(err) {
                    console.error('Save error:', err);
                    btnConfirm.disabled = false;
                    btnConfirm.textContent = 'Ya, Simpan & Kunci Permanen';
                    closeConfirmModal();
                    showToast('Terjadi kesalahan komunikasi dengan server.', 'error');
                });
            };
        })();
    </script>
</body>
</html>
