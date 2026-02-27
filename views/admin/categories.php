<?php
require_once __DIR__ . '/../../config/init.php';
ensure_role(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verify_csrf()) die('CSRF validation failed');
    $del_id = (int)$_POST['delete_id'];
    
    // Check usage
    $check = $conn->prepare("SELECT COUNT(*) as c FROM assets WHERE category_id = ?");
    $check->bind_param("i", $del_id);
    $check->execute();
    $count = $check->get_result()->fetch_assoc()['c'];
    
    if ($count > 0) {
        set_flash('err', "Cannot delete: This category is used by $count assets.");
    } else {
        $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->bind_param("i", $del_id);
        if ($stmt->execute()) {
            set_flash('ok', 'Category deleted successfully.');
        } else {
            set_flash('err', 'Failed to delete category.');
        }
    }
    header('Location: ' . BASE_URL . 'views/admin/categories.php');
    exit;
}

$categories = $conn->query("
    SELECT c.*, COUNT(a.id) as asset_count 
    FROM categories c 
    LEFT JOIN assets a ON c.id = a.category_id 
    GROUP BY c.id 
    ORDER BY c.name
");

include __DIR__ . '/../partials/header.php';
?>
<div class="main-content">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2 style="margin: 0; color: #fff; font-size: 24px;">Manage Categories</h2>
        <a href="<?= BASE_URL ?>views/admin/forms/add_category.php" class="btn icon-btn-fab" style="width: auto; padding: 0 16px; border-radius: 20px;">
            <i class="fas fa-plus" style="margin-right: 8px;"></i> Add New
        </a>
    </div>

    <?php if ($m = flash('ok')): ?><div class="alert success"><?= htmlspecialchars($m) ?></div><?php endif; ?>
    <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>

    <div class="table-card animate-card-entry">
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Category Name</th>
                        <th>Total Assets</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($categories->num_rows > 0): ?>
                        <?php while ($cat = $categories->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($cat['name']) ?></strong></td>
                                <td><span class="badge <?= $cat['asset_count'] > 0 ? 'good' : 'na' ?>"><?= $cat['asset_count'] ?></span></td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?= BASE_URL ?>views/admin/forms/edit_category.php?id=<?= $cat['id'] ?>" class="btn icon-btn" style="color: #f39c12;" title="Edit Category">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if ($cat['asset_count'] == 0): ?>
                                            <form method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to delete this category?');">
                                                <?= get_csrf_input() ?>
                                                <input type="hidden" name="delete_id" value="<?= $cat['id'] ?>">
                                                <button type="submit" class="btn icon-btn" style="color: #e74c3c; background: none; border: none;" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="btn icon-btn disabled" style="color: #8fa0c9; opacity: 0.5;" title="Cannot delete: Used in assets"><i class="fas fa-lock"></i></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="3" style="text-align: center;">No categories found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
