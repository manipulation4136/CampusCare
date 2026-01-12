<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../config/asset_helper.php';
// ✅ NEW: Room Utils for optimized syncing
require_once __DIR__ . '/../../config/room_utils.php'; 

ensure_role(['student','faculty']);

$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');
    
    // Inputs
    $asset_code = trim($_POST['asset_code'] ?? '');
    $asset_name_id = (int)($_POST['asset_name_id'] ?? 0);
    $room_id = (int)($_POST['room_id'] ?? 0);
    $number = (int)($_POST['number'] ?? 1);
    $issue_type = $_POST['issue_type'] ?? 'Damage';
    $urgency_priority = $_POST['urgency_priority'] ?? 'Medium';
    $description = trim($_POST['description'] ?? '');
    $cpu_id = trim($_POST['cpu_id'] ?? '');
    $reported_by = $_SESSION['user']['id'];

    // 1. Asset Code Logic (Auto-Generate if needed)
    if (empty($asset_code) && $asset_name_id > 0 && $room_id > 0) {
        $roomStmt = $conn->prepare("SELECT room_no FROM rooms WHERE id = ?");
        $roomStmt->bind_param("i", $room_id);
        $roomStmt->execute();
        if ($roomRow = $roomStmt->get_result()->fetch_assoc()) {
            $asset_code = getNextAssetCode($conn, $asset_name_id, $roomRow['room_no']);
            if (!$asset_code) $error = 'Could not auto-generate asset code. Please enter manually.';
        }
    }

    if (!$error) {
        // 2. Check/Create Asset
        $stmt = $conn->prepare("SELECT id, room_id, parent_asset_id, status FROM assets WHERE asset_code = ?");
        $stmt->bind_param("s", $asset_code);
        $stmt->execute();
        $asset = $stmt->get_result()->fetch_assoc();

        // Create if missing
        if (!$asset) {
            $catRes = $conn->query("SELECT id FROM categories LIMIT 1");
            $category_id = ($row = $catRes->fetch_assoc()) ? $row['id'] : 0;
            $dealerRes = $conn->query("SELECT id FROM dealers LIMIT 1");
            $dealer_id = ($row = $dealerRes->fetch_assoc()) ? $row['id'] : 0;

            if ($category_id && $dealer_id) {
                try {
                    $newAssetData = [
                        'asset_name_id' => $asset_name_id,
                        'category_id' => $category_id,
                        'room_id' => $room_id,
                        'parent_asset_id' => null,
                        'warranty_end' => date('Y-m-d', strtotime('+2 years')),
                        'dealer_id' => $dealer_id
                    ];
                    $result = insertAssetSafe($conn, $newAssetData);
                    $asset = ['id' => $result['id'], 'room_id' => $room_id, 'parent_asset_id' => null, 'status' => 'Good'];
                    $asset_code = $result['code'];
                } catch (Exception $e) {
                    $error = 'Failed to auto-create asset: ' . $e->getMessage();
                }
            } else {
                $error = 'System configuration error: Missing default category/dealer.';
            }
        }

        // 3. Validations
        if (!$error) {
            if ($asset['status'] === 'Needs Repair') {
                $error = 'DUPLICATE_REPORT';
            } else {
                if ($asset['parent_asset_id'] && empty($cpu_id)) {
                    $error = 'CPU ID is required for computer components.';
                }

                // Image Upload Logic with Fix
                $img_path = null;
                if (!empty($_FILES['image']['name'])) {
                    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                        $error = 'Only JPG/PNG/GIF allowed';
                    } else {
                        // ✅ FIX: Auto-create uploads directory with permissions
                        $upload_dir = __DIR__ . '/../../uploads/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }

                        $new = 'uploads/' . date('Ymd_His') . '_' . $asset_code . '.' . $ext;
                        
                        // Use $upload_dir for full path to avoid permission issues
                        if (!move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . basename($new))) {
                            $error = 'Upload failed';
                        } else {
                            $img_path = '/' . $new;
                        }
                    }
                }
            }
        }

        // 4. Save Report & Update Status
        if (!$error) {
            try {
                $conn->begin_transaction();

                $final_description = $description;
                if ($asset['parent_asset_id'] && !empty($cpu_id)) {
                    $final_description = "(CPU ID: " . $cpu_id . ")\n\n" . $description;
                }

                // Insert Report
                $stmt = $conn->prepare("INSERT INTO damage_reports (asset_id, reported_by, description, image_path, urgency_priority, issue_type) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iissss", $asset['id'], $reported_by, $final_description, $img_path, $urgency_priority, $issue_type);
                $stmt->execute();

                // Update Asset Status
                $updateAssetStmt = $conn->prepare("UPDATE assets SET status = 'Needs Repair' WHERE id = ?");
                $updateAssetStmt->bind_param("i", $asset['id']);
                $updateAssetStmt->execute();

                // ✅ OPTIMIZED: Sync ONLY this room
                syncExamReadyStatus($conn, (int)$asset['room_id']);

                $conn->commit(); 
                
                // Notifications (Post-Commit)
                $ok = 'Report submitted for asset: ' . $asset_code;
                if ($asset['parent_asset_id'] && !empty($cpu_id)) $ok .= ' (CPU ID: ' . $cpu_id . ')';
                
                // Notification Details
                $notifyDetailsQuery = $conn->prepare("
                    SELECT an.name as asset_name, r.room_no 
                    FROM assets a 
                    JOIN asset_names an ON a.asset_name_id = an.id 
                    JOIN rooms r ON a.room_id = r.id 
                    WHERE a.id = ?
                ");
                $notifyDetailsQuery->bind_param("i", $asset['id']);
                $notifyDetailsQuery->execute();
                $notifyDetails = $notifyDetailsQuery->get_result()->fetch_assoc();
                
                $n_asset_name = $notifyDetails['asset_name'] ?? 'Asset';
                $n_room_no = $notifyDetails['room_no'] ?? 'Unknown';
                
                // Notify Faculty
                $fac = $conn->query("SELECT faculty_id FROM room_assignments WHERE room_id = " . (int)$asset['room_id']);
                while($f = $fac->fetch_assoc()) {
                    $msg = "⚠️ New Report: $n_asset_name in Room $n_room_no is damaged. Priority: $urgency_priority. Code ($asset_code)";
                    notify_user($conn, (int)$f['faculty_id'], $msg);
                }

                // Notify Admin (DB Users)
                $admin_query = $conn->query("SELECT id FROM users WHERE role = 'admin'");
                while($admin = $admin_query->fetch_assoc()) {
                    $msg = "⚠️ Action Required: New $urgency_priority priority report for $n_asset_name in Room $n_room_no";
                    notify_user($conn, (int)$admin['id'], $msg);
                }



            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}

// Fetch Data for Dropdowns
$rooms = $conn->query("SELECT id, building, floor, room_no FROM rooms ORDER BY building, floor, room_no");
$assetNames = $conn->query("SELECT id, name FROM asset_names ORDER BY name");

include __DIR__ . '/../partials/header.php';
?>

<script>
function playAchievementSound() {
    const audio = new Audio('<?= BASE_URL ?>sounds/achievement.mp3');
    audio.volume = 0.7;
    audio.play().catch(e => console.log('Audio failed:', e));
}
</script>

<div class="glass-card" style="max-width: 600px; margin: 2rem auto;">
    <div class="login-header" style="margin-bottom: 25px;">
        <h2 style="color: white; text-align: center;">Report Asset Damage</h2>
        <p style="text-align: center; color: rgba(255,255,255,0.7);">Submit a new maintenance request</p>
    </div>
    
    <?php if ($error && $error !== 'DUPLICATE_REPORT'): ?>
        <div class="alert error" style="margin-bottom: 20px;">
            <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    
    <?php if($ok): ?>
        <div class="alert success" style="margin-bottom: 20px;">
            <i class="fa-solid fa-check-circle"></i> <?= htmlspecialchars($ok) ?>
        </div>
        <script>playAchievementSound();</script>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?= get_csrf_input() ?>
        
        <div style="margin-bottom: 15px;">
            <label style="color: #ccc; font-size: 0.9em; margin-bottom: 5px; display: block;">Asset Code</label>
            <div class="input-group">
                <input class="input-dark" name="asset_code" id="asset_code" 
                       placeholder="Auto-generated or enter manually"
                       value="<?= htmlspecialchars($_POST['asset_code'] ?? '') ?>">
                <i class="fa-solid fa-barcode"></i>
            </div>
        </div>
        
        <div style="margin-bottom: 15px;">
            <label style="color: #ccc; font-size: 0.9em; margin-bottom: 5px; display: block;">Asset Name</label>
            <div class="input-group">
                <select class="input-dark" name="asset_name_id" id="asset_name_id" onchange="generateAssetCode()" style="appearance: none; -webkit-appearance: none; cursor: pointer;">
                    <option value="">Select Asset Name</option>
                    <?php while ($an = $assetNames->fetch_assoc()): ?>
                        <option value="<?= (int)$an['id'] ?>" 
                                <?= (isset($_POST['asset_name_id']) && $_POST['asset_name_id'] == $an['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($an['name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <i class="fa-solid fa-tag"></i>
                <i class="fa-solid fa-chevron-down" style="left: auto; right: 16px;"></i>
            </div>
            <small style="color: rgba(255,255,255,0.5); display: block; margin-top: 5px;">Select standardized name</small>
        </div>

        <div style="margin-bottom: 15px;">
            <label style="color: #ccc; font-size: 0.9em; margin-bottom: 5px; display: block;">Room</label>
            <div class="input-group">
                <select class="input-dark" name="room_id" id="room_id" onchange="generateAssetCode()" style="appearance: none; -webkit-appearance: none; cursor: pointer;">
                    <option value="">Select room</option>
                    <?php while ($r = $rooms->fetch_assoc()): ?>
                        <option value="<?= (int)$r['id'] ?>" data-room-no="<?= htmlspecialchars($r['room_no']) ?>"
                                <?= (isset($_POST['room_id']) && $_POST['room_id'] == $r['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['building'] . '/' . $r['floor'] . '/' . $r['room_no']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <i class="fa-solid fa-map-marker-alt"></i>
                <i class="fa-solid fa-chevron-down" style="left: auto; right: 16px;"></i>
            </div>
        </div>

        <div style="margin-bottom: 15px;">
            <label style="color: #ccc; font-size: 0.9em; margin-bottom: 5px; display: block;">Asset Number</label>
            <div class="input-group">
                <input class="input-dark" name="number" id="number" type="number" value="1" min="1" onchange="generateAssetCode()">
                <i class="fa-solid fa-hashtag"></i>
            </div>
            <small style="color: rgba(255,255,255,0.5); display: block; margin-top: 5px;">Item count in room</small>
        </div>

        <div style="margin-bottom: 15px;">
            <label style="color: #ccc; font-size: 0.9em; margin-bottom: 5px; display: block;">Issue Type</label>
            <div class="input-group">
                <select class="input-dark" name="issue_type" id="issue_type" required onchange="updateDescriptionPlaceholder()" style="appearance: none; -webkit-appearance: none; cursor: pointer;">
                    <option value="Damage">Damage (Broken/Malfunction)</option>
                    <option value="Missing Sticker">Missing Sticker</option>
                    <option value="Other">Other</option>
                </select>
                <i class="fa-solid fa-triangle-exclamation"></i>
                <i class="fa-solid fa-chevron-down" style="left: auto; right: 16px;"></i>
            </div>
        </div>

        <div style="margin-bottom: 15px;">
            <label style="color: #ccc; font-size: 0.9em; margin-bottom: 5px; display: block;">Urgency Priority</label>
            <div class="input-group">
                <select class="input-dark" name="urgency_priority" required style="appearance: none; -webkit-appearance: none; cursor: pointer;">
                    <option value="Low">Low - Cosmetic</option>
                    <option value="Medium" selected>Medium - Functional</option>
                    <option value="High">High - Critical Failure</option>
                </select>
                <i class="fa-solid fa-fire"></i>
                <i class="fa-solid fa-chevron-down" style="left: auto; right: 16px;"></i>
            </div>
        </div>

        <div id="cpu-id-container" style="display:none; margin-bottom: 15px;">
            <label style="color: #ccc; font-size: 0.9em; margin-bottom: 5px; display: block;">CPU ID <span style="color:#e74c3c">*</span></label>
            <div class="input-group">
                <input class="input-dark" name="cpu_id" id="cpu_id" 
                       placeholder="Enter CPU ID">
                <i class="fa-solid fa-microchip"></i>
            </div>
            <small style="color: rgba(255,255,255,0.5); display: block; margin-top: 5px;">Track component location</small>
        </div>

        <div style="margin-bottom: 15px;">
            <label style="color: #ccc; font-size: 0.9em; margin-bottom: 5px; display: block;">Description</label>
            <div class="input-group">
                <textarea class="input-dark" name="description" id="description" rows="4" placeholder="Describe the issue..." required style="height: auto; padding-top: 12px; min-height: 100px; resize: vertical;"></textarea>
                <i class="fa-solid fa-align-left" style="top: 15px;"></i>
            </div>
        </div>

        <div style="margin-bottom: 25px;">
            <label style="color: #ccc; font-size: 0.9em; margin-bottom: 5px; display: block;">Image (Optional)</label>
            <div class="input-group">
                <input class="input-dark" type="file" name="image" accept="image/*" style="padding-top: 10px;">
                <i class="fa-solid fa-camera"></i>
            </div>
        </div>

        <button class="btn-login" type="submit" id="submitBtn" style="width: 100%; margin-top: 10px;">Submit Report</button>

    </form>
</div>

<div id="duplicateModal" class="modal" style="display:none;">
    <div class="glass-card modal-content" style="border: 1px solid #d32f2f; background: rgba(20, 0, 0, 0.95);">
        <h3 style="color: #ff4444; margin-bottom: 15px;"><i class="fa-solid fa-triangle-exclamation"></i> Report Already Exists</h3>
        <p style="color: #ddd;">This asset already has an active damage report and is marked as "Needs Repair".</p>
        <p style="color: #ddd; margin-bottom: 20px;">Only one report per asset is allowed at a time.</p>
        <button onclick="document.getElementById('duplicateModal').style.display='none'" class="btn-login" style="width: 100%;">OK</button>
    </div>
</div>

<script>
<?php if ($error === 'DUPLICATE_REPORT'): ?>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('duplicateModal').style.display = 'block';
});
<?php endif; ?>

async function generateAssetCode() {
    const assetNameSelect = document.getElementById('asset_name_id');
    const roomSelect = document.getElementById('room_id');
    const numberInput = document.getElementById('number');
    const assetCodeInput = document.getElementById('asset_code');
    const submitBtn = document.getElementById('submitBtn');

    const assetNameId = assetNameSelect.value;
    const selectedRoom = roomSelect.options[roomSelect.selectedIndex];
    const number = numberInput.value || 1;
    const userTypedCode = assetCodeInput.dataset.userTyped === 'true';

    if (assetNameId && selectedRoom && selectedRoom.value && number && !userTypedCode) {
        const roomNo = selectedRoom.getAttribute('data-room-no');
        const assetNameText = assetNameSelect.options[assetNameSelect.selectedIndex].text;
        
        if (roomNo && assetNameText) {
            const cleanName = assetNameText.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
            const assetCode = cleanName + '-' + roomNo + '-' + number;
            assetCodeInput.value = assetCode;
            assetCodeInput.style.backgroundColor = 'rgba(255, 255, 255, 0.1)';
            assetCodeInput.placeholder = 'Auto-generated asset code';
            checkAssetForCpuId();
        }
    }

    submitBtn.disabled = !assetCodeInput.value.trim();
}

async function checkAssetForCpuId() {
    const assetCode = document.getElementById('asset_code').value.trim();
    const cpuIdContainer = document.getElementById('cpu-id-container');
    const cpuIdInput = document.getElementById('cpu_id');

    if (!assetCode) {
        cpuIdContainer.style.display = 'none';
        cpuIdInput.required = false;
        return;
    }

    try {
        const csrfToken = document.querySelector('input[name="csrf_token"]').value;
        const response = await fetch('<?= BASE_URL ?>includes/check_asset.php', {            
            method: 'POST',            
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'asset_code=' + encodeURIComponent(assetCode) + '&csrf_token=' + encodeURIComponent(csrfToken)
        });

        if (response.ok) {
            const data = await response.json();
            if (data.success && data.has_parent) {
                cpuIdContainer.style.display = 'block';
                cpuIdInput.required = true;
            } else {
                cpuIdContainer.style.display = 'none';
                cpuIdInput.required = false;
                cpuIdInput.value = '';
            }
        }
    } catch (error) {
        console.error('Error checking asset:', error);
        cpuIdContainer.style.display = 'none';
        cpuIdInput.required = false;
    }
}

document.getElementById('asset_code').addEventListener('input', function() {
    const assetCodeInput = this;
    if (assetCodeInput.value.trim()) {
        assetCodeInput.dataset.userTyped = 'true';
        assetCodeInput.placeholder = 'Manual asset code entry';
        checkAssetForCpuId();
    } else {
        assetCodeInput.dataset.userTyped = 'false';
        assetCodeInput.placeholder = 'Enter asset code or use auto-generation';
        generateAssetCode();
    }
    document.getElementById('submitBtn').disabled = !assetCodeInput.value.trim();
});

function updateDescriptionPlaceholder() {
    const issueType = document.getElementById('issue_type').value;
    const desc = document.getElementById('description');
    if (issueType === 'Missing Sticker') {
        desc.placeholder = "Describe where the sticker was located or where the item is in the room...";
    } else {
        desc.placeholder = "What happened?";
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const assetCodeInput = document.getElementById('asset_code');
    assetCodeInput.dataset.userTyped = 'false';
    generateAssetCode();
});

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('duplicateModal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}
</script>

<style>
.modal {
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.6);
    backdrop-filter: blur(5px);
}

.modal-content {
    margin: 15% auto;
    padding: 30px;
    width: 90%;
    max-width: 400px;
    text-align: center;
}

textarea.input-dark::placeholder {
    color: rgba(255, 255, 255, 0.6);
}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
