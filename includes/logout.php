// includes/logout.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fallback if config is missing (Error Prevention)
if (!defined('BASE_URL')) {
    define('BASE_URL', '/'); // Default to root if undefined
} else {
    // If config file exists, require it
    if (file_exists(__DIR__ . '/../config/config.php')) {
        require_once __DIR__ . '/../config/config.php';
    }
}

// Destroy Server Session
$_SESSION = array();

// Clear Session Cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy Session
session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Meta Refresh Fallback (2 seconds) -->
    <meta http-equiv="refresh" content="2;url=<?php echo BASE_URL; ?>index.php">
    <title>Logging Out...</title>
    <style>
        body, html { margin: 0; padding: 0; height: 100%; background: #0b1020; font-family: sans-serif; display: flex; align-items: center; justify-content: center; flex-direction: column; }
        .spinner { width: 50px; height: 50px; border: 3px solid rgba(110, 168, 254, 0.1); border-top: 3px solid #6ea8fe; border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 20px; }
        .logout-text { color: #6ea8fe; font-size: 16px; letter-spacing: 1px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="spinner"></div>
    <p class="logout-text">Logging out...</p>

    <script>
        // Clear LocalStorage Client-Side
        localStorage.removeItem("isLoggedIn");
        localStorage.removeItem("userRole");
        localStorage.removeItem("userId"); // Added userId

        // Unregister Service Workers (Optional but good for clean state)
        if ('serviceWorker' in navigator) {
             navigator.serviceWorker.getRegistrations().then(function(registrations) {
                for(let registration of registrations) {
                    // registration.unregister(); // Optional: Keep SW for faster load next time
                }
             });
        }

        // JavaScript Redirect (Primary)
        setTimeout(function() {
            // Use replace() to prevent "Back" button
            window.location.replace("<?php echo BASE_URL; ?>index.php"); 
        }, 1500);
    </script>
</body>
</html>
