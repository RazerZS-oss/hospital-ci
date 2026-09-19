<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctors Directory - CodeIgniter 3</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background-color: #f8fafc; margin: 0; padding: 0; color: #1e293b; }
        .navbar { background-color: #0f172a; color: white; padding: 16px 32px; display: flex; justify-content: space-between; align-items: center; }
        .navbar a { color: #94a3b8; text-decoration: none; margin-left: 20px; font-size: 14px; font-weight: 500; }
        .navbar a:hover, .navbar a.active { color: white; }
        .container { max-width: 1100px; margin: 30px auto; padding: 0 20px; }
        .card { background: white; padding: 24px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .flex-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 12px 16px; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
        th { background-color: #f1f5f9; color: #475569; font-weight: 600; }
        .badge { background: #dcfce7; color: #15803d; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 12px; }
    </style>
</head>
<body>
    <div class="navbar">
        <div style="font-size: 18px; font-weight: bold;">🏥 Central Hospital System (CI3)</div>
        <div>
            <a href="<?= base_url('dashboard') ?>">Dashboard</a>
            <a href="<?= base_url('patients') ?>">Patients</a>
            <a href="<?= base_url('doctors') ?>" class="active">Doctors</a>
            <a href="<?= base_url('auth/logout') ?>" style="color: #f87171;">Logout</a>
        </div>
    </div>

    <div class="container">
        <div class="card">
            <div class="flex-header">
                <h2 style="margin: 0; font-size: 20px;">Medical Specialists & Doctors</h2>
                <span style="color: #64748b; font-size: 14px;">Total: <?= count($doctors) ?> specialists</span>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Doctor Name</th>
                        <th>Specialization</th>
                        <th>Phone Number</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($doctors)): ?>
                        <?php foreach ($doctors as $d): ?>
                            <tr>
                                <td>#<?= $d['id'] ?></td>
                                <td><strong><?= htmlspecialchars($d['name']) ?></strong></td>
                                <td><span class="badge"><?= htmlspecialchars($d['specialization']) ?></span></td>
                                <td style="color: #64748b;"><?= htmlspecialchars($d['phone'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: #94a3b8; padding: 30px;">No doctors registered.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
