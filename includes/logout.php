<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// Unset all variables
$_SESSION = array();
// Kill the cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
header("Location: ../views/login.php");
exit;
?>

