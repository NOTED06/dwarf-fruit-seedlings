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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Name, email, and password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $check = $conn->prepare('SELECT id FROM drivers WHERE email = ? LIMIT 1');
        $check->bind_param('s', $email);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error = 'That email is already registered.';
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO drivers (name, contact_number, email, password_hash, status) VALUES (?, ?, ?, ?, 'active')");
            $stmt->bind_param('ssss', $name, $contact, $email, $passwordHash);
            if ($stmt->execute()) {
                header('Location: login.php?registered=1');
                exit;
            }
            $error = 'Registration failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Registration - Dwarf Fruit</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #FAF5EF; color: #17221d; font-family: Arial, sans-serif; }
        .card { width: 100%; max-width: 420px; background: #fff; padding: 32px; border: 1px solid #e2e8e4; border-radius: 16px; box-shadow: 0 16px 40px rgba(20, 50, 35, .08); }
        h1 { margin: 0 0 6px; font-size: 24px; }
        p { margin: 0 0 24px; color: #65736b; font-size: 14px; }
        label { display: block; margin: 14px 0 6px; color: #435149; font-size: 13px; font-weight: 700; }
        input { width: 100%; padding: 12px; border: 1px solid #d7e0da; border-radius: 8px; font: inherit; }
        button { width: 100%; margin-top: 22px; padding: 12px; border: 0; border-radius: 8px; background: #22a06b; color: #fff; font-weight: 700; cursor: pointer; }
        .error { margin-bottom: 14px; padding: 10px 12px; border-radius: 8px; background: #fff1f1; color: #c53030; font-size: 13px; }
        .login-link { display: block; margin-top: 18px; text-align: center; color: #22a06b; font-size: 13px; font-weight: 700; text-decoration: none; }
    </style>
</head>
<body>
    <main class="card">
        <h1>Driver Registration</h1>
        <p>Create an account to receive and manage deliveries.</p>
        <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post">
            <label for="name">Full Name</label>
            <input id="name" name="name" type="text" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required autocomplete="name">
            <label for="contact_number">Contact Number</label>
            <input id="contact_number" name="contact_number" type="tel" value="<?= htmlspecialchars($_POST['contact_number'] ?? '') ?>" autocomplete="tel">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autocomplete="username">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" minlength="8" required autocomplete="new-password">
            <label for="confirm_password">Confirm Password</label>
            <input id="confirm_password" name="confirm_password" type="password" minlength="8" required autocomplete="new-password">
            <button type="submit">Create Driver Account</button>
        </form>
        <a class="login-link" href="login.php">Already have an account? Sign in</a>
    </main>
</body>
</html>
