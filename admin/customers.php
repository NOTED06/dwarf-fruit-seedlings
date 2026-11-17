<?php
ini_set('session.gc_maxlifetime', 86400 * 30);
session_set_cookie_params(86400 * 30);
session_start();
require_once '../db_conn.php';
require_once 'check_admin.php';

$customersRes = $conn->query("SELECT c.*, (SELECT COUNT(*) FROM customer_orders WHERE customer_id = c.customer_id) as order_count FROM customer c ORDER BY c.created_at DESC");
$customers = [];
while ($r = $customersRes->fetch_assoc()) {
    $customers[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers -  Dwarf Fruit Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../css/admin.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50 admin-page">

<?php include 'sidebar.php'; ?>

<main class="ml-64 p-8 min-h-screen">
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Customers</h1>
            <p class="text-gray-500 text-sm mt-1">View all registered customers</p>
        </div>
        <div class="text-sm text-gray-400"><?= count($customers) ?> total customers</div>
    </div>

    <div class="bg-white rounded-xl stat-card overflow-hidden">
        <table class="admin-table w-full">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Address</th>
                    <th>Orders</th>
                    <th>Status</th>
                    <th>Joined</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr><td colspan="7" class="text-center text-gray-400 py-8">No customers yet</td></tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): ?>
                    <tr>
                        <td>
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-bold" style="background:#ff6b35;">
                                    <?= strtoupper(substr($c['name'] ?? 'U', 0, 1)) ?>
                                </div>
                                <span class="font-medium text-gray-900"><?= htmlspecialchars($c['name'] ?: 'N/A') ?></span>
                            </div>
                        </td>
                        <td class="text-gray-600"><?= htmlspecialchars($c['email'] ?? 'N/A') ?></td>
                        <td class="text-gray-600"><?= htmlspecialchars($c['phone'] ?? 'N/A') ?></td>
                        <td class="text-gray-600 text-sm"><?= htmlspecialchars($c['address'] ?? 'N/A') ?></td>
                        <td class="font-medium"><?= $c['order_count'] ?></td>
                        <td>
                            <?php
                            $isOnline = false;
                            if (!empty($c['last_active'])) {
                                $lastActive = strtotime($c['last_active']);
                                $isOnline = (time() - $lastActive) < 300; 
                            }
                            ?>
                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1 rounded-full <?= $isOnline ? 'bg-green-50 text-green-600' : 'bg-gray-100 text-gray-500' ?>">
                                <span class="w-2 h-2 rounded-full <?= $isOnline ? 'bg-green-500' : 'bg-gray-400' ?>"></span>
                                <?= $isOnline ? 'Active' : 'Offline' ?>
                            </span>
                        </td>
                        <td class="text-gray-500 text-sm"><?= date('M d, Y', strtotime($c['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
</body>
</html>
