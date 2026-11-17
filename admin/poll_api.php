<?php
ini_set('session.gc_maxlifetime', 86400 * 30);
session_set_cookie_params(86400 * 30);
session_start();
require_once '../db_conn.php';
require_once 'check_admin.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action === 'sidebar_badges') {
    require_once __DIR__ . '/../seen_helper.php';
    $adminId = $_SESSION['admin_id'] ?? 0;
    $badges = getAdminBadgeCounts($conn, $adminId);
    echo json_encode($badges);
    exit;
}

if ($action === 'orders') {
    $driverFilter = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : 0;
    $driverWhere = $driverFilter === -1 ? ' WHERE co.driver_id IS NULL' : ($driverFilter > 0 ? ' WHERE co.driver_id = ' . $driverFilter : '');
    $res = $conn->query("SELECT co.*, c.name as customer_name, c.email as customer_email, c.address as customer_address, d.name as driver_name, p.img as product_img, pv.image as variant_img FROM customer_orders co LEFT JOIN customer c ON co.customer_id = c.customer_id LEFT JOIN drivers d ON co.driver_id = d.id LEFT JOIN product p ON co.product_id = p.product_id LEFT JOIN product_variants pv ON co.variant_id = pv.variant_id$driverWhere ORDER BY co.created_at DESC");
    $ordersByBatch = [];
    while ($r = $res->fetch_assoc()) {
        $key = $r['batch_id'] ?: 'solo_' . $r['id'];
        if (!isset($ordersByBatch[$key])) {
            $r['items_count'] = 0;
            $r['total_quantity'] = 0;
            $r['order_total'] = (float)($r['shipping_fee'] ?? 0);
            $r['variant_name'] = '';
            $r['items'] = [];
            if (empty($r['image'])) {
                $r['image'] = !empty($r['variant_img']) ? $r['variant_img'] : (!empty($r['product_img']) ? $r['product_img'] : '');
            }
            $ordersByBatch[$key] = $r;
        }
        $group = &$ordersByBatch[$key];
        $group['items_count']++;
        $group['total_quantity'] += (int)$r['quantity'];
        $group['order_total'] += (float)$r['price'] * (int)$r['quantity'];
        $variant = trim($r['variant_name'] ?? '');
        if ($variant !== '') $group['variant_name'] = $group['variant_name'] === '' ? $variant : $group['variant_name'] . ', ' . $variant;
        $group['items'][] = [
            'variant_name' => $r['variant_name'] ?? '',
            'size'         => $r['size'] ?? '',
            'quantity'     => $r['quantity'],
            'price'        => $r['price'],
            'variant_img'  => $r['variant_img'] ?? '',
        ];
        if (empty($group['image'])) {
            $group['image'] = !empty($r['variant_img']) ? $r['variant_img'] : (!empty($r['product_img']) ? $r['product_img'] : '');
        }
        unset($group);
    }
    $orders = array_values($ordersByBatch);
    foreach ($orders as &$order) {
        $order['quantity'] = $order['total_quantity'];
        $order['price'] = $order['order_total'];
    }
    unset($order);
    echo json_encode(['orders' => $orders, 'count' => count($orders)]);
    exit;
}

if ($action === 'dashboard_stats') {
    $pending = $conn->query("SELECT COUNT(*) as c FROM customer_orders WHERE status IN ('In Cart','Processing')")->fetch_assoc()['c'];
    $processing = $conn->query("SELECT COUNT(*) as c FROM customer_orders WHERE status='Processing'")->fetch_assoc()['c'];
    $completed = $conn->query("SELECT COUNT(*) as c FROM customer_orders WHERE status='Completed'")->fetch_assoc()['c'];
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $completedWeek = $conn->query("SELECT COUNT(*) as c FROM customer_orders WHERE status='Completed' AND created_at >= '$weekStart'")->fetch_assoc()['c'];
    $products = $conn->query("SELECT COUNT(*) as c FROM product")->fetch_assoc()['c'];

    
    $recentRes = $conn->query("SELECT co.id, co.product_name, co.price, co.status, co.created_at, c.name as customer_name FROM customer_orders co LEFT JOIN customer c ON co.customer_id = c.customer_id ORDER BY co.created_at DESC LIMIT 5");
    $recent = [];
    while ($r = $recentRes->fetch_assoc()) $recent[] = $r;

    echo json_encode([
        'pending' => (int)$pending,
        'processing' => (int)$processing,
        'completed' => (int)$completed,
        'completedWeek' => (int)$completedWeek,
        'products' => (int)$products,
        'recentOrders' => $recent
    ]);
    exit;
}

if ($action === 'products') {
    $res = $conn->query("SELECT p.*, (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.product_id) as variant_count FROM product p ORDER BY p.product_id DESC");
    $products = [];
    while ($r = $res->fetch_assoc()) $products[] = $r;
    echo json_encode(['products' => $products, 'count' => count($products)]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
