<?php
require_once __DIR__ . '/../config/init.php';
ensure_logged_in();

$user_id = $_SESSION['user']['id'];
$success_msg = '';
$error_msg = '';

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!verify_csrf()) {
        $error_msg = 'CSRF validation failed.';
    } else {
        $current = $_POST['current_password'];
        $new = $_POST['new_password'];
        $confirm = $_POST['confirm_password'];

        if (empty($current) || empty($new) || empty($confirm)) {
            $error_msg = "All password fields are required.";
        } elseif ($new !== $confirm) {
            $error_msg = "New passwords do not match.";
        } elseif (strlen($new) < 6) {
            $error_msg = "Password must be at least 6 characters long.";
        } else {
            // Verify Current Password
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            
            if ($row = $res->fetch_assoc()) {
                if (password_verify($current, $row['password'])) {
                    // Update Password
                    $hash = password_hash($new, PASSWORD_DEFAULT);
                    $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $update->bind_param("si", $hash, $user_id);
                    if ($update->execute()) {
                        $success_msg = "Password updated successfully!";
                    } else {
                        $error_msg = "Database error updating password.";
                    }
                } else {
                    $error_msg = "Current password is incorrect.";
                }
            } else {
                $error_msg = "User not found.";
            }
        }
    }
}

// Fetch Latest User Data
$stmt = $conn->prepare("SELECT name, email, role, telegram_chat_id FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();

// Update Session if name changed (optional, but good practice)
if ($user_data) {
    $_SESSION['user']['name'] = $user_data['name'];
    $_SESSION['user']['role'] = $user_data['role'];
}

include __DIR__ . '/partials/header.php';
?>

<div class="container" style="max-width: 900px;">
    <h2 class="page-title" style="margin-bottom: 24px;">Account Settings</h2>

    <?php if ($success_msg): ?>
        <div class="alert success"><?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert error"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <div class="profile-grid">
        <!-- Left: User Info Card -->
        <div class="card profile-card">
            <div class="profile-header">
                <div class="profile-avatar-large <?= getRoleClass($user_data['role']) ?>">
                    <?= strtoupper(substr($user_data['role'], 0, 1)) ?>
                </div>
                <h3 class="profile-name"><?= htmlspecialchars($user_data['name']) ?></h3>
                <span class="role-badge"><?= htmlspecialchars($user_data['role']) ?></span>
            </div>
            
            <div class="profile-details">
                <div class="detail-item">
                    <label>Email Address</label>
                    <div class="value"><?= htmlspecialchars($user_data['email']) ?></div>
                </div>
                
                <div class="detail-item">
                    <label>Telegram Alerts</label>
                    <?php if (!empty($user_data['telegram_chat_id'])): ?>
                        <div class="status-active">
                            ✅ Active
                            <a href="<?= BASE_URL ?>views/telegram_setup.php" class="btn small outline" style="margin-left:auto;">Manage</a>
                        </div>
                    <?php else: ?>
                        <div class="status-inactive">
                            ❌ Not Connected
                            <a href="<?= BASE_URL ?>views/telegram_setup.php" class="btn small" style="margin-left:auto;">Connect</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Change Password Form -->
        <div class="card">
            <h3 class="card-title">Security</h3>
            <form method="POST" action="">
                <?= get_csrf_input() ?>
                <input type="hidden" name="change_password" value="1">
                
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" class="form-control" required minlength="6">
                </div>

                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="6">
                </div>

                <div class="actions" style="margin-top: 20px; text-align: right;">
                    <button type="submit" class="btn">Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
