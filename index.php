<?php
// index.php
require_once __DIR__ . '/config/init.php';
?>
<script>
// CRITICAL: IMMEDIATE OFFLINE REDIRECT
// This runs BEFORE the login page HTML is rendered.
if (!navigator.onLine) {
    const role = localStorage.getItem('userRole');
    const isLoggedIn = localStorage.getItem('isLoggedIn');
    
    // If student is logged in, STOP everything and jump to dashboard
    if (isLoggedIn === 'true' && role === 'student') {
        window.stop(); // Stop loading the login form
        window.location.replace('views/student/dashboard.php');
    }
}
</script>
<?php
// ... The rest of the Router Logic ...

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
    case 'index.php': 
        require __DIR__ . '/views/login.php';
        break;
        
    case 'register':
        require __DIR__ . '/views/register.php';
        break;
        
    case 'logout': 
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
