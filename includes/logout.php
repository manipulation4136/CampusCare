<?php
// includes/logout.php

// 1. Initialize Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Load Config FIRST (Critical Fix)
$configPath = __DIR__ . '/../config/config.php';
if (file_exists($configPath)) {
    require_once $configPath;
}

// 3. Fallback for BASE_URL (Auto-detection)
if (!defined('BASE_URL')) {
    // If we are in /campuscare/includes/logout.php, this returns /campuscare/
    $projectDir = dirname(dirname($_SERVER['SCRIPT_NAME']));
    define('BASE_URL', rtrim($projectDir, '/') . '/');
}

// 4. Destroy Server Session
$_SESSION = array();
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out...</title>
    <meta http-equiv="refresh" content="2;url=<?php echo BASE_URL; ?>index.php">
    <style>
        body, html { margin: 0; padding: 0; height: 100%; background: #0b1020; font-family: sans-serif; display: flex; align-items: center; justify-content: center; flex-direction: column; }
        .spinner { width: 50px; height: 50px; border: 3px solid rgba(110, 168, 254, 0.1); border-top: 3px solid #6ea8fe; border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 20px; }
        .logout-text { color: #8fa0c9; font-size: 16px; letter-spacing: 1px; font-weight: 500; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="spinner"></div>
    <p class="logout-text">Logging out...</p>

    <script>
        // 1. Clear Client Data
        localStorage.removeItem("isLoggedIn");
        localStorage.removeItem("userRole");
        localStorage.removeItem("userId");

        // 2. Unregister Service Worker (Optional - keeps app fresh)
        if ('serviceWorker' in navigator) {
             navigator.serviceWorker.getRegistrations().then(function(registrations) {
                for(let registration of registrations) {
                    registration.unregister();
                }
             });
        }

        // 3. Redirect
        setTimeout(function() {
            window.location.replace("<?php echo BASE_URL; ?>index.php"); 
        }, 1500);
    </script>
</body>
</html>
