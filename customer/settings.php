<?php
ini_set('session.gc_maxlifetime', 86400 * 30);
session_set_cookie_params(86400 * 30);
session_start();
require_once '../db_conn.php';
require_once 'check_customer.php';

$customerId = (int)$_SESSION['user_id'];
$errors = [];
$success = '';
$profileUploadDir = __DIR__ . '/../uploads/profiles';
if (!is_dir($profileUploadDir)) mkdir($profileUploadDir, 0755, true);
$accountStmt = $conn->prepare('SELECT name, email, phone, address, profile_picture, password FROM customer WHERE customer_id = ? LIMIT 1');
$accountStmt->bind_param('i', $customerId);
$accountStmt->execute();
$customer = $accountStmt->get_result()->fetch_assoc();
if (!$customer) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $profilePicture = null;

    if ($name === '') $errors[] = 'Name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if ($newPassword !== '' && strlen($newPassword) < 8) $errors[] = 'New password must be at least 8 characters.';
    if ($newPassword !== $confirmPassword) $errors[] = 'New passwords do not match.';

    if (!$errors) {
        $emailCheck = $conn->prepare('SELECT customer_id FROM customer WHERE email = ? AND customer_id <> ? LIMIT 1');
        $emailCheck->bind_param('si', $email, $customerId);
        $emailCheck->execute();
        if ($emailCheck->get_result()->num_rows > 0) $errors[] = 'That email address is already registered.';
    }

    if (!$errors && $newPassword !== '') {
        if ($currentPassword === '' || !password_verify($currentPassword, $customer['password'])) {
            $errors[] = 'Enter your current password to set a new password.';
        }
    }

    if (!$errors && isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload = $_FILES['profile_picture'];
        $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $imageInfo = $upload['error'] === UPLOAD_ERR_OK ? @getimagesize($upload['tmp_name']) : false;
        $mime = $imageInfo['mime'] ?? '';
        if ($upload['error'] !== UPLOAD_ERR_OK || !$imageInfo || !isset($allowedMimes[$mime])) {
            $errors[] = 'Upload a valid JPG, PNG, GIF, or WEBP image.';
        } elseif ($upload['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Profile picture must be 5 MB or smaller.';
        } else {
            $profilePicture = 'uploads/profiles/customer_' . $customerId . '_' . bin2hex(random_bytes(8)) . '.' . $allowedMimes[$mime];
            if (!move_uploaded_file($upload['tmp_name'], __DIR__ . '/../' . $profilePicture)) {
                $errors[] = 'Unable to save the profile picture.';
                $profilePicture = null;
            }
        }
    }

    if (!$errors) {
        if ($profilePicture !== null && $newPassword !== '') {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE customer SET name = ?, email = ?, phone = ?, address = ?, password = ?, profile_picture = ? WHERE customer_id = ?');
            $stmt->bind_param('ssssssi', $name, $email, $phone, $address, $hashedPassword, $profilePicture, $customerId);
        } elseif ($profilePicture !== null) {
            $stmt = $conn->prepare('UPDATE customer SET name = ?, email = ?, phone = ?, address = ?, profile_picture = ? WHERE customer_id = ?');
            $stmt->bind_param('sssssi', $name, $email, $phone, $address, $profilePicture, $customerId);
        } elseif ($newPassword !== '') {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE customer SET name = ?, email = ?, phone = ?, address = ?, password = ? WHERE customer_id = ?');
            $stmt->bind_param('sssssi', $name, $email, $phone, $address, $hashedPassword, $customerId);
        } else {
            $stmt = $conn->prepare('UPDATE customer SET name = ?, email = ?, phone = ?, address = ? WHERE customer_id = ?');
            $stmt->bind_param('ssssi', $name, $email, $phone, $address, $customerId);
        }

        if ($stmt->execute()) {
            $_SESSION['username'] = $name;
            $_SESSION['email'] = $email;
            if ($profilePicture !== null) {
                $oldPicture = $customer['profile_picture'] ?? '';
                if ($oldPicture && is_file(__DIR__ . '/../' . $oldPicture)) unlink(__DIR__ . '/../' . $oldPicture);
            }
            $success = 'Account details updated successfully.';
        } else {
            $errors[] = 'Unable to update your account. Please try again.';
        }
    }
}

