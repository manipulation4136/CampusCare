<?php
require_once __DIR__ . '/../../../config/init.php';
require_once __DIR__ . '/../../../config/asset_helper.php'; // For addExamRoom if needed, though mostly handled inline in original
ensure_role('admin');

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');
    
    $building = trim($_POST['building'] ?? '');
    $floor = trim($_POST['floor'] ?? '');
    $room_no = trim(strtoupper($_POST['room_no'] ?? ''));
    $room_type = trim($_POST['room_type'] ?? 'classroom');
    $capacity = !empty($_POST['capacity']) ? (int)$_POST['capacity'] : null;
    $notes = trim($_POST['notes'] ?? '');

    // Strict Backend Validation
    $valid_buildings = ['Main', 'Science Block', 'Arts Block', 'Library', 'Hostel A', 'Hostel B', 'Sports Complex', 'Admin Block'];
    $valid_floors = ['0', '1', '2', '3', '4'];

    if (!in_array($building, $valid_buildings)) {
        set_flash('err', 'Invalid Building selected.');
    } elseif (!in_array($floor, $valid_floors) && $floor !== '') {
        set_flash('err', 'Invalid Floor selected.');
    } elseif (!preg_match('/^[a-zA-Z0-9-]+$/', $room_no)) {
        set_flash('err', 'Room No can only contain letters, numbers, and hyphens.');
    } elseif ($building && $room_no && $room_type) {
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
        if (!flash('err')) { // Only set if not already caught by validation above
            set_flash('err', 'Building, Room No, and Room Type required');
        }
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
                <select class="input" name="building" required>
                    <option value="">Select Building</option>
                    <?php
                    $buildings = ['Main', 'Science Block', 'Arts Block', 'Library', 'Hostel A', 'Hostel B', 'Sports Complex', 'Admin Block'];
                    foreach($buildings as $b) {
                        echo '<option value="' . htmlspecialchars($b) . '">' . htmlspecialchars($b) . '</option>';
                    }
                    ?>
                </select>
            </div>
            
            <div>
                <label>Floor <span style="color: #e74c3c;">*</span></label>
                <select class="input" name="floor" required>
                    <option value="">Select Floor</option>
                    <?php
                    $floors = ['0', '1', '2', '3', '4'];
                    foreach($floors as $f) {
                        echo '<option value="' . htmlspecialchars($f) . '">' . htmlspecialchars($f) . '</option>';
                    }
                    ?>
                </select>
            </div>
            
            <div>
                <label>Room No <span style="color: #e74c3c;">*</span></label>
                <input class="input" name="room_no" required pattern="[A-Za-z0-9-]+" title="Only letters, numbers, and hyphens allowed (e.g., 101, A-102)" placeholder="e.g. 101 or A-102" oninput="this.value = this.value.toUpperCase()">
            </div>
            
            <div>
                <label>Room Type <span style="color: #e74c3c;">*</span></label>
                <select class="input" name="room_type" required>
                    <option value="">Select Type</option>
                    <option value="Classroom">Classroom</option>
                    <option value="Laboratory">Laboratory</option>
                    <option value="Library">Library</option>
                    <option value="Staff Room">Staff Room</option>
                    <option value="Seminar Hall">Seminar Hall</option>
                    <option value="Auditorium">Auditorium</option>
                    <option value="Boys Restroom">Boys Restroom</option>
                    <option value="Girls Restroom">Girls Restroom</option>
                    <option value="Staff Restroom">Staff Restroom</option>
                    <option value="Store Room">Store Room</option>
                    <option value="Server Room">Server Room</option>
                    <option value="Office">Office</option>
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
