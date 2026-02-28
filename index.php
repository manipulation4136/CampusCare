<?php
// index.php
require_once __DIR__ . '/config/init.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CampusCare</title>
    
    <!-- PWA & Icons (Absolute Paths) -->
    <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>img/logo.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= BASE_URL ?>img/logo.png">
    <link rel="manifest" href="<?= BASE_URL ?>manifest.json">
    
    <style>
        /* CRITICAL: Critical CSS for Instant Loading Screen */
        body, html { margin: 0; padding: 0; height: 100%; background: #0b1020; font-family: sans-serif; }
        
        #app-loader {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(180deg, #0b1020, #101631);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            z-index: 99999; transition: opacity 0.5s ease;
        }
        
        .loader-spinner {
            width: 50px; height: 50px;
            border: 3px solid rgba(110, 168, 254, 0.1);
            border-top: 3px solid #6ea8fe;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }
        
        .loader-text { color: #6ea8fe; font-size: 14px; letter-spacing: 1px; font-weight: 600; }
        
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        /* Utility to hide loader */
        .loader-hidden { opacity: 0; pointer-events: none; }
    </style>
</head>
<body>

    <div id="app-loader">
        <div class="loader-spinner"></div>
        <div class="loader-text">CAMPUSCARE</div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const role = localStorage.getItem('userRole');
        const isLoggedIn = localStorage.getItem('isLoggedIn');
        const isOnline = navigator.onLine;

        // Helper to remove loader if we stay on this page (Login)
        function hideLoader() {
            setTimeout(() => {
                const loader = document.getElementById('app-loader');
                if(loader) loader.classList.add('loader-hidden');
            }, 500); // Small buffer for smoothness
        }

        // --- OFFLINE LOGIC ---
        // --- OFFLINE LOGIC ---
        const isLocalhost = window.location.hostname.includes('localhost') || window.location.hostname.includes('127.0.0.1');

        // Clean up SW on localhost
        if (isLocalhost) {
            navigator.serviceWorker.getRegistrations().then(function(registrations) {
                for(let registration of registrations) {
                    registration.unregister();
                    console.log("Service Worker Unregistered on Localhost");
                }
            });
        }

        if (!isOnline && !isLocalhost) {
            if (isLoggedIn === 'true' && role === 'student') {
                window.location.replace('views/student/dashboard.php');
            } else {
                window.location.replace('offline.php');
            }
        } 
        // --- ONLINE LOGIC (or Localhost) ---
        else {
            if (isLoggedIn === 'true') {
                // Redirecting... Keep loader visible
                if (role === 'admin') window.location.replace('views/admin/dashboard.php');
                else if (role === 'faculty') window.location.replace('views/faculty/dashboard.php');
                else if (role === 'student') window.location.replace('views/student/dashboard.php');
                else hideLoader(); // Fallback if role is unknown
            } else {
                // Not logged in? We need to show the Login Page.
                // HIDE THE LOADER so the user can see the form.
                hideLoader();
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
