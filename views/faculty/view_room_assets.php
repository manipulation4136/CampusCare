<?php
require_once __DIR__ . '/../../config/init.php';
ensure_role('faculty');

$room_id = (int)($_GET['room_id'] ?? 0);
$user_id = (int)$_SESSION['user']['id'];

if (!$room_id) {
    header('Location: assigned_rooms.php');
    exit;
}

// Security Check: Ensure this room is assigned to the logged-in faculty
$check = $conn->prepare("SELECT id FROM room_assignments WHERE room_id = ? AND faculty_id = ?");
$check->bind_param("ii", $room_id, $user_id);
$check->execute();
if ($check->get_result()->num_rows === 0) {
    die("Unauthorized access to this room.");
}

// Fetch Room Details
$stmt = $conn->prepare("SELECT room_no, room_type, building, floor FROM rooms WHERE id = ?");
$stmt->bind_param("i", $room_id);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();

// Fetch Assets
$assets_sql = "
    SELECT a.id, a.asset_code, a.status, an.name as asset_name, c.name as category_name
    FROM assets a
    JOIN asset_names an ON a.asset_name_id = an.id
    LEFT JOIN categories c ON a.category_id = c.id
    WHERE a.room_id = ?
    ORDER BY an.name, a.asset_code
";
$assets_stmt = $conn->prepare($assets_sql);
$assets_stmt->bind_param("i", $room_id);
$assets_stmt->execute();
$assets = $assets_stmt->get_result();

include __DIR__ . '/../partials/header.php';
?>

<div class="container" style="max-width: 1000px; padding-bottom: 80px;">

    <!-- Header -->
    <div style="margin-bottom: 24px;">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
            <a href="assigned_rooms.php" style="color: #6ea8fe; font-size: 20px; text-decoration: none;">←</a>
            <h1 style="font-size: 24px; margin: 0; color: #fff;">Room <?= htmlspecialchars($room['room_no']) ?></h1>
        </div>
        <p style="color: #8fa0c9; margin: 0; margin-left: 24px;">
            <?= htmlspecialchars($room['building']) ?> • Floor <?= htmlspecialchars($room['floor']) ?> • <?= htmlspecialchars(ucfirst($room['room_type'])) ?>
        </p>
    </div>

    <!-- Assets List -->
    <div class="table-card">
        <div style="padding: 16px; border-bottom: 1px solid #1f2a44;">
            <h3 style="margin: 0; font-size: 16px; color: #fff;">Asset Inventory</h3>
        </div>

        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Asset Name</th>
                        <th>Code</th>
                        <th>Category</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($assets->num_rows > 0): ?>
                        <?php while ($asset = $assets->fetch_assoc()): 
                            $statusClass = match(strtolower($asset['status'])) {
                                'good' => 'good',
                                'needs repair' => 'bad',
                                'maintenance' => 'warn',
                                default => 'na'
                            };
                        ?>
                        <tr>
                            <td style="font-weight: 500; color: #fff;"><?= htmlspecialchars($asset['asset_name']) ?></td>
                            <td style="color: #8fa0c9; font-family: monospace;"><?= htmlspecialchars($asset['asset_code']) ?></td>
                            <td><?= htmlspecialchars($asset['category_name'] ?? '-') ?></td>
                            <td><span class="badge <?= $statusClass ?>"><?= htmlspecialchars($asset['status']) ?></span></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 40px;">
                                <div style="font-size: 32px; margin-bottom: 10px;">📦</div>
                                <div style="color: #8fa0c9;">No assets found in this room.</div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
