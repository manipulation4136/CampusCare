<?php
require_once __DIR__ . '/../../../config/init.php';
ensure_role('admin');

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $register_number = trim($_POST['register_number'] ?? '');
    $role = $_POST['role'] ?? 'student';
    $password = $_POST['password'] ?? '';

    if ($name && $email && $password && $register_number) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        // Admin created users are auto-verified (is_verified=1)
        $stmt = $conn->prepare("INSERT INTO users(name, email, password, role, is_verified, register_number) VALUES (?, ?, ?, ?, 1, ?)");
        $stmt->bind_param("sssss", $name, $email, $hash, $role, $register_number);
        try {
            $stmt->execute();
            set_flash('ok', ucfirst($role) . ' created successfully');
            
            // Redirect smoothly to the distinct sub-dashboard based on their generated role
            $redirectTarget = ($role === 'faculty') ? 'faculty.php' : (($role === 'admin') ? 'users.php' : 'students.php');
            header('Location: ' . BASE_URL . 'views/admin/' . $redirectTarget);
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
    <!-- Back Button dynamic context handling -->
    <div style="margin-bottom: 24px;">
        <a href="<?= BASE_URL ?>views/admin/<?= isset($_GET['role']) && $_GET['role'] == 'faculty' ? 'faculty.php' : 'students.php' ?>" class="btn outline small">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <!-- Form Card -->
    <div class="card" style="max-width: 800px; margin: 0 auto;">
        <h2 class="card-title">Add New User</h2><br>
        
        <?php if ($m = flash('ok')): ?><div class="alert success"><?= htmlspecialchars($m) ?></div><?php endif; ?>
        <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>
        
        <form method="post" class="grid cols-2">
            <?= get_csrf_input() ?>
            
            <div class="col-span-full">
                <label>Name</label>
                <input class="input" name="name" required placeholder="Full Name">
            </div>
           
             <div class="col-span-full">
                <label>Register No / Identifier</label>
                <input class="input" name="register_number" required placeholder="Reg No">
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
