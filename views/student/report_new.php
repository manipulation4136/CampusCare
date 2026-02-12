<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../config/asset_helper.php';
require_once __DIR__ . '/../../config/room_utils.php';

// 1. OFFLINE-SAFE SESSION CHECK
// If session is missing/expired, check connection before kicking out.
if (!isset($_SESSION['user'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Checking Status...</title>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                if (!navigator.onLine) {
                    // Offline? Go to Offline Page
                    window.location.replace('../../offline.php');
                } else {
                    // Online & No Session? Go to Login
                    window.location.replace('../../index.php?msg=Session expired');
                }
            });
        </script>
        <style>body{background:#0b1020;color:#6ea8fe;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;font-family:sans-serif;}</style>
    </head>
    <body>
        <h2>Checking connection...</h2>
    </body>
    </html>
    <?php
    exit(); // Stop execution here
}

ensure_role(['student','faculty']);

$error = '';
$error = '';
$ok = '';
$qr_id = $_GET['qr_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_ajax = isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');

    if (!verify_csrf()) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'CSRF validation failed']);
            exit;
        }
        die('CSRF validation failed');
    }
    
    // Inputs
    $asset_code = trim($_POST['asset_code'] ?? '');
    $asset_name_id = (int)($_POST['asset_name_id'] ?? 0);
    $room_id = (int)($_POST['room_id'] ?? 0);
    $is_relocated = $_POST['is_relocated'] ?? '0'; // Capture the flag
    
    // User Decision: Hardcoded Priority & No Issue Type
    $urgency_priority = 'Medium'; 
    
    $description = trim($_POST['description'] ?? '');
    $cpu_id = trim($_POST['cpu_id'] ?? '');
    $reported_by = $_SESSION['user']['id'];

    // 1. Asset Code Backend Logic
    if ($asset_code === 'MISSING_STICKER') {
         // Security Check REMOVED to allow reporting moved assets
         // We no longer block if count == 0.

         // DUPLICATE CHECK: Is there ALREADY an active "Missing Sticker" report for this type in this room?
         $dupCheck = $conn->prepare("SELECT id FROM assets WHERE room_id = ? AND asset_name_id = ? AND asset_code LIKE 'MS-%' AND status = 'Needs Repair'");
         $dupCheck->bind_param("ii", $room_id, $asset_name_id);
         $dupCheck->execute();
         if ($dupCheck->get_result()->fetch_assoc()) {
             $error = "A missing sticker report is already pending for this item type in this room. Please wait for the admins to inspect it.";
         } else {
             // Generate a SPECIAL Separate Code for Missing Stickers
             // Do NOT use an existing asset code to avoid false flagging
             $asset_code = 'MS-' . strtoupper(uniqid()); 
             $description = "[STICKER MISSING] - " . $description;
         }
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
        // Fetch asset_name as well for notifications
        $assetResult = $stmt->get_result();
        // We need to join or fetch the name if it's not in the 'assets' table directly (it's normalized)
        // Let's optimize: First fetch basic data, then fetch name if needed.
        $asset = $assetResult->fetch_assoc();
        
        if ($asset) {
            $nameQ = $conn->prepare("SELECT name FROM asset_names WHERE id = (SELECT asset_name_id FROM assets WHERE id = ?)");
            $nameQ->bind_param("i", $asset['id']);
            $nameQ->execute();
            $nameRow = $nameQ->get_result()->fetch_assoc();
            $asset['asset_name'] = $nameRow['name'] ?? 'Unknown Asset';
        }

        // ✅ RACE CONDITION FIX STARTS HERE
        if (!$asset) {
             // CRITICAL SECURITY FIX: Only allow creation for "Missing Sticker" (MS-) codes
             // Reject random user inputs like "stikermis" or typos
             if (strpos($asset_code, 'MS-') !== 0) {
                 $error = "Invalid Asset Code: '{$asset_code}' does not exist in the database. Please scan a QR code or select a valid asset.";
             } 
             elseif ($asset_name_id && $room_id) {
                // ... (Creation Logic for MS- codes) ...
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
                        // Keep the duplicate handler just in case
                        if ($e->getCode() == 1062) {
                            $retryStmt = $conn->prepare("SELECT id, room_id, parent_asset_id, status FROM assets WHERE asset_code = ?");
                            $retryStmt->bind_param("s", $asset_code);
                            $retryStmt->execute();
                            $asset = $retryStmt->get_result()->fetch_assoc();
                            
                            if (!$asset) $error = "System Error: Duplicate asset code conflict.";
                        } else {
                            $error = 'Database Error: ' . $e->getMessage();
                        }
                    } catch (Exception $e) {
                        $error = 'General Error: ' . $e->getMessage();
                    }
                } else {
                    $error = 'System configuration error: Missing default category/dealer.';
                }
             } else {
                 $error = "Asset details incomplete.";
             }
        }
        // ✅ RACE CONDITION FIX ENDS HERE

        // 3. Validations
        if (!$error && $asset) {
            if ($asset['status'] === 'Needs Repair') {
                $error = 'DUPLICATE_REPORT';
                // Notify Student about duplicate
                 $msg = "⚠️ Report Failed: A report for {$asset_code} is already active.";
                 notify_user($conn, (int)$reported_by, $msg);
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

                // CHECK FOR RELOCATION
                // Explicitly check for flag OR implicit mismatch
                if (($asset['room_id'] != $room_id) || ($is_relocated === '1')) {
                    // Get Old Room Details
                    $oldRoomQ = $conn->prepare("SELECT room_no FROM rooms WHERE id = ?");
                    $oldRoomQ->bind_param("i", $asset['room_id']);
                    $oldRoomQ->execute();
                    $oldRoomRow = $oldRoomQ->get_result()->fetch_assoc();
                    $oldRoomNo = $oldRoomRow['room_no'] ?? 'Unknown';

                    // Update Asset Location
                    $moveStmt = $conn->prepare("UPDATE assets SET room_id = ? WHERE id = ?");
                    $moveStmt->bind_param("ii", $room_id, $asset['id']);
                    $moveStmt->execute();

                    // Get New Room Details
                    $newRoomQ = $conn->prepare("SELECT room_no FROM rooms WHERE id = ?");
                    $newRoomQ->bind_param("i", $room_id);
                    $newRoomQ->execute();
                    $newRoomRow = $newRoomQ->get_result()->fetch_assoc();
                    $newRoomNo = $newRoomRow['room_no'] ?? 'Unknown';

                    $studentName = $_SESSION['user']['name'] ?? 'A Student';
                    $assetName = $asset['asset_name'] ?? 'Asset'; // Need to make sure asset_name is available or fetched

                    // 1. Notify OLD Room Faculty
                    $oldFacQ = $conn->query("SELECT faculty_id FROM room_assignments WHERE room_id = " . (int)$asset['room_id']);
                    while($f = $oldFacQ->fetch_assoc()) {
                        $msg = "■ Asset Removed: Student {$studentName} moved {$assetName} ({$asset_code}) OUT of your room {$oldRoomNo} to {$newRoomNo}.";
                        notify_user($conn, (int)$f['faculty_id'], $msg);
                    }

                    // 2. Notify NEW Room Faculty
                    $newFacQ = $conn->query("SELECT faculty_id FROM room_assignments WHERE room_id = " . (int)$room_id);
                    while($f = $newFacQ->fetch_assoc()) {
                        $msg = "■ Asset Incoming: Student {$studentName} moved {$assetName} ({$asset_code}) INTO your room {$newRoomNo} from {$oldRoomNo}. Status: Needs Repair.";
                        notify_user($conn, (int)$f['faculty_id'], $msg);
                    }

                    // 3. Notify Admins
                    $admin_query = $conn->query("SELECT id FROM users WHERE role = 'admin'");
                    while($admin = $admin_query->fetch_assoc()) {
                         $msg = "■■ Relocation Alert: {$assetName} ({$asset_code}) was moved from {$oldRoomNo} to {$newRoomNo} by {$studentName}.";
                        notify_user($conn, (int)$admin['id'], $msg);
                    }
                }

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

                // Notify Student (Success)
                $msg = "✅ Report Submitted: Your report for {$asset_code} in {$n_room_no} has been received successfully.";
                notify_user($conn, (int)$reported_by, $msg);

            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }

    if ($is_ajax) {
        header('Content-Type: application/json');
        if ($error) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $error]);
        } else {
            echo json_encode(['success' => true, 'message' => $ok]);
        }
        exit;
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

    <form method="post" enctype="multipart/form-data" id="reportForm">
        <?= get_csrf_input() ?>
        <input type="hidden" name="is_relocated" id="is_relocated" value="0">
        
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
            <small id="relocation-warning" style="display: none; color: #f1c40f; margin-top: 5px;">
                <i class="fa-solid fa-triangle-exclamation"></i> Note: Asset location will be updated to the current room.
            </small>
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

        <div style="margin-bottom: 15px; position: relative;" id="asset-code-group">
            <label style="color: #ccc; font-size: 0.9em; margin-bottom: 5px; display: block;">Asset Code</label>
            <div class="input-group">
                <input class="input-dark" name="asset_code" id="asset_code" 
                       placeholder="Select item or type code (e.g., AST-B02)"
                       value="<?= htmlspecialchars($_POST['asset_code'] ?? '') ?>"
                       autocomplete="off">
                <i class="fa-solid fa-barcode"></i>
            </div>
            <!-- Custom Dropdown Container -->
            <div id="custom-dropdown" class="custom-dropdown"></div>
            
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

