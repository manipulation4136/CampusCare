<?php
require_once __DIR__ . '/../../../config/init.php';
ensure_role('admin');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    set_flash('err', 'Invalid assignment ID.');
    header("Location: " . BASE_URL . "views/admin/assignments.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');
    
    $room_id = (int)($_POST['room_id'] ?? 0);
    $faculty_id = (int)($_POST['faculty_id'] ?? 0);
    
    if ($room_id && $faculty_id) {
        $stmt = $conn->prepare("UPDATE room_assignments SET room_id = ?, faculty_id = ? WHERE id = ?");
        $stmt->bind_param("iii", $room_id, $faculty_id, $id);
        
        if ($stmt->execute()) {
            set_flash('ok', 'Assignment updated successfully.');
            header("Location: " . BASE_URL . "views/admin/assignments.php");
            exit;
        } else {
            set_flash('err', 'Failed to update assignment.');
        }
    } else {
        set_flash('err', 'Select both room and faculty.');
    }
}

$assignment = $conn->query("SELECT * FROM room_assignments WHERE id = $id")->fetch_assoc();
if (!$assignment) {
    set_flash('err', 'Assignment not found.');
    header("Location: " . BASE_URL . "views/admin/assignments.php");
    exit;
}

$rooms = $conn->query("SELECT id, building, floor, room_no FROM rooms ORDER BY building, floor, room_no");
$faculty = $conn->query("SELECT id, name FROM users WHERE role='faculty' ORDER BY name");

include __DIR__ . '/../../partials/header.php';
?>

<div class="main-content">
    <div style="margin-bottom: 24px;">
        <a href="<?= BASE_URL ?>views/admin/assignments.php" class="btn outline small"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
    <div class="card animate-card-entry" style="max-width: 600px; margin: 0 auto;">
        <h2 class="card-title">Edit Assignment</h2>
        <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>
        
        <form method="POST">
            <?= get_csrf_input() ?>
            <div style="margin-bottom: 16px;">
                <label style="color: var(--muted); display: block; margin-bottom: 8px;">Room</label>
                <select class="input" name="room_id" required>
                    <option value="">Select Room</option>
                    <?php while($r = $rooms->fetch_assoc()): ?>
                        <option value="<?= (int)$r['id'] ?>" <?= $assignment['room_id'] == $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['building'] . '/' . $r['floor'] . '/' . $r['room_no']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div style="margin-bottom: 16px;">
                <label style="color: var(--muted); display: block; margin-bottom: 8px;">Faculty</label>
                <select class="input" name="faculty_id" required>
                    <option value="">Select Faculty</option>
                    <?php while($f = $faculty->fetch_assoc()): ?>
                        <option value="<?= (int)$f['id'] ?>" <?= $assignment['faculty_id'] == $f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <div class="actions" style="text-align: right; margin-top: 20px;">
                <button type="submit" class="btn"><i class="fas fa-save"></i> Update Assignment</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
