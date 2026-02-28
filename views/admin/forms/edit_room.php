<?php
require_once __DIR__ . '/../../../config/init.php';
require_once __DIR__ . '/../../../config/room_utils.php'; 
ensure_role('admin');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    set_flash('err', 'Invalid Room ID');
    header('Location: ' . BASE_URL . 'views/admin/rooms.php');
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

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');
    
    $building = trim($_POST['building'] ?? '');
    $floor = trim($_POST['floor'] ?? '');
    $room_no = trim($_POST['room_no'] ?? '');
    $room_type = trim($_POST['room_type'] ?? 'classroom');
    $capacity = !empty($_POST['capacity']) ? (int)$_POST['capacity'] : null;
    $notes = trim($_POST['notes'] ?? '');
    
    // Retain exam eligibility status depending on room_type change
    $stmt_eligibility = $conn->prepare("SELECT is_exam_eligible FROM rooms WHERE id = ?");
    $stmt_eligibility->bind_param("i", $id);
    $stmt_eligibility->execute();
    $eligibility_row = $stmt_eligibility->get_result()->fetch_assoc();
    $is_exam_eligible = $eligibility_row ? (int)$eligibility_row['is_exam_eligible'] : 0;

    if ($building && $room_no && $room_type) {
        try {
            $conn->begin_transaction();

            // Update rooms table
            $stmt = $conn->prepare("UPDATE rooms SET building = ?, floor = ?, room_no = ?, room_type = ?, capacity = ?, notes = ?, is_exam_eligible = ? WHERE id = ?");
            // If they change it to non-exam type, forcefully turn off eligibility
            if (!in_array(strtolower($room_type), ['classroom', 'lab', 'laboratory'])) {
                $is_exam_eligible = 0;
            }
            $stmt->bind_param("ssssisii", $building, $floor, $room_no, $room_type, $capacity, $notes, $is_exam_eligible, $id);
            $stmt->execute();
            
            // Sync Exam Room Status logic
            if (in_array(strtolower($room_type), ['classroom', 'lab', 'laboratory'])) {
                syncExamReadyStatus($conn, $id);
                syncAllExamReadyStatuses($conn);
            } else {
                // If it was changed to a non-exam type, remove from exam_rooms
                removeExamRoom($conn, $id);
                syncAllExamReadyStatuses($conn);
            }

            $conn->commit();
            set_flash('ok', 'Room updated successfully');
            header('Location: ' . BASE_URL . 'views/admin/rooms.php');
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            set_flash('err', $e->getMessage());
        }
    } else {
        set_flash('err', 'Building, Room No, and Room Type required');
    }
}

include __DIR__ . '/../../partials/header.php';
?>

<div class="main-content">
    <!-- Back Button -->
    <div style="margin-bottom: 24px;">
        <a href="<?= BASE_URL ?>views/admin/rooms.php" class="btn outline small">
            <i class="fas fa-arrow-left"></i> Back to Rooms
        </a>
    </div>

    <!-- Form Card -->
    <div class="card" style="max-width: 800px; margin: 0 auto;">
        <h2 class="card-title">Edit Room</h2>

        <?php if ($m = flash('ok')): ?><div class="alert success"><?= htmlspecialchars($m) ?></div><?php endif; ?>
        <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>
        
        <form method="post" class="grid cols-2">
            <?= get_csrf_input() ?>
            
            <div>
                <label>Building <span style="color: #e74c3c;">*</span></label>
                <input class="input" name="building" required value="<?= htmlspecialchars($room['building']) ?>">
            </div>
            
            <div>
                <select class="input" name="floor">
                    <option value="">Select Floor</option>
                    <?php
                    for ($f = 1; $f <= 20; $f++) {
                        $selected = ((string)$room['floor'] === (string)$f) ? 'selected' : '';
                        echo '<option value="' . $f . '" ' . $selected . '>' . $f . '</option>';
                    }
                    ?>
                </select>
            </div>
            
            <div>
                <label>Room No <span style="color: #e74c3c;">*</span></label>
                <select class="input" name="room_no" required>
                    <option value="">Select Room No</option>
                    <?php
                    $room_options = [];
                    $prefixes = ['', 'A-', 'B-', 'C-'];
                    for ($f = 1; $f <= 20; $f++) {
                        for ($i = 0; $i <= 15; $i++) {
                            // Example: Floor 1, i = 0 => 100. Floor 2, i = 5 => 205.
                            $num = $f . sprintf('%02d', $i);
                            foreach ($prefixes as $p) {
                                $room_options[] = $p . $num;
                            }
                        }
                    }
                    $specials = ['LAB-01', 'LAB-02', 'LIB-01', 'AUD-01', 'CONF-01', 'STAFF-01', 'OFFICE-01'];
                    $room_options = array_merge($room_options, $specials);
                    
                    // Always ensure current room_no is in the list
                    $current_room = $room['room_no'];
                    if (!in_array($current_room, $room_options)) {
                        $room_options[] = $current_room;
                    }
                    
                    sort($room_options);
                    
                    foreach($room_options as $rn) {
                        $selected = (strcasecmp($current_room, $rn) === 0) ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($rn) . '" ' . $selected . '>' . htmlspecialchars($rn) . '</option>';
                    }
                    ?>
                </select>
            </div>
            
            <div>
                <label>Room Type <span style="color: #e74c3c;">*</span></label>
                <select class="input" name="room_type" required>
                    <?php 
                        $types = ['Classroom', 'Laboratory', 'Library', 'Staff Room', 'Seminar Hall', 'Auditorium', 'Boys Restroom', 'Girls Restroom', 'Staff Restroom', 'Store Room', 'Server Room', 'Office'];
                        foreach ($types as $type) {
                            $selected = (strcasecmp($room['room_type'], $type) === 0) ? 'selected' : '';
                            echo "<option value=\"$type\" $selected>$type</option>";
                        }
                    ?>
                </select>
            </div>
            
            <div>
                <label>Capacity</label>
                <input class="input" name="capacity" type="number" min="1" value="<?= htmlspecialchars((string)$room['capacity']) ?>">
            </div>
            
            <div>
                <label>Notes</label>
                <input class="input" name="notes" type="text" value="<?= htmlspecialchars((string)$room['notes']) ?>">
            </div>
            
            <div class="col-span-full" style="text-align: center; margin-top: 16px;">
                <button class="btn" style="min-width: 200px;">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
