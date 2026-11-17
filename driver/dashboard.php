<?php
ini_set('session.gc_maxlifetime', 86400 * 30);
session_set_cookie_params(86400 * 30);
session_start();
require_once '../db_conn.php';
require_once 'check_driver.php';
require_once '../notifications_helper.php';

$driverId = (int)$_SESSION['driver_id'];
$driverNotificationCount = getUnreadNotifCount($conn, 'driver', $driverId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_delivery') {
    header('Content-Type: application/json');
    $orderId = (int)($_POST['order_id'] ?? 0);
    $orderInfo = $conn->query("SELECT id, product_name, customer_id, batch_id FROM customer_orders WHERE id = $orderId AND driver_id = $driverId AND delivery_status = 'assigned'")->fetch_assoc();
    if (!$orderInfo) {
        echo json_encode(['success' => false, 'message' => 'This delivery is no longer in assigned status.']);
        exit;
    }
    $batchId = $orderInfo['batch_id'] ?? '';
    if ($batchId) {
        $stmt = $conn->prepare("UPDATE customer_orders SET delivery_status = 'out_for_delivery', status = 'Production', updated_at = NOW() WHERE batch_id = ? AND driver_id = ? AND delivery_status = 'assigned'");
        if ($stmt) $stmt->bind_param('si', $batchId, $driverId);
    } else {
        $stmt = $conn->prepare("UPDATE customer_orders SET delivery_status = 'out_for_delivery', status = 'Production', updated_at = NOW() WHERE id = ? AND driver_id = ? AND delivery_status = 'assigned'");
        if ($stmt) $stmt->bind_param('ii', $orderId, $driverId);
    }
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit;
    }
    $success = $stmt->execute() && $stmt->affected_rows > 0;
    if ($success) {
        $driverName = $_SESSION['driver_name'] ?? 'Driver';
        
        createNotification(
            $conn, 
            'customer', 
            (int)$orderInfo['customer_id'], 
            'delivery', 
            '🚚 Order Out for Delivery', 
            'Your order for ' . $orderInfo['product_name'] . ' is now in transit! Driver ' . $driverName . ' is on the way.', 
            'customer_orders.php'
        );
        
        $orderTag = 'ORD-' . str_pad($orderId, 3, '0', STR_PAD_LEFT);
        createNotification(
            $conn, 
            'admin', 
            0, 
            'delivery', 
            'Order In Transit', 
            $driverName . ' started delivery for ' . $orderTag . ' (' . $orderInfo['product_name'] . ').', 
            'orders.php'
        );
    }
    echo json_encode(['success' => $success, 'message' => $success ? '' : ($stmt->error ?: 'Could not update delivery status.')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_delivered') {
    header('Content-Type: application/json');
    $orderId = (int)($_POST['order_id'] ?? 0);
    $notes = trim($_POST['delivery_notes'] ?? '');
    $paymentCollected = isset($_POST['payment_collected']) && $_POST['payment_collected'] !== '' ? (int)$_POST['payment_collected'] : null;
    $remainingCollected = isset($_POST['remaining_collected']) && $_POST['remaining_collected'] !== '' ? (int)$_POST['remaining_collected'] : 0;
    $orderInfo = $conn->query("SELECT product_name, customer_id, batch_id, delivery_method, payment_mode, price, quantity, shipping_fee, down_payment_amount FROM customer_orders WHERE id = $orderId AND driver_id = $driverId AND delivery_status IN ('assigned', 'out_for_delivery')")->fetch_assoc();
    if (!$orderInfo) {
        echo json_encode(['success' => false, 'message' => 'This delivery is no longer available.']);
        exit;
    }
    $deliveryMethodValue = strtoupper(trim($orderInfo['delivery_method'] ?? ''));
    $paymentModeValue = strtoupper(trim($orderInfo['payment_mode'] ?? ''));
    $isCodOrder = in_array($deliveryMethodValue, ['COD', 'CASH ON HAND', 'CASH ON DELIVERY'], true) || in_array($paymentModeValue, ['COD', 'CASH ON HAND', 'CASH ON DELIVERY'], true);
    if ($isCodOrder) {
        $baseTotal = ((float)$orderInfo['price'] * (int)$orderInfo['quantity']) + (float)($orderInfo['shipping_fee'] ?? 0);
        $remainingDue = max(0, $baseTotal - (float)($orderInfo['down_payment_amount'] ?? 0));
        if ($remainingDue > 0) {
            if ((int)$remainingCollected !== 1) {
                echo json_encode(['success' => false, 'message' => 'Please confirm that the customer paid the remaining COD balance of ₱' . number_format($remainingDue, 2) . '.']);
                exit;
            }
            $paymentCollected = 1;
        } else {
            $paymentCollected = 1;
        }
    }
    $batchId = $orderInfo['batch_id'] ?? '';
    if ($batchId) {
        $stmt = $conn->prepare("UPDATE customer_orders SET status = 'Completed', delivery_status = 'delivered', delivered_at = NOW(), payment_collected = ?, delivery_notes = ?, updated_at = NOW() WHERE batch_id = ? AND driver_id = ? AND delivery_status IN ('assigned', 'out_for_delivery')");
        if ($stmt) $stmt->bind_param('issi', $paymentCollected, $notes, $batchId, $driverId);
    } else {
        $stmt = $conn->prepare("UPDATE customer_orders SET status = 'Completed', delivery_status = 'delivered', delivered_at = NOW(), payment_collected = ?, delivery_notes = ?, updated_at = NOW() WHERE id = ? AND driver_id = ? AND delivery_status IN ('assigned', 'out_for_delivery')");
        if ($stmt) $stmt->bind_param('isii', $paymentCollected, $notes, $orderId, $driverId);
    }
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to prepare delivery update: ' . $conn->error]);
        exit;
    }
    $success = $stmt->execute() && $stmt->affected_rows > 0;
    if ($success) {
        $driverName = $_SESSION['driver_name'] ?? 'Driver';
        createNotification($conn, 'admin', 0, 'order', 'Delivery Completed', $driverName . ' marked ' . $orderInfo['product_name'] . ' as delivered. Order is now Completed.', 'orders.php');
        createNotification($conn, 'customer', (int)$orderInfo['customer_id'], 'delivery', '🎉 Order Delivered!', 'Your order for ' . $orderInfo['product_name'] . ' has been successfully delivered by Driver ' . $driverName . '. Thank you!', 'customer_orders.php');
    }
    echo json_encode(['success' => $success, 'message' => $success ? '' : ($stmt->error ?: 'This delivery is no longer available.')]);
    exit;
}

