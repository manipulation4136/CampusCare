<?php
// includes/logout.php
// Professional Logout: Clears Session & LocalStorage, then redirects to Login

// 1. Start Session to access it
require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Clear PHP Session Variables
$_SESSION = array();

// 3. Destroy Session Cookies
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. Destroy PHP Session
session_destroy();

// 5. Client-Side Cleanup & Redirect (The Professional Part)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out...</title>
    <style>
        body {
            margin: 0;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .loader-container {
            text-align: center;
            animation: fadeOut 0.5s ease-in-out 0.8s forwards; /* Smooth exit */
        }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px auto;
        }
        p {
            color: #6c757d;
            font-size: 16px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        @keyframes fadeOut {
            to { opacity: 0; }
        }
    </style>
</head>
<body>
    <div class="loader-container">
        <div class="spinner"></div>
        <p>Logging out...</p>
    </div>

    <script>
        // 1. Clear the "Offline Access" Keys
        localStorage.removeItem("isLoggedIn");
        localStorage.removeItem("userRole");

        // 2. Redirect to Login Page (index.php)
        // Small delay ensures the storage is cleared before redirecting
        setTimeout(() => {
            window.location.href = "<?php echo BASE_URL; ?>index.php"; 
        }, 800); 
    </script>
</body>
</html>
