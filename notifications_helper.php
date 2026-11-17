<?php

function createNotification($conn, $userType, $userId, $type, $title, $message = '', $link = '') {
    $stmt = $conn->prepare("INSERT INTO notifications (user_type, user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('sissss', $userType, $userId, $type, $title, $message, $link);
    $stmt->execute();
}

function getUnreadNotifCount($conn, $userType, $userId = 0) {
    if ($userType === 'admin') {
        $res = $conn->query("SELECT COUNT(*) as c FROM notifications WHERE user_type = 'admin' AND is_read = 0");
    } else {
        $safeUserType = $conn->real_escape_string($userType);
        $res = $conn->query("SELECT COUNT(*) as c FROM notifications WHERE user_type = '$safeUserType' AND user_id = $userId AND is_read = 0");
    }
    return $res ? ($res->fetch_assoc()['c'] ?? 0) : 0;
}

function getNotifications($conn, $userType, $userId = 0, $limit = 20) {
    if ($userType === 'admin') {
        $res = $conn->query("SELECT * FROM notifications WHERE user_type = 'admin' ORDER BY created_at DESC LIMIT $limit");
    } else {
        $res = $conn->query("SELECT * FROM notifications WHERE user_type = '" . $conn->real_escape_string($userType) . "' AND user_id = $userId ORDER BY created_at DESC LIMIT $limit");
    }
    $notifs = [];
    while ($r = $res->fetch_assoc()) $notifs[] = $r;
    return $notifs;
}

function markAllRead($conn, $userType, $userId = 0) {
    if ($userType === 'admin') {
        $conn->query("UPDATE notifications SET is_read = 1 WHERE user_type = 'admin' AND is_read = 0");
    } else {
        $conn->query("UPDATE notifications SET is_read = 1 WHERE user_type = '" . $conn->real_escape_string($userType) . "' AND user_id = $userId AND is_read = 0");
    }
}
