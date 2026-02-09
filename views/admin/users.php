<?php
require_once __DIR__ . '/../../config/init.php';
ensure_role('admin');

// Handle POST Requests (Approve or Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');

    // Verify User
    if (isset($_POST['approve_user_id'])) {
        $uid = (int)$_POST['approve_user_id'];
        $conn->query("UPDATE users SET is_verified=1 WHERE id=$uid");
        
        // Notify the user
        $msg = "✅ Account Verified: Your account has been approved by the administrator. You can now access the dashboard.";
        notify_user($conn, $uid, $msg);
        
        set_flash('ok', 'User approved successfully');
    }
    // Delete User
    elseif (isset($_POST['delete_id'])) {
        $del_id = (int)$_POST['delete_id'];
        
        // Prevent deleting self (optional but good practice)
        if ($del_id == $_SESSION['user_id']) {
            set_flash('err', 'Cannot delete your own account.');
        } else {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $del_id);
            if ($stmt->execute()) {
                set_flash('ok', 'User deleted successfully');
            } else {
                set_flash('err', 'Failed to delete user');
            }
        }
    }
    
    // Refresh to clear post data
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Pagination & Search Logic
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');

$whereSQL = "";
$params = [];
$types = "";

if ($search) {
    $searchTerm = "%$search%";
    $whereSQL = "WHERE name LIKE ? OR email LIKE ? OR register_number LIKE ?";
    $params = [$searchTerm, $searchTerm, $searchTerm];
    $types = "sss";
}

// Count Total
$countQuery = "SELECT COUNT(*) as total FROM users $whereSQL";
$stmt = $conn->prepare($countQuery);
if ($search) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$totalUsers = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalUsers / $limit);

// Fetch Data
$usersQuery = "SELECT id,name,email,role,created_at,register_number,is_verified FROM users $whereSQL ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($usersQuery);
if ($search) {
    $stmt->bind_param($types . "ii", ...array_merge($params, [$limit, $offset]));
} else {
    $stmt->bind_param("ii", $limit, $offset);
}
$stmt->execute();
$users = $stmt->get_result();

include __DIR__.'/../partials/header.php';
?>

<div class="main-content">
    <!-- Professional Header with Icon Button -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2 style="margin: 0; color: #fff; font-size: 24px;">User Management</h2>
        <div style="display: flex; gap: 12px; align-items: center;">
            <a href="<?= BASE_URL ?>views/admin/forms/add_user.php" class="icon-btn-fab" title="Add New User">
                <i class="fas fa-plus"></i>
            </a>
        </div>
    </div>

    <?php if ($m = flash('ok')): ?><div class="alert success"><?= htmlspecialchars($m) ?></div><?php endif; ?>
    <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>

    <div class="table-card">
        <!-- Search Bar (Responsive) -->
        <div class="table-header-row">
            <h3 class="card-title">All Users (<?= $totalUsers ?>)</h3>
            <form method="get" class="search-form">
                <input class="input search-input" name="search" placeholder="Search users..." value="<?= htmlspecialchars($search) ?>">
                <?php if ($search): ?>
                    <a href="users.php" class="btn outline small">Clear</a>
                <?php endif; ?>
                <button class="btn small" type="submit">Search</button>
            </form>
        </div>

        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Register No</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($users->num_rows > 0): ?>
                        <?php while($u=$users->fetch_assoc()): ?>
                            <tr>
                                <td><?= (int)$u['id'] ?></td>
                                <td><?= htmlspecialchars($u['register_number'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($u['name']) ?></td>
                                <td><?= htmlspecialchars($u['email']) ?></td>
                                <td><span class="badge <?= strtolower($u['role']) ?>"><?= htmlspecialchars($u['role']) ?></span></td>
                                <td>
                                    <?php if ($u['is_verified']): ?>
                                        <span class="badge success">Verified</span>
                                    <?php else: ?>
                                        <form method="post" style="display:inline;">
                                            <?= get_csrf_input() ?>
                                            <input type="hidden" name="approve_user_id" value="<?= $u['id'] ?>">
                                            <button class="btn btn-sm">Approve</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($u['created_at']) ?></td>
                                <td>
                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                                        <?= get_csrf_input() ?>
                                        <input type="hidden" name="delete_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn icon-btn" style="color: #e74c3c; background: none; border: none; padding: 5px;" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: #8fa0c9;">
                                <?= $search ? 'No users found matching your search.' : 'No users found.' ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($totalPages > 1): ?>
        <div style="padding: 16px; border-top: 1px solid #1f2a44; display: flex; justify-content: space-between; align-items: center;">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>" class="btn outline small">Previous</a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>
            
            <span style="color: #8fa0c9; font-size: 14px;">Page <?= $page ?> of <?= $totalPages ?></span>
            
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>" class="btn outline small">Next</a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__.'/../partials/footer.php'; ?>
