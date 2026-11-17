<?php

if (!isset($_SESSION['driver_id']) || ($_SESSION['type'] ?? '') !== 'driver') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Your driver session has expired. Please sign in again.']);
        exit;
    }
    header('Location: login.php');
    exit;
}
