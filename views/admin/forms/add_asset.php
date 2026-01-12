<?php
require_once __DIR__ . '/../../../config/init.php';
require_once __DIR__ . '/../../../config/asset_helper.php';
ensure_role(['admin', 'faculty']);

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');
    
    $asset_name_id = (int)($_POST['asset_name_id'] ?? 0);
    $category_id = (int)($_POST['category_id'] ?? 0);
    $room_id = (int)($_POST['room_id'] ?? 0);
    $parent_asset_id = $_POST['parent_asset_id'] ? (int)$_POST['parent_asset_id'] : null;
    $warranty_end = !empty($_POST['warranty_end']) ? $_POST['warranty_end'] : null;
    $dealer_id = (int)($_POST['dealer_id'] ?? 0);
    
    // Validation
    if ($asset_name_id && $room_id && $category_id && $dealer_id && !empty($warranty_end)) {
        // Validate date format
        $date = DateTime::createFromFormat('Y-m-d', $warranty_end);
        if (!$date || $date->format('Y-m-d') !== $warranty_end) {
            set_flash('err', 'Invalid warranty end date format');
        } else {
            // Validate category exists
            $categoryCheck = $conn->prepare("SELECT id FROM categories WHERE id = ?");
            $categoryCheck->bind_param("i", $category_id);
            $categoryCheck->execute();
            if (!$categoryCheck->get_result()->fetch_assoc()) {
                set_flash('err', 'Invalid category selected');
            } else {
                // Validate dealer exists
                $dealerCheck = $conn->prepare("SELECT id FROM dealers WHERE id = ?");
                $dealerCheck->bind_param("i", $dealer_id);
                $dealerCheck->execute();
                if (!$dealerCheck->get_result()->fetch_assoc()) {
                    set_flash('err', 'Invalid dealer selected');
                } else {
                    // Validate asset name exists
                    $assetNameCheck = $conn->prepare("SELECT name FROM asset_names WHERE id = ?");
                    $assetNameCheck->bind_param("i", $asset_name_id);
                    $assetNameCheck->execute();
                    if (!$assetNameResult = $assetNameCheck->get_result()->fetch_assoc()) {
                        set_flash('err', 'Invalid asset name selected');
                    } else {
                        try {
                            $data = [
                                'asset_name_id' => $asset_name_id,
                                'category_id' => $category_id,
                                'room_id' => $room_id,
                                'parent_asset_id' => $parent_asset_id,
                                'warranty_end' => $warranty_end,
                                'dealer_id' => $dealer_id
                            ];
                            $result = insertAssetSafe($conn, $data);
                            set_flash('ok', 'Asset added with code: ' . $result['code']);
                            header('Location: ' . BASE_URL . 'views/admin/assets.php');
                            exit;
                        } catch (Exception $e) {
                            set_flash('err', $e->getMessage());
                        }
                    }
                }
            }
        }
    } else {
        set_flash('err', 'Fill required fields: Asset Name, Category, Room, Dealer, and Warranty End Date');
    }
}

// Fetch dropdown data
$rooms = $conn->query("SELECT id, building, floor, room_no FROM rooms ORDER BY building, floor, room_no");
$asset_list = $conn->query("SELECT id, asset_code FROM assets ORDER BY asset_code");

include __DIR__ . '/../../partials/header.php';
?>

