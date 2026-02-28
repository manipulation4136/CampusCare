<?php
require_once __DIR__ . '/../../../config/init.php';
ensure_role('admin');

// Handle Relocation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');
    
    $asset_code = trim($_POST['asset_code'] ?? '');
    $new_room_id = (int)($_POST['new_room_id'] ?? 0);
    
    if (empty($asset_code) || empty($new_room_id)) {
        set_flash('err', 'Please enter an asset code and select a new room.');
    } else {
        // Check if asset exists
        $check = $conn->prepare("SELECT id, room_id FROM assets WHERE asset_code = ?");
        $check->bind_param("s", $asset_code);
        $check->execute();
        $res = $check->get_result();
        
        if ($asset = $res->fetch_assoc()) {
            if ($asset['room_id'] == $new_room_id) {
                set_flash('err', 'Asset is already in this room.');
            } else {
                try {
                    $update = $conn->prepare("UPDATE assets SET room_id = ? WHERE asset_code = ?");
                    $update->bind_param("is", $new_room_id, $asset_code);
                    
                    if ($update->execute()) {
                        set_flash('ok', "Asset {$asset_code} successfully moved to new room.");
                        header('Location: ' . BASE_URL . 'views/admin/assets.php');
                        exit;
                    } else {
                        set_flash('err', 'Failed to update asset location.');
                    }
                } catch (Exception $e) {
                    set_flash('err', 'Error: ' . $e->getMessage());
                }
            }
        } else {
            set_flash('err', "Asset with code '{$asset_code}' not found.");
        }
    }
}

// Check for pre-filled asset
$prefilled_asset = null;
$asset_id = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : 0;
if ($asset_id) {
    $stmt = $conn->prepare("
        SELECT a.asset_code, an.name as asset_name 
        FROM assets a 
        JOIN asset_names an ON a.asset_name_id = an.id 
        WHERE a.id = ?
    ");
    $stmt->bind_param("i", $asset_id);
    $stmt->execute();
    $prefilled_asset = $stmt->get_result()->fetch_assoc();
}

// Fetch Rooms for Dropdown
$rooms = $conn->query("SELECT id, building, floor, room_no FROM rooms ORDER BY building, floor, room_no");

include __DIR__ . '/../../partials/header.php';
?>

<div class="main-content">
    <div style="margin-bottom: 24px;">
        <a href="<?= BASE_URL ?>views/admin/assets.php" class="btn outline small">
            <i class="fas fa-arrow-left"></i> Back to Assets
        </a>
    </div>

    <div class="card" style="max-width: 600px; margin: 0 auto;">
        <h2 class="card-title">Relocate Asset</h2>
        <p style="color: #8fa0c9; margin-bottom: 24px;">Move an asset to a different room without changing its ID.</p>

        <?php if ($m = flash('ok')): ?>
            <div class="alert success"><?= htmlspecialchars($m) ?></div>
        <?php endif; ?>
        <?php if ($m = flash('err')): ?>
            <div class="alert error"><?= htmlspecialchars($m) ?></div>
        <?php endif; ?>

        <form method="post">
            <?= get_csrf_input() ?>
            
            <div style="margin-bottom: 16px;">
                <label>Asset to Relocate</label>
                <?php if ($prefilled_asset): ?>
                    <!-- Read-only display for pre-filled asset -->
                    <input type="text" class="input form-control input-dark" value="<?= htmlspecialchars($prefilled_asset['asset_code'] . ' - ' . $prefilled_asset['asset_name']) ?>" disabled style="background-color: rgba(255,255,255,0.05); color: #8fa0c9; cursor: not-allowed; border: 1px solid rgba(255,255,255,0.1);">
                    <input type="hidden" name="asset_code" value="<?= htmlspecialchars($prefilled_asset['asset_code']) ?>">
                <?php else: ?>
                    <div class="input-group">
                        <input class="input form-control input-dark" name="asset_code" list="asset_list" placeholder="Enter or search Asset Code" required value="<?= htmlspecialchars($_GET['code'] ?? '') ?>">
                        <datalist id="asset_list">
                            <?php
                            $allAssets = $conn->query("SELECT asset_code FROM assets ORDER BY asset_code");
                            while($a = $allAssets->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($a['asset_code']) ?>">
                            <?php endwhile; ?>
                        </datalist>
                    </div>
                <?php endif; ?>
            </div>

            <div style="margin-bottom: 24px;">
                <label>Target Room</label>
                <select class="input form-control input-dark" name="new_room_id" required>
                    <option value="">Select Target Location</option>
                    <?php 
                    $rooms->data_seek(0);
                    while ($r = $rooms->fetch_assoc()): 
                    ?>
                        <option value="<?= (int)$r['id'] ?>">
                            <?= htmlspecialchars($r['building'] . ' - Floor ' . $r['floor'] . ' - Room ' . $r['room_no']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <button class="btn" type="submit" style="width: 100%;">
                <i class="fas fa-exchange-alt"></i> Relocate Asset
            </button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
