<!DOCTYPE html>
<html>
<head>
    <title>Hospital Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background-color: #f4f7f6; display: flex; }
        .sidebar { width: 250px; background-color: #2c3e50; color: white; height: 100vh; padding: 20px; box-sizing: border-box; }
        .sidebar a { color: #bdc3c7; text-decoration: none; display: block; margin-bottom: 15px; font-size: 16px; }
        .sidebar a:hover { color: white; }
        .main-content { flex: 1; padding: 40px; box-sizing: border-box; }
        h1 { color: #333; margin-top: 0; }
        .badge { background: #e74c3c; color: white; padding: 3px 8px; border-radius: 12px; font-size: 12px; vertical-align: middle; margin-left: 10px; }
        .card-container { display: flex; gap: 20px; margin-top: 30px; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); flex: 1; text-align: center; }
        .card h3 { margin: 0 0 10px 0; color: #7f8c8d; }
        .card .number { font-size: 48px; font-weight: bold; color: #3498db; margin: 0; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>🏥 Menu</h2>
        <a href="<?= base_url('dashboard') ?>">📊 Dashboard</a>
        <a href="<?= base_url('patients') ?>">🧑‍⚕️ Patients Directory</a>
        <a href="<?= base_url('doctors') ?>">🩺 Doctors Directory</a>
        
        <?php if($user_role === 'admin'): ?>
            <a href="#" style="color: #e74c3c;">⚙️ IT Administration</a>
        <?php endif; ?>

        <div style="margin-top: 50px; border-top: 1px solid #34495e; padding-top: 20px;">
            <p style="color: #95a5a6; font-size: 14px;">Logged in as:</p>
            <p style="margin: 0; font-weight: bold;"><?= esc($user_name) ?> <span class="badge"><?= strtoupper(esc($user_role)) ?></span></p>
            <br>
            <a href="<?= base_url('logout') ?>" style="color: #e74c3c;">🚪 Secure Logout</a>
        </div>
    </div>
    <div class="main-content">
        <h1>Welcome back, <?= esc($user_name) ?></h1>
        <p>This system tracks live hospital metrics based on your clearance level.</p>

        <div class="card-container">
            <div class="card">
                <h3>Total Admitted Patients</h3>
                <p class="number"><?= $total_patients ?></p>
            </div>
            <div class="card">
                <h3>Registered Doctors</h3>
                <p class="number"><?= $total_doctors ?></p>
            </div>
        </div>
    </div>
</body>
</html>
