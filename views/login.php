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
</head>
<body>

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

            <div class="apk-banner animate-card-entry" style="animation-delay: 0.3s;">
            <div class="apk-icon">

    <img src="<?= BASE_URL ?>img/logo.png" style="width: 100%; height: 100%; object-fit: contain; border-radius: 50%;">

                </div>
                <div class="apk-text">
                    <strong>Get the CampusCare App</strong>
                    <span>Faster access & better experience</span>
                </div>
                <a href="/campuscare.apk" class="btn-apk-download" download>
                    <i class="fas fa-download"></i> Install
                </a>
            </div>
        </div>
    </div>

</body>
</html>





