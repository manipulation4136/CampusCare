<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../config/asset_helper.php';
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
    
    // User Decision: Hardcoded Priority & No Issue Type
    $urgency_priority = 'Medium'; 
    
    $description = trim($_POST['description'] ?? '');
    $cpu_id = trim($_POST['cpu_id'] ?? '');
    $reported_by = $_SESSION['user']['id'];

    // 1. Asset Code Backend Logic
    if ($asset_code === 'MISSING_STICKER') {
         // Find ANY valid asset of this type in this room
         $stmt = $conn->prepare("SELECT asset_code FROM assets WHERE room_id = ? AND asset_name_id = ? LIMIT 1");
         $stmt->bind_param("ii", $room_id, $asset_name_id);
         $stmt->execute();
         if ($row = $stmt->get_result()->fetch_assoc()) {
             $asset_code = $row['asset_code'];
         } else {
             $asset_code = generateUniqueAssetCode();
         }
         $description = "[STICKER MISSING] - " . $description;
    }
    elseif (empty($asset_code) && $asset_name_id > 0 && $room_id > 0) {
        $asset_code = generateUniqueAssetCode();
    }

    if (!$error) {
        // 2. Check/Create Asset
        // First, check normally
        $stmt = $conn->prepare("SELECT id, room_id, parent_asset_id, status FROM assets WHERE asset_code = ?");
        $stmt->bind_param("s", $asset_code);
        $stmt->execute();
        $asset = $stmt->get_result()->fetch_assoc();

        // ✅ RACE CONDITION FIX STARTS HERE
        if (!$asset) {
             if ($asset_name_id && $room_id) {
                $catRes = $conn->query("SELECT id FROM categories LIMIT 1");
                $category_id = ($row = $catRes->fetch_assoc()) ? $row['id'] : 0;
                $dealerRes = $conn->query("SELECT id FROM dealers LIMIT 1");
                $dealer_id = ($row = $dealerRes->fetch_assoc()) ? $row['id'] : 0;

                if ($category_id && $dealer_id) {
                    try {
                        // Prepare Data
                        $newAssetData = [
                            'asset_name_id' => $asset_name_id,
                            'category_id' => $category_id,
                            'room_id' => $room_id,
                            'warranty_end' => date('Y-m-d', strtotime('+2 years')),
                            'dealer_id' => $dealer_id
                        ];
                        
                        // Try DIRECT INSERT
                        $stmt = $conn->prepare("INSERT INTO assets (asset_code, asset_name_id, category_id, room_id, warranty_end, dealer_id) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("siiisi", $asset_code, $asset_name_id, $category_id, $room_id, $newAssetData['warranty_end'], $dealer_id);

                        if ($stmt->execute()) {
                             $asset = ['id' => $conn->insert_id, 'room_id' => $room_id, 'parent_asset_id' => null, 'status' => 'Good'];
                        } else {
                            $error = 'Failed to create new asset record.';
                        }

                    } catch (mysqli_sql_exception $e) {
                        // ✅ CRITICAL FIX: Catch Duplicate Entry Error (Code 1062)
                        // If someone else created it 1ms ago, don't crash. Just use it.
                        if ($e->getCode() == 1062) {
                            $retryStmt = $conn->prepare("SELECT id, room_id, parent_asset_id, status FROM assets WHERE asset_code = ?");
                            $retryStmt->bind_param("s", $asset_code);
                            $retryStmt->execute();
                            $asset = $retryStmt->get_result()->fetch_assoc();
                            
                            // If still null, then it's a real ghost error
                            if (!$asset) $error = "System Error: Duplicate asset code conflict.";
                        } else {
                            // Real Database Error
                            $error = 'Database Error: ' . $e->getMessage();
                        }
                    } catch (Exception $e) {
                        $error = 'General Error: ' . $e->getMessage();
                    }
                } else {
                    $error = 'System configuration error: Missing default category/dealer.';
                }
             } else {
                 $error = "Asset not found. Please select Room and Asset Name to create it.";
             }
        }
        // ✅ RACE CONDITION FIX ENDS HERE

        // 3. Validations
        if (!$error && $asset) {
            if ($asset['status'] === 'Needs Repair') {
                $error = 'DUPLICATE_REPORT';
            } else {
                if ($asset['parent_asset_id'] && empty($cpu_id)) {
                    $error = 'CPU ID is required for computer components.';
                }

               // Image Upload (With Collision Protection)
                $img_path = null;
                if (!empty($_FILES['image']['name'])) {
                    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                        $error = 'Only JPG/PNG/GIF allowed';
                    } else {
                        $upload_dir = __DIR__ . '/../../uploads/';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                        
                        // ✅ FIX: Added uniqid() to prevent filename duplication
                        $new = 'uploads/' . date('Ymd_His') . '_' . uniqid() . '_' . $asset_code . '.' . $ext;
                        
                        if (!move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . basename($new))) {
                            $error = 'Upload failed';
                        } else {
                            $img_path = '/' . $new;
                        }
                    }
                }
            }
        }

        // 4. Save Report
        if (!$error && $asset) {
            try {
                $conn->begin_transaction();

                $final_description = $description;
                if ($asset['parent_asset_id'] && !empty($cpu_id)) {
                    $final_description = "(CPU ID: " . $cpu_id . ")\n\n" . $description;
                }

                // Insert into damage_reports (Using your simplified table structure)
                $stmt = $conn->prepare("INSERT INTO damage_reports (asset_id, reported_by, description, image_path, urgency_priority) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("iisss", $asset['id'], $reported_by, $final_description, $img_path, $urgency_priority);
                $stmt->execute();

                $updateAssetStmt = $conn->prepare("UPDATE assets SET status = 'Needs Repair' WHERE id = ?");
                $updateAssetStmt->bind_param("i", $asset['id']);
                $updateAssetStmt->execute();

                syncExamReadyStatus($conn, (int)$asset['room_id']);

                $conn->commit();
                
                $ok = 'Report submitted for asset: ' . $asset_code;
                if ($asset['parent_asset_id'] && !empty($cpu_id)) $ok .= ' (CPU ID: ' . $cpu_id . ')';

                // Notifications
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
                
                $fac = $conn->query("SELECT faculty_id FROM room_assignments WHERE room_id = " . (int)$asset['room_id']);
                while($f = $fac->fetch_assoc()) {
                    $msg = "⚠️ New Report: $n_asset_name in Room $n_room_no. Priority: $urgency_priority. Code ($asset_code)";
                    notify_user($conn, (int)$f['faculty_id'], $msg);
                }

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
            <label style="color: #ccc; font-size: 0.9em; margin-bottom: 5px; display: block;">Room</label>
            <div class="input-group">
                <select class="input-dark" name="room_id" id="room_id" onchange="fetchRoomAssets(); resetAutoFill('room');" style="appearance: none; -webkit-appearance: none; cursor: pointer;">
                    <option value="">Select room</option>
                    <?php while ($r = $rooms->fetch_assoc()): ?>
                        <option value="<?= (int)$r['id'] ?>" 
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
            <label style="color: #ccc; font-size: 0.9em; margin-bottom: 5px; display: block;">Asset Name</label>
            <div class="input-group">
                <select class="input-dark" name="asset_name_id" id="asset_name_id" onchange="fetchRoomAssets(); resetAutoFill('name');" style="appearance: none; -webkit-appearance: none; cursor: pointer;">
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
        </div>

        <div style="margin-bottom: 15px;">
            <label style="color: #ccc; font-size: 0.9em; margin-bottom: 5px; display: block;">Asset Code</label>
            <div class="input-group">
                <input class="input-dark" name="asset_code" id="asset_code" list="asset_code_list"
                       placeholder="Select item or type code (e.g., AST-B02)"
                       value="<?= htmlspecialchars($_POST['asset_code'] ?? '') ?>"
                       autocomplete="off">
                <datalist id="asset_code_list">
                    </datalist>
                <i class="fa-solid fa-barcode"></i>
            </div>
            <small style="color: rgba(255,255,255,0.5); display: block; margin-top: 5px;">
                Mode A: Select Room + Name to see list. <br> Mode B: Type code directly if known.
            </small>
        </div>
        
        <div id="cpu-id-container" style="display:none; margin-bottom: 15px;">
            <label style="color: #ccc; font-size: 0.9em; margin-bottom: 5px; display: block;">CPU ID <span style="color:#e74c3c">*</span></label>
            <div class="input-group">
                <input class="input-dark" name="cpu_id" id="cpu_id" placeholder="Enter CPU ID">
                <i class="fa-solid fa-microchip"></i>
            </div>
        </div>
        
        <div style="margin-bottom: 15px;">
            <label style="color: #ccc; font-size: 0.9em; margin-bottom: 5px; display: block;" id="desc-label">Description</label>
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
        <button onclick="document.getElementById('duplicateModal').style.display='none'" class="btn-login" style="width: 100%;">OK</button>
    </div>
</div>

<script>
<?php if ($error === 'DUPLICATE_REPORT'): ?>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('duplicateModal').style.display = 'block';
});
<?php endif; ?>

let isAutoFilling = false;
let currentRoomAssets = [];

async function fetchRoomAssets() {
    if (isAutoFilling) return;

    const roomId = document.getElementById('room_id').value;
    const assetNameId = document.getElementById('asset_name_id').value;
    const datalist = document.getElementById('asset_code_list');
    
    datalist.innerHTML = '';
    currentRoomAssets = [];
    
    if (roomId && assetNameId) {
        try {
            const response = await fetch(`<?= BASE_URL ?>includes/api_get_room_assets.php?room_id=${roomId}&asset_name_id=${assetNameId}`);
            currentRoomAssets = await response.json();
            
            if (currentRoomAssets.length === 0) {
                const nameSelect = document.getElementById('asset_name_id');
                const assetName = nameSelect.options[nameSelect.selectedIndex].text;
                const message = `No ${assetName} found in this room`;
                
                const noOption = document.createElement('option');
                noOption.value = message;
                datalist.appendChild(noOption);
                
                const input = document.getElementById('asset_code');
                input.value = message;
                input.readOnly = true; 
            } else {
                 // Reset if coming back to a valid state
                const input = document.getElementById('asset_code');
                if(input.readOnly) { input.readOnly = false; input.value = ''; }
            }

            currentRoomAssets.forEach(asset => {
                const option = document.createElement('option');
                option.value = asset.code;
                option.label = `${asset.name} (Code: ${asset.code}) - ${asset.status}`;
                datalist.appendChild(option);
            });
            
            const missingOption = document.createElement('option');
            missingOption.value = "MISSING_STICKER";
            missingOption.label = "Unknown / Sticker Missing";
            datalist.appendChild(missingOption);
            
        } catch (e) {
            console.error('Failed to fetch assets');
        }
    }
}

document.getElementById('asset_code').addEventListener('input', function() {
    const val = this.value.trim();
    const descLabel = document.getElementById('desc-label');
    const descInput = document.getElementById('description');
    
    if (val === 'MISSING_STICKER') {
        descLabel.innerHTML = 'Describe Location & Damage <span style="color:#e74c3c">*</span>';
        descInput.placeholder = 'Please describe where the asset is located AND what is wrong (e.g., Near the window, leg broken).';
        descInput.style.borderColor = '#e74c3c';
        
        if (typeof currentRoomAssets !== 'undefined' && currentRoomAssets.some(a => a.has_parent)) {
             document.getElementById('cpu-id-container').style.display = 'block';
             document.getElementById('cpu_id').required = true;
        } else {
             document.getElementById('cpu-id-container').style.display = 'none';
             document.getElementById('cpu_id').required = false;
        }

    } else {
        descLabel.innerHTML = 'Description';
        descInput.placeholder = "Describe the issue...";
        descInput.style.borderColor = '';
        
        if (val.length > 3) {
            verifyAssetCode(val);
        }
    }
});

async function verifyAssetCode(code) {
    if (!code || code === 'MISSING_STICKER') return;

    try {
        const formData = new FormData();
        formData.append('asset_code', code);
        const csrf = document.querySelector('input[name="csrf_token"]').value;
        formData.append('csrf_token', csrf);

        const response = await fetch('<?= BASE_URL ?>includes/check_asset.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        
        if (data.exists) {
            if (!isAutoFilling) {
                isAutoFilling = true;
                const roomSelect = document.getElementById('room_id');
                const nameSelect = document.getElementById('asset_name_id');
                if (data.asset.room_id) roomSelect.value = data.asset.room_id;
                if (data.asset.asset_name_id) nameSelect.value = data.asset.asset_name_id;
                isAutoFilling = false;
            }

            const cpuContainer = document.getElementById('cpu-id-container');
            const cpuInput = document.getElementById('cpu_id');
            if (data.has_parent) {
                cpuContainer.style.display = 'block';
                cpuInput.required = true;
            } else {
                cpuContainer.style.display = 'none';
                cpuInput.required = false;
            }
        }
    } catch (e) {
        console.error('Validation failed', e);
    }
}

document.getElementById('asset_code').addEventListener('blur', function() {
    verifyAssetCode(this.value.trim());
});

function resetAutoFill(source) {
    if (isAutoFilling) return;
    const input = document.getElementById('asset_code');
    if (input.value.startsWith('No ') && input.value.includes(' found')) {
        input.value = '';
        input.readOnly = false;
    }
}

window.onclick = function(event) {
    if (event.target == document.getElementById('duplicateModal')) {
        document.getElementById('duplicateModal').style.display = 'none';
    }
}
</script>

<style>
.modal {
    position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%;
    background-color: rgba(0,0,0,0.6); backdrop-filter: blur(5px);
}
.modal-content {
    margin: 15% auto; padding: 30px; width: 90%; max-width: 400px; text-align: center;
}
textarea.input-dark::placeholder { color: rgba(255, 255, 255, 0.6); }
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
