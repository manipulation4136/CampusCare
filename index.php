<?php
// index.php
require_once __DIR__ . '/config/init.php';
?>
<script>
// CRITICAL: CLIENT-SIDE ROUTER (Online & Offline)
// This handles redirects because the Cached `index.php` is always the Login Page.

document.addEventListener("DOMContentLoaded", function() {
    const role = localStorage.getItem('userRole');
    const isLoggedIn = localStorage.getItem('isLoggedIn');
    const isOnline = navigator.onLine;

    // 1. OFFLINE LOGIC
    if (!isOnline) {
        if (isLoggedIn === 'true' && role === 'student') {
            window.stop();
            window.location.replace('views/student/dashboard.php');
        } else {
            // Admin/Faculty/Guest -> Offline Page
            window.stop();
            window.location.replace('offline.html');
        }
    } 
    // 2. ONLINE LOGIC (Fix for Admin Dashboard)
    else {
        if (isLoggedIn === 'true') {
            // If user is already logged in, don't show the Login Form!
            // Redirect them to their respective dashboard.
            if (role === 'admin') {
                window.location.replace('views/admin/dashboard.php');
            } else if (role === 'faculty') {
                window.location.replace('views/faculty/dashboard.php');
            } else if (role === 'student') {
                window.location.replace('views/student/dashboard.php');
            }
        }
    }
});
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
