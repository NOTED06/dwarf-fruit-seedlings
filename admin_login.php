<?php
ini_set('session.gc_maxlifetime', 86400 * 30);
session_set_cookie_params(86400 * 30);
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'db_conn.php';
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Email and password are required';
    } else {
        $stmt = $conn->prepare('SELECT id, username, password FROM admins WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows === 1) {
            $row = $result->fetch_assoc();
            if (password_verify($password, $row['password'])) {
                $_SESSION['admin_id'] = $row['id'];
                $_SESSION['admin_email'] = $row['username'];
                $_SESSION['type'] = 'admin';
                header('Location: admin/admin_dashboard.php');
                exit;
            }
        }
        $error = 'Invalid email or password';
    }
}

if (isset($_SESSION['admin_id'])) {
    header('Location: admin/admin_dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Dwarf Fruit</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        
        :root {
            --orange-primary:  #22a06b;
            --orange-dark:     #18794e;
            --orange-light:    #4fc58a;
            --orange-glow:     rgba(34,160,107,0.2);
            --bg-dark:         #ffffff;
            --bg-card:         #ffffff;
            --bg-input:        #faf9f6;
            --border-color:    #eaeaea;
            --text-muted:      #666666;
            --text-dim:        #999999;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-dark);
            color: #111111;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        
        body::before {
            content: '';
            position: absolute;
            top: -20%;
            left: -20%;
            width: 140%;
            height: 140%;
            background:
                radial-gradient(ellipse at 20% 40%, rgba(34, 160, 107, 0.07) 0%, transparent 55%),
                radial-gradient(ellipse at 80% 60%, rgba(79, 197, 138, 0.05) 0%, transparent 55%);
            animation: bgFloat 20s ease-in-out infinite;
            z-index: 0;
        }
        @keyframes bgFloat {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            33%       { transform: translate(20px, -20px) rotate(0.5deg); }
            66%       { transform: translate(-15px, 15px) rotate(-0.5deg); }
        }

        .login-card {
            position: relative;
            z-index: 1;
            background: var(--bg-card);
            border: 1.5px solid var(--border-color);
            border-radius: 20px;
            padding: 2.5rem;
            width: 90%;
            max-width: 420px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.08);
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-logo {
            width: 76px;
            height: 76px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            box-shadow: 0 8px 25px var(--orange-glow);
            object-fit: contain;
        }
        .login-header h1 { font-size: 1.5rem; font-weight: 700; color: #111111; }
        .login-header p  { color: var(--text-muted); font-size: 0.9rem; margin-top: 0.25rem; }

        .form-group { margin-bottom: 1.25rem; }
        .form-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 6px;
        }
        .form-input {
            width: 100%;
            padding: 12px 14px;
            background: var(--bg-input);
            border: 1.5px solid var(--border-color);
            color: #111111;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: inherit;
            transition: all 0.3s;
            outline: none;
        }
        .form-input::placeholder { color: var(--text-dim); }
        .form-input:focus {
            border-color: var(--orange-primary);
            box-shadow: 0 0 0 3px rgba(34,160,107,0.12);
            background: #ffffff;
        }
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--orange-primary), var(--orange-dark));
            color: #fff;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px var(--orange-glow);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-family: inherit;
            margin-top: 0.5rem;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(34,160,107,0.4);
        }
        .error-msg {
            background: rgba(239,68,68,0.08);
            border: 1px solid rgba(239,68,68,0.25);
            color: #ef4444;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 1rem;
            text-align: center;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 1.5rem;
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        .back-link a {
            color: var(--orange-primary);
            text-decoration: none;
            font-weight: 600;
        }
        .back-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <img src="img/logo.png" alt="Dwarf Fruit logo" class="login-logo">
            <h1>Admin Login</h1>
            <p>Dwarf Fruit</p>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="admin_login.php">
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-input" placeholder="admin@dwarffruit.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-input" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn-login">Sign In</button>
        </form>
        <div class="back-link">
            <a href="index.php">&larr; Back to Website</a>
        </div>
    </div>
</body>
</html>
