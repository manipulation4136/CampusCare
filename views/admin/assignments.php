<?php
require_once __DIR__ . '/../../config/init.php';
ensure_role('admin');

// Handle Post Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');

    // 1. Delete Assignment
    if (isset($_POST['delete_id'])) {
        $del_id = (int)$_POST['delete_id'];
        $stmt = $conn->prepare("DELETE FROM room_assignments WHERE id = ?");
        $stmt->bind_param("i", $del_id);
        
        if ($stmt->execute()) {
            set_flash('ok', 'Assignment removed successfully');
        } else {
            set_flash('err', 'Failed to remove assignment');
        }
        
        // Refresh to avoid resubmission
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } 
    // 2. Add Assignment
    else {
        $room_id = (int)($_POST['room_id'] ?? 0);
        $faculty_id = (int)($_POST['faculty_id'] ?? 0);
        
        if ($room_id && $faculty_id) {
            $stmt = $conn->prepare("INSERT INTO room_assignments(room_id, faculty_id) VALUES (?,?)");
            $stmt->bind_param("ii", $room_id, $faculty_id);
            try {
                $stmt->execute();
                set_flash('ok', 'Assigned successfully');
            } catch (Exception $e) {
                set_flash('err', $e->getMessage());
            }
        } else {
            set_flash('err', 'Select both room and faculty');
        }
    }
}

$rooms = $conn->query("SELECT id, building, floor, room_no FROM rooms ORDER BY building, floor, room_no");
$faculty = $conn->query("SELECT id, name FROM users WHERE role='faculty' ORDER BY name");
$assign = $conn->query("SELECT ra.id, r.building, r.floor, r.room_no, u.name FROM room_assignments ra JOIN rooms r ON r.id=ra.room_id JOIN users u ON u.id=ra.faculty_id ORDER BY r.building, r.room_no");

include __DIR__ . '/../partials/header.php';
?>

<div class="card">
    <h3 class="card-title">Room Assignments</h3>
    <?php if ($m = flash('ok')): ?><div class="alert success"><?= htmlspecialchars($m) ?></div><?php endif; ?>
    <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>
    
    <form method="post" class="grid cols-3">
        <?= get_csrf_input() ?>
        <div>
            <label>Room</label>
            <select class="input" name="room_id" required>
                <option value="">Select Room</option>
                <?php $rooms->data_seek(0); while($r = $rooms->fetch_assoc()): ?>
                    <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['building'] . '/' . $r['floor'] . '/' . $r['room_no']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div>
            <label>Faculty</label>
            <select class="input" name="faculty_id" required>
                <option value="">Select Faculty</option>
                <?php $faculty->data_seek(0); while($f = $faculty->fetch_assoc()): ?>
                    <option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="actions" style="text-align: center;">
            <button class="btn">Assign</button>
        </div>
    </form>
</div>
<br>

<div class="table-card">
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th>Room</th>
                    <th>Faculty</th>
                    <th style="width: 80px; text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($assign->num_rows > 0): ?>
                    <?php while($a = $assign->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($a['room_no']) ?></td>
                        <td><?= htmlspecialchars($a['name']) ?></td>
                        <td style="text-align: center;">
                            <form method="post" style="display:inline;">
                                <?= get_csrf_input() ?>
                                <input type="hidden" name="delete_id" value="<?= $a['id'] ?>">
                                <button class="btn icon-btn" style="color: #e74c3c;" onclick="return confirm('Are you sure you want to remove this assignment?');" title="Remove Assignment">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" style="text-align: center; padding: 20px; color: #8fa0c9;">No assignments found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
