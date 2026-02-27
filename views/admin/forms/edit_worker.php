<?php
require_once __DIR__ . '/../../../config/init.php';
ensure_role(['admin']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    set_flash('err', 'Invalid worker ID.');
    header("Location: " . BASE_URL . "views/admin/workers.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');
    
    $name = trim($_POST['name'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $contact = trim($_POST['contact'] ?? '');
    
    if (!empty($name) && $category_id > 0) {
        $stmt = $conn->prepare("UPDATE workers SET name = ?, category_id = ?, contact = ? WHERE id = ? OR worker_id = ?");
        $stmt->bind_param("sisii", $name, $category_id, $contact, $id, $id);
        if ($stmt->execute()) {
            set_flash('ok', 'Worker updated successfully.');
            header("Location: " . BASE_URL . "views/admin/workers.php");
            exit;
        } else {
            set_flash('err', 'Failed to update worker.');
        }
    } else {
        set_flash('err', 'Name and Category are required.');
    }
}

// Check worker table schema (could be id or worker_id)
$worker = $conn->query("SELECT * FROM workers WHERE id = $id OR worker_id = $id")->fetch_assoc();
if (!$worker) {
    set_flash('err', 'Worker not found.');
    header("Location: " . BASE_URL . "views/admin/workers.php");
    exit;
}

$categories = $conn->query("SELECT id, name FROM categories ORDER BY name");

include __DIR__ . '/../../partials/header.php';
?>
<div class="main-content">
    <div style="margin-bottom: 24px;">
        <a href="<?= BASE_URL ?>views/admin/workers.php" class="btn outline small"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
    <div class="card animate-card-entry" style="max-width: 600px; margin: 0 auto;">
        <h2 class="card-title">Edit Worker</h2>
        <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>
        
        <form method="POST">
            <?= get_csrf_input() ?>
            <div style="margin-bottom: 16px;">
                <label style="color: var(--muted); display: block; margin-bottom: 8px;">Worker Name *</label>
                <input class="input" type="text" name="name" value="<?= htmlspecialchars($worker['name']) ?>" required>
            </div>
            <div style="margin-bottom: 16px;">
                <label style="color: var(--muted); display: block; margin-bottom: 8px;">Specialization / Category *</label>
                <select name="category_id" class="input" required>
                    <option value="">Select Category</option>
                    <?php while($cat = $categories->fetch_assoc()): ?>
                        <option value="<?= (int)$cat['id'] ?>" <?= $worker['category_id'] == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div style="margin-bottom: 16px;">
                <label style="color: var(--muted); display: block; margin-bottom: 8px;">Contact Info</label>
                <input class="input" type="text" name="contact" value="<?= htmlspecialchars($worker['contact'] ?? '') ?>">
            </div>
            <div class="actions" style="text-align: right; margin-top: 20px;">
                <button type="submit" class="btn"><i class="fas fa-save"></i> Update Worker</button>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
