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
    case 'index.php': // Fix: Handle explicit index.php redirect
        require __DIR__ . '/views/login.php';
        break;
        
    case 'register':
        require __DIR__ . '/views/register.php';
        break;
        
    case 'logout': // Fix: Handle logout route specifically
        require __DIR__ . '/includes/logout.php';
        break;
        
    case 'dashboard':
        require __DIR__ . '/views/dashboard.php';
        break;
        
    case 'notifications':
        require __DIR__ . '/views/notifications.php';
        break;
        
    default:
        // Check if it's a valid view file
        if (file_exists(__DIR__ . '/views/' . $path . '.php')) {
            require __DIR__ . '/views/' . $path . '.php';
        } else {
            http_response_code(404);
            echo "404 Not Found (" . htmlspecialchars($path) . ")";
        }
        break;
}
?>

<script>
    // Force Redirect if Offline and Student
    if (!navigator.onLine) {
        var role = localStorage.getItem('userRole');
        var isLoggedIn = localStorage.getItem('isLoggedIn');
        
        if (isLoggedIn === 'true' && role === 'student') {
            // Redirect to Dashboard
            window.location.replace('views/student/dashboard.php');
        }
    }
</script>
