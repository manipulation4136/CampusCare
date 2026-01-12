<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function generate_csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function regenerate_csrf_token(): void {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function get_csrf_input(): string {
  $t = htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8');
  return '<input type="hidden" name="csrf_token" value="'.$t.'">';
}

function verify_csrf(): bool {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
  
  // Graceful Failure: If token missing or mismatch
  if (!isset($_SESSION['csrf_token']) || !isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
      // Log it if needed: error_log("CSRF Mismatch: " . $_SERVER['REQUEST_URI']);
      
      // Destroy session to be safe
      session_unset();
      session_destroy();
      
      // Redirect to login
      $login_url = defined('BASE_URL') ? BASE_URL . 'views/login.php' : '../views/login.php';
      header("Location: $login_url?msg=Session+expired+or+invalid+request");
      exit;
  }
  return true;
}


