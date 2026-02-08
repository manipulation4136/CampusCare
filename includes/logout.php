<?php
// includes/logout.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';

// Destroy Server Session
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
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
</head>
<body class="logout-body">
    <div class="logout-container">
        <div class="spinner"></div>
        <p class="logout-text">Logging out...</p>
    </div>
    <script>
        // Clear LocalStorage
        localStorage.removeItem("isLoggedIn");
        localStorage.removeItem("userRole");
        
        // Redirect using Absolute Path
        setTimeout(function() {
            window.location.href = "<?php echo BASE_URL; ?>index.php"; 
        }, 800);
    </script>
</body>
</html>
