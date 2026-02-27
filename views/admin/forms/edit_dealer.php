<?php
require_once __DIR__ . '/../../../config/init.php';
ensure_role(['admin']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    set_flash('err', 'Invalid dealer ID.');
    header("Location: " . BASE_URL . "views/admin/dealers.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');
    
    $name = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    
    if (!empty($name) && !empty($contact)) {
        $stmt = $conn->prepare("UPDATE dealers SET name = ?, contact = ? WHERE id = ?");
        $stmt->bind_param("ssi", $name, $contact, $id);
        if ($stmt->execute()) {
            set_flash('ok', 'Dealer updated successfully.');
            header("Location: " . BASE_URL . "views/admin/dealers.php");
            exit;
        } else {
            set_flash('err', 'Failed to update dealer.');
        }
    } else {
        set_flash('err', 'Name and Contact are required.');
    }
}

$stmt = $conn->prepare("SELECT * FROM dealers WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$dealer = $stmt->get_result()->fetch_assoc();

if (!$dealer) {
    set_flash('err', 'Dealer not found.');
    header("Location: " . BASE_URL . "views/admin/dealers.php");
    exit;
}

include __DIR__ . '/../../partials/header.php';
?>
<div class="main-content">
    <div style="margin-bottom: 24px;">
        <a href="<?= BASE_URL ?>views/admin/dealers.php" class="btn outline small"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
    <div class="card animate-card-entry" style="max-width: 600px; margin: 0 auto;">
        <h2 class="card-title">Edit Dealer</h2>
        <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>
        
        <form method="POST">
            <?= get_csrf_input() ?>
            <div style="margin-bottom: 16px;">
                <label style="color: var(--muted); display: block; margin-bottom: 8px;">Dealer Name *</label>
                <input class="input" type="text" name="name" value="<?= htmlspecialchars($dealer['name']) ?>" required>
            </div>
            <div style="margin-bottom: 16px;">
                <label style="color: var(--muted); display: block; margin-bottom: 8px;">Contact Info *</label>
                <input class="input" type="text" name="contact" value="<?= htmlspecialchars($dealer['contact']) ?>" required>
            </div>
            <div class="actions" style="text-align: right; margin-top: 20px;">
                <button type="submit" class="btn"><i class="fas fa-save"></i> Update Dealer</button>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
