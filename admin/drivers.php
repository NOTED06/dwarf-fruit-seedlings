<?php
ini_set('session.gc_maxlifetime', 86400 * 30);
session_set_cookie_params(86400 * 30);
session_start();
require_once '../db_conn.php';
require_once 'check_admin.php';

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_driver') {
        $name = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            $error = 'Enter a name, valid email, and password of at least 8 characters.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO drivers (name, contact_number, email, password_hash, status) VALUES (?, ?, ?, ?, 'active')");
            $stmt->bind_param('ssss', $name, $contact, $email, $hash);
            if ($stmt->execute()) $message = 'Driver account created.';
            else $error = $conn->errno === 1062 ? 'That email is already registered.' : 'Unable to create driver account.';
        }
    } elseif ($action === 'update_driver') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($id < 1 || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter valid driver details.';
        } elseif ($password !== '' && strlen($password) < 8) {
            $error = 'A new password must be at least 8 characters.';
        } else {
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('UPDATE drivers SET name=?, contact_number=?, email=?, password_hash=? WHERE id=?');
                $stmt->bind_param('ssssi', $name, $contact, $email, $hash, $id);
            } else {
                $stmt = $conn->prepare('UPDATE drivers SET name=?, contact_number=?, email=? WHERE id=?');
                $stmt->bind_param('sssi', $name, $contact, $email, $id);
            }
            if ($stmt->execute()) $message = 'Driver details updated.';
            else $error = $conn->errno === 1062 ? 'That email is already registered.' : 'Unable to update driver.';
        }
    } elseif ($action === 'toggle_driver') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE drivers SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?");
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) $message = 'Driver availability updated.';
        else $error = 'Unable to update driver availability.';
    }
}

$drivers = [];
$result = $conn->query('SELECT id, name, contact_number, email, status, created_at FROM drivers ORDER BY name ASC');
while ($row = $result->fetch_assoc()) $drivers[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drivers - Dwarf Fruit Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../css/admin.css?v=<?= time() ?>">
    <style>body { font-family: Inter, Arial, sans-serif; } .driver-input { width:100%; padding:9px 10px; border:1px solid #e2e8f0; border-radius:8px; font-size:.8rem; } .driver-input:focus { outline:none; border-color:#22a06b; box-shadow:0 0 0 3px rgba(34,160,107,.12); }</style>
</head>
<body class="bg-gray-50 admin-page">
<?php include 'sidebar.php'; ?>
<main class="ml-64 p-8 min-h-screen">
    <div class="flex items-center justify-between mb-8">
        <div><h1 class="text-2xl font-bold text-gray-900">Drivers</h1><p class="text-gray-500 text-sm mt-1">Manage driver accounts and delivery availability.</p></div>
        <span class="px-3 py-2 rounded-lg text-sm font-semibold" style="background:#f0fdf4;color:#18794e;"><?= count($drivers) ?> drivers</span>
    </div>
    <?php if ($message): ?><div class="mb-5 p-3 rounded-lg text-sm" style="background:#ecfdf3;color:#18794e;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="mb-5 p-3 rounded-lg text-sm" style="background:#fff1f1;color:#c53030;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <section class="bg-white rounded-xl p-6 mb-8 shadow-sm border border-gray-100">
        <h2 class="text-lg font-bold text-gray-900 mb-4">Register Driver</h2>
        <form method="post" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
            <input type="hidden" name="action" value="add_driver">
            <label class="text-xs font-semibold text-gray-500">Name<input class="driver-input mt-1" name="name" required></label>
            <label class="text-xs font-semibold text-gray-500">Contact<input class="driver-input mt-1" name="contact_number" type="tel"></label>
            <label class="text-xs font-semibold text-gray-500">Email<input class="driver-input mt-1" name="email" type="email" required></label>
            <label class="text-xs font-semibold text-gray-500">Password<input class="driver-input mt-1" name="password" type="password" minlength="8" required></label>
            <button class="px-4 py-2.5 rounded-lg text-white text-sm font-semibold" style="background:#22a06b;" type="submit">Create Driver</button>
        </form>
    </section>

    <section class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100"><h2 class="text-lg font-bold text-gray-900">Registered Drivers</h2></div>
        <?php if (!$drivers): ?><p class="p-8 text-center text-sm text-gray-400">No drivers registered yet.</p><?php else: ?>
        <div class="overflow-x-auto"><table class="w-full text-left"><thead class="bg-gray-50 text-xs uppercase text-gray-500"><tr><th class="px-6 py-3">Driver</th><th class="px-6 py-3">Contact</th><th class="px-6 py-3">Status</th><th class="px-6 py-3">Actions</th></tr></thead><tbody>
        <?php foreach ($drivers as $driver): ?>
            <tr class="border-t border-gray-100 align-top">
                <td class="px-6 py-4"><div class="font-semibold text-sm text-gray-900"><?= htmlspecialchars($driver['name']) ?></div><div class="text-xs text-gray-500"><?= htmlspecialchars($driver['email']) ?></div></td>
                <td class="px-6 py-4 text-sm text-gray-600"><?= htmlspecialchars($driver['contact_number'] ?: '—') ?></td>
                <td class="px-6 py-4"><span class="px-2.5 py-1 rounded-full text-xs font-semibold <?= $driver['status'] === 'active' ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= ucfirst($driver['status']) ?></span></td>
                <td class="px-6 py-4"><div class="flex flex-wrap gap-2">
                    <form method="post"><input type="hidden" name="action" value="toggle_driver"><input type="hidden" name="id" value="<?= (int)$driver['id'] ?>"><button class="px-3 py-1.5 rounded border border-gray-200 text-xs font-semibold text-gray-600" type="submit"><?= $driver['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form>
                    <details><summary class="cursor-pointer px-3 py-1.5 rounded border border-gray-200 text-xs font-semibold text-gray-600">Edit</summary><form method="post" class="mt-3 grid gap-2 min-w-56"><input type="hidden" name="action" value="update_driver"><input type="hidden" name="id" value="<?= (int)$driver['id'] ?>"><input class="driver-input" name="name" value="<?= htmlspecialchars($driver['name']) ?>" required><input class="driver-input" name="contact_number" value="<?= htmlspecialchars($driver['contact_number'] ?? '') ?>"><input class="driver-input" name="email" type="email" value="<?= htmlspecialchars($driver['email']) ?>" required><input class="driver-input" name="password" type="password" minlength="8" placeholder="New password (optional)"><button class="px-3 py-1.5 rounded text-white text-xs font-semibold" style="background:#22a06b;" type="submit">Save</button></form></details>
                </div></td>
            </tr>
        <?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
