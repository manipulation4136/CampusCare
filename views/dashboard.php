<?php
// views/dashboard.php
require_once __DIR__ . '/../config/init.php';
ensure_logged_in();

$role = $_SESSION['user']['role'] ?? '';

switch ($role) {
    case 'admin':
        require __DIR__ . '/admin/dashboard.php';
        break;
    case 'faculty':
        require __DIR__ . '/faculty/dashboard.php';
        break;
    case 'student':
        require __DIR__ . '/student/dashboard.php';
        break;
    default:
        header('Location: ' . BASE_URL . 'login');
        exit;
}
?>
