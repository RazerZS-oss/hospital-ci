<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital - Doctors</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background-color: #f4f7f6; }
        .container { max-width: 800px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        h1, h2 { color: #333; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        table, th, td { border: 1px solid #ddd; }
        th, td { padding: 12px; text-align: left; }
        th { background-color: #17a2b8; color: white; }
        .nav { margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #eee; }
        .nav a { text-decoration: none; color: #007bff; margin-right: 15px; font-weight: bold; }
        .nav a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="container">
    <div class="nav">
        <a href="<?= base_url('patients') ?>">🧑‍⚕️ Patients</a>
        <a href="<?= base_url('doctors') ?>">🩺 Doctors</a>
    </div>

    <h1>Hospital Doctor Directory</h1>

    <h2>Our Doctors</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Specialization</th>
                <th>Phone</th>
                <th>Joined At</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($doctors) && is_array($doctors)): ?>
                <?php foreach ($doctors as $doctor): ?>
                    <tr>
                        <td><?= esc($doctor['id']) ?></td>
                        <td><?= esc($doctor['name']) ?></td>
                        <td><?= esc($doctor['specialization']) ?></td>
                        <td><?= esc($doctor['phone']) ?></td>
                        <td><?= esc($doctor['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5">No doctors found in the database.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</body>
</html>
