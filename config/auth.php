<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function ensure_logged_in() {
    if (!isset($_SESSION['user'])) {
        header('Location: ' . BASE_URL . 'login?msg=Please+login');
        exit;
    }
}

function ensure_role($roles) {
    ensure_logged_in();
    if (is_string($roles)) $roles = [$roles];
    if (!in_array($_SESSION['user']['role'], $roles, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

function redirect_by_role() {
    $role = $_SESSION['user']['role'] ?? '';     
    if ($role === 'admin') {
        header('Location: ' . BASE_URL . 'views/admin/dashboard.php');
    } elseif ($role === 'faculty') {
        header('Location: ' . BASE_URL . 'views/faculty/dashboard.php');
    } else {
        header('Location: ' . BASE_URL . 'views/student/dashboard.php');
    }
    exit;
}

function flash($key) {
    if (!empty($_SESSION['flash'][$key])) {
        $m = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $m;
    }
    return '';
}

function set_flash($key, $val) {
    $_SESSION['flash'][$key] = $val;
}

function send_telegram_alert($chat_id, $message) {
    $bot_token = env('TELEGRAM_BOT_TOKEN'); 
    $url = "https://api.telegram.org/bot$bot_token/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Fix for some local setups, usually safe for this API
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        error_log("Telegram cURL Error: " . curl_error($ch), 3, __DIR__ . '/../telegram_debug.log');
    } else {
        error_log("Telegram Response (Code $http_code): " . $response . PHP_EOL, 3, __DIR__ . '/../telegram_debug.log');
    }
    
    curl_close($ch);
    return $response;
}

function notify_user(mysqli $conn, int $user_id, string $message) {
    // 1. Insert into DB
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?,?)");
    $stmt->bind_param("is", $user_id, $message);
    $stmt->execute();

    // 2. Check for Telegram Chat ID
    $stmt_user = $conn->prepare("SELECT telegram_chat_id FROM users WHERE id = ?");
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $res = $stmt_user->get_result();
    if ($row = $res->fetch_assoc()) {
        if (!empty($row['telegram_chat_id'])) {
            send_telegram_alert($row['telegram_chat_id'], $message);
        }
    }
}

function purgeOldNotifications(mysqli $conn) {
    try {
        $stmt = $conn->prepare("DELETE FROM notifications WHERE created_at < (NOW() - INTERVAL 30 DAY)");
        $stmt->execute();
    } catch (Exception $e) {
        error_log("Failed to purge old notifications: " . $e->getMessage());
    }
}
?>
