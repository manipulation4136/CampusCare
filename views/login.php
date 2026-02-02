<?php
// views/login.php
require_once __DIR__ . '/../includes/auth_logic.php';

// If already logged in, redirect
if (isset($_SESSION['user'])) {
    redirect_by_role();
}

$error = handle_login($conn);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login | CampusCare</title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>favicon.png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css?v=<?= time() ?>">
  <link rel="manifest" href="<?= BASE_URL ?>manifest.json">
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => {
        navigator.serviceWorker.register('<?= BASE_URL ?>service-worker.js')
          .then(reg => console.log('SW Registered!', reg.scope))
          .catch(err => console.log('SW Failed:', err));
      });
    }
  </script>
</head>
  <style>
    /* Splash Screen Styles */
    #app-splash {
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: #0b1020;
        z-index: 999999;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        transition: opacity 0.5s ease-out, visibility 0.5s;
    }
    .splash-logo {
        width: 120px;
        height: 120px;
        border-radius: 50%;
        margin-bottom: 20px;
        animation: pulse 2s infinite;
        box-shadow: 0 0 30px rgba(110, 168, 254, 0.3);
    }
    .splash-text {
        font-size: 24px;
        font-weight: 800;
        color: #fff;
        opacity: 0;
        animation: slideUp 0.8s ease-out 0.3s forwards;
    }
    .splash-text span { color: #6ea8fe; }
    
    @keyframes pulse {
        0% { transform: scale(1); box-shadow: 0 0 30px rgba(110, 168, 254, 0.3); }
        50% { transform: scale(1.05); box-shadow: 0 0 50px rgba(110, 168, 254, 0.6); }
        100% { transform: scale(1); box-shadow: 0 0 30px rgba(110, 168, 254, 0.3); }
    }
    @keyframes slideUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
  </style>
</head>
<body>

    <!-- Native-like Splash Screen -->
    <div id="app-splash">
        <img src="<?= BASE_URL ?>img/logo.png" class="splash-logo" alt="Logo">
        <div class="splash-text">Campus<span>Care</span></div>
    </div>

    <script>
        // Splash Screen Logic
        window.addEventListener('load', () => {
            const splash = document.getElementById('app-splash');
            setTimeout(() => {
                splash.style.opacity = '0';
                splash.style.visibility = 'hidden';
            }, 1500); // 1.5s Minimum display time
        });
    </script>
    
    <div class="ambient-glow"></div>

    <div class="login-wrapper">
        <div class="glass-card">
            <div class="login-header">
                <h1>Welcome Back</h1>
                <p>Login to CampusCare</p>
            </div>

            <?php if ($error): ?>
                <div class="alert error" style="margin-bottom: 20px;">
                    <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($_GET['msg'])): ?>
                <div class="alert success" style="margin-bottom: 20px;">
                     <?= htmlspecialchars($_GET['msg']) ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <?= get_csrf_input() ?>
                
                <div class="input-group">
                    <input class="input-dark" type="text" name="login" placeholder="Email or Username" required>
                    <i class="fa-solid fa-user"></i>
                </div>

                <div class="input-group">
                    <input class="input-dark" type="password" name="password" placeholder="Password" required>
                    <i class="fa-solid fa-lock"></i>
                </div>

                <button type="submit" class="btn-login">Login</button>
                
                <div class="login-footer">
                    <a href="<?= BASE_URL ?>views/register.php">Don't have an account? Register</a>
                </div>
            </form>

            <div id="install-banner" class="apk-banner animate-card-entry" style="display: none; animation-delay: 0.3s;">
                <div class="apk-icon">
                    <img src="<?= BASE_URL ?>img/logo.png" style="width: 100%; height: 100%; object-fit: contain; border-radius: 50%;">
                </div>
                <div class="apk-text">
                    <strong>Install CampusCare</strong>
                    <span>Faster access & better experience</span>
                </div>
                <button id="install-btn" class="btn-apk-download" style="border:none; cursor:pointer;">
                    <i class="fas fa-download"></i> Install
                </button>
            </div>
        </div>
    </div>

<script>
    let deferredPrompt;
    const installBanner = document.getElementById('install-banner');
    const installBtn = document.getElementById('install-btn');

    window.addEventListener('beforeinstallprompt', (e) => {
        // Prevent Chrome 67 and earlier from automatically showing the prompt
        e.preventDefault();
        // Stash the event so it can be triggered later.
        deferredPrompt = e;
        // Update UI to notify the user they can add to home screen
        installBanner.style.display = 'flex';
    });

    installBtn.addEventListener('click', (e) => {
        // Hide our user interface that shows our A2HS button
        installBanner.style.display = 'none';
        // Show the prompt
        deferredPrompt.prompt();
        // Wait for the user to respond to the prompt
        deferredPrompt.userChoice.then((choiceResult) => {
            if (choiceResult.outcome === 'accepted') {
                console.log('User accepted the A2HS prompt');
            } else {
                console.log('User dismissed the A2HS prompt');
            }
            deferredPrompt = null;
        });
    });

    window.addEventListener('appinstalled', (evt) => {
        console.log('a2hs installed');
        installBanner.style.display = 'none';
    });
</script>

</body>
</html>





