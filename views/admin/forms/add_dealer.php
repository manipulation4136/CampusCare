<?php
require_once __DIR__ . '/../../../config/init.php';
ensure_role(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');
    
    $name = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    
    if (!empty($name) && !empty($contact)) {
        $stmt = $conn->prepare("INSERT INTO dealers (name, contact) VALUES (?, ?)");
        $stmt->bind_param("ss", $name, $contact);
        if ($stmt->execute()) {
            set_flash('ok', 'Dealer added successfully.');
            header("Location: " . BASE_URL . "views/admin/dealers.php");
            exit;
        } else {
            set_flash('err', 'Failed to add dealer.');
        }
    } else {
        set_flash('err', 'Name and Contact are required.');
    }
}

include __DIR__ . '/../../partials/header.php';
?>
<div class="main-content">
    <div style="margin-bottom: 24px;">
        <a href="<?= BASE_URL ?>views/admin/dealers.php" class="btn outline small"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
    <div class="card animate-card-entry" style="max-width: 600px; margin: 0 auto;">
        <h2 class="card-title">Add New Dealer</h2>
        <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>
        
        <form method="POST">
            <?= get_csrf_input() ?>
            <div style="margin-bottom: 16px;">
                <label style="color: var(--muted); display: block; margin-bottom: 8px;">Dealer Name *</label>
                <input class="input" type="text" name="name" required>
            </div>
            <div style="margin-bottom: 16px;">
                <label style="color: var(--muted); display: block; margin-bottom: 8px;">Contact Info *</label>
                <input class="input" type="text" name="contact" required>
            </div>
            <div class="actions" style="text-align: right; margin-top: 20px;">
                <button type="submit" class="btn"><i class="fas fa-save"></i> Save Dealer</button>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>