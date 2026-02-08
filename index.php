<?php
// index.php
require_once __DIR__ . '/config/init.php';

$request = $_SERVER['REQUEST_URI'];
$base = BASE_URL;
$path = parse_url($request, PHP_URL_PATH);

// Remove base path from request path
$scriptName = $_SERVER['SCRIPT_NAME'];
$scriptDir = dirname($scriptName);
if ($scriptDir !== '/' && strpos($path, $scriptDir) === 0) {
    $path = substr($path, strlen($scriptDir));
}
$path = trim($path, '/');

// Simple routing
switch ($path) {
    case '':
    case 'login':
        require __DIR__ . '/views/login.php';
        break;
    case 'register':
        require __DIR__ . '/views/register.php';
        break;
    case 'dashboard':
        require __DIR__ . '/views/dashboard.php';
        break;
    case 'notifications':
        require __DIR__ . '/views/notifications.php'; // Will move this next
        break;
    case 'logout':
        require __DIR__ . '/includes/logout.php';
        break;
    default:
        // Check if it's a valid view file
        if (file_exists(__DIR__ . '/views/' . $path . '.php')) {
            require __DIR__ . '/views/' . $path . '.php';
        } else {
            http_response_code(404);
            echo "404 Not Found";
        }
        break;
}
?>

<script>
// Auto-Redirect Trick for Offline PWA Startup
// Auto-Redirect Trick for Offline PWA Startup
if (!navigator.onLine) {
    const isLoggedIn = localStorage.getItem('isLoggedIn');
    const role = localStorage.getItem('userRole');
    
    if (isLoggedIn === 'true') {
        // Redirect based on role
        if (role === 'student') {
            window.location.href = 'views/student/dashboard.php';
        } else if (role === 'faculty') {
            window.location.href = 'views/faculty/dashboard.php';
        } else {
            window.location.href = 'views/admin/dashboard.php';
        }
    }
}
</script>