<div id="locationConfirmModal" class="modal" style="display:none;">
    <div class="glass-card modal-content" style="border: 1px solid rgba(255,255,255,0.1); background: rgba(20, 20, 25, 0.95); max-width: 500px;">
        <h3 style="color: #fff; margin-bottom: 20px;">Verify Location</h3>
        <p style="color: #ddd; margin-bottom: 25px; line-height: 1.5;">
            Asset found: <strong id="confirm_asset_name" style="color: #6ea8fe;"></strong>.<br>
            Database shows this asset belongs in <strong id="confirm_room_name" style="color: #6ea8fe;"></strong>.<br><br>
            Is it currently there?
        </p>
        <div style="display: flex; gap: 15px; justify-content: center;">
            <button id="btn-confirm-loc" class="btn-login" style="background: #2ecc71; width: auto; flex: 1;">
                <i class="fas fa-check"></i> Yes, Confirm Location
            </button>
            <button id="btn-asset-moved" class="btn-login" style="background: transparent; border: 1px solid #e74c3c; color: #e74c3c; width: auto; flex: 1;">
                <i class="fas fa-times"></i> No, It's Moved
            </button>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>assets/js/offline-db.js"></script>
<script>
const USER_ROLE = "<?php echo $_SESSION['user']['role']; ?>";

