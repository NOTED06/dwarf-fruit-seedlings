<?php
ini_set('session.gc_maxlifetime', 86400 * 30);
session_set_cookie_params(86400 * 30);
session_start();
require_once '../db_conn.php';
require_once 'check_admin.php';
require_once '../inventory_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_exception_handler(function (Throwable $error) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'The order could not be updated. Please try again.']);
        exit;
    });
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'advance_delivery') {
    header('Content-Type: application/json');
    $id = (int)($_POST['order_id'] ?? 0);
    $orderInfo = $conn->query("SELECT batch_id, status, payment_mode, delivery_method, driver_id, product_name, customer_id FROM customer_orders WHERE id = $id")->fetch_assoc();
    if (!$orderInfo) {
        echo json_encode(['success' => false, 'message' => 'Order was not found.']);
        exit;
    }
    if (in_array($orderInfo['status'], ['Cancelled', 'Completed'], true)) {
        echo json_encode(['success' => false, 'message' => 'This order is already closed.']);
        exit;
    }
    $paymentModeValue = strtoupper(trim($orderInfo['payment_mode'] ?? ''));
    $deliveryMethodValue = strtoupper(trim($orderInfo['delivery_method'] ?? ''));
    $isCod = in_array($deliveryMethodValue, ['COD', 'CASH ON DELIVERY'], true) || in_array($paymentModeValue, ['COD', 'CASH ON DELIVERY'], true);
    $batchId = $orderInfo['batch_id'] ?? '';
    if ($isCod) {
        if (!(int)$orderInfo['driver_id']) {
            echo json_encode(['success' => false, 'message' => 'Assign an active driver before sending a COD order out for delivery.']);
            exit;
        }
        $nextStatus = 'Production';
        $nextDeliveryStatus = 'out_for_delivery';
    } else {
        $nextStatus = 'Ready for Pickup';
        $nextDeliveryStatus = 'pending';
    }
    $scheduledDelivery = !empty($_POST['scheduled_delivery']) ? $_POST['scheduled_delivery'] : null;
    if ($batchId) {
        $stmt = $scheduledDelivery
            ? $conn->prepare("UPDATE customer_orders SET status = ?, delivery_status = ?, scheduled_release = ?, updated_at = NOW() WHERE batch_id = ? AND status NOT IN ('Cancelled', 'Completed')")
            : $conn->prepare("UPDATE customer_orders SET status = ?, delivery_status = ?, updated_at = NOW() WHERE batch_id = ? AND status NOT IN ('Cancelled', 'Completed')");
        if ($stmt) $scheduledDelivery ? $stmt->bind_param('ssss', $nextStatus, $nextDeliveryStatus, $scheduledDelivery, $batchId) : $stmt->bind_param('sss', $nextStatus, $nextDeliveryStatus, $batchId);
    } else {
        $stmt = $scheduledDelivery
            ? $conn->prepare("UPDATE customer_orders SET status = ?, delivery_status = ?, scheduled_release = ?, updated_at = NOW() WHERE id = ? AND status NOT IN ('Cancelled', 'Completed')")
            : $conn->prepare("UPDATE customer_orders SET status = ?, delivery_status = ?, updated_at = NOW() WHERE id = ? AND status NOT IN ('Cancelled', 'Completed')");
        if ($stmt) $scheduledDelivery ? $stmt->bind_param('sssi', $nextStatus, $nextDeliveryStatus, $scheduledDelivery, $id) : $stmt->bind_param('ssi', $nextStatus, $nextDeliveryStatus, $id);
    }
    $success = $stmt && $stmt->execute() && $stmt->affected_rows > 0;
    if ($success) {
        require_once '../notifications_helper.php';
        $label = $isCod ? 'Out for Delivery' : 'Ready for Pickup';
        createNotification($conn, 'customer', (int)$orderInfo['customer_id'], 'status', 'Order ' . $label, 'Your order for ' . $orderInfo['product_name'] . ' is now ' . $label . '.', 'customer_orders.php');
    }
    echo json_encode(['success' => $success, 'message' => $success ? '' : 'The order could not be advanced.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_driver') {
    header('Content-Type: application/json');
    $id = (int)($_POST['order_id'] ?? 0);
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $orderInfo = $conn->query("SELECT batch_id, status, delivery_method, payment_mode FROM customer_orders WHERE id = $id")->fetch_assoc();
    if (!$orderInfo) {
        echo json_encode(['success' => false, 'message' => 'Order was not found.']);
        exit;
    }
    if (in_array($orderInfo['status'], ['Cancelled', 'Completed'], true)) {
        echo json_encode(['success' => false, 'message' => 'Cancelled or completed orders cannot be assigned.']);
        exit;
    }
    $paymentModeValue = strtoupper(trim($orderInfo['payment_mode'] ?? ''));
    $deliveryMethodValue = strtoupper(trim($orderInfo['delivery_method'] ?? ''));
    $isCodOrder = in_array($deliveryMethodValue, ['COD', 'CASH ON DELIVERY'], true) || in_array($paymentModeValue, ['COD', 'CASH ON DELIVERY'], true);
    if (!$isCodOrder && $driverId > 0) {
        echo json_encode(['success' => false, 'message' => 'Only Cash on Delivery orders can be assigned to a driver.']);
        exit;
    }
    if ($driverId > 0) {
        $driverStmt = $conn->prepare("SELECT id FROM drivers WHERE id = ? AND status = 'active'");
        $driverStmt->bind_param('i', $driverId);
        $driverStmt->execute();
        if (!$driverStmt->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'message' => 'That driver is not available.']);
            exit;
        }
    }
    $batchId = $orderInfo['batch_id'] ?? '';
    if ($batchId) {
        if ($driverId > 0) {
            $stmt = $conn->prepare("UPDATE customer_orders SET driver_id = ?, delivery_status = 'assigned', assigned_at = NOW(), updated_at = NOW() WHERE batch_id = ? AND status NOT IN ('Cancelled', 'Completed')");
            $stmt->bind_param('is', $driverId, $batchId);
        } else {
            $stmt = $conn->prepare("UPDATE customer_orders SET driver_id = NULL, delivery_status = 'pending', assigned_at = NULL, updated_at = NOW() WHERE batch_id = ? AND status NOT IN ('Cancelled', 'Completed')");
            $stmt->bind_param('s', $batchId);
        }
    } elseif ($driverId > 0) {
        $stmt = $conn->prepare("UPDATE customer_orders SET driver_id = ?, delivery_status = 'assigned', assigned_at = NOW(), updated_at = NOW() WHERE id = ? AND status NOT IN ('Cancelled', 'Completed')");
        $stmt->bind_param('ii', $driverId, $id);
    } else {
        $stmt = $conn->prepare("UPDATE customer_orders SET driver_id = NULL, delivery_status = 'pending', assigned_at = NULL, updated_at = NOW() WHERE id = ? AND status NOT IN ('Cancelled', 'Completed')");
        $stmt->bind_param('i', $id);
    }
    $success = $stmt && $stmt->execute() && $stmt->affected_rows > 0;
    if ($success && $driverId > 0) {
        require_once '../notifications_helper.php';
        createNotification($conn, 'driver', $driverId, 'delivery', 'New COD delivery assigned', 'COD order ORD-' . str_pad($id, 3, '0', STR_PAD_LEFT) . ' is ready in your delivery queue.', '../driver/dashboard.php');
    }
    echo json_encode(['success' => $success, 'message' => $success ? '' : 'No assignment was changed.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    header('Content-Type: application/json');
    $id = (int)$_POST['order_id'];
    $status = $_POST['status'];
    $allowed = ['In Cart','Processing','Production','Ready for Pickup','Completed','Cancelled'];
    if (!in_array($status, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit;
    }
    
    $orderInfo = $conn->query("SELECT customer_id, product_name, variant_name, batch_id, status, payment_mode, delivery_method, delivery_status, driver_id FROM customer_orders WHERE id = $id")->fetch_assoc();
    $batchId = $orderInfo['batch_id'] ?? null;
    if (!$orderInfo || in_array($orderInfo['status'], ['Cancelled', 'Completed'], true)) {
        $message = !$orderInfo ? 'Order was not found.' : ($orderInfo['status'] === 'Completed' ? 'Completed orders cannot be changed.' : 'Cancelled orders cannot be processed.');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
    $paymentModeValue = strtoupper(trim($orderInfo['payment_mode'] ?? ''));
    $deliveryMethodValue = strtoupper(trim($orderInfo['delivery_method'] ?? ''));
    $isCodStatusOrder = in_array($deliveryMethodValue, ['COD', 'CASH ON DELIVERY'], true) || in_array($paymentModeValue, ['COD', 'CASH ON DELIVERY'], true);
    
    
    $deliverySql = '';
    if ($status === 'Production') {
        $deliverySql = ", delivery_status = 'out_for_delivery'";
    } elseif ($status === 'Completed') {
        $deliverySql = ", delivery_status = 'delivered', delivered_at = COALESCE(delivered_at, NOW()), payment_collected = 1";
    } elseif ($status === 'Processing') {
        $deliverySql = ", delivery_status = CASE WHEN driver_id IS NOT NULL THEN 'assigned' ELSE 'pending' END";
    } elseif ($status === 'Ready for Pickup') {
        $deliverySql = ", delivery_status = 'pending'";
    } elseif ($status === 'Cancelled') {
        $deliverySql = ", delivery_status = 'cancelled'";
    }

    $ordersToRelease = [];
    if ($status === 'Cancelled') {
        if ($batchId) {
            $releaseStmt = $conn->prepare("SELECT product_id, variant_id, quantity FROM customer_orders WHERE batch_id = ? AND status <> 'Cancelled'");
            $releaseStmt->bind_param('s', $batchId);
        } else {
            $releaseStmt = $conn->prepare("SELECT product_id, variant_id, quantity FROM customer_orders WHERE id = ? AND status <> 'Cancelled'");
            $releaseStmt->bind_param('i', $id);
        }
        $releaseStmt->execute();
        $releaseResult = $releaseStmt->get_result();
        while ($row = $releaseResult->fetch_assoc()) $ordersToRelease[] = $row;
        $releaseStmt->close();
    }

    $scheduledRelease = !empty($_POST['scheduled_release']) ? $_POST['scheduled_release'] : null;
    if ($batchId) {
        
        if ($scheduledRelease) {
            $stmt = $conn->prepare("UPDATE customer_orders SET status = ?, scheduled_release = ?, updated_at = NOW()$deliverySql WHERE batch_id = ?");
            $stmt->bind_param('sss', $status, $scheduledRelease, $batchId);
        } else {
            $stmt = $conn->prepare("UPDATE customer_orders SET status = ?, updated_at = NOW()$deliverySql WHERE batch_id = ?");
            $stmt->bind_param('ss', $status, $batchId);
        }
    } else {
        if ($scheduledRelease) {
            $stmt = $conn->prepare("UPDATE customer_orders SET status = ?, scheduled_release = ?, updated_at = NOW()$deliverySql WHERE id = ?");
            $stmt->bind_param('ssi', $status, $scheduledRelease, $id);
        } else {
            $stmt = $conn->prepare("UPDATE customer_orders SET status = ?, updated_at = NOW()$deliverySql WHERE id = ?");
            $stmt->bind_param('si', $status, $id);
        }
    }
    $conn->begin_transaction();
    $stmt->execute();
    $statusUpdated = $stmt->affected_rows > 0 || $status !== 'Cancelled';
    if ($status === 'Cancelled' && $statusUpdated) {
        foreach ($ordersToRelease as $order) {
            if (!releaseProductStock($conn, (int)$order['product_id'], $order['variant_id'] !== null ? (int)$order['variant_id'] : null, (int)$order['quantity'])) {
                $statusUpdated = false;
                break;
            }
        }
    }
    if ($statusUpdated) {
        $conn->commit();
    } else {
        $conn->rollback();
    }
    if (!$statusUpdated) {
        echo json_encode(['success' => false, 'message' => 'Order status was not changed.']);
        exit;
    }
    
    
    if ($orderInfo) {
        require_once '../notifications_helper.php';
        $varLabel = !empty($orderInfo['variant_name']) ? ' (' . $orderInfo['variant_name'] . ')' : '';
        $displayStatusLabel = ($status === 'Production') ? 'Out for Delivery' : $status;
        createNotification($conn, 'customer', (int)$orderInfo['customer_id'], 'status', 'Order ' . $displayStatusLabel, 'Your order for ' . $orderInfo['product_name'] . $varLabel . ' is now ' . $displayStatusLabel . '.', 'customer_orders.php');

        
        $driverId = (int)($orderInfo['driver_id'] ?? 0);
        if ($driverId > 0) {
            $orderTag = 'ORD-' . str_pad($id, 3, '0', STR_PAD_LEFT);
            if ($status === 'Production') {
                createNotification($conn, 'driver', $driverId, 'delivery', '🚚 Order In Transit', "Order $orderTag ({$orderInfo['product_name']}) is marked Out for Delivery.", 'dashboard.php?status=out_for_delivery');
            } elseif ($status === 'Completed') {
                createNotification($conn, 'driver', $driverId, 'delivery', '✅ Order Completed', "Order $orderTag ({$orderInfo['product_name']}) has been marked Completed.", 'dashboard.php?status=delivered');
            } elseif ($status === 'Cancelled') {
                createNotification($conn, 'driver', $driverId, 'delivery', '❌ Order Cancelled', "Order $orderTag was cancelled.", 'dashboard.php?status=assigned');
            }
        }

        
        if ($status === 'Completed') {
            $custId = (int)$orderInfo['customer_id'];
            $pName = $orderInfo['product_name'];
            $celebMsg = "🎉🎊 Congratulations! Your order for {$pName} is now COMPLETED!\n\n" .
                "Thank you so much for choosing  Dwarf Fruit! We truly appreciate your trust in us. 🧡\n\n" .
                "We hope you love your order! If you need anything else, don't hesitate to reach out. We'd love to work with you again! 🙌\n\n" .
                "—  Dwarf Fruit Team ✨";
            $celebStmt = $conn->prepare("INSERT INTO customer_messages (customer_id, sender, message, sent_at) VALUES (?, 'admin', ?, NOW())");
            $celebStmt->bind_param('is', $custId, $celebMsg);
            $celebStmt->execute();

            createNotification($conn, 'customer', $custId, 'status', '🎉 Order Completed!', 'Your order for ' . $pName . ' is done! Thank you for choosing  Dwarf Fruit!', 'customer_orders.php');
        }

        
        if ($status === 'Ready for Pickup') {
            $custId = (int)$orderInfo['customer_id'];
            
            $finRow = $conn->query("SELECT price, down_payment_amount, payment_mode, product_name FROM customer_orders WHERE id = $id")->fetch_assoc();
            if ($finRow && (float)$finRow['down_payment_amount'] > 0) {
                $fullPrice = (float)$finRow['price'];
                $downPaid = (float)$finRow['down_payment_amount'];
                $remaining = $fullPrice - $downPaid;
                if ($remaining > 0) {
                    $pName = $finRow['product_name'];
                    
                    $balJson = json_encode([
                        'name' => $pName,
                        'full' => $fullPrice,
                        'down_paid' => $downPaid,
                        'remaining' => $remaining,
                        'order_id' => $id
                    ]);
                    $balMsg = '__BAL__:' . $balJson;
                    $balStmt = $conn->prepare("INSERT INTO customer_messages (customer_id, sender, message, sent_at) VALUES (?, 'admin', ?, NOW())");
                    $balStmt->bind_param('is', $custId, $balMsg);
                    $balStmt->execute();

                    
                    $fmtRemaining = number_format($remaining, 2);
                    createNotification($conn, 'customer', $custId, 'payment', '💳 Remaining Balance', 'Your order for ' . $pName . ' is ready for pickup! Remaining balance: ₱' . $fmtRemaining . '. Pay via GCash or at the store.', 'customer_messages.php');
                }
            }
        }

        
        require_once '../classes/HttpSmsGateway.php';
        $smsGateway = new HttpSmsGateway($conn);
        $customer = $conn->query("SELECT name, phone FROM customer WHERE customer_id = " . (int)$orderInfo['customer_id'])->fetch_assoc();
        $smsConf = $smsGateway->getConfiguration();
        
        if ($customer && !empty($customer['phone']) && !empty($smsConf['gateway_type'])) {
            $custPhone = $customer['phone'];
            $custName = $customer['name'];
            $prodName = $orderInfo['product_name'] . $varLabel;
            
            if ($status === 'Ready for Pickup') {
                $finRow = $conn->query("SELECT price, down_payment_amount FROM customer_orders WHERE id = $id")->fetch_assoc();
                $remaining = 0;
                if ($finRow && (float)$finRow['down_payment_amount'] > 0) {
                    $remaining = (float)$finRow['price'] - (float)$finRow['down_payment_amount'];
                }
                if ($remaining > 0) {
                    $smsMsg = "Hello {$custName}! Your order for {$prodName} is ready for pickup. Remaining balance: ₱" . number_format($remaining, 2) . ". - Dwarf Fruit";
                } else {
                    $smsMsg = "Hello {$custName}! Your order for {$prodName} is ready for pickup. - Dwarf Fruit";
                }
            } else if ($status === 'Completed') {
                $smsMsg = "Hello {$custName}! Your order for {$prodName} is completed. Thank you for choosing Dwarf Fruit ! 🧡";
            } else {
                $smsMsg = "Hello {$custName}! Your order for {$prodName} is now {$status}. - Dwarf Fruit";
            }
            
            $smsGateway->sendSMS($custPhone, $smsMsg);
        }
    }
    
    echo json_encode(['success' => true]);
    exit;
}

$drivers = [];
$driversRes = $conn->query("SELECT id, name, status FROM drivers ORDER BY name ASC");
while ($driver = $driversRes->fetch_assoc()) $drivers[] = $driver;
$driverFilter = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : 0;
$driverWhere = $driverFilter === -1 ? ' WHERE co.driver_id IS NULL' : ($driverFilter > 0 ? ' WHERE co.driver_id = ' . $driverFilter : '');
$ordersRes = $conn->query("SELECT co.*, c.name as customer_name, c.email as customer_email, c.address as customer_address, d.name as driver_name, p.img as product_img, pv.image as variant_img FROM customer_orders co LEFT JOIN customer c ON co.customer_id = c.customer_id LEFT JOIN drivers d ON co.driver_id = d.id LEFT JOIN product p ON co.product_id = p.product_id LEFT JOIN product_variants pv ON co.variant_id = pv.variant_id$driverWhere ORDER BY co.created_at DESC");
$rawOrders = [];
while ($row = $ordersRes->fetch_assoc()) $rawOrders[] = $row;

$batchMap = [];
foreach ($rawOrders as $o) {
    $key = !empty($o['batch_id']) ? $o['batch_id'] : 'solo_' . $o['id'];
    if (!isset($batchMap[$key])) {
        $batchMap[$key] = [
            'first_id'            => $o['id'],
            'batch_id'            => $o['batch_id'] ?? null,
            'driver_id'           => $o['driver_id'] ?? null,
            'driver_name'         => $o['driver_name'] ?? null,
            'shipping_fee'        => (float)($o['shipping_fee'] ?? 0),
            'customer_name'       => $o['customer_name'],
            'customer_email'      => $o['customer_email'],
            'delivery_address'    => trim($o['delivery_address'] ?? '') !== '' ? $o['delivery_address'] : ($o['customer_address'] ?? ''),
            'product_name'        => $o['product_name'],
            'product_img'         => $o['product_img'] ?? '',
            'image'               => $o['image'],
            'payment_mode'        => $o['payment_mode'],
            'delivery_method'    => $o['delivery_method'] ?? 'Pickup',
            'delivery_status'    => $o['delivery_status'] ?? 'pending',
            'delivered_at'       => $o['delivered_at'] ?? null,
            'payment_collected'   => $o['payment_collected'] ?? null,
            'gcash_reference'     => $o['gcash_reference'],
            'down_payment_amount' => $o['down_payment_amount'],
            'order_notes'         => $o['order_notes'],
            'status'              => $o['status'],
            'created_at'          => $o['created_at'],
            'scheduled_release'   => $o['scheduled_release'],
            'items'               => [],
        ];
    }
    $batchMap[$key]['items'][] = [
        'variant_name' => $o['variant_name'] ?? '',
        'size'         => $o['size'] ?? '',
        'quantity'     => $o['quantity'],
        'price'        => $o['price'],
        'variant_img'  => $o['variant_img'] ?? '',
    ];
}
$orders = array_values($batchMap);

function getStatusBadge($status) {
    $map = [
        'In Cart'          => 'badge-incart',
        'Processing'       => 'badge-processing',
        'Production'       => 'badge-production',
        'Ready for Pickup' => 'badge-ready',
        'Completed'        => 'badge-completed',
        'Cancelled'        => 'badge-cancelled',
    ];
    return $map[$status] ?? 'badge-pending';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders -  Dwarf Fruit Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../css/admin.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    body { font-family: 'Inter', sans-serif; }
    .orders-table-wrap { 
        background: #fff; 
        border-radius: 14px; 
        box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 16px rgba(0,0,0,0.04); 
        overflow-x: auto; 
        border: 1px solid #e2e8f0; 
        width: 100%; 
    }
    .orders-table { 
        width: 100%; 
        border-collapse: separate; 
        border-spacing: 0; 
    }
    .orders-table thead th { 
        background: #f8fafc; 
        padding: 9px 8px; 
        font-size: 0.65rem; 
        font-weight: 700; 
        text-transform: uppercase; 
        letter-spacing: 0.04em; 
        color: #64748b; 
        border-bottom: 2px solid #e2e8f0; 
        text-align: left; 
        white-space: nowrap; 
    }
    .orders-table tbody tr { transition: all 0.15s ease; }
    .orders-table tbody tr:hover { background: #fafbff; }
    .orders-table tbody td { 
        padding: 10px 8px; 
        border-bottom: 1px solid #f1f5f9; 
        vertical-align: middle; 
        font-size: 0.76rem; 
    }
    .orders-table tbody tr:last-child td { border-bottom: none; }

    .order-id-chip { font-weight: 700; color: #1e293b; font-size: 0.76rem; white-space: nowrap; }
    .order-id-chip .multi-badge { display: inline-block; background: #fff3ed; color: #ea580c; font-size: 0.58rem; font-weight: 700; padding: 1px 5px; border-radius: 6px; margin-top: 2px; }
    .customer-info .name { font-weight: 600; color: #1e293b; font-size: 0.76rem; white-space: nowrap; }
    .customer-info .email { color: #94a3b8; font-size: 0.68rem; max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .location-cell { max-width: 110px; color: #64748b; font-size: 0.68rem; line-height: 1.25; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
    .product-cell .prod-name { font-weight: 600; color: #1e293b; font-size: 0.76rem; white-space: nowrap; }
    .product-cell .variant-line { font-size: 0.68rem; color: #ea580c; margin-top: 2px; }
    .product-cell .variant-line .meta { color: #94a3b8; }
    .product-cell .ref-thumb { width: 22px; height: 22px; object-fit: cover; border-radius: 5px; border: 1.5px solid #e2e8f0; cursor: pointer; transition: transform 0.15s; }
    .product-cell .ref-thumb:hover { transform: scale(1.2); border-color: #ff6b35; }
    .product-cell .ref-count { font-size: 0.58rem; color: #94a3b8; }

    .num-cell { font-weight: 600; color: #334155; text-align: center; font-size: 0.76rem; padding: 10px 4px; }
    .price-cell { font-weight: 700; color: #1e293b; white-space: nowrap; font-size: 0.76rem; }
    .pmt-cell { font-size: 0.68rem; line-height: 1.25; }
    .pmt-cell .pmt-amount { font-weight: 700; color: #16a34a; font-size: 0.72rem; }
    .pmt-cell .pmt-ref { font-size: 0.64rem; color: #64748b; white-space: nowrap; }
    .release-cell { font-size: 0.7rem; color: #64748b; white-space: nowrap; text-align: center; }
    .date-cell { font-size: 0.7rem; color: #94a3b8; white-space: nowrap; }
    .notes-chip { display: inline-flex; align-items: center; gap: 3px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 2px 6px; font-size: 0.62rem; color: #64748b; cursor: help; max-width: 100px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 3px; }

    
    .status-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 12px; font-size: 0.66rem; font-weight: 700; white-space: nowrap; }
    .status-pill .status-dot { width: 6px; height: 6px; border-radius: 50%; }
    .status-pill.s-incart { background: #f1f5f9; color: #64748b; }
    .status-pill.s-incart .status-dot { background: #94a3b8; }
    .status-pill.s-processing { background: #eff6ff; color: #2563eb; }
    .status-pill.s-processing .status-dot { background: #3b82f6; }
    .status-pill.s-production { background: #fffbeb; color: #d97706; }
    .status-pill.s-production .status-dot { background: #f59e0b; }
    .status-pill.s-ready { background: #f5f3ff; color: #7c3aed; }
    .status-pill.s-ready .status-dot { background: #8b5cf6; }
    .status-pill.s-completed { background: #f0fdf4; color: #16a34a; }
    .status-pill.s-completed .status-dot { background: #22c55e; }
    .status-pill.s-cancelled { background: #fef2f2; color: #dc2626; }
    .status-pill.s-cancelled .status-dot { background: #ef4444; }

    .action-select { font-size: 0.7rem; border: 1.5px solid #e2e8f0; border-radius: 6px; padding: 4px 6px; background: #fff; cursor: pointer; transition: all 0.15s; font-weight: 500; color: #334155; font-family: inherit; max-width: 105px; }
    .action-select:focus { outline: none; border-color: #ff6b35; box-shadow: 0 0 0 2px rgba(255,107,53,0.12); }
    .action-select:hover { border-color: #cbd5e1; }
    .action-select.locked-action,
    .action-select.locked-action:disabled { background: #f1f5f9; border-color: #e2e8f0; color: #94a3b8; cursor: not-allowed; opacity: 0.8; }

    .orders-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
    .orders-header h1 { font-size: 1.35rem; font-weight: 800; color: #0f172a; }
    .orders-header .subtitle { color: #94a3b8; font-size: 0.8rem; margin-top: 1px; }
    .orders-count { background: #f8fafc; border: 1px solid #e2e8f0; padding: 5px 12px; border-radius: 8px; font-size: 0.75rem; font-weight: 600; color: #64748b; }
    .driver-filter, .driver-select { font-size: 0.7rem; border: 1.5px solid #e2e8f0; border-radius: 6px; padding: 4px 6px; background: #fff; color: #334155; font-family: inherit; }
    .driver-select { max-width: 100px; }
    .driver-select:disabled { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; }
    .driver-status-badge { display: inline-flex; align-items: center; gap: 3px; padding: 2px 7px; border-radius: 6px; font-size: 0.62rem; font-weight: 700; margin-top: 4px; white-space: nowrap; }
    .driver-status-badge.d-assigned { background: #fef3c7; color: #b45309; }
    .driver-status-badge.d-transit { background: #e0f2fe; color: #0369a1; }
    .driver-status-badge.d-delivered { background: #dcfce7; color: #15803d; }
    .driver-status-badge.d-cancelled { background: #fee2e2; color: #b91c1c; }
    .advance-delivery-btn { border: 0; border-radius: 6px; padding: 4px 8px; background: #22a06b; color: #fff; font-size: .66rem; font-weight: 700; cursor: pointer; white-space: nowrap; }
    .advance-delivery-btn:hover { background: #18794e; }
    .advance-delivery-btn:disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; }
    .view-order-btn { border: 1px solid #dbe4ee; border-radius: 6px; padding: 4px 8px; background: #fff; color: #2563eb; font-size: .66rem; font-weight: 700; cursor: pointer; white-space: nowrap; }
    .view-order-btn:hover { background: #eff6ff; border-color: #93c5fd; }
    .order-detail-overlay { display:none; position:fixed; inset:0; z-index:3000; align-items:center; justify-content:center; padding:20px; background:rgba(15,23,42,.55); backdrop-filter:blur(4px); }
    .order-detail-overlay.open { display:flex; }
    .order-detail-modal { width:min(680px, 100%); max-height:90vh; overflow:auto; background:#fff; border-radius:18px; box-shadow:0 24px 70px rgba(15,23,42,.25); }
    .order-detail-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:22px 24px; color:#fff; background:linear-gradient(135deg,#0f766e,#166534); }
    .order-detail-header h2 { margin:0; color:#fff; font-size:1.15rem; }
    .order-detail-header p { margin:5px 0 0; color:rgba(255,255,255,.72); font-size:.75rem; }
    .order-detail-close { border:0; background:transparent; color:rgba(255,255,255,.8); font-size:1.4rem; cursor:pointer; line-height:1; }
    .order-detail-body { padding:22px 24px; }
    .detail-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; margin-bottom:20px; }
    .detail-block { padding:13px 14px; border:1px solid #e5eaf0; border-radius:11px; background:#f8fafc; }
    .detail-block.full { grid-column:1 / -1; }
    .detail-label { color:#64748b; font-size:.65rem; font-weight:800; letter-spacing:.06em; text-transform:uppercase; }
    .detail-value { margin-top:5px; color:#172033; font-size:.82rem; line-height:1.45; word-break:break-word; }
    .detail-section-title { margin:0 0 10px; color:#172033; font-size:.82rem; font-weight:800; }
    .detail-items { border:1px solid #e5eaf0; border-radius:11px; overflow:hidden; margin-bottom:20px; }
    .detail-item { display:flex; justify-content:space-between; gap:14px; padding:11px 13px; border-bottom:1px solid #edf1f5; font-size:.78rem; }
    .detail-item:last-child { border-bottom:0; }
    .detail-item-name { color:#334155; font-weight:700; }
    .detail-item-meta { color:#64748b; font-size:.7rem; margin-top:3px; }
    .detail-item-total { color:#0f766e; font-weight:800; white-space:nowrap; }
    .detail-total { display:flex; justify-content:space-between; padding:13px; background:#ecfdf5; color:#166534; font-size:.85rem; font-weight:800; }
    @media (max-width: 600px) { .detail-grid { grid-template-columns:1fr; } .detail-block.full { grid-column:auto; } .order-detail-body { padding:18px; } }
    </style>
</head>
<body class="bg-gray-50 admin-page">

<?php include 'sidebar.php'; ?>

<main class="ml-64 p-4 lg:p-6 min-h-screen">
    <div class="orders-header">
        <div>
            <h1>Orders</h1>
            <p class="subtitle">Manage all customer orders</p>
        </div>
        <div style="display:flex;align-items:center;gap:12px;">
            <form method="get">
                <select name="driver_id" class="driver-filter" onchange="this.form.submit()" aria-label="Filter by driver">
                    <option value="0">All drivers</option>
                    <option value="-1" <?= $driverFilter === -1 ? 'selected' : '' ?>>Unassigned</option>
                    <?php foreach ($drivers as $driver): ?>
                        <option value="<?= (int)$driver['id'] ?>" <?= $driverFilter === (int)$driver['id'] ? 'selected' : '' ?>><?= htmlspecialchars($driver['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <div class="orders-count"><?= count($orders) ?> total orders</div>
        </div>
    </div>

    <div class="orders-table-wrap">
        <table class="orders-table admin-table">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Customer</th>
                    <th>Location</th>
                    <th>Product / Variants</th>
                    <th style="text-align:center;">Qty</th>
                    <th>Total Price</th>
                    <th>Payment</th>
                    <th>Release</th>
                    <th>Driver</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr><td colspan="12" class="text-center text-gray-400 py-8">No orders found</td></tr>
                <?php else: ?>
                    <?php foreach ($orders as $o):
                        $totalQty   = array_sum(array_column($o['items'], 'quantity'));
                        $totalPrice = array_sum(array_map(fn($it) => (float)$it['price'] * (int)$it['quantity'], $o['items']));
                        $totalPrice += (float)($o['shipping_fee'] ?? 0);
                        $isMulti    = count($o['items']) > 1;
                        $refImgs    = array_filter(array_map('trim', explode(',', $o['image'] ?? '')));
                        $firstRefImg = !empty($refImgs) ? reset($refImgs) : '';
                    ?>
                    <tr>
                        <td>
                            <span class="order-id-chip">ORD-<?= str_pad($o['first_id'], 3, '0', STR_PAD_LEFT) ?></span>
                            <?php if ($isMulti): ?><span class="order-id-chip"><span class="multi-badge"><?= count($o['items']) ?> items</span></span><?php endif; ?>
                        </td>
                        <td>
                            <div class="customer-info">
                                <div class="name"><?= htmlspecialchars($o['customer_name'] ?: 'Unknown') ?></div>
                                <div class="email"><?= htmlspecialchars($o['customer_email'] ?? '') ?></div>
                            </div>
                        </td>
                        <td class="location-cell" title="<?= htmlspecialchars($o['delivery_address'] ?? '') ?>">
                            <?= !empty($o['delivery_address']) ? nl2br(htmlspecialchars($o['delivery_address'])) : '<span style="color:#cbd5e1;">No location</span>' ?>
                        </td>
                        <td>
                            <div class="product-cell">
                                <div class="prod-name"><?= htmlspecialchars($o['product_name']) ?></div>
                                <?php foreach ($o['items'] as $it): ?>
                                    <div class="variant-line" style="display:flex;align-items:center;gap:6px;margin-top:2px;">
                                        <?php $itThumb = !empty($it['variant_img']) ? $it['variant_img'] : (!empty($o['product_img']) ? $o['product_img'] : ''); ?>
                                        <?php if (!empty($itThumb)): ?>
                                            <img src="../<?= htmlspecialchars($itThumb) ?>" class="ref-thumb" style="width:26px;height:26px;margin-top:0;" onclick="viewPhoto('../<?= htmlspecialchars($itThumb) ?>')" title="View item image">
                                        <?php endif; ?>
                                        <span>
                                            <?= htmlspecialchars($it['variant_name'] ?: 'Custom') ?>
                                            <?php if (!empty($it['size'])): ?><span class="meta"> — <?= htmlspecialchars($it['size']) ?></span><?php endif; ?>
                                            <span class="meta"> ×<?= (int)$it['quantity'] ?></span>
                                            <?php if ($it['price'] > 0): ?><span class="meta"> @ ₱<?= number_format($it['price'],2) ?></span><?php endif; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!empty($firstRefImg)): ?>
                                    <div style="display:flex;align-items:center;gap:4px;margin-top:4px;">
                                        <img src="../<?= htmlspecialchars($firstRefImg) ?>" class="ref-thumb" onclick="viewPhoto('../<?= htmlspecialchars($firstRefImg) ?>')" title="Customer uploaded reference photo">
                                        <?php if (count($refImgs) > 1): ?><span class="ref-count">+<?= count($refImgs)-1 ?> more</span><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="num-cell"><?= $totalQty ?></td>
                        <td class="price-cell"><?= $totalPrice > 0 ? '₱'.number_format($totalPrice,2) : '<span style="color:#cbd5e1;">—</span>' ?></td>
                        <td>
                            <div class="pmt-cell">
                            <?php if (!empty($o['payment_mode'])): ?>
                                <div class="pmt-ref"><?= htmlspecialchars($o['payment_mode'] ?: 'Not specified') ?></div>
                            <?php endif; ?>
                            <?php $downPaid = (float)($o['down_payment_amount'] ?? 0); $remainingDue = max(0, $totalPrice - $downPaid); $paymentModeValue = strtoupper(trim($o['payment_mode'] ?? '')); $deliveryMethodValue = strtoupper(trim($o['delivery_method'] ?? '')); $isCodOrder = in_array($deliveryMethodValue, ['COD', 'CASH ON DELIVERY'], true) || in_array($paymentModeValue, ['COD', 'CASH ON DELIVERY'], true); $codPaid = (int)($o['payment_collected'] ?? 0) === 1 || $remainingDue <= 0; ?>
                            <?php if ($downPaid > 0): ?>
                                <div class="pmt-amount">Down: ₱<?= number_format($downPaid,2) ?></div>
                                <?php if ($remainingDue > 0): ?>
                                    <div class="pmt-ref" style="color:#dc2626;">Remaining: ₱<?= number_format($remainingDue,2) ?></div>
                                <?php else: ?>
                                    <div class="pmt-ref" style="color:#16a34a;">Fully paid</div>
                                <?php endif; ?>
                                <?php if (!empty($o['gcash_reference'])): ?>
                                    <div class="pmt-ref">Ref: <?= htmlspecialchars($o['gcash_reference']) ?></div>
                                <?php endif; ?>
                            <?php elseif ($totalPrice > 0): ?>
                                <div class="pmt-ref">Total: ₱<?= number_format($totalPrice,2) ?></div>
                            <?php else: ?>
                                <span style="color:#cbd5e1;">—</span>
                            <?php endif; ?>
                            <?php if ($isCodOrder): ?>
                                <div class="pmt-ref" style="color:<?= $codPaid ? '#16a34a' : '#dc2626' ?>;"><?= $codPaid ? 'COD balance paid' : 'COD balance due' ?></div>
                            <?php endif; ?>
                            </div>
                        </td>
                        <td class="release-cell">
                            <?= !empty($o['scheduled_release']) ? date('M d, Y', strtotime($o['scheduled_release'])) : '<span style="color:#cbd5e1;">—</span>' ?>
                        </td>
                        <td class="driver-cell">
                            <?php $isCodDriverRow = in_array(strtoupper(trim($o['delivery_method'] ?? 'Pickup')), ['COD', 'CASH ON DELIVERY'], true) || in_array(strtoupper(trim($o['payment_mode'] ?? '')), ['COD', 'CASH ON DELIVERY'], true); ?>
                            <?php if (!$isCodDriverRow): ?>
                                <select class="driver-select pickup-driver" disabled title="Pickup orders do not require a driver"><option>Pickup</option></select>
                            <?php else: ?>
                            <select class="driver-select" data-order-id="<?= $o['first_id'] ?>" onchange="assignDriver(this)" <?= in_array($o['status'], ['Cancelled', 'Completed'], true) ? 'disabled' : '' ?> title="Assign an active driver">
                                <option value="0">Unassigned</option>
                                <?php foreach ($drivers as $driver): ?>
                                    <?php if ($driver['status'] === 'active' || (int)$driver['id'] === (int)$o['driver_id']): ?>
                                        <option value="<?= (int)$driver['id'] ?>" <?= (int)$driver['id'] === (int)$o['driver_id'] ? 'selected' : '' ?>><?= htmlspecialchars($driver['name']) ?><?= $driver['status'] !== 'active' ? ' (Inactive)' : '' ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <?php if ((int)($o['driver_id'] ?? 0) > 0): ?>
                                <?php
                                $dStatus = $o['delivery_status'] ?? 'assigned';
                                $dLabels = [
                                    'assigned' => '⏳ Assigned',
                                    'out_for_delivery' => '🚚 In Transit',
                                    'delivered' => '✅ Delivered',
                                    'cancelled' => '❌ Cancelled'
                                ];
                                $dCls = 'd-' . ($dStatus === 'out_for_delivery' ? 'transit' : $dStatus);
                                ?>
                                <div><span class="driver-status-badge <?= $dCls ?>"><?= $dLabels[$dStatus] ?? '⏳ Assigned' ?></span></div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $pillMap = ['In Cart'=>'s-incart','Processing'=>'s-processing','Production'=>'s-production','Ready for Pickup'=>'s-ready','Completed'=>'s-completed','Cancelled'=>'s-cancelled'];
                            $pillCls = $pillMap[$o['status']] ?? 's-incart';
                            ?>
                            <span class="status-pill <?= $pillCls ?>"><span class="status-dot"></span><?= $o['status'] === 'Production' ? 'Out for Delivery' : htmlspecialchars($o['status']) ?></span>
                        </td>
                        <td class="date-cell"><?= date('M d, Y', strtotime($o['created_at'])) ?></td>
                        <td>
                            <div style="display:flex;flex-direction:column;gap:4px;">
                            <button type="button" class="view-order-btn" onclick="viewOrder(<?= (int)$o['first_id'] ?>)">View Details</button>
                            <?php $deliveryMethodValue = strtoupper(trim($o['delivery_method'] ?? '')); $paymentModeValue = strtoupper(trim($o['payment_mode'] ?? '')); $isCodOrder = in_array($deliveryMethodValue, ['COD', 'CASH ON DELIVERY'], true) || in_array($paymentModeValue, ['COD', 'CASH ON DELIVERY'], true); $canAdvance = in_array($o['status'], ['In Cart', 'Processing'], true); ?>
                            <?php if ($canAdvance && $isCodOrder): ?>
                                <button type="button" class="advance-delivery-btn" data-order-id="<?= $o['first_id'] ?>" data-cod="<?= $isCodOrder ? '1' : '0' ?>" <?= $isCodOrder && !(int)$o['driver_id'] ? 'disabled title="Assign a driver first"' : '' ?> onclick="advanceDelivery(this)"><?= $isCodOrder ? 'Out for Delivery' : 'Ready for Pickup' ?></button>
                            <?php endif; ?>
                                    <select class="action-select status-select <?= in_array($o['status'], ['Cancelled', 'Completed'], true) ? 'locked-action' : '' ?>"
                                    data-order-id="<?= $o['first_id'] ?>"
                                    data-original="<?= htmlspecialchars($o['status']) ?>"
                                        <?= in_array($o['status'], ['Cancelled', 'Completed'], true) ? 'disabled title="This order is locked"' : '' ?>
                                    onchange="updateStatus(this)">
                                <option value="In Cart" <?= $o['status'] === 'In Cart' ? 'selected' : '' ?>>In Cart</option>
                                <option value="Processing" <?= $o['status'] === 'Processing' ? 'selected' : '' ?>>Processing</option>
                                <option value="Production" <?= $o['status'] === 'Production' ? 'selected' : '' ?>>Out for Delivery (In Transit)</option>
                                <option value="Ready for Pickup" <?= $o['status'] === 'Ready for Pickup' ? 'selected' : '' ?>>Ready for Pickup</option>
                                <option value="Completed" <?= $o['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="Cancelled" <?= $o['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                            <?php if (!empty($o['order_notes'])): ?>
                                <span title="<?= htmlspecialchars($o['order_notes']) ?>" class="notes-chip">📝 <?= htmlspecialchars(mb_substr($o['order_notes'],0,20)) ?>...</span>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<div id="orderDetailOverlay" class="order-detail-overlay" onclick="if (event.target === this) closeOrderDetails()">
    <section class="order-detail-modal" role="dialog" aria-modal="true" aria-labelledby="orderDetailTitle">
        <header class="order-detail-header">
            <div><h2 id="orderDetailTitle">Order Details</h2><p id="orderDetailSubtitle">Complete order information</p></div>
            <button type="button" class="order-detail-close" onclick="closeOrderDetails()" aria-label="Close order details">&times;</button>
        </header>
        <div id="orderDetailContent" class="order-detail-body"></div>
    </section>
</div>

<script>
const ADMIN_DRIVERS = <?= json_encode($drivers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const ADMIN_ORDERS = <?= json_encode($orders, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

function viewOrder(orderId) {
    const order = ADMIN_ORDERS.find(item => Number(item.first_id) === Number(orderId)) || (window._polledOrders || {})[orderId];
    if (!order) return;
    const displayId = order.first_id || order.id;
    const items = (order.items || []).map(item => `
        <div class="detail-item">
            <div><div class="detail-item-name">${escHtml(item.variant_name || 'Custom item')}</div><div class="detail-item-meta">${item.size ? escHtml(item.size) + ' · ' : ''}${item.quantity} pcs × ₱${Number(item.price || 0).toFixed(2)}</div></div>
            <div class="detail-item-total">₱${(Number(item.price || 0) * Number(item.quantity || 0)).toFixed(2)}</div>
        </div>`).join('');
    const totalQuantity = (order.items || []).reduce((sum, item) => sum + Number(item.quantity || 0), 0);
    const itemTotal = (order.items || []).reduce((sum, item) => sum + Number(item.price || 0) * Number(item.quantity || 0), 0);
    const shipping = Number(order.shipping_fee || 0);
    const total = itemTotal + shipping;
    const downPaid = Number(order.down_payment_amount || 0);
    const remainingBalance = Math.max(0, total - downPaid);
    const isCodPayment = ['COD', 'CASH ON DELIVERY'].includes(String(order.payment_mode || '').toUpperCase()) || ['COD', 'CASH ON DELIVERY'].includes(String(order.delivery_method || '').toUpperCase());
    const codPaid = Number(order.payment_collected) === 1 || remainingBalance <= 0;
    const paymentCollected = isCodPayment ? (codPaid ? 'COD balance paid' : 'COD balance due') : (Number(order.down_payment_amount || 0) > 0 ? 'Down payment recorded' : 'Payment pending');
    document.getElementById('orderDetailContent').innerHTML = `
        <div class="detail-grid">
            <div class="detail-block"><div class="detail-label">Customer</div><div class="detail-value">${escHtml(order.customer_name || 'Unknown')}<br>${escHtml(order.customer_email || 'No email')}</div></div>
            <div class="detail-block"><div class="detail-label">Order Date</div><div class="detail-value">${formatOrderDate(order.created_at)}</div></div>
            <div class="detail-block full"><div class="detail-label">Delivery Address</div><div class="detail-value">${escHtml(order.delivery_address || 'No address provided').replace(/\n/g, '<br>')}</div></div>
            <div class="detail-block"><div class="detail-label">Delivery Method</div><div class="detail-value">${escHtml(order.delivery_method || 'Pickup')}</div></div>
            <div class="detail-block"><div class="detail-label">Delivery Status</div><div class="detail-value">${order.delivery_status === 'delivered' ? 'Done' : escHtml(order.delivery_status || 'pending')}</div></div>
            <div class="detail-block"><div class="detail-label">Delivered At</div><div class="detail-value">${order.delivered_at ? formatOrderDate(order.delivered_at) : 'Not delivered yet'}</div></div>
            <div class="detail-block"><div class="detail-label">Payment Method</div><div class="detail-value">${escHtml(order.payment_mode || 'Not specified')}</div></div>
            <div class="detail-block"><div class="detail-label">Down Payment</div><div class="detail-value">₱${downPaid.toFixed(2)}</div></div>
            <div class="detail-block"><div class="detail-label">Remaining Balance</div><div class="detail-value">₱${remainingBalance.toFixed(2)}</div></div>
            <div class="detail-block"><div class="detail-label">Payment Status</div><div class="detail-value">${isCodPayment ? paymentCollected : (Number(order.down_payment_amount || 0) > 0 ? 'Down payment recorded' : 'Payment pending')}</div></div>
            <div class="detail-block"><div class="detail-label">Assigned Driver</div><div class="detail-value">${escHtml(order.driver_name || 'Unassigned')}</div></div>
            <div class="detail-block"><div class="detail-label">Release Date</div><div class="detail-value">${order.scheduled_release ? formatOrderDate(order.scheduled_release) : 'Not scheduled'}</div></div>
            ${order.order_notes ? `<div class="detail-block full"><div class="detail-label">Order Notes</div><div class="detail-value">${escHtml(order.order_notes).replace(/\n/g, '<br>')}</div></div>` : ''}
        </div>
        <h3 class="detail-section-title">${escHtml(order.product_name || 'Order items')} · ${totalQuantity} pcs</h3>
        <div class="detail-items">${items}<div class="detail-total"><span>Total${shipping ? ' including shipping' : ''}</span><span>₱${total.toFixed(2)}</span></div></div>`;
    document.getElementById('orderDetailTitle').textContent = 'ORD-' + String(displayId).padStart(3, '0');
    document.getElementById('orderDetailSubtitle').textContent = order.status === 'Production' ? 'Out for Delivery' : (order.status || 'Order details');
    document.getElementById('orderDetailOverlay').classList.add('open');
}

function formatOrderDate(value) {
    const date = new Date(String(value).includes('T') ? value : String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? String(value || 'Not available') : date.toLocaleString('en-US', { month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit' });
}

function closeOrderDetails() {
    document.getElementById('orderDetailOverlay').classList.remove('open');
}

function advanceDelivery(button) {
    if (button.disabled) return;
    const isCod = button.dataset.cod === '1';
    showDeliveryScheduleModal(button, isCod);
}

function showDeliveryScheduleModal(button, isCod) {
    const today = new Date().toISOString().split('T')[0];
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;';
    overlay.innerHTML = `
        <div style="background:#fff;border-radius:16px;padding:2rem;width:380px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
            <h3 style="font-size:1.1rem;font-weight:700;margin-bottom:0.5rem;">${isCod ? 'Schedule Out for Delivery' : 'Schedule Ready for Pickup'}</h3>
            <p style="color:#6b7280;font-size:0.85rem;margin-bottom:1.25rem;">Order #${button.dataset.orderId} &mdash; Choose the date when this order will be released.</p>
            <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:0.4rem;">${isCod ? 'Scheduled Delivery Date' : 'Scheduled Pickup Date'}</label>
            <input type="date" id="deliveryScheduleDate" min="${today}" required style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;outline:none;margin-bottom:1.25rem;">
            <div style="display:flex;gap:0.75rem;">
                <button type="button" onclick="this.closest('div[style*=fixed]').remove()" style="flex:1;padding:10px;border:1px solid #e5e7eb;border-radius:8px;font-size:0.85rem;font-weight:600;background:#f9fafb;cursor:pointer;color:#374151;">Cancel</button>
                <button type="button" onclick="confirmDeliverySchedule(this, '${button.dataset.orderId}', '${isCod ? '1' : '0'}')" style="flex:2;padding:10px;background:linear-gradient(135deg,#ff6b35,#e55a2b);border:none;border-radius:8px;font-size:0.85rem;font-weight:700;color:#fff;cursor:pointer;">Confirm ${isCod ? 'Out for Delivery' : 'Ready for Pickup'}</button>
            </div>
        </div>`;
    overlay.querySelector('#deliveryScheduleDate').focus();
    document.body.appendChild(overlay);
}

function confirmDeliverySchedule(button, orderId, cod) {
    const overlay = button.closest('div[style*=fixed]');
    const scheduledDate = overlay.querySelector('#deliveryScheduleDate').value;
    if (!scheduledDate) {
        overlay.querySelector('#deliveryScheduleDate').reportValidity();
        return;
    }
    overlay.remove();
    const orderButton = document.querySelector(`.advance-delivery-btn[data-order-id="${orderId}"]`);
    if (orderButton) orderButton.disabled = true;
    const formData = new FormData();
    formData.append('action', 'advance_delivery');
    formData.append('order_id', orderId);
    formData.append('scheduled_delivery', scheduledDate);
    fetch('orders.php', { method: 'POST', body: formData })
        .then(async response => {
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) throw new Error(response.ok ? 'The server returned an invalid response.' : 'Your admin session has expired. Please log in again.');
            return response.json();
        })
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Unable to update delivery.');
            showToast('Order Updated', cod === '1' ? 'Order is scheduled and out for delivery.' : 'Order is scheduled for pickup.', 'success');
            setTimeout(() => location.reload(), 700);
        })
        .catch(error => { showToast('Error', error.message, 'error'); if (orderButton) orderButton.disabled = false; });
}

function assignDriver(select) {
    const originalValue = select.dataset.original || '0';
    const driverId = select.value;
    const formData = new FormData();
    formData.append('action', 'assign_driver');
    formData.append('order_id', select.dataset.orderId);
    formData.append('driver_id', driverId);
    select.disabled = true;

    fetch('orders.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Unable to assign driver.');
            select.dataset.original = driverId;
            showToast('Driver Assigned', driverId === '0' ? 'Driver assignment removed.' : 'Delivery assigned successfully.', 'success');
        })
        .catch(error => {
            select.value = originalValue;
            showToast('Error', error.message, 'error');
        })
        .finally(() => { select.disabled = select.closest('tr')?.querySelector('.status-select')?.disabled || false; });
}

function updateStatus(select) {
    if (select.disabled || ['Cancelled', 'Completed'].includes(select.dataset.original)) return;
    const orderId = select.dataset.orderId;
    const status = select.value;
    const originalValue = select.getAttribute('data-original') || '';
    
    select.value = originalValue;

    if (status === 'Ready for Pickup') {
        
        showReleaseDateModal(orderId, status);
        return;
    }

    const displayStatusName = status === 'Production' ? 'Out for Delivery (In Transit)' : status;
    showConfirm({
        title: 'Update Order Status?',
        message: 'Change order #' + orderId + ' status to <strong>' + displayStatusName + '</strong>.',
        type: status === 'Cancelled' ? 'danger' : status === 'Completed' ? 'success' : 'warning',
        confirmText: 'Update',
        onConfirm: () => { _doUpdateStatus(orderId, status, null); },
    });
}

function showReleaseDateModal(orderId, status) {
    const today = new Date().toISOString().split('T')[0];
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;';
    overlay.innerHTML = `
        <div style="background:#fff;border-radius:16px;padding:2rem;width:380px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
            <h3 style="font-size:1.1rem;font-weight:700;margin-bottom:0.5rem;">Set Ready for Pickup</h3>
            <p style="color:#6b7280;font-size:0.85rem;margin-bottom:1.25rem;">Order #${orderId} &mdash; Set an optional scheduled release/pickup date for the customer.</p>
            <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:0.4rem;">Scheduled Release Date <span style="color:#9ca3af;">(optional)</span></label>
            <input type="date" id="releaseDate" min="${today}" style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;outline:none;margin-bottom:1.25rem;">
            <div style="display:flex;gap:0.75rem;">
                <button onclick="this.closest('div[style*=fixed]').remove()" style="flex:1;padding:10px;border:1px solid #e5e7eb;border-radius:8px;font-size:0.85rem;font-weight:600;background:#f9fafb;cursor:pointer;color:#374151;">Cancel</button>
                <button onclick="_confirmReleaseDate(${orderId}, '${status}', this)" style="flex:2;padding:10px;background:linear-gradient(135deg,#ff6b35,#e55a2b);border:none;border-radius:8px;font-size:0.85rem;font-weight:700;color:#fff;cursor:pointer;">Confirm Ready for Pickup</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
}

function _confirmReleaseDate(orderId, status, btn) {
    const releaseDate = document.getElementById('releaseDate').value || null;
    btn.closest('div[style*=fixed]').remove();
    _doUpdateStatus(orderId, status, releaseDate);
}

function _doUpdateStatus(orderId, status, scheduledRelease) {
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('order_id', orderId);
    formData.append('status', status);
    if (scheduledRelease) formData.append('scheduled_release', scheduledRelease);

    fetch('orders.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const displayStatus = status === 'Production' ? 'Out for Delivery (In Transit)' : status;
                showToast('Status Updated', 'Order status changed to ' + displayStatus + '.', 'success');
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast('Error', data.message || 'Failed to update order status.', 'error');
            }
        })
        .catch(() => showToast('Error', 'Something went wrong.', 'error'));
}

function viewPhoto(src) {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.85);z-index:9999;display:flex;align-items:center;justify-content:center;cursor:pointer;';
    overlay.onclick = () => overlay.remove();
    const img = document.createElement('img');
    img.src = src;
    img.style.cssText = 'max-width:85%;max-height:85%;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.5);';
    overlay.appendChild(img);
    document.body.appendChild(overlay);
}

let _lastOrderData = null;
let _isUpdating = false;

function getStatusBadgeClass(status) {
    const map = { 'In Cart': 'badge-incart', 'Processing': 'badge-processing', 'Production': 'badge-production', 'Ready for Pickup': 'badge-ready', 'Completed': 'badge-completed', 'Cancelled': 'badge-cancelled' };
    return map[status] || 'badge-pending';
}

function escHtml(str) {
    const d = document.createElement('div'); d.textContent = str || ''; return d.innerHTML;
}

function buildOrderRow(o) {
    window._polledOrders = window._polledOrders || {};
    window._polledOrders[o.id] = o;
    const orderId = String(o.id).padStart(3, '0');
    const isCodForStatus = ['COD', 'CASH ON DELIVERY'].includes(String(o.delivery_method || 'Pickup').toUpperCase()) || ['COD', 'CASH ON DELIVERY'].includes(String(o.payment_mode || '').toUpperCase());
    const statusOpts = ['In Cart','Processing','Production','Ready for Pickup','Completed','Cancelled'];
    const statusLabels = { 'Production': 'Out for Delivery (In Transit)' };
    const optionsHtml = statusOpts.map(s => `<option value="${s}" ${o.status === s ? 'selected' : ''}>${statusLabels[s] || s}</option>`).join('');
    const items = o.items || [];
    let variantHtml = '';
    if (items.length > 0) {
        variantHtml = items.map(it => {
            const thumb = it.variant_img || o.product_img || '';
            const thumbTag = thumb ? `<img src="../${escHtml(thumb)}" class="ref-thumb" style="width:24px;height:24px;margin-top:0;" onclick="viewPhoto('../${escHtml(thumb)}')" title="View item image">` : '';
            return `<div class="variant-line" style="display:flex;align-items:center;gap:6px;margin-top:2px;">${thumbTag}<span>${escHtml(it.variant_name || 'Custom')}${it.size ? `<span class="meta"> &mdash; ${escHtml(it.size)}</span>` : ''}<span class="meta"> &times;${parseInt(it.quantity) || 1}</span>${parseFloat(it.price) > 0 ? `<span class="meta"> @ \u20b1${parseFloat(it.price).toFixed(2)}</span>` : ''}</span></div>`;
        }).join('');
    } else if (o.variant_name) {
        const thumb = o.variant_img || o.product_img || '';
        const thumbTag = thumb ? `<img src="../${escHtml(thumb)}" class="ref-thumb" style="width:24px;height:24px;margin-top:0;" onclick="viewPhoto('../${escHtml(thumb)}')" title="View item image">` : '';
        variantHtml = `<div class="variant-line" style="display:flex;align-items:center;gap:6px;margin-top:2px;">${thumbTag}<span>${escHtml(o.variant_name)}${o.size ? `<span class="meta"> &mdash; ${escHtml(o.size)}</span>` : ''}</span></div>`;
    }
    const location = o.delivery_address || o.customer_address || '';
    const locationHtml = location ? escHtml(location).replace(/\n/g, '<br>') : '<span style="color:#cbd5e1">No location</span>';
    const refImgs = o.image ? o.image.split(',').map(s=>s.trim()).filter(Boolean) : [];
    const imgHtml = refImgs.length ? `<div style="display:flex;align-items:center;gap:4px;margin-top:4px;"><img src="../${escHtml(refImgs[0])}" class="ref-thumb" onclick="viewPhoto('../${escHtml(refImgs[0])}')" style="width:24px;height:24px;object-fit:cover;border-radius:5px;border:1.5px solid #e2e8f0;cursor:pointer;" title="Customer uploaded photo">${refImgs.length > 1 ? `<span class="ref-count" style="font-size:0.58rem;color:#94a3b8;">+${refImgs.length-1} more</span>` : ''}</div>` : '';
    const dateStr = new Date(o.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    const orderTotalValue = parseFloat(o.order_total ?? o.price) || 0;
    const priceHtml = orderTotalValue > 0 ? `\u20b1${orderTotalValue.toFixed(2)}` : '<span style="color:#cbd5e1">&mdash;</span>';
    const paidAmount = parseFloat(o.down_payment_amount) || 0;
    const orderTotal = orderTotalValue;
    const shippingFee = parseFloat(o.shipping_fee) || 0;
    const remainingDue = Math.max(0, orderTotal + shippingFee - paidAmount);
    const paymentModeHtml = o.payment_mode
        ? `<div class="pmt-ref">${escHtml(o.payment_mode || 'Not specified')}</div>`
        : '';
    const payHtml = paidAmount > 0
        ? `${paymentModeHtml}<div class="pmt-amount">Down: \u20b1${paidAmount.toFixed(2)}</div>${remainingDue > 0 ? `<div class="pmt-ref" style="color:#dc2626;">Remaining: \u20b1${remainingDue.toFixed(2)}</div>` : '<div class="pmt-ref" style="color:#16a34a;">Fully paid</div>'}${o.gcash_reference ? `<div class="pmt-ref">Ref: ${escHtml(o.gcash_reference)}</div>` : ''}`
        : orderTotal > 0
            ? `${paymentModeHtml}<div class="pmt-ref">Total: \u20b1${(orderTotal + shippingFee).toFixed(2)}</div>`
            : '<span style="color:#cbd5e1">&mdash;</span>';
    const isCodOrder = ['COD', 'CASH ON DELIVERY'].includes(String(o.delivery_method || '').toUpperCase()) || ['COD', 'CASH ON DELIVERY'].includes(String(o.payment_mode || '').toUpperCase());
    const codPaid = Number(o.payment_collected) === 1 || remainingDue <= 0;
    const collectionHtml = isCodOrder
        ? `<div class="pmt-ref" style="color:${codPaid ? '#16a34a' : '#dc2626'};">${codPaid ? 'COD balance paid' : 'COD balance due'}</div>`
        : '';
    const releaseHtml = o.scheduled_release ? new Date(o.scheduled_release + 'T00:00:00').toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' }) : '<span style="color:#cbd5e1">&mdash;</span>';
    const notesHtml = o.order_notes ? `<span title="${escHtml(o.order_notes)}" class="notes-chip">\ud83d\udcdd ${escHtml(o.order_notes.substring(0,20))}...</span>` : '';
    const driverOptionsHtml = '<option value="0">Unassigned</option>' + ADMIN_DRIVERS.filter(d => d.status === 'active' || Number(d.id) === Number(o.driver_id)).map(d => `<option value="${d.id}" ${Number(d.id) === Number(o.driver_id) ? 'selected' : ''}>${escHtml(d.name)}${d.status !== 'active' ? ' (Inactive)' : ''}</option>`).join('');
    let driverBadgeHtml = '';
    if (Number(o.driver_id) > 0) {
        const ds = o.delivery_status || 'assigned';
        const dsMap = { 'assigned': '⏳ Assigned', 'out_for_delivery': '🚚 In Transit', 'delivered': '✅ Delivered', 'cancelled': '❌ Cancelled' };
        const dsCls = ds === 'out_for_delivery' ? 'transit' : ds;
        driverBadgeHtml = `<div><span class="driver-status-badge d-${dsCls}">${dsMap[ds] || '⏳ Assigned'}</span></div>`;
    }
    const isCod = ['COD', 'CASH ON DELIVERY'].includes(String(o.delivery_method || 'Pickup').toUpperCase()) || ['COD', 'CASH ON DELIVERY'].includes(String(o.payment_mode || '').toUpperCase());
    const canAdvance = ['In Cart', 'Processing'].includes(o.status);
    const advanceHtml = canAdvance && isCod ? `<button type="button" class="advance-delivery-btn" data-order-id="${o.id}" data-cod="1"${!Number(o.driver_id) ? ' disabled title="Assign a driver first"' : ''} onclick="advanceDelivery(this)">Out for Delivery</button>` : '';
    const pillMap = {'In Cart':'s-incart','Processing':'s-processing','Production':'s-production','Ready for Pickup':'s-ready','Completed':'s-completed','Cancelled':'s-cancelled'};
    const pillCls = pillMap[o.status] || 's-incart';
    const multiHtml = o.items_count > 1 ? `<span class="order-id-chip"><span class="multi-badge">${o.items_count} items</span></span>` : '';

    return `<tr>
        <td><span class="order-id-chip">ORD-${orderId}</span>${multiHtml}</td>
        <td><div class="customer-info"><div class="name">${escHtml(o.customer_name || 'Unknown')}</div><div class="email">${escHtml(o.customer_email || '')}</div></div></td>
        <td class="location-cell" title="${escHtml(location)}">${locationHtml}</td>
        <td><div class="product-cell"><div class="prod-name">${escHtml(o.product_name)}</div>${variantHtml}${imgHtml}</div></td>
        <td class="num-cell">${o.quantity}</td>
        <td class="price-cell">${priceHtml}</td>
        <td><div class="pmt-cell">${payHtml}${collectionHtml}</div></td>
        <td class="release-cell">${releaseHtml}</td>
        <td class="driver-cell">${!(['COD', 'CASH ON DELIVERY'].includes(String(o.delivery_method || 'Pickup').toUpperCase()) || ['COD', 'CASH ON DELIVERY'].includes(String(o.payment_mode || '').toUpperCase())) ? '<select class="driver-select pickup-driver" disabled title="Pickup orders do not require a driver"><option>Pickup</option></select>' : `<select class="driver-select" data-order-id="${o.id}" data-original="${o.driver_id || 0}" onchange="assignDriver(this)"${['Cancelled', 'Completed'].includes(o.status) ? ' disabled' : ''} title="Assign an active driver">${driverOptionsHtml}</select>${driverBadgeHtml}`}</td>
        <td><span class="status-pill ${pillCls}"><span class="status-dot"></span>${o.status === 'Production' ? 'Out for Delivery' : (statusLabels[o.status] || o.status)}</span></td>
        <td class="date-cell">${dateStr}</td>
        <td><div style="display:flex;flex-direction:column;gap:4px;"><button type="button" class="view-order-btn" onclick="viewOrder(${o.id})">View Details</button>${advanceHtml}
            <select class="action-select status-select${['Cancelled', 'Completed'].includes(o.status) ? ' locked-action' : ''}" data-order-id="${o.id}" data-original="${escHtml(o.status)}"${['Cancelled', 'Completed'].includes(o.status) ? ' disabled title="This order is locked"' : ''} onchange="updateStatus(this)">${optionsHtml}</select>
            ${notesHtml}
        </div></td>
    </tr>`;
}

function pollOrders() {
    if (_isUpdating || document.hidden) return;
    fetch('poll_api.php?action=orders&driver_id=' + encodeURIComponent('<?= $driverFilter ?>'))
        .then(r => r.json())
        .then(data => {
            const newOrders = data.orders;
            const newSnapshot = newOrders.map(o => ({ id: o.id, status: o.status }));

            
            if (_lastOrderData === null) {
                _lastOrderData = newSnapshot;
                return;
            }

            const newMap = newOrders.map(o => o.id + ':' + o.status).join(',');
            const oldMap = _lastOrderData.map(o => o.id + ':' + o.status).join(',');

            if (newMap !== oldMap) {
                
                const oldIds = new Set(_lastOrderData.map(o => o.id));
                const newIds = newOrders.filter(o => !oldIds.has(o.id));
                
                const oldStatusMap = {};
                _lastOrderData.forEach(o => oldStatusMap[o.id] = o.status);
                const changed = newOrders.filter(o => oldStatusMap[o.id] && oldStatusMap[o.id] !== o.status);

                
                const tbody = document.querySelector('.admin-table tbody');
                if (newOrders.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="11" class="text-center text-gray-400 py-8">No orders found</td></tr>';
                } else {
                    tbody.innerHTML = newOrders.map(buildOrderRow).join('');
                }

                
                const countEl = document.querySelector('.orders-count');
                if (countEl) countEl.textContent = data.count + ' total orders';

                
                if (newIds.length > 0) {
                    showToast('New Order', newIds.length + ' new order' + (newIds.length > 1 ? 's' : '') + ' received!', 'info');
                }
                if (changed.length > 0) {
                    changed.forEach(o => {
                        showToast('Order Updated', 'ORD-' + String(o.id).padStart(3,'0') + ' is now ' + o.status, 'info');
                    });
                }

                _lastOrderData = newSnapshot;
            }
        })
        .catch(() => {});
}

setInterval(pollOrders, 15000);
</script>
</body>
</html>
