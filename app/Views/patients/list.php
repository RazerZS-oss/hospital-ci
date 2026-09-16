<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Patient Management</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background-color: #f4f7f6; }
        .container { max-width: 800px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        h1, h2 { color: #333; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        table, th, td { border: 1px solid #ddd; }
        th, td { padding: 12px; text-align: left; }
        th { background-color: #007bff; color: white; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; }
        input[type="text"] { width: 100%; padding: 8px; box-sizing: border-box; }
        button { background-color: #28a745; color: white; border: none; padding: 10px 15px; cursor: pointer; border-radius: 4px; }
        button:hover { background-color: #218838; }
        .alert { padding: 10px; background-color: #d4edda; color: #155724; margin-bottom: 20px; border-radius: 4px; }
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

    <h1>Hospital Patient Directory</h1>

    <?php if (session()->getFlashdata('success')): ?>
        <div class="alert"><?= session()->getFlashdata('success') ?></div>
    <?php endif; ?>

    <h2>All Patients</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Medical Record Number (MRN)</th>
                <th>Name</th>
                <th>Blood Type</th>
                <th>Registered At</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($patients) && is_array($patients)): ?>
                <?php foreach ($patients as $patient): ?>
                    <tr>
                        <td><?= esc($patient['id']) ?></td>
                        <td><?= esc($patient['medical_record_number']) ?></td>
                        <td><?= esc($patient['name']) ?></td>
                        <td><?= esc($patient['blood_type']) ?></td>
                        <td><?= esc($patient['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5">No patients found in the database.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <h2>Admit New Patient</h2>
    <form action="<?= base_url('patients/create') ?>" method="post">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="name">Patient Name:</label>
            <input type="text" id="name" name="name" required>
        </div>
        <div class="form-group">
            <label for="medical_record_number">Medical Record Number (MRN):</label>
            <input type="text" id="medical_record_number" name="medical_record_number" required>
        </div>
        <div class="form-group">
            <label for="blood_type">Blood Type:</label>
            <input type="text" id="blood_type" name="blood_type" placeholder="e.g., O+, A-, AB+">
        </div>
        <button type="submit">Register Patient</button>
    </form>
</div>

</body>
</html>