$batchKeySql = "COALESCE(NULLIF(batch_id, ''), CONCAT('solo_', id))";
$assignedCount = (int)$conn->query("SELECT COUNT(DISTINCT $batchKeySql) AS c FROM customer_orders WHERE driver_id = $driverId AND delivery_status = 'assigned'")->fetch_assoc()['c'];
$outCount = (int)$conn->query("SELECT COUNT(DISTINCT $batchKeySql) AS c FROM customer_orders WHERE driver_id = $driverId AND delivery_status = 'out_for_delivery'")->fetch_assoc()['c'];
$deliveredToday = (int)$conn->query("SELECT COUNT(DISTINCT $batchKeySql) AS c FROM customer_orders WHERE driver_id = $driverId AND delivery_status = 'delivered' AND delivered_at >= CURDATE()")->fetch_assoc()['c'];

$selectedStatus = $_GET['status'] ?? 'assigned';
if (!in_array($selectedStatus, ['assigned', 'out_for_delivery', 'delivered'], true)) {
    $selectedStatus = 'assigned';
}

$statusFilter = [
    'assigned' => "co.delivery_status = 'assigned'",
    'out_for_delivery' => "co.delivery_status = 'out_for_delivery'",
    'delivered' => "co.delivery_status = 'delivered' AND co.delivered_at >= CURDATE()"
][$selectedStatus];

