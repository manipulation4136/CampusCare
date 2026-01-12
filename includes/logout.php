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

// 5. Redirect to Login (Assuming logic is in ../views/login.php)
// Use relative path since this file is in includes/
header("Location: ../views/login.php?msg=Logged+out+successfully");
exit;
?>
