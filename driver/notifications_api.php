<?php
session_start();
require_once '../db_conn.php';
require_once 'check_driver.php';
require_once '../notifications_helper.php';

$driverId = (int)$_SESSION['driver_id'];
$action = $_GET['action'] ?? '';

header('Content-Type: application/json');

if ($action === 'count') {
    echo json_encode(['count' => getUnreadNotifCount($conn, 'driver', $driverId)]);
    exit;
}

if ($action === 'list') {
    $notifications = getNotifications($conn, 'driver', $driverId, 15);
    echo json_encode($notifications);
    exit;
}

if ($action === 'mark_read') {
    markAllRead($conn, 'driver', $driverId);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);