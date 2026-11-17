<?php

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0 || ($_SESSION['type'] ?? '') !== 'customer') {
    $isAjaxRequest = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || $_SERVER['REQUEST_METHOD'] === 'POST';

    if ($isAjaxRequest) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Your session has expired. Please log in again.']);
        exit;
    }

    header('Location: ../index.php');
    exit;
}

if (isset($conn) && isset($_SESSION['user_id'])) {
    $conn->query("UPDATE customer SET last_active = NOW() WHERE customer_id = " . (int)$_SESSION['user_id']);
}
?>
