<?php
require_once __DIR__ . '/../config/init.php';

// Ensure user is logged in
if (!isset($_SESSION['user'])) {
    echo 0;
    exit;
}

// Validate CSRF
if (!verify_csrf()) {
    echo 0; 
    exit;
}

$user_id = (int)$_SESSION['user']['id'];

// Count unread notifications
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();

echo (int)$row['count'];
