<?php
require_once __DIR__ . '/../../config/init.php';
ensure_role(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verify_csrf()) die('CSRF validation failed');
    $del_id = (int)$_POST['delete_id'];
    
    $stmt = $conn->prepare("DELETE FROM workers WHERE id = ?");
    $stmt->bind_param("i", $del_id);
    if ($stmt->execute()) set_flash('ok', 'Worker deleted.');
    else set_flash('err', 'Failed to delete worker.');
    
    header('Location: ' . BASE_URL . 'views/admin/workers.php');
    exit;
}

$workers = $conn->query("
    SELECT w.*, c.name as category_name 
    FROM workers w 
    LEFT JOIN categories c ON c.id = w.category_id 
    ORDER BY c.name, w.name
");
include __DIR__ . '/../partials/header.php';
?>
<div class="main-content">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2 style="margin: 0; color: #fff; font-size: 24px;">Manage Maintenance Workers</h2>
        <a href="<?= BASE_URL ?>views/admin/forms/add_worker.php" class="btn icon-btn-fab" style="width: auto; padding: 0 16px; border-radius: 20px;">
            <i class="fas fa-plus" style="margin-right: 8px;"></i> Add Worker
        </a>
    </div>

    <?php if ($m = flash('ok')): ?><div class="alert success"><?= htmlspecialchars($m) ?></div><?php endif; ?>
    <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>

    <div class="table-card animate-card-entry">
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Worker Name</th>
                        <th>Role / Category</th>
                        <th>Contact / Phone</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($workers->num_rows > 0): ?>
                        <?php while ($row = $workers->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
                                <td><span class="badge" style="background: rgba(52, 152, 219, 0.2); color: #3498db; border: 1px solid #3498db;"><?= htmlspecialchars($row['category_name']) ?></span></td>
                                <td><?= htmlspecialchars($row['contact']) ?></td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?= BASE_URL ?>views/admin/forms/edit_worker.php?id=<?= $row['id'] ?? $row['worker_id'] ?>" class="btn icon-btn" style="color: #f39c12;" title="Edit Worker">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="POST" style="margin: 0;" onsubmit="return confirm('Delete this worker?');">
                                            <?= get_csrf_input() ?>
                                            <input type="hidden" name="delete_id" value="<?= $row['id'] ?? $row['worker_id'] ?>">
                                            <button type="submit" class="btn icon-btn" style="color: #e74c3c; background: none; border: none;" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="text-align: center;">No workers found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>