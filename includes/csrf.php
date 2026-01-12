<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

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
  if (!isset($_SESSION['csrf_token'])) return false;
  if (!isset($_POST['csrf_token'])) return false;
  return hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}


