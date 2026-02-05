<?php
// includes/logout.php
// Fail-safe Logout: Clears everything regardless of state

// 1. Initialize Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Unset all session variables
$_SESSION = array();

// 3. Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. Destroy the session
session_destroy();

// 5. Client-Side Redirect
echo '<script>
        localStorage.removeItem("isLoggedIn");
        localStorage.removeItem("userRole");
        window.location.href = "../index.php"; 
      </script>';
exit;
?>
