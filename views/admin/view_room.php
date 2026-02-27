<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../config/room_utils.php';
ensure_role('admin');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    set_flash('err', 'Invalid Room ID');
    header('Location: ' . BASE_URL . 'views/admin/rooms.php');
    exit;
}

// Handle Exam Eligibility Toggle POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_eligibility'])) {
    if (!verify_csrf()) die('CSRF validation failed');
    
    $room_id = (int)$_POST['room_id'];
    $new_status = (int)$_POST['is_exam_eligible'];
    
    try {
        $stmt = $conn->prepare("UPDATE rooms SET is_exam_eligible = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_status, $room_id);
        $stmt->execute();
        
        // Auto-sync the modified room as well as recalculate exam ready statuses broadly if necessary
        syncExamReadyStatus($conn, $room_id);
        syncAllExamReadyStatuses($conn); // Force auto-sync entire table when toggling
        
        set_flash('ok', 'Exam eligibility updated successfully. Statuses Synced.');
    } catch (Exception $e) {
        set_flash('err', 'Error updating eligibility: ' . $e->getMessage());
    }
    
    header("Location: view_room.php?id=$id");
    exit;
}

// Handle Faculty Assignment POST Request (In-Page Workflow)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_faculty_id'])) {
    if (!verify_csrf()) die('CSRF validation failed');
    
    $room_id = (int)$_POST['assign_room_id'];
    $faculty_id = (int)$_POST['assign_faculty_id'];
    
    if ($room_id && $faculty_id) {
        try {
            // Check if there's already an assignment for this room
            $check = $conn->prepare("SELECT id FROM room_assignments WHERE room_id = ?");
            $check->bind_param("i", $room_id);
            $check->execute();
            $result = $check->get_result();

            if ($result->num_rows > 0) {
                // Update Existing Assignment
                $stmt = $conn->prepare("UPDATE room_assignments SET faculty_id = ? WHERE room_id = ?");
                $stmt->bind_param("ii", $faculty_id, $room_id);
                $stmt->execute();
                set_flash('ok', 'Faculty reassigned successfully!');
            } else {
                // Insert New Assignment
                $stmt = $conn->prepare("INSERT INTO room_assignments (room_id, faculty_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $room_id, $faculty_id);
                $stmt->execute();
                set_flash('ok', 'Faculty assigned successfully!');
            }
        } catch (Exception $e) {
            set_flash('err', 'Error updating assignment: ' . $e->getMessage());
        }
    } else {
        set_flash('err', 'Please select a valid Room and Faculty member.');
    }
    
    header("Location: view_room.php?id=$id");
    exit;
}

