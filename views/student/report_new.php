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
$qr_id = trim($_GET['qr_id'] ?? $_GET['asset_id'] ?? '');

// --- PRE-FETCH LOGIC FOR PARENT COMPONENT VERIFICATION ---
$prefetched_parent_js = "null";
if (!empty($qr_id)) {
    // Determine if $qr_id is ID (numeric) or code (string)
    $q_type = is_numeric($qr_id) ? "a.id = ?" : "a.asset_code = ?";
    
    // SQL query using LEFT JOIN to get parent details and faculty
    $prefetch_q = $conn->prepare("
        SELECT 
            a.id as child_id, a.asset_code as child_code,
            pa.id as parent_id, pa.asset_code as parent_code, an.name as parent_name,
            a.room_id, ra.faculty_id
        FROM assets a
        INNER JOIN assets pa ON a.parent_asset_id = pa.id
        LEFT JOIN asset_names an ON pa.asset_name_id = an.id
        LEFT JOIN room_assignments ra ON a.room_id = ra.room_id
        WHERE $q_type
    ");
    
    if (is_numeric($qr_id)) {
        $prefetch_val = (int)$qr_id;
        $prefetch_q->bind_param("i", $prefetch_val);
    } else {
        $prefetch_q->bind_param("s", $qr_id);
    }
    
    $prefetch_q->execute();
    $prefetch_res = $prefetch_q->get_result();
    $prefetch_data = $prefetch_res->fetch_assoc();
    
    if ($prefetch_data && $prefetch_data['parent_id']) {
        // We found a parent asset!
        $prefetched_parent_js = json_encode([
            'child_code' => $prefetch_data['child_code'],
            'parent_code' => $prefetch_data['parent_code'],
            'parent_name' => $prefetch_data['parent_name'] ?? 'Parent Asset',
            'faculty_id' => $prefetch_data['faculty_id']
        ]);
        
        // If the user scanned an ID, we want the form to use the actual code
        $qr_id = $prefetch_data['child_code']; 
    } elseif ($prefetch_data === null) {
        // If it was just a regular asset (no parent) but was ID-based, still resolve to code
        $resolve_q = $conn->prepare("SELECT asset_code FROM assets a WHERE $q_type");
        if (is_numeric($qr_id)) {
            $resolve_val = (int)$qr_id;
            $resolve_q->bind_param("i", $resolve_val);
        } else {
            $resolve_q->bind_param("s", $qr_id);
        }
        $resolve_q->execute();
        $resolve_res = $resolve_q->get_result()->fetch_assoc();
        if ($resolve_res) {
            $qr_id = $resolve_res['asset_code'];
        }
    }
}

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

               // Image Upload (Secure Validation)
                $img_path = null;
                if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $tmp_name = $_FILES['image']['tmp_name'];
                    $size = $_FILES['image']['size'];
                    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                    
                    // Enforce 50MB limit on server
                    if ($size > 50 * 1024 * 1024) {
                        $error = 'Image file size must be less than 50MB.';
                    } else {
                        // Strict MIME type validation
                        if (function_exists('finfo_open')) {
                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            $mime = finfo_file($finfo, $tmp_name);
                            finfo_close($finfo);
                        } else {
                            $mime = mime_content_type($tmp_name); // Fallback
                        }
                        
                        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                        
                        if (!in_array($mime, $allowed_mimes) || !in_array($ext, $allowed_exts)) {
                            $error = 'Invalid image format. Only JPG, PNG, GIF, and WEBP are allowed.';
                        } else {
                            $upload_dir = __DIR__ . '/../../uploads/';
                            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                            
                            // Prevent directory traversal by sanitizing asset code
                            $safe_asset_code = preg_replace('/[^a-zA-Z0-9_-]/', '', $asset_code);
                            $new = 'uploads/' . date('Ymd_His') . '_' . uniqid() . '_' . $safe_asset_code . '.' . $ext;
                            
                            if (!move_uploaded_file($tmp_name, $upload_dir . basename($new))) {
                                $error = 'Upload failed due to server error.';
                            } else {
                                $img_path = '/' . $new;
                            }
                        }
                    }
                } elseif (!empty($_FILES['image']['name'])) {
                    $error = 'File upload error (Code: ' . $_FILES['image']['error'] . ').';
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
                
                $manual_override_alert = (isset($_POST['manual_override_alert']) && $_POST['manual_override_alert'] == '1');
                
                $fac = $conn->query("SELECT faculty_id FROM room_assignments WHERE room_id = " . (int)$asset['room_id']);
                while($f = $fac->fetch_assoc()) {
                    $msg = "⚠️ New Report: $n_asset_name in Room $n_room_no. Priority: $urgency_priority. Code ($asset_code)";
                    notify_user($conn, (int)$f['faculty_id'], $msg);
                    
                    if ($manual_override_alert) {
                        $override_msg = "🚨 COMPONENT MISMATCH ALERT: A student manually opted to report an issue on a child component ($asset_code) instead of its Parent Machine in Room $n_room_no. Please verify if the main machine is affected.";
                        notify_user($conn, (int)$f['faculty_id'], $override_msg);
                    }
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

// Fetch active CPUs/Machines in Labs for the Parent Selection
$cpu_query = "
    SELECT 
        a.asset_code, 
        an.name AS asset_name, 
        r.room_no
    FROM assets a
    JOIN asset_names an ON a.asset_name_id = an.id
    JOIN rooms r ON a.room_id = r.id
    WHERE r.room_type IN ('Lab', 'Laboratory')
      AND an.name LIKE '%CPU%'
      AND a.status != 'Retired'
    ORDER BY r.room_no ASC, a.asset_code ASC
";
$cpu_assets_res = $conn->query($cpu_query);

include __DIR__ . '/../partials/header.php';
?>

<!-- Add SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


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
        <input type="hidden" name="manual_override_alert" id="manual_override_alert" value="0">
        
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
                <select class="input-dark" name="cpu_id" id="cpu_id" style="appearance: none; -webkit-appearance: none; cursor: pointer;">
                    <option value="">Select Parent CPU</option>
                    <?php 
                    if ($cpu_assets_res && $cpu_assets_res->num_rows > 0) {
                        // Reset pointer just in case
                        $cpu_assets_res->data_seek(0);
                        while ($cpu = $cpu_assets_res->fetch_assoc()) {
                            $selected = (isset($_POST['cpu_id']) && $_POST['cpu_id'] == $cpu['asset_code']) ? 'selected' : '';
                            $display_text = htmlspecialchars($cpu['asset_code'] . ' - ' . $cpu['asset_name'] . ' [Room: ' . $cpu['room_no'] . ']');
                            echo "<option value=\"" . htmlspecialchars($cpu['asset_code']) . "\" {$selected}>{$display_text}</option>";
                        }
                    }
                    ?>
                </select>
                <i class="fa-solid fa-microchip"></i>
                <i class="fa-solid fa-chevron-down" style="left: auto; right: 16px;"></i>
            </div>
            <small style="color: rgba(255,255,255,0.5); display: block; margin-top: 5px;">
                Required only if reporting an independent component.
            </small>
        </div>
        
        <div style="margin-bottom: 15px;">
            <label style="color: #ccc; font-size: 0.9em; margin-bottom: 5px; display: block;" id="desc-label">Description</label>
            <div class="input-group">
                <textarea class="input-dark" name="description" id="description" rows="4" placeholder="Describe the issue..." required style="height: auto; padding-top: 12px; min-height: 100px; resize: vertical;"></textarea>
                <i class="fa-solid fa-align-left" style="top: 15px;"></i>
            </div>
        </div>

        <div style="margin-bottom: 25px;">
            <label style="color: #ccc; font-size: 0.9em; margin-bottom: 5px; display: block;">Image Proof <span style="color:#e74c3c">*</span></label>
            <div class="input-group" style="background: transparent; padding: 0; box-shadow: none; display: block;">
                <label for="image_upload" class="input-dark" style="display: flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer; padding: 16px; border: 2px dashed rgba(110, 168, 254, 0.4); text-align: center; color: #6ea8fe; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); border-radius: 12px; margin: 0; background: rgba(110, 168, 254, 0.05);">
                    <i class="fa-solid fa-camera" style="position: relative; left: auto; top: auto; transform: none; color: inherit; font-size: 1.1em;"></i>
                    <span id="upload_text" style="font-weight: 500;">Take Photo or Upload Image</span>
                </label>
                <input id="image_upload" type="file" name="image" accept="image/*" capture="environment" required style="display: none;" onchange="previewImage(this)">
            </div>
            
            <!-- Live Preview Container -->
            <div id="image_preview_container" style="display: none; margin-top: 16px; text-align: center; position: relative;">
                <img id="image_preview" src="" alt="Image Preview" style="max-width: 100%; max-height: 280px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 8px 24px rgba(0,0,0,0.4); object-fit: contain; background: #000;">
                <button type="button" onclick="clearImage()" style="position: absolute; top: -12px; right: -12px; background: #e74c3c; color: white; border: none; border-radius: 50%; width: 32px; height: 32px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(0,0,0,0.5); font-size: 14px; transition: transform 0.2s;">
                    <i class="fa-solid fa-times"></i>
                </button>
            </div>
        </div>

        <script>
        function previewImage(input) {
            const previewContainer = document.getElementById('image_preview_container');
            const previewImage = document.getElementById('image_preview');
            const uploadText = document.getElementById('upload_text');
            const uploadLabel = input.previousElementSibling;
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                
                // 50MB Limit Check
                if (file.size > 50 * 1024 * 1024) {
                    alert('File size exceeds 50MB limit. Please choose a smaller image.');
                    clearImage();
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    previewContainer.style.display = 'block';
                    uploadText.innerText = 'Change Photo';
                    uploadLabel.style.borderColor = '#2ecc71';
                    uploadLabel.style.color = '#2ecc71';
                    uploadLabel.style.background = 'rgba(46, 204, 113, 0.05)';
                    uploadLabel.querySelector('i').className = 'fa-solid fa-check-circle';
                }
                reader.readAsDataURL(file);
            } else {
                clearImage();
            }
        }

        function clearImage() {
            const input = document.getElementById('image_upload');
            const previewContainer = document.getElementById('image_preview_container');
            const previewImage = document.getElementById('image_preview');
            const uploadText = document.getElementById('upload_text');
            const uploadLabel = input.previousElementSibling;
            
            input.value = '';
            previewImage.src = '';
            previewContainer.style.display = 'none';
            uploadText.innerText = 'Take Photo or Upload Image';
            
            // Reset styles
            uploadLabel.style.borderColor = 'rgba(110, 168, 254, 0.4)';
            uploadLabel.style.color = '#6ea8fe';
            uploadLabel.style.background = 'rgba(110, 168, 254, 0.05)';
            uploadLabel.querySelector('i').className = 'fa-solid fa-camera';
        }
        </script>

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

</div>

<!-- Custom Confirm Modal -->
<div id="customConfirmModal" class="modal" style="display:none; align-items: center; justify-content: center; z-index: 1050;">
    <div class="glass-card modal-content" style="border: 1px solid rgba(110, 168, 254, 0.3); background: rgba(16, 21, 49, 0.95); max-width: 450px; padding: 25px; border-radius: 12px; box-shadow: 0 15px 40px rgba(0,0,0,0.6);">
        <h3 style="color: white; margin-bottom: 5px;"><i class="fa-solid fa-circle-question" style="color: #6ea8fe; margin-right: 8px;"></i> Verification Required</h3>
        <p id="customConfirmMessage" style="color: #ccc; font-size: 0.95em; line-height: 1.5; margin-bottom: 25px;">Message goes here</p>
        
        <div style="display: flex; gap: 15px; justify-content: center;">
            <button id="customConfirmNo" class="btn-login" style="background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.2); flex: 1; padding: 10px;">No, keep component</button>
            <button id="customConfirmYes" class="btn-login" style="flex: 1; padding: 10px;">Yes, report main PC</button>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>assets/js/offline-db.js"></script>
<script>
// Promise-based Custom Confirm (No longer used for parent check, but kept for potential future use or generic confirms)
function showConfirmModal(message) {
    return new Promise((resolve) => {
        const modal = document.getElementById('customConfirmModal');
        const msgEl = document.getElementById('customConfirmMessage');
        const btnYes = document.getElementById('customConfirmYes');
        const btnNo = document.getElementById('customConfirmNo');
        
        msgEl.innerText = message;
        modal.style.display = 'flex'; 
        
        const cleanup = () => {
            modal.style.display = 'none';
            btnYes.removeEventListener('click', onYes);
            btnNo.removeEventListener('click', onNo);
            window.removeEventListener('click', onOutsideClick);
        };
        
        const onYes = () => { cleanup(); resolve(true); };
        const onNo = () => { cleanup(); resolve(false); };
        const onOutsideClick = (e) => {
            if (e.target === modal) {
                cleanup(); resolve(false);
            }
        };
        
        btnYes.addEventListener('click', onYes);
        btnNo.addEventListener('click', onNo);
        window.addEventListener('click', onOutsideClick);
    });
}

const USER_ROLE = "<?php echo $_SESSION['user']['role']; ?>";

<?php if ($error === 'DUPLICATE_REPORT'): ?>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('duplicateModal').style.display = 'block';
});
<?php endif; ?>

