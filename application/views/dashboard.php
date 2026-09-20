<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Dashboard - CodeIgniter 3</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background-color: #f8fafc; margin: 0; padding: 0; color: #1e293b; }
        .navbar { background-color: #0f172a; color: white; padding: 16px 32px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: #94a3b8; text-decoration: none; margin-left: 20px; font-size: 14px; font-weight: 500; }
        .navbar a:hover, .navbar a.active { color: white; }
        .container { max-width: 1100px; margin: 40px auto; padding: 0 20px; }
        .badge-ci3 { background-color: #dc2626; color: white; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; margin-left: 8px; }
        .welcome-card { background: white; padding: 24px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 24px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-left: 4px solid #2563eb; }
        .stat-card.doctors { border-left-color: #10b981; }
        .stat-title { color: #64748b; font-size: 14px; font-weight: 600; text-transform: uppercase; margin-bottom: 8px; }
        .stat-number { font-size: 36px; font-weight: bold; color: #0f172a; margin: 0; }
        .btn { display: inline-block; padding: 10px 18px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600; cursor: pointer; }
        .btn-primary { background: #2563eb; color: white; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-danger { background: #ef4444; color: white; }
    </style>
</head>
<body>
    <div class="navbar">
        <div style="font-size: 18px; font-weight: bold; display: flex; align-items: center;">
            🏥 Central Hospital System <span class="badge-ci3">CodeIgniter 3</span>
        </div>
        <div>
            <a href="<?= base_url('dashboard') ?>" class="active">Dashboard</a>
            <a href="<?= base_url('patients') ?>">Patients</a>
            <a href="<?= base_url('doctors') ?>">Doctors</a>
            <a href="<?= base_url('emr') ?>">EMR Queue</a>
            <a href="<?= base_url('auth/logout') ?>" style="color: #f87171;">Logout (<?= htmlspecialchars($user_name) ?>)</a>
        </div>
    </div>

    <div class="container">
        <div class="welcome-card">
            <h1 style="margin: 0 0 8px 0; font-size: 24px;">Welcome, <?= htmlspecialchars($user_name) ?>!</h1>
            <p style="margin: 0; color: #64748b;">Logged in with role: <strong><?= strtoupper($user_role) ?></strong> | Architecture: <strong>CodeIgniter 3.1.13 + Docker</strong></p>
        </div>

        <div class="grid">
            <div class="stat-card">
                <div class="stat-title">Total Registered Patients</div>
                <div class="stat-number"><?= $total_patients ?></div>
                <div style="margin-top: 15px;">
                    <a href="<?= base_url('patients') ?>" class="btn btn-primary" style="font-size: 12px; padding: 6px 12px;">View Patients &rarr;</a>
                </div>
            </div>

            <div class="stat-card doctors">
                <div class="stat-title">Active Medical Specialists</div>
                <div class="stat-number"><?= $total_doctors ?></div>
                <div style="margin-top: 15px;">
                    <a href="<?= base_url('doctors') ?>" class="btn btn-primary" style="background: #10b981; font-size: 12px; padding: 6px 12px;">View Doctors &rarr;</a>
                </div>
            </div>

            <div class="stat-card" style="border-left-color: #8b5cf6;">
                <div class="stat-title">Clinical Workspace</div>
                <div class="stat-number">EMR</div>
                <div style="margin-top: 15px;">
                    <a href="<?= base_url('emr') ?>" class="btn btn-primary" style="background: #8b5cf6; font-size: 12px; padding: 6px 12px;">Doctor Queue &rarr;</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
