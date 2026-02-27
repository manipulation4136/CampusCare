<?php
require_once __DIR__ . '/../../../config/init.php';
ensure_role(['admin']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    set_flash('err', 'Invalid asset name ID.');
    header("Location: " . BASE_URL . "views/admin/asset_names.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');
    $name = trim($_POST['name'] ?? '');
    
    if (!empty($name)) {
        $stmt = $conn->prepare("UPDATE asset_names SET name = ? WHERE id = ?");
        $stmt->bind_param("si", $name, $id);
        if ($stmt->execute()) {
            set_flash('ok', 'Asset name updated successfully.');
            header("Location: " . BASE_URL . "views/admin/asset_names.php");
            exit;
        } else {
            set_flash('err', 'Failed to update asset name.');
        }
    } else {
        set_flash('err', 'Asset Type Name is required.');
    }
}

$stmt = $conn->prepare("SELECT * FROM asset_names WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$asset_name = $stmt->get_result()->fetch_assoc();

if (!$asset_name) {
    set_flash('err', 'Asset name not found.');
    header("Location: " . BASE_URL . "views/admin/asset_names.php");
    exit;
}

include __DIR__ . '/../../partials/header.php';
?>
<div class="main-content">
    <div style="margin-bottom: 24px;">
        <a href="<?= BASE_URL ?>views/admin/asset_names.php" class="btn outline small"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
    <div class="card animate-card-entry" style="max-width: 600px; margin: 0 auto;">
        <h2 class="card-title">Edit Asset Type</h2>
        <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>
        
        <form method="POST">
            <?= get_csrf_input() ?>
            <div style="margin-bottom: 16px;">
                <label style="color: var(--muted); display: block; margin-bottom: 8px;">Asset Type Name *</label>
                <input class="input" type="text" name="name" value="<?= htmlspecialchars($asset_name['name']) ?>" required>
            </div>
            <div class="actions" style="text-align: right; margin-top: 20px;">
                <button type="submit" class="btn"><i class="fas fa-save"></i> Update</button>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