<?php if ($error === 'DUPLICATE_REPORT'): ?>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('duplicateModal').style.display = 'block';
});
<?php endif; ?>

document.addEventListener('DOMContentLoaded', function() {
    const reportForm = document.getElementById('reportForm');
    
    // Register Service Worker if possible
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('<?= BASE_URL ?>service-worker.js')
        .then(reg => console.log('SW Registered', reg))
        .catch(err => console.log('SW Failed', err));
    }

    // 1. Submit Listener
    reportForm.addEventListener('submit', function(e) {
        
        // CHECK ONLINE STATUS FIRST
        if (navigator.onLine) {
            // ONLINE: Allow default form submission (PHP handles it)
            // No e.preventDefault() here!
            return;
        }

        // OFFLINE: Prevent default and save locally
        e.preventDefault();
        console.log('OFFLINE DETECTED: Saving locally...');

        const btn = document.getElementById('submitBtn');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving Offline...';

        const formData = new FormData(reportForm);
        // Convert FormData to plain object for IndexedDB
        // We need to handle files appropriately. 
        // IndexedDB can store Blobs/Files directly.
        const data = {};
        formData.forEach((value, key) => {
            data[key] = value;
        });

        // Safety Check
        if (typeof saveReportLocally !== 'function') {
             alert("Offline resources are missing. Please reload the page when you are back online.");
             btn.disabled = false;
             btn.innerHTML = originalText;
             return;
        }

        // Use the offline-db.js function
        saveReportLocally(data).then(() => {
            // Register Background Sync if supported
            if ('serviceWorker' in navigator && 'SyncManager' in window) {
                navigator.serviceWorker.ready.then(registration => {
                    return registration.sync.register('sync-reports');
                });
            }
            
            // Clear form & UI Feedback
            reportForm.reset();
            // Clear any programmatic state if needed
            if(window.resetAutoFill) window.resetAutoFill(); 
            
            // Remove existing alerts if any
            const existingAlerts = reportForm.parentElement.querySelectorAll('.alert');
            existingAlerts.forEach(el => el.remove());

            // Show "Saved Offline" message
            const msg = document.createElement('div');
            msg.className = 'alert success';
            msg.innerHTML = '<i class="fa-solid fa-save"></i> <strong>Saved Offline:</strong> Report saved securely. It will be sent automatically when you reconnect.';
            msg.style.marginBottom = '20px';
            msg.style.backgroundColor = '#f39c12'; // Distinct color for offline save (Orange)
            msg.style.borderColor = '#e67e22';

            // Insert before form
            reportForm.parentNode.insertBefore(msg, reportForm);
            
            // Optional: Scroll to top
            window.scrollTo({ top: 0, behavior: 'smooth' });

        }).catch(err => {
            console.error("Failed to save report offline", err);
            alert("Failed to save report offline. Please try again.");
        }).finally(() => {
            // Restore button state regardless of outcome
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
    });

    // 2. UI Updates for Offline Status
    function updateOnlineStatus() {
        const isOffline = !navigator.onLine;
        const offlineId = 'offline-header-bar';
        let offlineBar = document.getElementById(offlineId);
        let gameBtn = document.getElementById('offline-game-btn');
        
        if (isOffline) {
            if (!offlineBar) {
                offlineBar = document.createElement('div');
                offlineBar.id = offlineId;
                offlineBar.style.position = 'fixed';
                offlineBar.style.top = '0';
                offlineBar.style.left = '0';
                offlineBar.style.width = '100%';
                offlineBar.style.backgroundColor = '#e74c3c';
                offlineBar.style.color = '#fff';
                offlineBar.style.textAlign = 'center';
                offlineBar.style.padding = '10px';
                offlineBar.style.zIndex = '10001';
                offlineBar.style.fontWeight = 'bold';
                offlineBar.style.boxShadow = '0 2px 10px rgba(0,0,0,0.3)';
                offlineBar.innerText = 'You are Offline. Reports will be saved locally.';
                document.body.appendChild(offlineBar);
            }
            
            if (USER_ROLE === 'student' && !gameBtn) {
                const header = document.querySelector('.login-header');
                if (header) {
                     gameBtn = document.createElement('a');
                     gameBtn.id = 'offline-game-btn';
                     gameBtn.href = '/offline-game.html'; // Direct to offline game
                     gameBtn.className = 'btn-login';
                     gameBtn.style.display = 'block';
                     gameBtn.style.background = '#f39c12'; // Orange/Gold
                     gameBtn.style.marginTop = '10px';
                     gameBtn.style.textAlign = 'center';
                     gameBtn.style.textDecoration = 'none';
                     gameBtn.innerHTML = '<i class="fa-solid fa-gamepad"></i> 🎮 Play Time Killer';
                     header.appendChild(gameBtn);
                }
            }
            
        } else {
            // Online
             if (offlineBar) offlineBar.remove();
             if (gameBtn) gameBtn.remove();
        }
    }

    // 3. AGGRESSIVE MANUAL SYNC
    async function checkAndSyncPendingReports() {
        if (!navigator.onLine) return;

        try {
             // 1. Get all pending reports from IndexedDB
             const db = await openDB(); 
             // Note: openDB is from offline-db.js. 
             // We can use helper getAllReports() if available, or direct transaction.
             // Using helper from offline-db.js:
             const pendingReports = await getAllReports();

             if (pendingReports.length > 0) {
                 console.log(`📡 Found ${pendingReports.length} pending reports. Syncing now...`);

                 let syncedCount = 0;

                 for (const report of pendingReports) {
                     const formData = new FormData();
                     // Reconstruct FormData
                     for (const key in report.data) {
                         formData.append(key, report.data[key]);
                     }
                     // Add a flag to bypass duplicate checks if needed, or strictly validate
                     formData.append('is_sync', '1'); 

                     // Add ajax flag
                     formData.append('ajax', '1');

                     try {
                         const response = await fetch(window.location.href, {
                             method: 'POST',
                             body: formData,
                             headers: { 'X-Requested-With': 'XMLHttpRequest' }
                         });
                         
                         const res = await response.json();

                         if (res.success) {
                             // Success! Delete from IDB
                             await deleteReport(report.id);
                             syncedCount++;
                             console.log(`✅ Report ${report.id} synced.`);
                         } else {
                             console.error(`❌ Failed to sync report ${report.id}:`, res.error);
                             
                             // Alert User
                             if(navigator.onLine) {
                                alert("❌ Sync Failed for asset " + report.data.asset_code + ": " + res.error);
                             }
                             
                             // Do NOT delete from IDB, allowing retry
                         }
                     } catch (e) {
                         console.error("Network or JSON error during sync", e);
                     }
                 }

                 if (syncedCount > 0) {
                     // Toast Notification
                     const toast = document.createElement('div');
                     toast.className = 'alert success';
                     toast.style.position = 'fixed';
                     toast.style.bottom = '20px';
                     toast.style.right = '20px';
                     toast.style.zIndex = '9999';
                     toast.innerHTML = `<i class="fa-solid fa-cloud-arrow-up"></i> <strong>Sync Complete</strong>: ${syncedCount} reports uploaded.`;
                     document.body.appendChild(toast);
                     setTimeout(() => toast.remove(), 5000);
                     
                     // Play Sound
                     if(typeof playAchievementSound === 'function') playAchievementSound();
                 }
             }
        } catch (err) {
            console.error("Manual Sync Error:", err);
        }
    }

    // Run Sync on Load (if online)
    checkAndSyncPendingReports();

    // Run Sync when coming back online
    window.addEventListener('online', () => {
        checkAndSyncPendingReports();
        fetchRoomAssets(); // Retry asset fetch on reconnection
    });
    window.addEventListener('offline', updateOnlineStatus);
    
    // Check initially
    updateOnlineStatus();
});


// ... (Top of scripts)
let isAutoFilling = false;
let currentRoomAssets = [];
let originalRoomId = null;
let pendingRoomId = null;
let lastProcessedCode = null; // Track handled codes

async function fetchRoomAssets() {
    // ... (Existing fetch logic) ...
    if (isAutoFilling) return;

    const roomId = document.getElementById('room_id').value;
    const assetNameId = document.getElementById('asset_name_id').value;
    // Cleared custom dropdown logic handled in render
    
    currentRoomAssets = [];
    
    if (roomId && assetNameId) {
        try {
            const response = await fetch(`<?= BASE_URL ?>includes/api_get_room_assets.php?room_id=${roomId}&asset_name_id=${assetNameId}`);
            currentRoomAssets = await response.json();
            
            const input = document.getElementById('asset_code');
            
            // Validate if we should clear the input
            const currentVal = input.value.trim();
            // Only clear if empty OR if it holds a system message ("No ... found").
            // Do NOT clear if user entered a code (e.g. for relocation).
            const isSystemMessage = currentVal.startsWith('No ') && currentVal.includes('found');
            
            if (currentRoomAssets.length === 0) {
                const nameSelect = document.getElementById('asset_name_id');
                const assetName = nameSelect.options[nameSelect.selectedIndex].text;
                
                // Only overwrite if it's safe to do so
                if (!currentVal || isSystemMessage) {
                    input.value = ''; 
                    input.placeholder = `No ${assetName} found in this room`;
                    input.readOnly = false; 
                }
            } else {
                input.placeholder = "Select item or type code (e.g., AST-B02)";
                if(input.readOnly) { input.readOnly = false; }
                
                // If previously showing "No assets found", clear it now that we have assets
                if (isSystemMessage) {
                    input.value = '';
                }
            }
            
            // Render the dropdown options initially (filtered by current empty input)
            renderDropdown();

        } catch (e) {
            console.error('Failed to fetch assets');
        }
    }
}

// Render Dropdown Function
function renderDropdown() {
    const input = document.getElementById('asset_code');
    const filter = input.value.trim().toUpperCase();
    const dropdown = document.getElementById('custom-dropdown');
    
    // Always clear first
    dropdown.innerHTML = '';
    
    // 1. Add "Missing Sticker" option if it matches filter or filter is empty
    const missingOptionText = "Unknown / Sticker Missing";
    const missingOptionCode = "MISSING_STICKER";
    
    let hasMatches = false;

    // Filter Assets
    const filteredAssets = currentRoomAssets.filter(asset => {
        return asset.code.toUpperCase().includes(filter) || asset.name.toUpperCase().includes(filter);
    });

    if (filteredAssets.length > 0) {
        filteredAssets.forEach(asset => {
            const div = document.createElement('div');
            div.className = 'dropdown-option';
            div.innerHTML = `<strong>${asset.code}</strong> <small>${asset.name} - ${asset.status}</small>`;
            div.onclick = function() { selectAsset(asset.code); };
            dropdown.appendChild(div);
        });
        hasMatches = true;
    }

    // ALWAYS show Missing Sticker option
    const div = document.createElement('div');
    div.className = 'dropdown-option';
    div.innerHTML = `<strong style="color: #e74c3c;">FAILED TO SCAN? </strong> <small>Click here to report without a code</small>`;
    div.onclick = function() { selectAsset('MISSING_STICKER'); };
    dropdown.appendChild(div);
    hasMatches = true;

    // Show/Hide Dropdown
    if (hasMatches) { // Only show if we have data or user is typing
         dropdown.classList.add('show');
    } else {
         dropdown.classList.remove('show');
    }
}

function selectAsset(code) {
    const input = document.getElementById('asset_code');
    input.value = code;
    document.getElementById('custom-dropdown').classList.remove('show');
    
    // Trigger existing logic
    handleInputLogic();
    verifyAssetCode(code);
}

// Consolidated Input Logic
function handleInputLogic() {
    const input = document.getElementById('asset_code');
    const val = input.value.trim();
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
            // verifyAssetCode is called on blur or selection, we can debounce here if needed
        } else {
            lastProcessedCode = null; 
        }
    }
}


