<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Antrean Poliklinik - Polyclinic Queue | Central Hospital IT</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #f8fafc;
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
            margin: 32px auto;
            padding: 0 24px;
        }
        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
        }
        .alert-danger {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        /* Header Doctor Card */
        .doctor-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 24px 28px;
            margin-bottom: 28px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .doctor-info {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .clinic-badge {
            display: inline-flex;
            align-items: center;
            background-color: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            width: fit-content;
        }
        .doctor-name {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
        }
        .doctor-meta {
            color: #64748b;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .doctor-meta .dot-divider {
            color: #cbd5e1;
        }
        /* Stat Badges */
        .queue-stats {
            display: flex;
            gap: 16px;
        }
        .stat-badge {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 20px;
            min-width: 100px;
            text-align: center;
        }
        .stat-badge-number {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
            display: block;
            line-height: 1.1;
        }
        .stat-badge-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        /* Queue Table Card */
        .card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        .card-header {
            padding: 20px 28px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ffffff;
        }
        .card-title {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
        }
        .card-subtitle {
            color: #64748b;
            font-size: 13px;
            margin-top: 2px;
        }
        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        th {
            background-color: #f8fafc;
            color: #475569;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 14px 24px;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }
        td {
            padding: 16px 24px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
            vertical-align: middle;
        }
        tr:last-child td {
            border-bottom: none;
        }
        tbody tr:hover {
            background-color: #f8fafc;
        }
        /* Queue Number & Visit Code */
        .queue-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: #0f172a;
            color: #ffffff;
            font-weight: 700;
            font-size: 15px;
        }
        .visit-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            color: #475569;
            font-weight: 600;
            font-size: 13px;
        }
        .patient-title {
            font-weight: 600;
            color: #0f172a;
            font-size: 15px;
        }
        .patient-mrn {
            font-size: 12px;
            color: #64748b;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            margin-top: 2px;
        }
        .blood-badge {
            display: inline-block;
            background: #fee2e2;
            color: #b91c1c;
            font-size: 11px;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 4px;
            margin-left: 6px;
        }
        /* Status Badges */
        .badge-status {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            transition: all 0.2s ease-in-out;
        }
        .badge-waiting {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
        }
        .badge-called {
            background-color: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }
        .badge-consultation {
            background-color: #f3e8ff;
            color: #6b21a8;
            border: 1px solid #e9d5ff;
        }
        /* Action Buttons */
        .actions-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: nowrap;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s ease-in-out;
            border: 1px solid transparent;
            line-height: 1.2;
            white-space: nowrap;
        }
        .btn-call {
            background-color: #0284c7;
            color: #ffffff;
            border-color: #0284c7;
        }
        .btn-call:hover:not(:disabled) {
            background-color: #0369a1;
            border-color: #0369a1;
        }
        .btn-call:disabled {
            background-color: #e2e8f0;
            color: #94a3b8;
            border-color: #cbd5e1;
            cursor: not-allowed;
        }
        .btn-examine {
            background-color: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
        }
        .btn-examine:hover {
            background-color: #1d4ed8;
            border-color: #1d4ed8;
        }
        /* Empty State */
        .empty-state {
            padding: 64px 24px;
            text-align: center;
        }
        .empty-icon {
            font-size: 48px;
            margin-bottom: 16px;
            display: inline-block;
        }
        .empty-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 8px 0;
        }
        .empty-desc {
            color: #64748b;
            font-size: 14px;
            margin: 0;
            max-width: 440px;
            margin-left: auto;
            margin-right: auto;
        }
        /* Toast Notification */
        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            padding: 14px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.3s ease;
            z-index: 9999;
            pointer-events: none;
        }
        .toast-show {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }
        .toast-success {
            background-color: #0f172a;
            color: #ffffff;
            border-left: 4px solid #10b981;
        }
        .toast-error {
            background-color: #0f172a;
            color: #ffffff;
            border-left: 4px solid #ef4444;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="nav-brand">
            🏥 Central Hospital System <span class="badge-ci3">CodeIgniter 3</span>
        </div>
        <div class="nav-links">
            <a href="<?= base_url('dashboard') ?>">Dashboard</a>
            <a href="<?= base_url('patients') ?>">Patients</a>
            <a href="<?= base_url('doctors') ?>">Doctors</a>
            <a href="<?= base_url('emr') ?>" class="active">EMR Queue</a>
            <a href="<?= base_url('auth/logout') ?>" class="nav-logout">Logout (<?= htmlspecialchars($this->session->userdata('name') ?: ($doctor['name'] ?? 'Staff')) ?>)</a>
        </div>
    </div>

    <div class="container">
        <?php if ($this->session->flashdata('error')): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($this->session->flashdata('error')) ?></div>
        <?php endif; ?>
        <?php if ($this->session->flashdata('success')): ?>
            <div class="alert alert-success"><?= htmlspecialchars($this->session->flashdata('success')) ?></div>
        <?php endif; ?>

        <!-- Doctor & Polyclinic Header -->
        <div class="doctor-card">
            <div class="doctor-info">
                <span class="clinic-badge"><?= htmlspecialchars($polyclinic['name'] ?? 'Poliklinik Spesialis') ?></span>
                <h1 class="doctor-name"><?= htmlspecialchars($doctor['name'] ?? 'Dokter Spesialis') ?></h1>
                <div class="doctor-meta">
                    <span>🩺 <?= htmlspecialchars($doctor['specialization'] ?? 'Spesialis') ?></span>
                    <span class="dot-divider">•</span>
                    <span>📅 <?= date('l, d F Y') ?></span>
                    <span class="dot-divider">•</span>
                    <span>📋 Antrean Poliklinik (Polyclinic Queue)</span>
                </div>
            </div>
            <div class="queue-stats">
                <?php
                    $count_waiting = count(array_filter($visits ?: [], fn($v) => $v['queue_status'] === 'WAITING'));
                    $count_called = count(array_filter($visits ?: [], fn($v) => $v['queue_status'] === 'CALLED'));
                    $count_consult = count(array_filter($visits ?: [], fn($v) => $v['queue_status'] === 'IN_CONSULTATION'));
                ?>
                <div class="stat-badge">
                    <span class="stat-badge-number" id="count-waiting"><?= $count_waiting ?></span>
                    <span class="stat-badge-label">Menunggu</span>
                </div>
                <div class="stat-badge">
                    <span class="stat-badge-number" id="count-called"><?= $count_called ?></span>
                    <span class="stat-badge-label">Dipanggil</span>
                </div>
                <div class="stat-badge">
                    <span class="stat-badge-number" id="count-consult"><?= $count_consult ?></span>
                    <span class="stat-badge-label">Pemeriksaan</span>
                </div>
            </div>
        </div>

        <!-- Live Queue Table -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Daftar Antrean Pasien Hari Ini</h2>
                    <div class="card-subtitle">Manajemen pemanggilan pasien dan rekam medis elektronik (EMR)</div>
                </div>
                <span style="font-size: 13px; color: #64748b; font-weight: 500;">
                    Total Pasien: <strong><?= count($visits ?: []) ?></strong>
                </span>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 80px; text-align: center;">Queue #</th>
                            <th style="width: 170px;">Visit Code</th>
                            <th>Patient Name & MRN</th>
                            <th style="width: 140px;">Gender & Age</th>
                            <th style="width: 160px;">Queue Status</th>
                            <th style="width: 200px; text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="queue-table-body">
                        <?php if (!empty($visits)): ?>
                            <?php foreach ($visits as $v): ?>
                                <?php
                                    // Calculate Age from date_of_birth
                                    $age_str = '-';
                                    if (!empty($v['date_of_birth'])) {
                                        try {
                                            $dob = new DateTime($v['date_of_birth']);
                                            $now = new DateTime();
                                            $age_str = $now->diff($dob)->y . ' thn';
                                        } catch (Exception $e) {
                                            $age_str = '-';
                                        }
                                    }
                                    // Gender
                                    $gender_raw = strtoupper(trim((string)($v['gender'] ?? '')));
                                    if ($gender_raw === 'L' || $gender_raw === 'M' || $gender_raw === 'MALE' || $gender_raw === 'LAKI-LAKI') {
                                        $gender_label = 'L';
                                    } elseif ($gender_raw === 'P' || $gender_raw === 'F' || $gender_raw === 'FEMALE' || $gender_raw === 'PEREMPUAN') {
                                        $gender_label = 'P';
                                    } else {
                                        $gender_label = !empty($gender_raw) ? htmlspecialchars($gender_raw) : '-';
                                    }

                                    // Queue status styling
                                    $status = $v['queue_status'];
                                    if ($status === 'WAITING') {
                                        $badge_class = 'badge-waiting';
                                    } elseif ($status === 'CALLED') {
                                        $badge_class = 'badge-called';
                                    } elseif ($status === 'IN_CONSULTATION') {
                                        $badge_class = 'badge-consultation';
                                    } else {
                                        $badge_class = 'badge-waiting';
                                    }
                                ?>
                                <tr id="row-visit-<?= $v['id'] ?>">
                                    <td style="text-align: center;">
                                        <span class="queue-pill">#<?= htmlspecialchars($v['queue_number']) ?></span>
                                    </td>
                                    <td>
                                        <span class="visit-code"><?= htmlspecialchars($v['visit_number']) ?></span>
                                    </td>
                                    <td>
                                        <div class="patient-title">
                                            <?= htmlspecialchars($v['patient_name']) ?>
                                            <?php if (!empty($v['blood_type'])): ?>
                                                <span class="blood-badge"><?= htmlspecialchars($v['blood_type']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="patient-mrn">MRN: <?= htmlspecialchars($v['medical_record_number'] ?? '-') ?></div>
                                    </td>
                                    <td>
                                        <strong><?= $gender_label ?></strong> (<?= $age_str ?>)
                                    </td>
                                    <td>
                                        <span id="badge-status-<?= $v['id'] ?>" class="badge-status <?= $badge_class ?>">
                                            <?= htmlspecialchars($status) ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <div class="actions-group" style="justify-content: flex-end;">
                                            <?php if ($status === 'WAITING'): ?>
                                                <button type="button"
                                                        id="btn-call-<?= $v['id'] ?>"
                                                        class="btn-action btn-call"
                                                        onclick="callPatient('<?= $v['id'] ?>')">
                                                    Panggil
                                                </button>
                                            <?php elseif ($status === 'CALLED'): ?>
                                                <button type="button"
                                                        id="btn-call-<?= $v['id'] ?>"
                                                        class="btn-action btn-call"
                                                        disabled
                                                        style="background-color: #e2e8f0; color: #94a3b8; border-color: #cbd5e1; cursor: not-allowed;">
                                                    Dipanggil
                                                </button>
                                            <?php else: ?>
                                                <button type="button"
                                                        id="btn-call-<?= $v['id'] ?>"
                                                        class="btn-action btn-call"
                                                        disabled
                                                        style="background-color: #e2e8f0; color: #94a3b8; border-color: #cbd5e1; cursor: not-allowed;">
                                                    Pemeriksaan
                                                </button>
                                            <?php endif; ?>

                                            <a href="<?= base_url('emr/consult/' . $v['id']) ?>" class="btn-action btn-examine">
                                                Periksa &rarr;
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="padding: 0;">
                                    <div class="empty-state">
                                        <div class="empty-icon">🩺</div>
                                        <h3 class="empty-title">Semua Antrean Telah Selesai</h3>
                                        <p class="empty-desc">Tidak ada pasien yang sedang menunggu dalam antrean poliklinik saat ini. Pasien baru yang mendaftar akan otomatis muncul di sini.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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

            window.callPatient = function(visitId) {
                const btnCall = document.getElementById('btn-call-' + visitId);
                const originalText = btnCall ? btnCall.textContent : 'Panggil';
                if (btnCall) {
                    btnCall.disabled = true;
                    btnCall.textContent = 'Memanggil...';
                }

                const formData = new FormData();
                formData.append(csrfTokenName, getCsrfToken());
                formData.append('visit_id', visitId);

                fetch('<?= base_url('emr/call') ?>', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) {
                    return response.json().then(function(data) {
                        return { status: response.status, data: data };
                    });
                })
                .then(function(res) {
                    currentCsrfHash = getCsrfToken();

                    if (res.status === 200 && res.data.status === 'success') {
                        // Update status badge
                        const badge = document.getElementById('badge-status-' + visitId);
                        if (badge) {
                            badge.textContent = 'CALLED';
                            badge.className = 'badge-status badge-called';
                        }
                        // Update button state
                        if (btnCall) {
                            btnCall.textContent = 'Dipanggil';
                            btnCall.disabled = true;
                            btnCall.style.backgroundColor = '#e2e8f0';
                            btnCall.style.color = '#94a3b8';
                            btnCall.style.borderColor = '#cbd5e1';
                            btnCall.style.cursor = 'not-allowed';
                        }
                        // Update summary counters
                        const countWaiting = document.getElementById('count-waiting');
                        const countCalled = document.getElementById('count-called');
                        if (countWaiting && countCalled) {
                            const w = parseInt(countWaiting.textContent || '0', 10);
                            const c = parseInt(countCalled.textContent || '0', 10);
                            if (w > 0) countWaiting.textContent = w - 1;
                            countCalled.textContent = c + 1;
                        }
                        showToast('Pasien berhasil dipanggil ke ruang periksa.', 'success');
                    } else if (res.status === 409) {
                        // Conflict: already called or in consultation
                        const badge = document.getElementById('badge-status-' + visitId);
                        if (badge) {
                            badge.textContent = res.data.queue_status || 'CALLED';
                            badge.className = 'badge-status badge-called';
                        }
                        if (btnCall) {
                            btnCall.textContent = 'Dipanggil';
                            btnCall.disabled = true;
                            btnCall.style.backgroundColor = '#e2e8f0';
                            btnCall.style.color = '#94a3b8';
                            btnCall.style.borderColor = '#cbd5e1';
                            btnCall.style.cursor = 'not-allowed';
                        }
                        showToast(res.data.message || 'Status antrean sudah diperbarui.', 'success');
                    } else {
                        if (btnCall) {
                            btnCall.disabled = false;
                            btnCall.textContent = originalText;
                        }
                        showToast(res.data.message || 'Gagal memanggil pasien.', 'error');
                    }
                })
                .catch(function(err) {
                    console.error('AJAX call error:', err);
                    if (btnCall) {
                        btnCall.disabled = false;
                        btnCall.textContent = originalText;
                    }
                    showToast('Terjadi kesalahan komunikasi dengan server.', 'error');
                });
            };
        })();
    </script>
</body>
</html>