// Fetch existing room details
$stmt = $conn->prepare("SELECT * FROM rooms WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();

if (!$room) {
    set_flash('err', 'Room not found');
    header('Location: ' . BASE_URL . 'views/admin/rooms.php');
    exit;
}

// Fetch current Assignment Data
$assign_stmt = $conn->prepare("
    SELECT ra.faculty_id, u.name as assigned_faculty_name 
    FROM room_assignments ra 
    JOIN users u ON u.id = ra.faculty_id 
    WHERE ra.room_id = ?
");
$assign_stmt->bind_param("i", $id);
$assign_stmt->execute();
$current_assignment = $assign_stmt->get_result()->fetch_assoc();

// Fetch Active Faculty List for Assignment Modal
$facultyQuery = $conn->query("SELECT id, name FROM users WHERE role='faculty' AND is_verified=1 ORDER BY name ASC");
$facultyList = $facultyQuery->fetch_all(MYSQLI_ASSOC);

// Fetch assets assigned to this room
$assets_stmt = $conn->prepare("
    SELECT 
        a.id, 
        a.asset_code, 
        a.status, 
        an.name as asset_name, 
        c.name as category_name
    FROM assets a
    JOIN asset_names an ON a.asset_name_id = an.id
    LEFT JOIN categories c ON a.category_id = c.id
    WHERE a.room_id = ?
    ORDER BY c.name ASC, an.name ASC
");
$assets_stmt->bind_param("i", $id);
$assets_stmt->execute();
$room_assets = $assets_stmt->get_result();

include __DIR__.'/../partials/header.php';
?>

<div class="main-content">
    
    <!-- Header / Breadcrumb -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div style="display: flex; gap: 16px; align-items: center;">
            <a href="<?= BASE_URL ?>views/admin/rooms.php" class="btn outline small">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <h2 style="margin: 0; color: #fff; font-size: 24px;">Room Details</h2>
        </div>
        
        <div style="display: flex; gap: 12px; align-items: center;">
            <!-- Assign Faculty Dynamic Header Button (Triggers JS Modal) -->
            <?php if ($current_assignment): ?>
                <button type="button" class="btn small" style="background: rgba(46, 204, 113, 0.15); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.3);"
                    data-id="<?= $room['id'] ?>"
                    data-room="<?= htmlspecialchars($room['building'].'/'.$room['floor'].'/'.$room['room_no']) ?>"
                    data-curr-fac="<?= htmlspecialchars($current_assignment['faculty_id'] ?? '') ?>"
                    onclick="openAssignModal(this)">
                    <i class="fas fa-chalkboard-teacher"></i> Assigned: <?= htmlspecialchars($current_assignment['assigned_faculty_name']) ?>
                </button>
            <?php else: ?>
                <button type="button" class="btn small" style="background: rgba(241, 196, 15, 0.15); color: #f1c40f; border: 1px solid rgba(241, 196, 15, 0.3);"
                    data-id="<?= $room['id'] ?>"
                    data-room="<?= htmlspecialchars($room['building'].'/'.$room['floor'].'/'.$room['room_no']) ?>"
                    data-curr-fac=""
                    onclick="openAssignModal(this)">
                    <i class="fas fa-user-plus"></i> Assign Faculty
                </button>
            <?php endif; ?>

            <!-- Edit Button -->
            <a href="<?= BASE_URL ?>views/admin/forms/edit_room.php?id=<?= $room['id'] ?>" class="btn small" style="background: #3498db; color: #fff;">
                <i class="fas fa-edit"></i> Edit Room
            </a>
        </div>
    </div>

    <?php if ($m = flash('ok')): ?><div class="alert success"><?= htmlspecialchars($m) ?></div><?php endif; ?>
    <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>

    <!-- Main Room Detail Card -->
    <div class="card" style="margin-bottom: 24px; padding: 32px;">
        <div class="grid cols-2" style="gap: 24px;">
            
            <!-- Left Info Pane -->
            <div>
                <h3 style="color: var(--text); border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 12px; margin-bottom: 20px;">
                    <i class="fas fa-door-open" style="color: #3498db; margin-right: 8px;"></i>
                    <?= htmlspecialchars($room['room_no']) ?> Information
                </h3>
                
                <table style="width: 100%; border-collapse: collapse;">
                    <tbody>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <td style="padding: 12px 0; color: var(--muted); width: 40%;">Building</td>
                            <td style="padding: 12px 0; color: var(--text); font-weight: 500;"><?= htmlspecialchars($room['building']) ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <td style="padding: 12px 0; color: var(--muted);">Floor</td>
                            <td style="padding: 12px 0; color: var(--text); font-weight: 500;"><?= htmlspecialchars($room['floor']) ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <td style="padding: 12px 0; color: var(--muted);">Type</td>
                            <td style="padding: 12px 0; color: var(--text); font-weight: 500;"><?= htmlspecialchars($room['room_type']) ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <td style="padding: 12px 0; color: var(--muted);">Capacity</td>
                            <td style="padding: 12px 0; color: var(--text); font-weight: 500;"><?= $room['capacity'] ? (int)$room['capacity'] . ' seats' : '-' ?></td>
                        </tr>
                        <tr>
                            <td style="padding: 12px 0; color: var(--muted);">Notes</td>
                            <td style="padding: 12px 0; color: var(--text); font-weight: 500;"><?= htmlspecialchars($room['notes'] ?: 'None') ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Right Exam Settings Pane -->
            <div>
                <h3 style="color: var(--text); border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 12px; margin-bottom: 20px;">
                    <i class="fas fa-tasks" style="color: #2ecc71; margin-right: 8px;"></i>
                    Exam Settings
                </h3>
                
                <div style="background: rgba(46, 204, 113, 0.05); border: 1px solid rgba(46, 204, 113, 0.2); padding: 24px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 1.1rem; margin-bottom: 16px; color: var(--text);">
                        Is this room eligible to host exams?
                    </div>
                    
                    <!-- On/Off Toggle Form -->
                    <form method="POST">
                        <?= get_csrf_input() ?>
                        <input type="hidden" name="toggle_eligibility" value="1">
                        <input type="hidden" name="room_id" value="<?= $room['id'] ?>">
                        <?php $is_eligible = isset($room['is_exam_eligible']) ? (int)$room['is_exam_eligible'] : 0; ?>
                        <input type="hidden" name="is_exam_eligible" value="<?= $is_eligible ? 0 : 1 ?>">
                        
                        <button type="submit" class="btn" style="background: <?= $is_eligible ? '#2ecc71' : '#e74c3c' ?>; color: #fff; padding: 12px 32px; font-size: 1.1rem; border-radius: 30px;">
                            <i class="fas <?= $is_eligible ? 'fa-toggle-on' : 'fa-toggle-off' ?>" style="font-size: 1.5rem; vertical-align: middle; margin-right: 8px;"></i>
                            <?= $is_eligible ? 'Enabled' : 'Disabled' ?>
                        </button>
                    </form>
                    
                    <div style="margin-top: 16px; color: var(--muted); font-size: 0.9rem;">
                        <?php if ($is_eligible): ?>
                            This room will be included in the automated Exam Readiness Check.
                        <?php else: ?>
                            This room is excluded from exam allocations.
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Helper message for non-exam types -->
                <?php if (!in_array(strtolower($room['room_type']), ['classroom', 'lab', 'laboratory'])): ?>
                <div style="margin-top: 16px; font-size: 0.85rem; color: #e67e22;">
                    <i class="fas fa-info-circle"></i> Only Classrooms and Labs are typically evaluated for exam readiness.
                </div>
                <?php endif; ?>
                
            </div>
            
        </div>
    </div>

    <!-- Assigned Assets Card -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="table-header-row" style="padding: 24px; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: space-between;">
            <h3 class="card-title" style="margin: 0; display: flex; align-items: center; gap: 8px; color: var(--text);">
                <i class="fas fa-boxes" style="color: #f39c12;"></i> 
                Assigned Assets (<?= $room_assets->num_rows ?>)
            </h3>
        </div>
        
        <?php if ($room_assets->num_rows > 0): ?>
            <div class="table-scroll">
                <table class="table">
                    <thead>
                        <tr>
                            <th>View</th>
                            <th>Asset Code</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($asset = $room_assets->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <a href="<?= BASE_URL ?>views/admin/view_asset.php?id=<?= $asset['id'] ?>" class="btn small outline" style="color: #3498db; border-color: #3498db;" title="View Asset Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                                <td style="font-weight: 500; font-family: monospace; color: #3498db;"><?= htmlspecialchars($asset['asset_code']) ?></td>
                                <td><?= htmlspecialchars($asset['asset_name']) ?></td>
                                <td><?= htmlspecialchars($asset['category_name'] ?? 'Uncategorized') ?></td>
                                <td>
                                    <?php 
                                    $status = $asset['status'];
                                    $badge_class = 'neutral';
                                    if ($status === 'Working') $badge_class = 'good';
                                    elseif ($status === 'Needs Repair') $badge_class = 'bad';
                                    elseif ($status === 'In Repair' || $status === 'Maintenance') $badge_class = 'warning';
                                    ?>
                                    <span class="badge <?= $badge_class ?>"><?= htmlspecialchars($status) ?></span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="padding: 40px; text-align: center; color: var(--muted);">
                <i class="fas fa-box-open" style="font-size: 3rem; opacity: 0.3; margin-bottom: 16px; display: block;"></i>
                <p style="margin: 0; font-size: 1.1rem;">No assets are currently assigned to this room.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Assign Faculty Modal (Glassmorphism Overlay) -->
<div id="assignFacultyModal" class="modal" style="display:none; justify-content: center; align-items: center; z-index: 9999;">
    <div class="glass-card modal-content" style="max-width: 450px; width: 100%; border: 1px solid #f1c40f;">
        <h3 style="color: #f1c40f; margin-bottom: 15px;"><i class="fas fa-user-plus"></i> Assign Faculty</h3>
        
        <div style="margin-bottom: 20px; font-size: 14px; color: #8fa0c9;">
            Assigning Role for Active Room: <strong style="color: #fff;" id="assign_room_display"></strong>
        </div>

        <form id="assignFacultyForm" method="POST">
            <?= get_csrf_input() ?>
            <input type="hidden" name="assign_room_id" id="assign_room_id">
            
            <div class="input-group" style="margin-bottom: 25px;">
                <select name="assign_faculty_id" id="assign_faculty_select" class="input-dark" required style="width: 100%; appearance: none;">
                    <option value="">-- Select Faculty Member --</option>
                    <?php foreach ($facultyList as $fac): ?>
                        <option value="<?= $fac['id'] ?>"><?= htmlspecialchars($fac['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="fas fa-chalkboard-teacher"></i>
            </div>
            
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn-login" style="flex: 1; background-color: #f1c40f; color: #131a2b;"><i class="fas fa-save"></i> Save Assignment</button>
                <button type="button" onclick="closeAssignModal()" class="btn-login outline" style="flex: 1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAssignModal(button) {
    // Extract row data from DOM attributes
    const roomId = button.getAttribute('data-id');
    const roomPath = button.getAttribute('data-room');
    const currFacId = button.getAttribute('data-curr-fac');

    // Bind DOM values into explicitly targeted Modal scope fields
    document.getElementById('assign_room_id').value = roomId;
    document.getElementById('assign_room_display').innerText = roomPath;
    
    // Set current faculty selection if existing, else default blank
    document.getElementById('assign_faculty_select').value = currFacId || "";

    // Launch UI Modal scope rendering outside standard DOM body locks
    const modal = document.getElementById('assignFacultyModal');
    if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }
    
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden'; // Lock vertical scrolling
}

function closeAssignModal() {
    document.getElementById('assignFacultyModal').style.display = 'none';
    document.body.style.overflow = ''; // Unlock vertical scrolling
}
</script>

<?php include __DIR__.'/../partials/footer.php'; ?>