document.getElementById('asset_code').addEventListener('input', function() {
    renderDropdown(); // Filter list
    handleInputLogic(); // Handle UI changes
});

document.getElementById('asset_code').addEventListener('focus', function() {
    // Show list on focus if we have data
    if (currentRoomAssets.length > 0 || this.value.length > 0) {
        renderDropdown();
    }
});

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const container = document.getElementById('asset-code-group');
    if (!container.contains(e.target)) {
        document.getElementById('custom-dropdown').classList.remove('show');
    }
});

async function verifyAssetCode(code) {
    if (!code || code === 'MISSING_STICKER') return;

    try {
        const formData = new FormData();
        formData.append('asset_code', code);
        const csrfRaw = document.querySelector('input[name="csrf_token"]');
        const csrf = csrfRaw ? csrfRaw.value : ''; // Safety check
        formData.append('csrf_token', csrf);

        const response = await fetch('<?= BASE_URL ?>includes/check_asset.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        
        if (data.exists) {
            if (!isAutoFilling) {
                // Prevent showing modal again if we just processed this code
                if (lastProcessedCode !== code) {
                    
                    isAutoFilling = true;
                    lastProcessedCode = code; // Mark as processed

                    const roomSelect = document.getElementById('room_id');
                    const nameSelect = document.getElementById('asset_name_id');
                    
                    // Set Name first
                    if (data.asset.asset_name_id) nameSelect.value = data.asset.asset_name_id;
                    
                    if (data.asset.room_id) {
                        // Prepare Modal Data
                        const roomOption = roomSelect.querySelector(`option[value="${data.asset.room_id}"]`);
                        const roomName = roomOption ? roomOption.text.trim() : 'Unknown Room';
                        const assetName = nameSelect.options[nameSelect.selectedIndex] ? nameSelect.options[nameSelect.selectedIndex].text.trim() : 'Asset';
                        
                        document.getElementById('confirm_asset_name').textContent = assetName;
                        document.getElementById('confirm_room_name').textContent = roomName;
                        
                        pendingRoomId = data.asset.room_id;
                        document.getElementById('locationConfirmModal').style.display = 'block';
                    }
                    
                    isAutoFilling = false;
                }
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
    // Small delay to allow click event on dropdown to fire first
    setTimeout(() => {
        verifyAssetCode(this.value.trim());
    }, 200);
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

function checkRelocation() {
    const roomSelect = document.getElementById('room_id');
    const warning = document.getElementById('relocation-warning');
    const hiddenInput = document.getElementById('is_relocated');
    
    // Only warn if we have a verified original room and the new value is different
    if (originalRoomId) {
        if (roomSelect.value != originalRoomId || roomSelect.value === "") {
            // Relocated (or currently empty/selecting)
            warning.style.display = 'block';
            hiddenInput.value = '1';
            roomSelect.style.borderColor = '#f39c12'; // Orange
        } else {
            // Matches Original
            warning.style.display = 'none';
            hiddenInput.value = '0';
            roomSelect.style.borderColor = '#27ae60'; // Green
        }
    } else {
        warning.style.display = 'none';
        hiddenInput.value = '0';
        roomSelect.style.borderColor = ''; // Reset
    }
}

// Listen for room changes
document.getElementById('room_id').addEventListener('change', checkRelocation);

// Modal Actions
document.getElementById('btn-confirm-loc').addEventListener('click', function(e) {
    e.preventDefault(); // Prevent form submission if inside form
    const roomSelect = document.getElementById('room_id');
    
    if (pendingRoomId) {
        roomSelect.value = pendingRoomId;
        originalRoomId = pendingRoomId;
        // Optionally add visual indicator here if needed
    }
    
    document.getElementById('is_relocated').value = '0';
    document.getElementById('locationConfirmModal').style.display = 'none';
    checkRelocation();
});

document.getElementById('btn-asset-moved').addEventListener('click', function(e) {
    e.preventDefault();
    const roomSelect = document.getElementById('room_id');
    
    roomSelect.value = ""; // Reset to empty
    originalRoomId = pendingRoomId; // We still know where it *should* be
    
    document.getElementById('is_relocated').value = '1';
    document.getElementById('locationConfirmModal').style.display = 'none';
    
    roomSelect.focus();
    checkRelocation(); // Will update UI warning
});

// Auto-trigger from QR Code
document.addEventListener('DOMContentLoaded', function() {
    const qrId = "<?= htmlspecialchars($qr_id) ?>";
    const hasSuccessMessage = <?= !empty($ok) ? 'true' : 'false' ?>;
    const hasErrorMessage = <?= !empty($error) ? 'true' : 'false' ?>;
    
    // Only auto-trigger if there is a QR ID AND we did not just submit (success or error)
    if (qrId && !hasSuccessMessage && !hasErrorMessage) {
        const input = document.getElementById('asset_code');
        input.value = qrId;
        verifyAssetCode(qrId);
    }
});
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

/* Custom Dropdown CSS */
.custom-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: rgba(20, 20, 25, 0.98); /* Slightly more opaque */
    border: 1px solid rgba(110, 168, 254, 0.2); /* Use accent color for border */
    border-radius: 8px;
    max-height: 250px;
    overflow-y: auto;
    z-index: 99999; /* Force it above everything */
    display: none;
    backdrop-filter: blur(10px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.5); /* Stronger shadow */
    margin-top: 5px;
}
.custom-dropdown.show {
    display: block;
}

@media (max-width: 600px) {
    .custom-dropdown {
        max-height: 200px; /* Reduced height for mobile keyboards */
    }
}
.dropdown-option {
    padding: 12px 15px;
    color: #eee;
    cursor: pointer;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    transition: background 0.2s;
    font-size: 0.95em;
}
.dropdown-option:last-child {
    border-bottom: none;
}
.dropdown-option:hover, .dropdown-option.active {
    background: rgba(255,255,255,0.1);
}
</style>
