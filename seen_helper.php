<?php

function markSectionSeen($conn, $userType, $userId, $section) {
    $stmt = $conn->prepare("INSERT INTO seen_tracking (user_type, user_id, section, last_seen_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE last_seen_at = NOW()");
    if ($stmt) {
        $stmt->bind_param('sis', $userType, $userId, $section);
        $stmt->execute();
        $stmt->close();
    }
}

function getLastSeen($conn, $userType, $userId, $section) {
    $stmt = $conn->prepare("SELECT last_seen_at FROM seen_tracking WHERE user_type = ? AND user_id = ? AND section = ? ORDER BY last_seen_at DESC LIMIT 1");
    $stmt->bind_param('sis', $userType, $userId, $section);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? $row['last_seen_at'] : '2000-01-01 00:00:00';
}

function getAdminBadgeCounts($conn, $adminId = 0) {
    $ordersLastSeen   = getLastSeen($conn, 'admin', $adminId, 'orders');
    $productsLastSeen = getLastSeen($conn, 'admin', $adminId, 'products');
    $customersLastSeen= getLastSeen($conn, 'admin', $adminId, 'customers');
    $messagesLastSeen = getLastSeen($conn, 'admin', $adminId, 'messages');

    
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT COALESCE(NULLIF(batch_id, ''), CONCAT('solo_', id))) as c FROM customer_orders WHERE created_at > ? AND status <> 'Cancelled'");
    $stmt->bind_param('s', $ordersLastSeen);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM product WHERE created_at > ?");
    $stmt->bind_param('s', $productsLastSeen);
    $stmt->execute();
    $products = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM customer WHERE created_at > ?");
    $stmt->bind_param('s', $customersLastSeen);
    $stmt->execute();
    $customers = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM customer_messages WHERE sender = 'customer' AND sent_at > ?");
    $stmt->bind_param('s', $messagesLastSeen);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    return [
        'orders'    => (int)$orders,
        'products'  => (int)$products,
        'customers' => (int)$customers,
        'messages'  => (int)$messages
    ];
}

function getCustomerBadgeCounts($conn, $customerId) {
    $productsLastSeen = getLastSeen($conn, 'customer', $customerId, 'products');
    $ordersLastSeen   = getLastSeen($conn, 'customer', $customerId, 'orders');
    $messagesLastSeen = getLastSeen($conn, 'customer', $customerId, 'messages');

    
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM product WHERE is_active = 1 AND created_at > ?");
    $stmt->bind_param('s', $productsLastSeen);
    $stmt->execute();
    $products = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM customer_orders WHERE customer_id = ? AND updated_at > ?");
    $stmt->bind_param('is', $customerId, $ordersLastSeen);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM customer_messages WHERE customer_id = ? AND sender = 'admin' AND sent_at > ?");
    $stmt->bind_param('is', $customerId, $messagesLastSeen);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    return [
        'products' => (int)$products,
        'orders'   => (int)$orders,
        'messages' => (int)$messages
    ];
}
