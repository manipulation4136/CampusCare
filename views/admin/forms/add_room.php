<?php
require_once __DIR__ . '/../../../config/init.php';
require_once __DIR__ . '/../../../config/asset_helper.php'; // For addExamRoom if needed, though mostly handled inline in original
ensure_role('admin');

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');
    
    $building = trim($_POST['building'] ?? '');
    $floor = trim($_POST['floor'] ?? '');
    $room_no = trim($_POST['room_no'] ?? '');
    $room_type = trim($_POST['room_type'] ?? 'classroom');
    $capacity = !empty($_POST['capacity']) ? (int)$_POST['capacity'] : null;
    $notes = trim($_POST['notes'] ?? '');

    if ($building && $room_no && $room_type) {
        try {
            $conn->begin_transaction();

            // Insert into rooms table
            $stmt = $conn->prepare("INSERT INTO rooms(building, floor, room_no, room_type, capacity, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssis", $building, $floor, $room_no, $room_type, $capacity, $notes);
            $stmt->execute();
            
            $room_id = $conn->insert_id;
            
            // If it's a classroom or lab, add to exam_rooms table
            // Reusing the logic from original rooms.php, assuming addExamRoom is available or implementing inline
            if (function_exists('addExamRoom')) {
                if (in_array(strtolower($room_type), ['classroom', 'lab', 'laboratory'])) {
                    addExamRoom($conn, $room_id, 'Yes');
                }
            } else {
                 // Fallback if helper not loaded or function missing (but it should be in asset_helper)
                 if (in_array(strtolower($room_type), ['classroom', 'lab', 'laboratory'])) {
                    $stmt_exam = $conn->prepare("INSERT INTO exam_rooms (room_id, status_exam_ready) VALUES (?, 'Yes')");
                    $stmt_exam->bind_param("i", $room_id);
                    $stmt_exam->execute();
                 }
            }

            $conn->commit();
            set_flash('ok', 'Room added successfully');
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
        <h2 class="card-title">Add New Room</h2>

        <?php if ($m = flash('ok')): ?><div class="alert success"><?= htmlspecialchars($m) ?></div><?php endif; ?>
        <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>
        
        <form method="post" class="grid cols-2">
            <?= get_csrf_input() ?>
            
            <div>
                <label>Building <span style="color: #e74c3c;">*</span></label>
                <input class="input" name="building" required placeholder="e.g. Science Block">
            </div>
            
            <div>
                <label>Floor</label>
                <input class="input" name="floor" placeholder="e.g. 1st Floor">
            </div>
            
            <div>
                <label>Room No <span style="color: #e74c3c;">*</span></label>
                <input class="input" name="room_no" required placeholder="e.g. 101">
            </div>
            
            <div>
                <label>Room Type <span style="color: #e74c3c;">*</span></label>
                <select class="input" name="room_type" required>
                    <option value="">Select Type</option>
                    <option value="classroom">Classroom</option>
                    <option value="lab">Laboratory</option>
                    <option value="library">Library</option>
                    <option value="toilet">Toilet</option>
                    <option value="office">Office</option>
                </select>
            </div>
            
            <div>
                <label>Capacity</label>
                <input class="input" name="capacity" type="number" min="1" placeholder="e.g. 60">
            </div>
            
            <div>
                <label>Notes</label>
                <input class="input" name="notes" type="text" placeholder="Equipment, special features, etc.">
            </div>
            
            <div class="col-span-full" style="text-align: center; margin-top: 16px;">
                <button class="btn" style="min-width: 200px;">
                    <i class="fas fa-plus"></i> Add Room
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