$stmt = $conn->prepare("SELECT 
    co.id, co.batch_id, co.product_id, co.variant_id, co.product_name, co.variant_name, 
    co.size, co.quantity, co.price, co.payment_mode, co.delivery_method, co.down_payment_amount, 
    co.delivery_address, co.shipping_fee, co.delivery_status, co.scheduled_release, 
    co.created_at, co.order_notes, co.image AS order_image, co.delivered_at, co.payment_collected, co.delivery_notes,
    c.name AS customer_name, c.phone, c.address,
    p.img AS product_img,
    pv.image AS variant_img
FROM customer_orders co 
INNER JOIN customer c ON c.customer_id = co.customer_id 
LEFT JOIN product p ON p.product_id = co.product_id
LEFT JOIN product_variants pv ON pv.variant_id = co.variant_id
WHERE co.driver_id = ? AND $statusFilter 
ORDER BY co.assigned_at ASC, co.created_at ASC");
$stmt->bind_param('i', $driverId);
$stmt->execute();
$deliveries = [];
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $key = $row['batch_id'] ?: 'solo_' . $row['id'];
    if (!isset($deliveries[$key])) {
        $deliveries[$key] = $row;
        $deliveries[$key]['items'] = [];
        $deliveries[$key]['total_quantity'] = 0;
        $deliveries[$key]['total_price'] = 0;
    }
    $deliveries[$key]['items'][] = [
        'id'           => $row['id'],
        'variant_name' => $row['variant_name'],
        'size'         => $row['size'],
        'quantity'     => (int)$row['quantity'],
        'price'        => (float)$row['price'],
        'variant_img'  => $row['variant_img'] ?: $row['product_img'],
        'product_img'  => $row['product_img'],
    ];
    $deliveries[$key]['total_quantity'] += (int)$row['quantity'];
    $deliveries[$key]['total_price'] += (float)$row['price'] * (int)$row['quantity'];
}
$deliveries = array_values($deliveries);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0d3b2e">
    <title>Driver Portal - Dwarf Fruit</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body {
            margin: 0;
            padding: 0;
            background: #f1f6f3;
            color: #0f291e;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            padding-bottom: 40px;
        }

        
        .driver-header {
            position: sticky;
            top: 0;
            z-index: 100;
            background: linear-gradient(135deg, #0a3327 0%, #0e4c3a 100%);
            color: #fff;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 20px rgba(10,51,39,0.22);
        }
        .driver-profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .driver-avatar {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, #22a06b 0%, #16804f 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 16px;
            border: 2px solid rgba(255,255,255,0.2);
            box-shadow: 0 3px 8px rgba(0,0,0,0.15);
            flex-shrink: 0;
        }
        .driver-info h1 {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
            line-height: 1.2;
            letter-spacing: -0.02em;
        }
        .driver-badge-live {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            color: #bbf7d0;
            font-weight: 600;
            margin-top: 2px;
        }
        .live-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #22c55e;
            box-shadow: 0 0 0 2px rgba(34,197,94,0.3);
            animation: pulse-dot 2s infinite;
        }
        @keyframes pulse-dot {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34,197,94,0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 5px rgba(34,197,94,0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34,197,94,0); }
        }
        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.15);
            background: rgba(255,255,255,0.08);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            position: relative;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .btn-icon:hover {
            background: rgba(255,255,255,0.18);
        }
        .notif-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            min-width: 17px;
            height: 17px;
            padding: 0 4px;
            border-radius: 99px;
            background: #ff6b35;
            color: #fff;
            font-size: 10px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #0a3327;
        }

        
        .notif-panel {
            display: none;
            position: fixed;
            top: 65px;
            left: 12px;
            right: 12px;
            max-width: 400px;
            margin-left: auto;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 16px 40px rgba(10,51,39,0.25);
            border: 1px solid #d8e8dd;
            overflow: hidden;
            z-index: 200;
        }
        .notif-panel.open { display: block; }
        .notif-head {
            padding: 12px 16px;
            background: #f8faf9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e6efe8;
            font-weight: 700;
            font-size: 13px;
        }
        .notif-clear {
            background: none;
            border: 0;
            color: #16804f;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            padding: 4px 6px;
        }
        .notif-item {
            display: block;
            padding: 12px 16px;
            border-bottom: 1px solid #f0f5f2;
            text-decoration: none;
            color: #334e40;
            font-size: 12px;
            line-height: 1.4;
        }
        .notif-item strong { display: block; color: #0f291e; margin-bottom: 2px; }
        .notif-item small { color: #819a8d; font-size: 10px; }
        .notif-empty { padding: 24px 16px; text-align: center; color: #819a8d; font-size: 12px; }

        
        .driver-content {
            width: 100%;
            max-width: 720px;
            margin: 0 auto;
            padding: 14px 12px;
        }

        
        .tab-bar {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
            background: #e2ede5;
            padding: 5px;
            border-radius: 14px;
            margin-bottom: 16px;
        }
        .tab-btn {
            all: unset;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 2px;
            padding: 10px 4px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 700;
            color: #496a5a;
            text-align: center;
            transition: all 0.2s ease;
            position: relative;
        }
        .tab-btn.active {
            background: #ffffff;
            color: #0f3d2e;
            box-shadow: 0 2px 8px rgba(15,61,46,0.12);
        }
        .tab-icon {
            font-size: 16px;
            margin-bottom: 1px;
        }
        .tab-count {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 99px;
            font-size: 10px;
            font-weight: 800;
            background: rgba(15,61,46,0.08);
            color: #0f3d2e;
            margin-top: 2px;
        }
        .tab-btn.active .tab-count {
            background: #16804f;
            color: #fff;
        }

        
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            padding: 0 4px;
        }
        .section-header h2 {
            margin: 0;
            font-size: 15px;
            font-weight: 800;
            color: #123e2f;
            letter-spacing: -0.01em;
        }
        .section-header span {
            font-size: 12px;
            font-weight: 600;
            color: #648274;
        }

        
        .queue-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .card-delivery {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #d9e8dd;
            box-shadow: 0 4px 16px rgba(15,61,46,0.05);
            overflow: hidden;
            transition: transform 0.15s ease;
        }
        .card-top {
            padding: 12px 14px;
            background: #f8faf8;
            border-bottom: 1px solid #ebf2ec;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            flex-wrap: wrap;
        }
        .order-tag {
            font-size: 12px;
            font-weight: 800;
            color: #0f3d2e;
            background: #e2f0e6;
            padding: 3px 8px;
            border-radius: 6px;
            letter-spacing: 0.02em;
        }
        .status-chip {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 9px;
            border-radius: 99px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .status-chip.s-assigned {
            background: #fef3c7;
            color: #b45309;
        }
        .status-chip.s-out {
            background: #e0f2fe;
            color: #0369a1;
        }
        .status-chip.s-delivered {
            background: #dcfce7;
            color: #15803d;
        }
        .order-date-text {
            font-size: 11px;
            color: #799587;
            font-weight: 500;
        }

        
        .card-items {
            padding: 12px 14px;
            border-bottom: 1px solid #f0f5f1;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .item-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .item-thumb {
            width: 46px;
            height: 46px;
            border-radius: 10px;
            object-fit: cover;
            border: 1px solid #dceade;
            cursor: pointer;
            flex-shrink: 0;
            background: #f4faf6;
        }
        .item-thumb-empty {
            width: 46px;
            height: 46px;
            border-radius: 10px;
            background: #eaf3ed;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #8da496;
            flex-shrink: 0;
        }
        .item-details {
            flex: 1;
            min-width: 0;
        }
        .item-name {
            font-size: 13px;
            font-weight: 700;
            color: #123a2c;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .item-meta {
            font-size: 11px;
            color: #658273;
            margin-top: 2px;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .item-size-badge {
            background: #eff6f1;
            border: 1px solid #dbeae0;
            padding: 1px 5px;
            border-radius: 4px;
            font-size: 10px;
            color: #3b5a4b;
        }
        .item-price {
            font-size: 12px;
            font-weight: 700;
            color: #16804f;
            white-space: nowrap;
        }

        
        .cash-alert-box {
            margin: 10px 14px;
            padding: 10px 12px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .cash-alert-box.cod-due {
            background: #fffbeb;
            border: 1.5px solid #fcd34d;
        }
        .cash-alert-box.paid {
            background: #f0fdf4;
            border: 1.5px solid #bbf7d0;
        }
        .cash-label {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .cash-alert-box.cod-due .cash-label { color: #b45309; }
        .cash-alert-box.paid .cash-label { color: #166534; }
        .cash-amount {
            font-size: 15px;
            font-weight: 800;
        }
        .cash-alert-box.cod-due .cash-amount { color: #d97706; }
        .cash-alert-box.paid .cash-amount { color: #15803d; }
        .cash-sub {
            font-size: 10px;
            color: #78716c;
            margin-top: 2px;
        }

        
        .card-dest {
            padding: 10px 14px 14px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .dest-row {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            font-size: 12px;
            line-height: 1.35;
        }
        .dest-icon {
            font-size: 14px;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .dest-text {
            color: #294437;
            font-weight: 500;
        }
        .dest-text strong {
            color: #0f291e;
        }
        .customer-notes-chip {
            background: #f9fbf9;
            border: 1px dashed #cadfd0;
            padding: 7px 10px;
            border-radius: 8px;
            font-size: 11px;
            color: #496355;
            display: flex;
            align-items: flex-start;
            gap: 6px;
        }

        
        .action-grid-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 4px;
        }
        .btn-call, .btn-map {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 8px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s ease;
            text-align: center;
            min-height: 42px;
        }
        .btn-call {
            background: #effaf3;
            color: #16804f;
            border: 1.5px solid #a3dfbe;
        }
        .btn-call:active {
            background: #d7f4e3;
        }
        .btn-map {
            background: #f0f9ff;
            color: #0284c7;
            border: 1.5px solid #bae6fd;
        }
        .btn-map:active {
            background: #e0f2fe;
        }

        
        .card-action-bar {
            padding: 10px 14px 14px;
            background: #fafdfb;
            border-top: 1px solid #edf4ee;
        }
        .btn-deliver-main {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 16px;
            background: linear-gradient(135deg, #1d925d 0%, #157348 100%);
            color: #fff;
            border: 0;
            border-radius: 12px;
            font-size: 13.5px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(22,120,77,0.22);
            transition: all 0.15s ease;
            min-height: 46px;
        }
        .btn-deliver-main:active {
            transform: scale(0.98);
            background: #11623d;
        }
        .btn-transit-main {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 16px;
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #fff;
            border: 0;
            border-radius: 12px;
            font-size: 13.5px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(2,132,199,0.28);
            transition: all 0.15s ease;
            min-height: 46px;
        }
        .btn-transit-main:active {
            transform: scale(0.98);
            background: #075985;
        }
        .btn-deliver-sub {
            width: 100%;
            background: none;
            border: none;
            color: #64748b;
            font-size: 11.5px;
            font-weight: 600;
            padding: 8px 0 0;
            cursor: pointer;
            text-decoration: underline;
            text-align: center;
        }
        .btn-deliver-sub:hover {
            color: #15803d;
        }
        .delivered-info-pill {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            background: #edf7f0;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            color: #15803d;
        }

        
        .empty-state-box {
            text-align: center;
            padding: 44px 20px;
            background: #ffffff;
            border: 1.5px dashed #cfdfd4;
            border-radius: 18px;
            margin-top: 8px;
        }
        .empty-icon {
            font-size: 40px;
            margin-bottom: 8px;
        }
        .empty-title {
            font-size: 15px;
            font-weight: 800;
            color: #133a2c;
            margin-bottom: 4px;
        }
        .empty-desc {
            font-size: 12px;
            color: #6d897b;
            max-width: 260px;
            margin: 0 auto 16px;
            line-height: 1.45;
        }
        .btn-refresh-queue {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 99px;
            background: #eef7f1;
            color: #16804f;
            border: 1px solid #b7e0c7;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
        }

        
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(10,36,27,0.72);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: flex-end;
            justify-content: center;
            padding: 0;
        }
        .modal-overlay.open {
            display: flex;
        }
        .modal-sheet {
            background: #fff;
            width: 100%;
            max-width: 500px;
            border-radius: 22px 22px 0 0;
            padding: 20px 20px 28px;
            box-shadow: 0 -10px 40px rgba(0,0,0,0.25);
            animation: slideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes slideUp {
            from { transform: translateY(100%); }
            to { transform: translateY(0); }
        }
        .modal-handle {
            width: 38px;
            height: 4px;
            border-radius: 99px;
            background: #d6e2da;
            margin: 0 auto 16px;
        }
        .modal-header h3 {
            margin: 0;
            font-size: 17px;
            font-weight: 800;
            color: #0f3d2e;
        }
        .modal-header p {
            margin: 4px 0 16px;
            font-size: 12px;
            color: #638072;
        }
        .modal-alert {
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 14px;
            font-size: 12px;
        }
        .modal-alert.cash {
            background: #fffbeb;
            border: 1.5px solid #fcd34d;
            color: #92400e;
        }
        .modal-check-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            background: #f4faf6;
            border: 1px solid #d4eadc;
            border-radius: 10px;
            margin-bottom: 14px;
            cursor: pointer;
        }
        .modal-check-row input {
            width: 18px;
            height: 18px;
            accent-color: #16804f;
            cursor: pointer;
        }
        .modal-check-row label {
            font-size: 12px;
            font-weight: 700;
            color: #123e2f;
            cursor: pointer;
        }
        .modal-textarea {
            width: 100%;
            border: 1.5px solid #d2e4d8;
            border-radius: 10px;
            padding: 10px 12px;
            font-family: inherit;
            font-size: 12px;
            color: #0f291e;
            resize: none;
            outline: none;
            margin-bottom: 16px;
        }
        .modal-textarea:focus {
            border-color: #16804f;
        }
        .modal-actions {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 10px;
        }
        .btn-modal-cancel {
            padding: 12px;
            background: #edf3ee;
            border: 0;
            border-radius: 12px;
            color: #496355;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
        }
        .btn-modal-confirm {
            padding: 12px;
            background: linear-gradient(135deg, #1d925d 0%, #157348 100%);
            border: 0;
            border-radius: 12px;
            color: #fff;
            font-weight: 800;
            font-size: 13px;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(22,120,77,0.22);
        }

        @media (min-width: 600px) {
            .modal-overlay {
                align-items: center;
                padding: 20px;
            }
            .modal-sheet {
                border-radius: 20px;
                padding: 24px;
            }
            .modal-handle { display: none; }
        }
    </style>
</head>
<body>

    
    <header class="driver-header">
        <div class="driver-profile">
            <div class="driver-avatar">
                <?= strtoupper(substr(trim($_SESSION['driver_name'] ?? 'D'), 0, 1)) ?>
            </div>
            <div class="driver-info">
                <h1><?= htmlspecialchars($_SESSION['driver_name']) ?></h1>
                <div class="driver-badge-live">
                    <span class="live-dot"></span>
                    <span>Active Rider</span>
                </div>
            </div>
        </div>
        <div class="header-actions">
            
            <div style="position:relative;">
                <button class="btn-icon" type="button" onclick="toggleNotifications()" aria-label="Notifications" title="Notifications">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
                    <span id="notifBadge" class="notif-badge" <?= $driverNotificationCount ? '' : 'style="display:none"' ?>><?= $driverNotificationCount ?></span>
                </button>
                <div id="notifPanel" class="notif-panel">
                    <div class="notif-head">
                        <span>Notifications</span>
                        <button class="notif-clear" type="button" onclick="markNotificationsRead()">Mark all read</button>
                    </div>
                    <div id="notifList">
                        <div class="notif-empty">No notifications yet.</div>
                    </div>
                </div>
            </div>
            
            <a href="../logout.php" class="btn-icon" title="Sign Out" onclick="return confirm('Sign out from Driver Portal?')">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            </a>
        </div>
    </header>

    
    <main class="driver-content">

        
        <nav class="tab-bar" aria-label="Delivery tabs">
            <button type="button" class="tab-btn <?= $selectedStatus === 'assigned' ? 'active' : '' ?>" onclick="switchQueue('assigned')">
                <span class="tab-icon">📋</span>
                <span>Assigned</span>
                <span class="tab-count"><?= $assignedCount ?></span>
            </button>
            <button type="button" class="tab-btn <?= $selectedStatus === 'out_for_delivery' ? 'active' : '' ?>" onclick="switchQueue('out_for_delivery')">
                <span class="tab-icon">🚚</span>
                <span>In Transit</span>
                <span class="tab-count"><?= $outCount ?></span>
            </button>
            <button type="button" class="tab-btn <?= $selectedStatus === 'delivered' ? 'active' : '' ?>" onclick="switchQueue('delivered')">
                <span class="tab-icon">✅</span>
                <span>Delivered</span>
                <span class="tab-count"><?= $deliveredToday ?></span>
            </button>
        </nav>

        
        <div class="section-header">
            <h2>
                <?= $selectedStatus === 'assigned' ? 'Assigned Queue' : ($selectedStatus === 'out_for_delivery' ? 'Out for Delivery' : 'Delivered Today') ?>
            </h2>
            <span><?= count($deliveries) ?> <?= count($deliveries) === 1 ? 'order' : 'orders' ?></span>
        </div>

        
        <?php if (empty($deliveries)): ?>
            <div class="empty-state-box">
                <div class="empty-icon">
                    <?= $selectedStatus === 'assigned' ? '📦' : ($selectedStatus === 'out_for_delivery' ? '🚚' : '🎉') ?>
                </div>
                <div class="empty-title">
                    <?= $selectedStatus === 'assigned' ? 'No Assigned Deliveries' : ($selectedStatus === 'out_for_delivery' ? 'No Deliveries In Transit' : 'No Completed Orders Today') ?>
                </div>
                <div class="empty-desc">
                    <?= $selectedStatus === 'assigned' 
                        ? 'You are ready! New delivery assignments from admin will appear here.' 
                        : ($selectedStatus === 'out_for_delivery' 
                            ? 'You currently have no orders marked as out for delivery.' 
                            : 'Orders you deliver today will be listed here for your records.') ?>
                </div>
                <a href="?status=<?= $selectedStatus ?>" class="btn-refresh-queue">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    Refresh Queue
                </a>
            </div>
        <?php else: ?>
            <div class="queue-list">
                <?php foreach ($deliveries as $delivery): 
                    $orderIdPad = 'ORD-' . str_pad($delivery['id'], 3, '0', STR_PAD_LEFT);
                    $delivMethod = strtoupper(trim((string)($delivery['delivery_method'] ?? 'COD')));
                    $paymentMode = strtoupper(trim((string)($delivery['payment_mode'] ?? 'COD')));
                    $isCod = in_array($delivMethod, ['COD', 'CASH ON HAND', 'CASH ON DELIVERY'], true) || in_array($paymentMode, ['COD', 'CASH ON HAND', 'CASH ON DELIVERY'], true);
                    
                    $orderTotal = (float)$delivery['total_price'] + (float)$delivery['shipping_fee'];
                    $downPaid = (float)($delivery['down_payment_amount'] ?? 0);
                    $codDue = max(0, $orderTotal - $downPaid);

                    
                    $custAddress = trim($delivery['delivery_address'] ?: ($delivery['address'] ?: ''));
                    $mapQuery = urlencode($custAddress ?: ($delivery['customer_name'] . ' Davao City'));
                    $googleMapsUrl = "https://www.google.com/maps/search/?api=1&query=" . $mapQuery;
                    $phoneClean = preg_replace('/[^0-9+]/', '', (string)$delivery['phone']);
                ?>
                    <article class="card-delivery" id="delivery-card-<?= (int)$delivery['id'] ?>">
                        
                        <div class="card-top">
                            <div style="display:flex;align-items:center;gap:6px;">
                                <span class="order-tag">#<?= $orderIdPad ?></span>
                                <?php if ($delivery['delivery_status'] === 'assigned'): ?>
                                    <span class="status-chip s-assigned">⏳ Assigned</span>
                                <?php elseif ($delivery['delivery_status'] === 'out_for_delivery'): ?>
                                    <span class="status-chip s-out">🚚 Out for Delivery</span>
                                <?php else: ?>
                                    <span class="status-chip s-delivered">✅ Delivered</span>
                                <?php endif; ?>
                            </div>
                            <span class="order-date-text"><?= date('M d, g:i A', strtotime($delivery['created_at'])) ?></span>
                        </div>

                        
                        <div class="card-items">
                            <?php foreach ($delivery['items'] as $it): 
                                $thumbSrc = !empty($it['variant_img']) ? '../' . $it['variant_img'] : (!empty($it['product_img']) ? '../' . $it['product_img'] : '');
                            ?>
                                <div class="item-row">
                                    <?php if ($thumbSrc): ?>
                                        <img src="<?= htmlspecialchars($thumbSrc) ?>" class="item-thumb" onclick="viewPhoto('<?= htmlspecialchars($thumbSrc) ?>')" title="Tap to view photo" alt="Plant">
                                    <?php else: ?>
                                        <div class="item-thumb-empty">🌱</div>
                                    <?php endif; ?>
                                    <div class="item-details">
                                        <div class="item-name"><?= htmlspecialchars($delivery['product_name']) ?></div>
                                        <div class="item-meta">
                                            <span><?= htmlspecialchars($it['variant_name'] ?: 'Standard') ?></span>
                                            <?php if (!empty($it['size'])): ?>
                                                <span class="item-size-badge"><?= htmlspecialchars($it['size']) ?></span>
                                            <?php endif; ?>
                                            <span style="font-weight:700;color:#ff6b35;">&times;<?= (int)$it['quantity'] ?></span>
                                        </div>
                                    </div>
                                    <div class="item-price">
                                        ₱<?= number_format((float)$it['price'] * (int)$it['quantity'], 2) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        
                        <?php if ($isCod && $codDue > 0): ?>
                            <div class="cash-alert-box cod-due">
                                <div>
                                    <div class="cash-label">💵 Collect Cash from Customer</div>
                                    <div class="cash-sub">Total: ₱<?= number_format($orderTotal, 2) ?> · Down: ₱<?= number_format($downPaid, 2) ?></div>
                                </div>
                                <div class="cash-amount">₱<?= number_format($codDue, 2) ?></div>
                            </div>
                        <?php else: ?>
                            <div class="cash-alert-box paid">
                                <div>
                                    <div class="cash-label">💳 Fully Paid / No Cash Due</div>
                                    <div class="cash-sub"><?= htmlspecialchars($delivery['payment_mode'] ?: 'Online Payment') ?></div>
                                </div>
                                <div class="cash-amount">₱0.00</div>
                            </div>
                        <?php endif; ?>

                        
                        <div class="card-dest">
                            
                            <div class="dest-row">
                                <span class="dest-icon">👤</span>
                                <div class="dest-text">
                                    <strong><?= htmlspecialchars($delivery['customer_name']) ?></strong>
                                    <?php if (!empty($delivery['phone'])): ?>
                                        <span style="color:#628072;">· <?= htmlspecialchars($delivery['phone']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            
                            <div class="dest-row">
                                <span class="dest-icon">📍</span>
                                <div class="dest-text">
                                    <?= nl2br(htmlspecialchars($custAddress ?: 'No delivery address specified')) ?>
                                </div>
                            </div>

                            
                            <?php if (!empty($delivery['order_notes'])): ?>
                                <div class="customer-notes-chip">
                                    <span>📝</span>
                                    <span><strong>Customer Note:</strong> <?= htmlspecialchars($delivery['order_notes']) ?></span>
                                </div>
                            <?php endif; ?>

                            
                            <div class="action-grid-buttons">
                                <?php if (!empty($phoneClean)): ?>
                                    <a href="tel:<?= htmlspecialchars($phoneClean) ?>" class="btn-call">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                        Call Customer
                                    </a>
                                <?php else: ?>
                                    <button type="button" class="btn-call" style="opacity:0.5;cursor:not-allowed;" disabled>No Phone</button>
                                <?php endif; ?>

                                <?php if (!empty($custAddress)): ?>
                                    <a href="<?= $googleMapsUrl ?>" target="_blank" rel="noopener noreferrer" class="btn-map">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" y1="3" x2="9" y2="18"/><line x1="15" y1="6" x2="15" y2="21"/></svg>
                                        Open in Maps
                                    </a>
                                <?php else: ?>
                                    <button type="button" class="btn-map" style="opacity:0.5;cursor:not-allowed;" disabled>No Address</button>
                                <?php endif; ?>
                            </div>
                        </div>

                        
                        <div class="card-action-bar">
                            <?php if ($selectedStatus === 'assigned'): ?>
                                <button type="button" 
                                    class="btn-transit-main" 
                                    id="transitBtn_<?= (int)$delivery['id'] ?>"
                                    onclick="startDelivery(<?= (int)$delivery['id'] ?>, '<?= $orderIdPad ?>', '<?= htmlspecialchars($delivery['customer_name'], ENT_QUOTES) ?>')">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
                                    Start Delivery (In Transit)
                                </button>
                                <button type="button" 
                                    class="btn-deliver-sub" 
                                    onclick="openDeliveryModal({
                                        id: <?= (int)$delivery['id'] ?>,
                                        orderTag: '<?= $orderIdPad ?>',
                                        customer: '<?= htmlspecialchars($delivery['customer_name'], ENT_QUOTES) ?>',
                                        codDue: <?= (float)$codDue ?>,
                                        isCod: <?= $isCod ? 'true' : 'false' ?>
                                    })">
                                    or Mark Delivered directly
                                </button>
                            <?php elseif ($selectedStatus === 'out_for_delivery'): ?>
                                <button type="button" 
                                    class="btn-deliver-main" 
                                    onclick="openDeliveryModal({
                                        id: <?= (int)$delivery['id'] ?>,
                                        orderTag: '<?= $orderIdPad ?>',
                                        customer: '<?= htmlspecialchars($delivery['customer_name'], ENT_QUOTES) ?>',
                                        codDue: <?= (float)$codDue ?>,
                                        isCod: <?= $isCod ? 'true' : 'false' ?>
                                    })">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                    Mark as Delivered
                                </button>
                            <?php else: ?>
                                <div class="delivered-info-pill">
                                    <span>Delivered <?= !empty($delivery['delivered_at']) ? date('M d, g:i A', strtotime($delivery['delivered_at'])) : 'Today' ?></span>
                                    <span><?= ((int)($delivery['payment_collected'] ?? 0) === 1 || $codDue <= 0) ? '✅ Paid' : 'Pending' ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </main>

    
    <div id="deliveryModal" class="modal-overlay" onclick="handleModalBackdropClick(event)">
        <div class="modal-sheet">
            <div class="modal-handle"></div>
            <div class="modal-header">
                <h3 id="modalOrderTitle">Confirm Delivery</h3>
                <p id="modalCustomerSub">Customer: Name</p>
            </div>

            <div id="modalCashNotice" class="modal-alert cash" style="display:none;">
                <strong>💵 Cash Collection Reminder</strong><br>
                Please collect <strong id="modalCashAmount" style="font-size:14px;color:#b45309;">₱0.00</strong> from customer.
            </div>

            <div id="modalCashCheckboxRow" class="modal-check-row" style="display:none;">
                <input type="checkbox" id="modalCollectCheck">
                <label for="modalCollectCheck" id="modalCollectCheckLabel">I have received the cash payment</label>
            </div>

            <label style="display:block;font-size:11px;font-weight:700;color:#516d5e;margin-bottom:6px;">Delivery Notes (optional):</label>
            <textarea id="modalDeliveryNotes" rows="2" class="modal-textarea" placeholder="e.g. Received by buyer, left at front door..."></textarea>

            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="closeDeliveryModal()">Cancel</button>
                <button type="button" class="btn-modal-confirm" id="modalConfirmBtn" onclick="submitDelivery()">Confirm Delivered</button>
            </div>
        </div>
    </div>

    
    <script>
    let currentDeliveryPayload = null;

    function switchQueue(status) {
        const params = new URLSearchParams(window.location.search);
        params.set('status', status);
        window.location.search = params.toString();
    }

    function viewPhoto(src) {
        if (!src) return;
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.88);z-index:99999;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:20px;backdrop-filter:blur(4px);';
        overlay.onclick = () => overlay.remove();
        const img = document.createElement('img');
        img.src = src;
        img.style.cssText = 'max-width:90vw;max-height:85vh;border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,0.6);object-fit:contain;border:1px solid rgba(255,255,255,0.15);';
        overlay.appendChild(img);
        document.body.appendChild(overlay);
    }

    function openDeliveryModal(payload) {
        currentDeliveryPayload = payload;
        document.getElementById('modalOrderTitle').textContent = `Complete Delivery: #${payload.orderTag}`;
        document.getElementById('modalCustomerSub').textContent = `Delivering to: ${payload.customer}`;

        const cashNotice = document.getElementById('modalCashNotice');
        const checkRow = document.getElementById('modalCashCheckboxRow');
        const checkInput = document.getElementById('modalCollectCheck');
        const checkLabel = document.getElementById('modalCollectCheckLabel');
        const cashAmountSpan = document.getElementById('modalCashAmount');

        if (payload.isCod && payload.codDue > 0) {
            cashNotice.style.display = 'block';
            checkRow.style.display = 'flex';
            checkInput.checked = false;
            cashAmountSpan.textContent = `₱${payload.codDue.toLocaleString('en-PH', {minimumFractionDigits: 2})}`;
            checkLabel.textContent = `I have collected ₱${payload.codDue.toLocaleString('en-PH', {minimumFractionDigits: 2})} in cash`;
        } else {
            cashNotice.style.display = 'none';
            checkRow.style.display = 'none';
            checkInput.checked = true;
        }

        document.getElementById('modalDeliveryNotes').value = '';
        document.getElementById('modalConfirmBtn').disabled = false;
        document.getElementById('modalConfirmBtn').textContent = 'Confirm Delivered';
        document.getElementById('deliveryModal').classList.add('open');
    }

    function closeDeliveryModal() {
        document.getElementById('deliveryModal').classList.remove('open');
        currentDeliveryPayload = null;
    }

    function handleModalBackdropClick(e) {
        if (e.target === document.getElementById('deliveryModal')) {
            closeDeliveryModal();
        }
    }

    function submitDelivery() {
        if (!currentDeliveryPayload) return;

        const checkInput = document.getElementById('modalCollectCheck');
        if (currentDeliveryPayload.isCod && currentDeliveryPayload.codDue > 0) {
            if (!checkInput.checked) {
                alert(`Please confirm that you collected the ₱${currentDeliveryPayload.codDue.toFixed(2)} cash balance.`);
                checkInput.focus();
                return;
            }
        }

        const confirmBtn = document.getElementById('modalConfirmBtn');
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Saving...';

        const notes = document.getElementById('modalDeliveryNotes').value.trim();
        const form = new FormData();
        form.append('action', 'mark_delivered');
        form.append('order_id', currentDeliveryPayload.id);
        form.append('payment_collected', currentDeliveryPayload.codDue > 0 ? '1' : '1');
        if (currentDeliveryPayload.codDue > 0) {
            form.append('remaining_collected', '1');
        }
        if (notes) {
            form.append('delivery_notes', notes);
        }

        fetch('dashboard.php', { method: 'POST', body: form })
            .then(res => res.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || 'Unable to update delivery.');
                
                const params = new URLSearchParams(window.location.search);
                params.set('status', 'delivered');
                window.location.search = params.toString();
            })
            .catch(err => {
                alert(err.message || 'Network error occurred.');
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Confirm Delivered';
            });
    }

    function startDelivery(orderId, orderTag, customerName) {
        if (!confirm(`Start delivery for #${orderTag} to ${customerName}?\n\nThis will mark the order as 'In Transit' and notify both the customer and admin.`)) {
            return;
        }
        const btn = document.getElementById(`transitBtn_${orderId}`);
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Updating...';
        }
        const form = new FormData();
        form.append('action', 'start_delivery');
        form.append('order_id', orderId);

        fetch('dashboard.php', { method: 'POST', body: form })
            .then(res => res.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || 'Unable to start delivery.');
                
                const params = new URLSearchParams(window.location.search);
                params.set('status', 'out_for_delivery');
                window.location.search = params.toString();
            })
            .catch(err => {
                alert(err.message || 'Network error occurred.');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg> Start Delivery (In Transit)`;
                }
            });
    }

    
    function toggleNotifications() {
        const panel = document.getElementById('notifPanel');
        panel.classList.toggle('open');
        if (panel.classList.contains('open')) loadNotifications();
    }

    function loadNotifications() {
        fetch('notifications_api.php?action=list')
            .then(r => r.json())
            .then(items => {
                const list = document.getElementById('notifList');
                list.innerHTML = items.length 
                    ? items.map(it => `
                        <a class="notif-item" href="${it.link || '#'}">
                            <strong>${escapeNotification(it.title)}</strong>
                            <div>${escapeNotification(it.message)}</div>
                            <small>${new Date(it.created_at).toLocaleString()}</small>
                        </a>
                    `).join('') 
                    : '<div class="notif-empty">No notifications yet.</div>';
            })
            .catch(() => {});
    }

    function markNotificationsRead() {
        fetch('notifications_api.php?action=mark_read', { method: 'POST' }).then(() => {
            document.getElementById('notifBadge').style.display = 'none';
            loadNotifications();
        });
    }

    function refreshNotificationCount() {
        if (document.hidden) return;
        fetch('notifications_api.php?action=count').then(r => r.json()).then(data => {
            const badge = document.getElementById('notifBadge');
            badge.textContent = data.count;
            badge.style.display = data.count > 0 ? 'flex' : 'none';
        }).catch(() => {});
    }

    function escapeNotification(val) {
        const el = document.createElement('div');
        el.textContent = val || '';
        return el.innerHTML;
    }

    document.addEventListener('click', e => {
        if (!e.target.closest('.header-actions')) {
            document.getElementById('notifPanel').classList.remove('open');
        }
    });

    setInterval(refreshNotificationCount, 20000);
    </script>
</body>
</html>
