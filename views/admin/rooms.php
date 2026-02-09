<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../config/asset_helper.php';
// ✅ NEW: Include room_utils for syncing
require_once __DIR__ . '/../../config/room_utils.php'; 

ensure_role('admin');

// ✅ OPTIMIZATION 1: Handle Sync Action
// ലൂപ്പിൽ വെച്ച് കണക്കുകൂട്ടുന്നതിന് പകരം, ആവശ്യമുള്ളപ്പോൾ മാത്രം ഈ ബട്ടൺ അമർത്തുക.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_exam_statuses'])) {
    if (!verify_csrf()) die('CSRF validation failed');
    $updated_count = syncAllExamReadyStatuses($conn);
    set_flash('ok', "Synchronized $updated_count exam room statuses.");
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verify_csrf()) die('CSRF validation failed');
    
    $room_id = (int)$_POST['delete_id'];
    
    try {
        $conn->begin_transaction();
        
        // Delete from exam_rooms first (if exists) due to foreign key constraint
        $conn->query("DELETE FROM exam_rooms WHERE room_id = $room_id");
        $stmt = $conn->prepare("DELETE FROM rooms WHERE id = ?");
        $stmt->bind_param("i", $room_id);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $conn->commit();
            set_flash('ok', 'Room deleted successfully');
        } else {
            $conn->rollback();
            set_flash('err', 'Room not found');
        }
    } catch (Exception $e) {
        $conn->rollback();
        set_flash('err', 'Error deleting room: ' . $e->getMessage());
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ✅ OPTIMIZATION 2: Close session early for faster page load
// POST അല്ലെങ്കിൽ മാത്രം ക്ലോസ് ചെയ്യുക
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    session_write_close();
}

// Pagination & Search Logic
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');

$whereSQL = "";
$params = [];
$types = "";

if ($search) {
    $searchTerm = "%$search%";
    $whereSQL = "WHERE r.room_no LIKE ? OR r.building LIKE ? OR r.room_type LIKE ?";
    $params = [$searchTerm, $searchTerm, $searchTerm];
    $types = "sss";
}

// Count Total
$countQuery = "SELECT COUNT(*) as total FROM rooms r $whereSQL";
$stmt = $conn->prepare($countQuery);
if ($search) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$totalRooms = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRooms / $limit);

// Fetch Data
$roomsQuery = "
    SELECT r.*, er.status_exam_ready, er.updated_at as status_updated_at
    FROM rooms r
    LEFT JOIN exam_rooms er ON er.room_id = r.id
    $whereSQL
    ORDER BY r.building, r.floor, r.room_no
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($roomsQuery);
if ($search) {
    $stmt->bind_param($types . "ii", ...array_merge($params, [$limit, $offset]));
} else {
    $stmt->bind_param("ii", $limit, $offset);
}
$stmt->execute();
$rooms = $stmt->get_result();

include __DIR__.'/../partials/header.php';
?>

<div class="main-content">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2 style="margin: 0; color: #fff; font-size: 24px;">Room Management</h2>
        <div style="display: flex; gap: 12px; align-items: center;">
            <form method="post" style="margin:0;">
                <?= get_csrf_input() ?>
                <button type="submit" name="sync_exam_statuses" class="btn outline small" title="Recalculate Statuses">
                    <i class="fas fa-sync-alt"></i> Sync Status
                </button>
            </form>
            
            <a href="<?= BASE_URL ?>views/admin/forms/add_room.php" class="icon-btn-fab" title="Add New Room">
                <i class="fas fa-plus"></i>
            </a>
        </div>
    </div>

    <?php if ($m = flash('ok')): ?><div class="alert success"><?= htmlspecialchars($m) ?></div><?php endif; ?>
    <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>

    <div class="table-card">
        <div class="table-header-row">
            <h3 class="card-title">All Rooms (<?= $totalRooms ?>)</h3>
            <form method="get" class="search-form">
                <input class="input search-input" name="search" placeholder="Search rooms..." value="<?= htmlspecialchars($search) ?>">
                <?php if ($search): ?>
                    <a href="rooms.php" class="btn outline small">Clear</a>
                <?php endif; ?>
                <button class="btn small" type="submit">Search</button>
            </form>
        </div>

        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Building</th>
                        <th>Floor</th>
                        <th>Room</th>
                        <th>Type</th>
                        <th>Capacity</th>
                        <th>Notes</th>
                        <th>Exam Ready</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rooms->num_rows > 0): ?>
                        <?php while($r=$rooms->fetch_assoc()): ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <td><?= htmlspecialchars($r['building']) ?></td>
                            <td><?= htmlspecialchars($r['floor']) ?></td>
                            <td><?= htmlspecialchars($r['room_no']) ?></td>
                            <td><?= htmlspecialchars($r['room_type']) ?></td>
                            <td><?= $r['capacity'] ? (int)$r['capacity'] : '-' ?></td>
                            <td><?= htmlspecialchars($r['notes'] ?: '-') ?></td>
                            <td>
                                <?php 
                                // ✅ OPTIMIZATION 3: Use stored status directly
                                if ($r['status_exam_ready'] !== null) {
                                    $status = $r['status_exam_ready'];
                                    $badge_class = $status === 'Yes' ? 'yes' : 'no';
                                    echo '<span class="badge ' . $badge_class . '">' . htmlspecialchars($status) . '</span>';
                                } else {
                                    echo '<span class="badge na">N/A</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this room? This will also remove it from exam_rooms if applicable.');">
                                    <?= get_csrf_input() ?>
                                    <input type="hidden" name="delete_id" value="<?= $r['id'] ?>">
                                    <button type="submit" class="btn icon-btn" style="color: #e74c3c; background: none; border: none; padding: 5px;" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px; color: #8fa0c9;">
                                <?= $search ? 'No rooms found matching your search.' : 'No rooms found.' ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div style="padding: 16px; border-top: 1px solid #1f2a44; display: flex; justify-content: space-between; align-items: center;">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>" class="btn outline small">Previous</a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>
            
            <span style="color: #8fa0c9; font-size: 14px;">Page <?= $page ?> of <?= $totalPages ?></span>
            
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>" class="btn outline small">Next</a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__.'/../partials/footer.php'; ?>
