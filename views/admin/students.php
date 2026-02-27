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
        
        set_flash('ok', 'Student approved successfully');
    }
    // Delete User
    elseif (isset($_POST['delete_id'])) {
        $del_id = (int)$_POST['delete_id'];
        
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $del_id);
        if ($stmt->execute()) {
            set_flash('ok', 'Student deleted successfully');
        } else {
            set_flash('err', 'Failed to delete student');
        }
    }
    // Update User (Edit Modal Action)
    elseif (isset($_POST['update_user_id'])) {
        $upd_id = (int)$_POST['update_user_id'];
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $reg_no = trim($_POST['register_number'] ?? '');

        if ($name && $email) {
            $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, register_number = ? WHERE id = ?");
            $stmt->bind_param("sssi", $name, $email, $reg_no, $upd_id);
            if ($stmt->execute()) {
                set_flash('ok', 'Student updated successfully');
            } else {
                set_flash('err', 'Failed to update student settings');
            }
        } else {
            set_flash('err', 'Name and Email are required parameters');
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

$whereSQL = "WHERE role = 'student'";
$params = [];
$types = "";

if ($search) {
    $searchTerm = "%$search%";
    $whereSQL .= " AND (name LIKE ? OR email LIKE ? OR register_number LIKE ?)";
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
        <h2 style="margin: 0; color: #fff; font-size: 24px;">Student Management</h2>
        <div style="display: flex; gap: 12px; align-items: center;">
            <a href="<?= BASE_URL ?>views/admin/forms/add_user.php?role=student" class="icon-btn-fab" title="Add New Student">
                <i class="fas fa-plus"></i>
            </a>
        </div>
    </div>

    <?php if ($m = flash('ok')): ?><div class="alert success"><?= htmlspecialchars($m) ?></div><?php endif; ?>
    <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>

    <div class="table-card">
        <!-- Search Bar (Responsive) -->
        <div class="table-header-row">
            <h3 class="card-title">All Students (<?= $totalUsers ?>)</h3>
            <form method="get" class="search-form">
                <input class="input search-input" name="search" placeholder="Search students..." value="<?= htmlspecialchars($search) ?>">
                <?php if ($search): ?>
                    <a href="students.php" class="btn outline small">Clear</a>
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
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
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
                                <td><?= htmlspecialchars(date('M d, Y', strtotime($u['created_at']))) ?></td>
                                <td>
                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <!-- Edit Action (Triggers JS Modal) -->
                                        <button type="button" class="btn icon-btn" style="color: #6ea8fe; border: none; background: none; padding: 5px; cursor: pointer;" title="Edit Student"
                                            data-id="<?= $u['id'] ?>"
                                            data-name="<?= htmlspecialchars($u['name']) ?>"
                                            data-email="<?= htmlspecialchars($u['email']) ?>"
                                            data-reg="<?= htmlspecialchars($u['register_number'] ?? '') ?>"
                                            onclick="openEditModal(this)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <!-- Delete Action -->
                                        <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this student? This action cannot be undone.');">
                                            <?= get_csrf_input() ?>
                                            <input type="hidden" name="delete_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn icon-btn" style="color: #e74c3c; background: none; border: none; padding: 5px;" title="Delete Student">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: #8fa0c9;">
                                <?= $search ? 'No students found matching your search.' : 'No students found.' ?>
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

<!-- Edit User Modal (Glassmorphism Overlay) -->
<div id="editUserModal" class="modal" style="display:none; justify-content: center; align-items: center; z-index: 9999;">
    <div class="glass-card modal-content" style="max-width: 450px; width: 100%; border: 1px solid #2a3558;">
        <h3 style="color: #fff; margin-bottom: 20px;"><i class="fas fa-user-edit"></i> Edit Student Settings</h3>
        
        <form id="editUserForm" method="POST">
            <?= get_csrf_input() ?>
            <input type="hidden" name="update_user_id" id="edit_user_id">
            
            <div class="input-group" style="margin-bottom: 15px;">
                <input type="text" name="name" id="edit_name" class="input-dark" placeholder="Full Name" required>
                <i class="fas fa-user"></i>
            </div>
            
            <div class="input-group" style="margin-bottom: 15px;">
                <input type="email" name="email" id="edit_email" class="input-dark" placeholder="Email Address" required>
                <i class="fas fa-envelope"></i>
            </div>
            
            <div class="input-group" style="margin-bottom: 25px;">
                <input type="text" name="register_number" id="edit_reg" class="input-dark" placeholder="Register No / Identifier">
                <i class="fas fa-id-badge"></i>
            </div>
            
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn-login" style="flex: 1;"><i class="fas fa-save"></i> Save Changes</button>
                <button type="button" onclick="closeEditModal()" class="btn-login outline" style="flex: 1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(button) {
    // Extract row data from DOM attributes
    const id = button.getAttribute('data-id');
    const name = button.getAttribute('data-name');
    const email = button.getAttribute('data-email');
    const reg = button.getAttribute('data-reg');

    // Bind DOM values into explicitly targeted Modal scope fields
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_email').value = email;
    document.getElementById('edit_reg').value = reg;

    // Launch UI Modal scope rendering outside standard DOM body locks
    const modal = document.getElementById('editUserModal');
    if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }
    
    modal.style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editUserModal').style.display = 'none';
}
</script>

<?php include __DIR__.'/../partials/footer.php'; ?>
