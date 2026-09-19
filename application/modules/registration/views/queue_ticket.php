<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Queue Ticket - <?= htmlspecialchars($visit['visit_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        body {
            font-family: 'Courier New', Courier, monospace;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: #f4f6f8;
            margin: 0;
        }
        .ticket {
            width: 320px;
            background: #ffffff;
            border: 2px dashed #000;
            padding: 24px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .hospital-title {
            font-size: 16px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .clinic-name {
            font-size: 14px;
            color: #333;
            margin-bottom: 12px;
        }
        .queue-box {
            border: 2px solid #000;
            padding: 12px;
            margin: 16px 0;
        }
        .queue-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .queue-number {
            font-size: 54px;
            font-weight: 900;
            margin: 4px 0;
        }
        .ticket-info {
            font-size: 11px;
            text-align: left;
            margin-top: 16px;
            line-height: 1.6;
        }
        .ticket-footer {
            font-size: 10px;
            margin-top: 20px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 8px;
        }
    </style>
</head>
<body>
    <div class="ticket">
        <div class="hospital-title">General Hospital</div>
        <div class="clinic-name"><?= htmlspecialchars($clinic_name ?? 'Outpatient Clinic', ENT_QUOTES, 'UTF-8'); ?></div>
        
        <div class="queue-box">
            <div class="queue-label">Queue Number</div>
            <div class="queue-number"><?= htmlspecialchars($visit['queue_number'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
        </div>

        <div class="ticket-info">
            <div><strong>Visit Token:</strong> <?= htmlspecialchars($visit['visit_number'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
            <div><strong>Date:</strong> <?= htmlspecialchars($visit['visit_date'] ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?></div>
            <div><strong>Patient:</strong> <?= htmlspecialchars($patient['full_name'] ?? 'Registered Patient', ENT_QUOTES, 'UTF-8'); ?></div>
            <div><strong>Doctor:</strong> <?= htmlspecialchars($doctor['full_name'] ?? 'Scheduled Doctor', ENT_QUOTES, 'UTF-8'); ?></div>
        </div>

        <div class="ticket-footer">
            Please wait for your queue number to be called on the display monitor.<br>
            Preserve this ticket for pharmacy and billing.
        </div>
    </div>
</body>
</html>
