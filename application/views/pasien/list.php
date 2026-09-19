<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patients Directory - CodeIgniter 3</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background-color: #f8fafc; margin: 0; padding: 0; color: #1e293b; }
        .navbar { background-color: #0f172a; color: white; padding: 16px 32px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: #94a3b8; text-decoration: none; margin-left: 20px; font-size: 14px; font-weight: 500; }
        .navbar a:hover, .navbar a.active { color: white; }
        .container { max-width: 1100px; margin: 30px auto; padding: 0 20px; }
        .card { background: white; padding: 24px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px; }
        .flex-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 12px 16px; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
        th { background-color: #f1f5f9; color: #475569; font-weight: 600; }
        .badge { background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 12px; }
        .form-row { display: grid; grid-template-columns: 2fr 2fr 1fr auto; gap: 12px; align-items: end; }
        input, select { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; font-size: 14px; }
        button { padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .alert-success { background: #dcfce7; color: #15803d; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="navbar">
        <div style="font-size: 18px; font-weight: bold;">🏥 Central Hospital System (CI3)</div>
        <div>
            <a href="<?= base_url('dashboard') ?>">Dashboard</a>
            <a href="<?= base_url('patients') ?>" class="active">Patients</a>
            <a href="<?= base_url('doctors') ?>">Doctors</a>
            <a href="<?= base_url('auth/logout') ?>" style="color: #f87171;">Logout</a>
        </div>
    </div>

    <div class="container">
        <?php if ($this->session->flashdata('success')): ?>
            <div class="alert-success"><?= $this->session->flashdata('success') ?></div>
        <?php endif; ?>

        <div class="card">
            <h3 style="margin-top: 0; margin-bottom: 15px;">➕ Register New Patient (CI3 Model Test)</h3>
            <?= form_open('patients/create') ?>
                <div class="form-row">
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:4px;">Full Name</label>
                        <input type="text" name="name" placeholder="e.g. Siti Rahayu" required>
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:4px;">Medical Record No. (No. RM)</label>
                        <input type="text" name="medical_record_number" placeholder="RM-2026-XXXX" required>
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:4px;">Blood Type</label>
                        <select name="blood_type" required>
                            <option value="A">A</option>
                            <option value="B">B</option>
                            <option value="AB">AB</option>
                            <option value="O" selected>O</option>
                        </select>
                    </div>
                    <div>
                        <button type="submit">Save Patient</button>
                    </div>
                </div>
            <?= form_close() ?>
        </div>

        <div class="card">
            <div class="flex-header">
                <h2 style="margin: 0; font-size: 20px;">Patient Directory</h2>
                <span style="color: #64748b; font-size: 14px;">Total: <?= count($patients) ?> records</span>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Medical Record (No. RM)</th>
                        <th>Patient Name</th>
                        <th>Blood Type</th>
                        <th>Registration Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($patients)): ?>
                        <?php foreach ($patients as $p): ?>
                            <tr>
                                <td>#<?= $p['id'] ?></td>
                                <td><span class="badge"><?= htmlspecialchars($p['medical_record_number']) ?></span></td>
                                <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                                <td><?= htmlspecialchars($p['blood_type']) ?></td>
                                <td style="color: #64748b;"><?= htmlspecialchars($p['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: #94a3b8; padding: 30px;">No patients found in database.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