$stmt = $conn->prepare('SELECT name, email, phone, address, profile_picture FROM customer WHERE customer_id = ? LIMIT 1');
$stmt->bind_param('i', $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
if (!$customer) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Dwarf Fruit</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/customer.css?v=<?= time() ?>">
    <style>
        .settings-page { max-width: 760px; margin: 0 auto; padding: 48px 24px 80px; }
        .settings-header { margin-bottom: 28px; }
        .settings-header h1 { margin: 0 0 6px; font-size: 1.8rem; color: #111; }
        .settings-header p { margin: 0; color: #6b7280; font-size: .85rem; }
        .settings-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 28px; box-shadow: 0 12px 30px rgba(17,24,39,.06); }
        .settings-section + .settings-section { margin-top: 30px; padding-top: 26px; border-top: 1px solid #edf0f2; }
        .settings-section h2 { margin: 0 0 16px; font-size: 1rem; color: #111; }
        .settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .settings-field { display: flex; flex-direction: column; gap: 6px; }
        .settings-field.full { grid-column: 1 / -1; }
        .settings-field label { font-size: .72rem; font-weight: 700; color: #374151; }
        .settings-field input, .settings-field textarea { width: 100%; box-sizing: border-box; border: 1px solid #dfe3e8; border-radius: 9px; padding: 11px 12px; font: inherit; font-size: .84rem; color: #111; outline: none; }
        .settings-field textarea { min-height: 92px; resize: vertical; }
        .settings-field input:focus, .settings-field textarea:focus { border-color: #ff6b35; box-shadow: 0 0 0 3px rgba(255,107,53,.1); }
        .settings-hint { margin: 0 0 16px; color: #6b7280; font-size: .76rem; }
        .settings-alert { border-radius: 9px; padding: 11px 13px; margin-bottom: 18px; font-size: .8rem; }
        .settings-alert.error { background: #fff1f2; color: #be123c; }
        .settings-alert.success { background: #ecfdf5; color: #047857; }
        .profile-picture-editor { display: flex; align-items: center; gap: 16px; }
        .profile-picture-preview { width: 76px; height: 76px; border-radius: 50%; object-fit: cover; border: 2px solid #f1f3f5; }
        .profile-picture-fallback { display: flex; align-items: center; justify-content: center; background: #ff6b35; color: #fff; font-size: 1.8rem; font-weight: 800; }
        .settings-upload { display: inline-block; border: 1px solid #ff6b35; border-radius: 9px; padding: 9px 13px; color: #e85a27; font-size: .78rem; font-weight: 700; cursor: pointer; }
        .settings-upload:hover { background: #fff7f3; }
        .profile-picture-editor .settings-hint { margin: 8px 0 0; }
        .settings-actions { display: flex; justify-content: flex-end; margin-top: 26px; }
        .settings-save { border: 0; border-radius: 9px; padding: 11px 20px; background: #ff6b35; color: #fff; font: inherit; font-size: .82rem; font-weight: 700; cursor: pointer; }
        .settings-save:hover { background: #e85a27; }
        @media (max-width: 600px) {
            .settings-page { padding: 18px 12px 60px; }
            .settings-card { padding: 18px 14px; border-radius: 12px; }
            .settings-grid { grid-template-columns: 1fr; }
            .settings-field.full { grid-column: auto; }
            .profile-picture-editor { flex-direction: column; align-items: flex-start; gap: 12px; }
            .settings-actions { width: 100%; }
            .settings-save { width: 100%; text-align: center; }
        }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<main class="settings-page">
    <header class="settings-header">
        <h1>Account Settings</h1>
        <p>Update your account details and password.</p>
    </header>

    <form method="POST" enctype="multipart/form-data" class="settings-card">
        <?php if ($errors): ?>
            <div class="settings-alert error"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
        <?php elseif ($success): ?>
            <div class="settings-alert success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <section class="settings-section">
            <h2>Profile picture</h2>
            <div class="profile-picture-editor">
                <?php if (!empty($customer['profile_picture'])): ?>
                    <img src="../<?= htmlspecialchars($customer['profile_picture']) ?>?v=<?= filemtime(__DIR__ . '/../' . $customer['profile_picture']) ?: time() ?>" alt="Profile picture" class="profile-picture-preview">
                <?php else: ?>
                    <div class="profile-picture-preview profile-picture-fallback"><?= strtoupper(substr($customer['name'] ?? 'C', 0, 1)) ?></div>
                <?php endif; ?>
                <div>
                    <label class="settings-upload" for="profile_picture">Change profile picture</label>
                    <input id="profile_picture" name="profile_picture" type="file" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
                    <p class="settings-hint">JPG, PNG, GIF, or WEBP, up to 5 MB.</p>
                </div>
            </div>
        </section>

        <section class="settings-section">
            <h2>Personal details</h2>
            <div class="settings-grid">
                <div class="settings-field"><label for="name">Full name</label><input id="name" name="name" value="<?= htmlspecialchars($customer['name'] ?? '') ?>" required></div>
                <div class="settings-field"><label for="email">Email address</label><input id="email" name="email" type="email" value="<?= htmlspecialchars($customer['email'] ?? '') ?>" required></div>
                <div class="settings-field"><label for="phone">Phone number</label><input id="phone" name="phone" value="<?= htmlspecialchars($customer['phone'] ?? '') ?>"></div>
                <div class="settings-field full"><label for="address">Address</label><textarea id="address" name="address"><?= htmlspecialchars($customer['address'] ?? '') ?></textarea></div>
            </div>
        </section>

        <section class="settings-section">
            <h2>Change password</h2>
            <p class="settings-hint">Leave these fields blank to keep your current password.</p>
            <div class="settings-grid">
                <div class="settings-field full"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password"></div>
                <div class="settings-field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8"></div>
                <div class="settings-field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8"></div>
            </div>
        </section>

        <div class="settings-actions"><button class="settings-save" type="submit">Save changes</button></div>
    </form>
</main>
</body>
</html>
