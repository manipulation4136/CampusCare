<?php
require_once __DIR__ . '/../../../config/init.php';
ensure_role('admin');

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'student';
    $password = $_POST['password'] ?? '';

    if ($name && $email && $password) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        // Admin created users are auto-verified (is_verified=1)
        $stmt = $conn->prepare("INSERT INTO users(name, email, password, role, is_verified) VALUES (?, ?, ?, ?, 1)");
        $stmt->bind_param("ssss", $name, $email, $hash, $role);
        try {
            $stmt->execute();
            set_flash('ok', 'User created successfully');
            header('Location: ' . BASE_URL . 'views/admin/users.php');
            exit;
        } catch (Exception $e) {
            set_flash('err', 'Error: ' . $e->getMessage());
        }
    } else {
        set_flash('err', 'All fields required');
    }
}

include __DIR__ . '/../../partials/header.php';
?>

<div class="main-content">
    <!-- Back Button -->
    <div style="margin-bottom: 24px;">
        <a href="<?= BASE_URL ?>views/admin/users.php" class="btn outline small">
            <i class="fas fa-arrow-left"></i> Back to Users
        </a>
    </div>

    <!-- Form Card -->
    <div class="card" style="max-width: 800px; margin: 0 auto;">
        <h2 class="card-title">Add New User</h2>
        
        <?php if ($m = flash('ok')): ?><div class="alert success"><?= htmlspecialchars($m) ?></div><?php endif; ?>
        <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>
        
        <form method="post" class="grid cols-2">
            <?= get_csrf_input() ?>
            
            <div class="col-span-full">
                <label>Name</label>
                <input class="input" name="name" required placeholder="Full Name">
            </div>
            
            <div class="col-span-full">
                <label>Email</label>
                <input class="input" type="email" name="email" required placeholder="user@example.com">
            </div>
            
            <div>
                <label>Password</label>
                <input class="input" type="text" name="password" required placeholder="Initial Password">
            </div>
            
            <div>
                <label>Role</label>
                <select class="input" name="role">
                    <option value="student">student</option>
                    <option value="faculty">faculty</option>
                    <option value="admin">admin</option>
                </select>
            </div>
            
            <div class="col-span-full" style="text-align: center; margin-top: 16px;">
                <button class="btn" style="min-width: 200px;">
                    <i class="fas fa-plus"></i> Add User
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
