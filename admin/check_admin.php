<?php

if (!isset($_SESSION['admin_id'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Your admin session has expired. Please log in again.']);
        exit;
    }
    header('Location: ../admin_login.php');
    exit;
}
?>
