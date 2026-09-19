<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🏥 Central Hospital IT - CodeIgniter 3</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background-color: #1e293b; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .login-box { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 15px 35px rgba(0,0,0,0.4); width: 100%; max-width: 400px; }
        .login-box h2 { margin-top: 0; color: #0f172a; text-align: center; font-size: 24px; }
        .badge { display: block; text-align: center; margin-bottom: 20px; font-size: 12px; color: #dc2626; background: #fee2e2; padding: 4px 10px; border-radius: 9999px; font-weight: bold; width: fit-content; margin-left: auto; margin-right: auto; }
        .form-group { margin-bottom: 18px; }
        label { display: block; margin-bottom: 6px; color: #475569; font-weight: 600; font-size: 14px; }
        input[type="text"], input[type="password"] { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; font-size: 15px; }
        input:focus { outline: none; border-color: #2563eb; ring: 2px solid #93c5fd; }
        button { width: 100%; padding: 12px; background-color: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: 600; margin-top: 10px; }
        button:hover { background-color: #1d4ed8; }
        .alert { background: #ef4444; color: white; padding: 12px; border-radius: 6px; margin-bottom: 20px; text-align: center; font-size: 14px; }
        .demo-creds { margin-top: 25px; padding-top: 20px; border-top: 1px solid #e2e8f0; font-size: 13px; color: #64748b; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>🏥 Central Hospital IT</h2>
        <span class="badge">Powered by CodeIgniter 3</span>

        <?php if ($this->session->flashdata('error')): ?>
            <div class="alert"><?= $this->session->flashdata('error') ?></div>
        <?php endif; ?>

        <form action="<?= base_url('auth/login') ?>" method="post">
            <div class="form-group">
                <label>Staff Username</label>
                <input type="text" name="username" placeholder="admin" required autofocus>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="admin123" required>
            </div>
            <button type="submit">Log In to Hospital System</button>
        </form>

        <div class="demo-creds">
            <strong>Default Accounts:</strong><br>
            • Admin: <code>admin</code> / <code>admin123</code><br>
            • Doctor: <code>drbudi</code> / <code>doctor123</code>
        </div>
    </div>
</body>
</html>