document.addEventListener('DOMContentLoaded', async function() {
    const reportForm = document.getElementById('reportForm');
    const prefetchedParent = <?= $prefetched_parent_js ?>;
    
    // Autoplay scanned QR code if provided in URL
    const scannedQrId = "<?= htmlspecialchars($qr_id) ?>";
    const hasSuccessMessage = <?= !empty($ok) ? 'true' : 'false' ?>;
    const hasErrorMessage = <?= !empty($error) ? 'true' : 'false' ?>;
    
    // Only auto-trigger if there is a QR ID AND we did not just submit (success or error)
    if (scannedQrId && !hasSuccessMessage && !hasErrorMessage) {
        const input = document.getElementById('asset_code');
        input.value = scannedQrId;
        
        if (typeof verifyAssetCode === 'function') {
            verifyAssetCode(scannedQrId);
        }
    }

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
            
        } else {
            // Online
             if (offlineBar) offlineBar.remove();
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
            // Create visual option for the regular asset Match
            let div = document.createElement('div');
            div.className = 'dropdown-option';
            
            // Format descriptive identifier
            let parentText = asset.parent_code ? ` (Parent: ${asset.parent_code})` : '';
            div.innerHTML = `<strong>${asset.code} - ${asset.name} [Room ${asset.room_no}]${parentText}</strong> <small>Status: ${asset.status}</small>`;
            
            div.onclick = function() { selectAsset(asset.code); };
            dropdown.appendChild(div);
        });
        hasMatches = true;
    }

    // ALWAYS show Missing Sticker option
    const div = document.createElement('div');
    div.className = 'dropdown-option';
    div.innerHTML = `<strong style="color: #e74c3c;">MISSING_STICKER </strong> <small>Click here to report without a code</small>`;
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
    
    // Aggressively close the custom dropdown to prevent UI overlapping
    document.getElementById('custom-dropdown').classList.remove('show');

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
            // ALWAYS Autofill Room and Asset Name BEFORE showing the modal
            if (lastProcessedCode !== code) {
                isAutoFilling = true;
                lastProcessedCode = code; // Mark as processed

                const roomSelect = document.getElementById('room_id');
                const nameSelect = document.getElementById('asset_name_id');
                
                // Set Name first (from data.asset)
                if (data.asset && data.asset.asset_name_id) nameSelect.value = data.asset.asset_name_id;
                
                // Set Room (from data.asset)
                if (data.asset && data.asset.room_id) roomSelect.value = data.asset.room_id;
                
                isAutoFilling = false;
                
                // Trigger fetch to populate local arrays properly
                fetchRoomAssets().then(() => {
                    // Force close dropdown again just in case fetchRoomAssets re-opens it
                    document.getElementById('custom-dropdown').classList.remove('show');
                });
            }

            // ALWAYS Autofill the CPU ID if a parent exists
            const cpuContainer = document.getElementById('cpu-id-container');
            const cpuInput = document.getElementById('cpu_id');
            if (data.has_parent) {
                cpuContainer.style.display = 'block';
                cpuInput.required = true;
                // Auto-fill CPU ID with parent code!
                if (data.parent && data.parent.code) {
                    cpuInput.value = data.parent.code;
                }
            } else {
                cpuContainer.style.display = 'none';
                cpuInput.required = false;
                cpuInput.value = '';
            }

            // Initial Asset Verification Popup (Using SweetAlert2)
            if (!window.initialVerificationShown) {
                window.initialVerificationShown = true;
                
                const assetNameDisplay = data.asset.name || 'Unknown Asset';
                const roomDisplay = data.asset.room_no || 'Unknown Room';
                const conditionStr = data.asset.status || 'Good';
                
                // Color formatting based on condition
                let condColor = '#2ecc71';
                if (conditionStr === 'Needs Repair') condColor = '#e74c3c';
                else if (conditionStr === 'Under Maintenance') condColor = '#f39c12';
                else if (conditionStr === 'Missing') condColor = '#9b59b6';
                
                const htmlContent = `
                    <div style="text-align: left; background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px; margin-top: 10px;">
                        <p style="margin: 5px 0;"><strong>Code:</strong> <span style="color:#6ea8fe;">${code}</span></p>
                        <p style="margin: 5px 0;"><strong>Name:</strong> <span style="color:#fff;">${assetNameDisplay}</span></p>
                        <p style="margin: 5px 0;"><strong>Room:</strong> <span style="color:#ccc;">${roomDisplay}</span></p>
                        <p style="margin: 5px 0;"><strong>Condition:</strong> <span style="color:${condColor}; font-weight:bold;">${conditionStr}</span></p>
                    </div>
                `;
                
                const result = await Swal.fire({
                    title: 'Verify Asset Details',
                    html: htmlContent,
                    icon: 'info',
                    showCancelButton: true,
                    confirmButtonColor: '#6ea8fe',
                    cancelButtonColor: '#333',
                    confirmButtonText: 'Yes, report this asset',
                    cancelButtonText: 'Cancel',
                    background: '#101531',
                    color: '#fff',
                    customClass: {
                        popup: 'glass-card border-glow',
                        title: 'swal-title-white'
                    }
                });

                if (!result.isConfirmed) {
                    // User Cancelled - Clear fields and abort
                    resetAutoFill('all');
                    document.getElementById('asset_code').value = '';
                    window.initialVerificationShown = false; // Reset flag to allow rescanning
                    return; 
                }
            }


            // Positional Tracking: Ask which CPU the child is currently connected to
            if (data.has_parent && !window.overrideAlertShown) {
                window.overrideAlertShown = true;
                
                // 1. Scrape existing CPU options from the DOM dropdown
                const cpuSelectEl = document.getElementById('cpu_id');
                let cpuOptions = {};
                Array.from(cpuSelectEl.options).forEach(opt => {
                    if (opt.value) { // Skip empty placeholder
                        cpuOptions[opt.value] = opt.text;
                    }
                });

                // 2. Fire SweetAlert Dropdown
                const { value: selectedCpu } = await Swal.fire({
                    title: 'Current Connection',
                    html: `This component is normally connected to <strong>${data.parent.code}</strong>.<br><br>Which CPU is it plugged into right now?`,
                    icon: 'question',
                    input: 'select',
                    inputOptions: cpuOptions,
                    inputPlaceholder: 'Select Current CPU',
                    inputValue: data.parent.code || '', // Pre-select assigned parent if exists
                    showCancelButton: true,
                    confirmButtonColor: '#6ea8fe',
                    cancelButtonColor: '#333',
                    confirmButtonText: 'Confirm Location',
                    background: '#101531',
                    color: '#fff',
                    didOpen: () => {
                        const selectEl = Swal.getInput();
                        if (selectEl) {
                            selectEl.style.cssText += 'margin: 15px auto !important; display: block !important; width: 85% !important; padding: 10px 35px 10px 10px !important; text-align: center; background-position: right 10px center; appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'white\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3E%3Cpolyline points=\'6 9 12 15 18 9\'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat;';
                        }
                    },
                    customClass: {
                        popup: 'glass-card border-glow',
                        title: 'swal-title-white',
                        input: 'input-dark'
                    },
                    inputValidator: (value) => {
                        return new Promise((resolve) => {
                            if (value) {
                                resolve();
                            } else {
                                resolve('You must select the currently connected CPU');
                            }
                        });
                    }
                });

                if (selectedCpu) {
                    // Update CPU ID field with their real-time selection
                    cpuSelectEl.value = selectedCpu;
                    
                    // DO NOT lock the main asset code, this is reporting the component itself!
                    document.getElementById('manual_override_alert').value = '1'; // Flag that this is a component report
                } else {
                    // Cancelled parent physical selection - abort reporting entirely
                    resetAutoFill('all');
                    document.getElementById('asset_code').value = '';
                    window.initialVerificationShown = false; 
                    window.overrideAlertShown = false;
                    return;
                }
            }

            // (Handled above, removed redundant logic)
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
    
    window.overrideAlertShown = false;
    window.initialVerificationShown = false; // Reset the SweetAlert verification flag
    document.getElementById('manual_override_alert').value = '0';
    document.getElementById('asset_code').classList.remove('highlight-locked');
    document.getElementById('asset_code').readOnly = false;
    
    // Clear the input and dropdown
    const input = document.getElementById('asset_code');
    input.value = '';
    
    // If user changed Room, clear Asset Name to force re-selection (optional, 
    // but better UX so they don't think they are scanning for old room)
    if (source === 'room') {
        document.getElementById('asset_name_id').value = '';
    }
    
    document.getElementById('cpu-id-container').style.display = 'none';
    const cpuInput = document.getElementById('cpu_id');
    cpuInput.required = false;
    cpuInput.value = '';
    cpuInput.readOnly = false;
    cpuInput.classList.remove('highlight-locked');
    document.getElementById('desc-label').innerHTML = 'Description';
    
    // Auto-fill trigger if both dropdowns are selected
    const roomVal = document.getElementById('room_id').value;
    const nameVal = document.getElementById('asset_name_id').value;
    if (roomVal && nameVal) {
        fetchRoomAssets();
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