<div class="main-content">
    <!-- Back Button -->
    <div style="margin-bottom: 24px;">
        <a href="<?= BASE_URL ?>views/admin/assets.php" class="btn outline small">
            <i class="fas fa-arrow-left"></i> Back to Assets
        </a>
    </div>

    <!-- Form Card -->
    <div class="card" style="max-width: 800px; margin: 0 auto;">
        <h2 class="card-title">Add New Asset</h2>

        <?php if ($m = flash('ok')): ?>
            <div class="alert success"><?= htmlspecialchars($m) ?></div>
        <?php endif; ?>
        <?php if ($m = flash('err')): ?>
            <div class="alert error"><?= htmlspecialchars($m) ?></div>
        <?php endif; ?>

        <form method="post" class="grid cols-2">
            <?= get_csrf_input() ?>

            <!-- Asset Name -->
            <div>
                <label>Asset Name <span style="color: #e74c3c;">*</span></label>
                <select class="input" name="asset_name_id" id="asset_name_id" required onchange="generateCode()">
                    <option value="">Select Asset Name</option>
                    <?php
                    $assetNames = $conn->query("SELECT id, name FROM asset_names ORDER BY name");
                    while ($an = $assetNames->fetch_assoc()):
                        $selected = (isset($_POST['asset_name_id']) && $_POST['asset_name_id'] == $an['id']) ? 'selected' : '';
                    ?>
                        <option value="<?= (int)$an['id'] ?>" <?= $selected ?>>
                            <?= htmlspecialchars($an['name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <small style="color: #8fa0c9;">
                    Don't see your asset name? <a href="<?= BASE_URL ?>views/admin/asset_names.php" style="color: #6ea8fe;">Add it here</a>
                </small>
            </div>

            <!-- Category -->
            <div>
                <label>Category <span style="color: #e74c3c;">*</span></label>
                <select class="input" name="category_id" id="category_id" required>
                    <option value="">Select Category</option>
                    <?php
                    $category_query = "SELECT id, name FROM categories ORDER BY name";
                    $category_result = $conn->query($category_query);
                    while ($c = $category_result->fetch_assoc()):
                        $selected = (isset($_POST['category_id']) && $_POST['category_id'] == $c['id']) ? 'selected' : '';
                    ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $selected ?>>
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- Room -->
            <div>
                <label>Room <span style="color: #e74c3c;">*</span></label>
                <select class="input" name="room_id" id="room_id" required onchange="generateCode()">
                    <option value="">Select room</option>
                    <?php $rooms->data_seek(0); while ($r = $rooms->fetch_assoc()):
                        $selected = (isset($_POST['room_id']) && $_POST['room_id'] == $r['id']) ? 'selected' : '';
                    ?>
                        <option value="<?= (int)$r['id'] ?>" <?= $selected ?> data-room-no="<?= htmlspecialchars($r['room_no']) ?>">
                            <?= htmlspecialchars($r['building'] . '/' . $r['floor'] . '/' . $r['room_no']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- Dealer -->
            <div>
                <label>Dealer <span style="color: #e74c3c;">*</span></label>
                <select class="input" name="dealer_id" id="dealer_id" required>
                    <option value="">Select Dealer</option>
                    <?php
                    $dealer_query = "SELECT id, name, contact FROM dealers ORDER BY name";
                    $dealer_result = $conn->query($dealer_query);
                    while ($d = $dealer_result->fetch_assoc()):
                        $selected = (isset($_POST['dealer_id']) && $_POST['dealer_id'] == $d['id']) ? 'selected' : '';
                    ?>
                        <option value="<?= (int)$d['id'] ?>" <?= $selected ?>>
                            <?= htmlspecialchars($d['name']) ?>
                            <?php if ($d['contact']): ?>
                                - <?= htmlspecialchars($d['contact']) ?>
                            <?php endif; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- Warranty End Date -->
            <div>
                <label>Warranty End Date <span style="color: #e74c3c;">*</span></label>
                <input class="input" type="date" name="warranty_end" id="warranty_end"
                    value="<?= htmlspecialchars($_POST['warranty_end'] ?? '') ?>"
                    min="<?= date('Y-m-d') ?>" required>
                <small style="color: #8fa0c9;">Select warranty expiration date</small>
            </div>

            <!-- Parent Asset -->
            <div>
                <label>Parent Asset (optional)</label>
                <select class="input" name="parent_asset_id">
                    <option value="">None</option>
                    <?php while ($p = $asset_list->fetch_assoc()):
                        $selected = (isset($_POST['parent_asset_id']) && $_POST['parent_asset_id'] == $p['id']) ? 'selected' : '';
                    ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $selected ?>>
                            <?= htmlspecialchars($p['asset_code']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- Asset Code Preview (Full Width) -->
            <div class="col-span-full">
                <label>Asset Code Preview</label>
                <input class="input" name="asset_code" id="asset_code" placeholder="Auto-generated" readonly>
                <small style="color: #8fa0c9;">Auto-generated based on asset name and room</small>
            </div>

            <!-- Submit Button (Full Width) -->
            <div class="col-span-full" style="text-align: center; margin-top: 16px;">
                <button class="btn" type="submit" style="min-width: 200px;">
                    <i class="fas fa-plus"></i> Add Asset
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Auto-generate code preview
function generateCode() {
    const assetNameSelect = document.getElementById('asset_name_id');
    const roomSelect = document.getElementById('room_id');
    const assetCodeInput = document.getElementById('asset_code');
    const assetNameId = assetNameSelect.value;
    const selectedRoom = roomSelect.options[roomSelect.selectedIndex];
    
    if (assetNameId && selectedRoom && selectedRoom.value) {
        const roomNo = selectedRoom.getAttribute('data-room-no');
        if (roomNo) {
            try {
                const assetNameText = assetNameSelect.options[assetNameSelect.selectedIndex].text;
                const cleanName = assetNameText.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
                const suggestedCode = cleanName + '-' + roomNo + '-1';
                assetCodeInput.placeholder = 'Will be: ' + suggestedCode + ' (or next available)';
            } catch (error) {
                console.error('Error generating code:', error);
            }
        }
    } else {
        assetCodeInput.placeholder = 'Auto-generated';
    }
}

// Date validation
document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.getElementById('warranty_end');
    if (dateInput) {
        dateInput.addEventListener('change', function() {
            if (!this.value) {
                this.setCustomValidity('Please select a warranty end date');
            } else {
                this.setCustomValidity('');
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
