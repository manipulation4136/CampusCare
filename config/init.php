<?php
// Session Configuration (1 Hour)
ini_set('session.gc_maxlifetime', 3600);
session_set_cookie_params(3600);

require_once __DIR__ . '/env_loader.php';
loadEnv(__DIR__ . '/../.env');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_utils.php';
require_once __DIR__ . '/../includes/csrf.php';

define('DIR', BASE_PATH);

// Safety Check: Redirect if session claims to be active but user is missing (Ghost Session)
// Only applies if we are NOT in login or register page
$current_script = basename($_SERVER['PHP_SELF']);
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['user']) && !in_array($current_script, ['login.php', 'register.php', 'index.php'])) {
    // Optional: Only redirect if it looks like a protected view
    if (strpos($_SERVER['REQUEST_URI'], '/views/') !== false) {
       header("Location: " . BASE_URL . "views/login.php?msg=Session+expired");
       exit;
    }
}
?>
