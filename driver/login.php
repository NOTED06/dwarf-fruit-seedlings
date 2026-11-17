<?php
ini_set('session.gc_maxlifetime', 86400 * 30);
session_set_cookie_params(86400 * 30);
session_start();
require_once '../db_conn.php';

if (isset($_SESSION['driver_id']) && ($_SESSION['type'] ?? '') === 'driver') {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = isset($_GET['registered']) ? 'Registration successful. You can now sign in.' : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } else {
        $stmt = $conn->prepare("SELECT id, name, email, password_hash FROM drivers WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $driver = $stmt->get_result()->fetch_assoc();
        if ($driver && password_verify($password, $driver['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['driver_id'] = (int)$driver['id'];
            $_SESSION['driver_name'] = $driver['name'];
            $_SESSION['driver_email'] = $driver['email'];
            $_SESSION['type'] = 'driver';
            header('Location: dashboard.php');
            exit;
        }
        $error = 'Invalid driver credentials or inactive account.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Login - Dwarf Fruit</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background: linear-gradient(135deg, #edf8ef 0%, #eaf7df 100%);
            color: #17221d;
            font-family: Inter, Arial, sans-serif;
        }
        .card {
            width: 100%;
            max-width: 420px;
            background: rgba(255,255,255,0.96);
            padding: 32px 28px 26px;
            border: 1px solid #dfeadf;
            border-radius: 20px;
            box-shadow: 0 18px 40px rgba(28, 74, 44, 0.1);
        }
        h1 {
            margin: 0 0 8px;
            font-size: 32px;
            letter-spacing: -0.04em;
            color: #133d2e;
        }
        p {
            margin: 0 0 24px;
            color: #4d6359;
            font-size: 15px;
        }
        label {
            display: block;
            margin: 18px 0 8px;
            color: #244f3d;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.02em;
        }
        .input-wrap {
            position: relative;
        }
        input {
            width: 100%;
            padding: 14px 48px 14px 14px;
            border: 1.5px solid #d8e5da;
            border-radius: 12px;
            font: inherit;
            background: #f9fbf9;
            color: #1b2e25;
            transition: border-color 0.18s ease, box-shadow 0.18s ease;
        }
        input:focus {
            outline: none;
            border-color: #27a766;
            box-shadow: 0 0 0 4px rgba(39, 167, 102, 0.12);
            background: #fff;
        }
        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #1a7d54;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            padding: 6px 8px;
            border-radius: 8px;
        }
        .toggle-password:hover {
            background: rgba(39, 167, 102, 0.08);
        }
        button[type="submit"] {
            width: 100%;
            margin-top: 24px;
            padding: 14px;
            border: 0;
            border-radius: 12px;
            background: linear-gradient(180deg, #1ea36b 0%, #167c52 100%);
            color: #fff;
            font-weight: 800;
            font-size: 16px;
            cursor: pointer;
            box-shadow: 0 12px 22px rgba(22, 124, 82, 0.22);
        }
        .error {
            margin-bottom: 14px;
            padding: 12px 14px;
            border-radius: 10px;
            background: #fff3d9;
            border: 1px solid #f1d98b;
            color: #8a6202;
            font-size: 13px;
            font-weight: 700;
        }
        .success {
            margin-bottom: 14px;
            padding: 12px 14px;
            border-radius: 10px;
            background: #ebfaef;
            border: 1px solid #bfe8ca;
            color: #1b6b3c;
            font-size: 13px;
            font-weight: 700;
        }
        .register-link {
            display: block;
            margin-top: 18px;
            text-align: center;
            color: #167d52;
            font-size: 14px;
            font-weight: 800;
            text-decoration: none;
        }
        .register-link:hover { text-decoration: underline; }
    </style>
</head>
<body style="background:#FAF5EF;">
    <main class="card">
        <h1>Driver Login</h1>
        <p>Sign in to view your assigned deliveries.</p>
        <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <form method="post" autocomplete="on">
            <label for="email">Email</label>
            <div class="input-wrap">
                <input id="email" name="email" type="email" required autocomplete="username" placeholder="Enter your email">
            </div>

            <label for="password">Password</label>
            <div class="input-wrap">
                <input id="password" name="password" type="password" required autocomplete="current-password" placeholder="Enter your password">
                <button type="button" class="toggle-password" onclick="togglePassword()" aria-label="Show password">Show</button>
            </div>

            <button type="submit">Sign In</button>
        </form>
        <a class="register-link" href="register.php">Create a driver account</a>
    </main>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleButton = document.querySelector('.toggle-password');
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
            toggleButton.textContent = isHidden ? 'Hide' : 'Show';
            toggleButton.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        }
    </script>
</body>
</html>
