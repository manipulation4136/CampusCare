<?php
// views/register.php
require_once __DIR__ . '/../includes/auth_logic.php';

// If already logged in, redirect
if (isset($_SESSION['user'])) {
    redirect_by_role();
}

$error = handle_register($conn);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Register | CampusCare</title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>favicon.png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css?v=<?= time() ?>">
</head>
<body>

    <div class="ambient-glow"></div>

    <div class="login-wrapper">
        <div class="glass-card" style="max-width: 500px;">
            <div class="login-header">
                <h1>Create Account</h1>
                <p>Join CampusCare</p>
            </div>

            <?php if ($error): ?>
                <div class="alert error" style="margin-bottom: 20px;">
                    <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <form method="post">
                <?= get_csrf_input() ?>
                
                <div class="input-group">
                    <input class="input-dark" type="text" name="name" placeholder="Full Name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                    <i class="fa-solid fa-user"></i>
                </div>

                <div class="input-group">
                    <input class="input-dark" type="text" name="register_number" placeholder="Enter ID/Register No" value="<?= htmlspecialchars($_POST['register_number'] ?? '') ?>" required>
                    <i class="fa-solid fa-id-card"></i>
                </div>

                <div class="input-group">
                    <input class="input-dark" type="email" name="email" placeholder="Email Address" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                    <i class="fa-solid fa-envelope"></i>
                </div>

                <div class="input-group">
                    <select class="input-dark" name="role" required style="appearance: none; -webkit-appearance: none; cursor: pointer;">
                        <option value="" disabled selected>Select Role</option>
                        <option value="student" <?= (isset($_POST['role']) && $_POST['role'] === 'student') ? 'selected' : '' ?>>Student</option>
                        <option value="faculty" <?= (isset($_POST['role']) && $_POST['role'] === 'faculty') ? 'selected' : '' ?>>Faculty</option>
                    </select>
                    <i class="fa-solid fa-users"></i>
                    <!-- Custom Arrow Icon if needed, but keeping it simple first -->
                    <i class="fa-solid fa-chevron-down" style="left: auto; right: 16px;"></i>
                </div>

                <div class="input-group">
                    <input class="input-dark" type="password" name="password" placeholder="Password" required>
                    <i class="fa-solid fa-lock"></i>
                </div>

                <div class="input-group">
                    <input class="input-dark" type="password" name="confirm_password" placeholder="Confirm Password" required>
                    <i class="fa-solid fa-check-circle"></i>
                </div>

                <button type="submit" class="btn-login">Register</button>
                
                <div class="login-footer">
                    <a href="<?= BASE_URL ?>views/login.php">Already have an account? Login</a>
                </div>
            </form>
        </div>
    </div>

</body>
</html>
