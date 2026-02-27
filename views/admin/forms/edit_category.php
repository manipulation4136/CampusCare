<?php
require_once __DIR__ . '/../../../config/init.php';
ensure_role(['admin']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    set_flash('err', 'Invalid category ID.');
    header("Location: " . BASE_URL . "views/admin/categories.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');
    
    $name = trim($_POST['name'] ?? '');
    
    if (!empty($name)) {
        $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE id = ?");
        $stmt->bind_param("si", $name, $id);
        
        if ($stmt->execute()) {
            set_flash('ok', 'Category updated successfully.');
            header("Location: " . BASE_URL . "views/admin/categories.php");
            exit;
        } else {
            set_flash('err', 'Failed to update category.');
        }
    } else {
        set_flash('err', 'Category name is required.');
    }
}

$stmt = $conn->prepare("SELECT * FROM categories WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$category = $stmt->get_result()->fetch_assoc();

if (!$category) {
    set_flash('err', 'Category not found.');
    header("Location: " . BASE_URL . "views/admin/categories.php");
    exit;
}

include __DIR__ . '/../../partials/header.php';
?>
<div class="main-content">
    <div style="margin-bottom: 24px;">
        <a href="<?= BASE_URL ?>views/admin/categories.php" class="btn outline small"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
    <div class="card animate-card-entry" style="max-width: 600px; margin: 0 auto;">
        <h2 class="card-title">Edit Category</h2>
        <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>
        
        <form method="POST">
            <?= get_csrf_input() ?>
            <div style="margin-bottom: 16px;">
                <label style="color: var(--muted); display: block; margin-bottom: 8px;">Category Name *</label>
                <input class="input" type="text" name="name" value="<?= htmlspecialchars($category['name']) ?>" required maxlength="100">
            </div>
            <div class="actions" style="text-align: right; margin-top: 20px;">
                <button type="submit" class="btn"><i class="fas fa-save"></i> Update</button>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
