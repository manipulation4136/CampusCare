<?php
require_once __DIR__ . '/../../config/init.php';
ensure_role('faculty');

$user_id = (int)$_SESSION['user']['id'];

// Fetch Assigned Rooms with Stats
$sql = "
    SELECT 
        r.id, 
        r.room_no, 
        r.room_type, 
        r.building, 
        r.floor,
        (SELECT COUNT(*) FROM assets a WHERE a.room_id = r.id) as total_assets,
        (SELECT COUNT(*) FROM damage_reports dr 
         JOIN assets a ON dr.asset_id = a.id 
         WHERE a.room_id = r.id AND dr.status IN ('pending', 'in_progress')) as active_issues
    FROM room_assignments ra
    JOIN rooms r ON ra.room_id = r.id
    WHERE ra.faculty_id = ?
    ORDER BY r.building, r.floor, r.room_no
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$rooms = $stmt->get_result();

include __DIR__ . '/../partials/header.php';
?>

<div class="container" style="max-width: 1000px; padding-bottom: 80px;">

    <!-- Header Section -->
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <a href="<?= BASE_URL ?>views/faculty/dashboard.php" style="color: #6ea8fe; font-size: 20px; text-decoration: none;">←</a>
            <h1 style="font-size: 24px; margin: 0; color: #fff;">My Assigned Rooms</h1>
        </div>
    </div>

    <!-- Rooms Grid -->
    <div class="grid cols-2">
        <?php if ($rooms->num_rows > 0): ?>
            <?php while ($room = $rooms->fetch_assoc()): 
                $hasIssues = $room['active_issues'] > 0;
                $statusClass = $hasIssues ? 'bad' : 'good';
                $statusText = $hasIssues ? $room['active_issues'] . ' Issues' : 'All Good';
                $statusIcon = $hasIssues ? '⚠️' : '✅';
            ?>
            <div class="kpi-card" style="display: flex; flex-direction: column; height: 100%;">
                
                <!-- Card Header -->
                <div style="display: flex; align-items: start; justify-content: space-between; margin-bottom: 12px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(110, 168, 254, 0.1); display: flex; align-items: center; justify-content: center; font-size: 20px;">
                            🏢
                        </div>
                        <div>
                            <div style="color: #fff; font-weight: 600; font-size: 16px;">
                                <?= htmlspecialchars($room['room_no']) ?>
                            </div>
                            <div style="color: #8fa0c9; font-size: 12px;">
                                <?= htmlspecialchars(ucfirst($room['room_type'])) ?>
                            </div>
                        </div>
                    </div>
                    <span class="badge <?= $statusClass ?>" style="display: flex; align-items: center; gap: 4px;">
                        <?= $statusIcon ?> <?= $statusText ?>
                    </span>
                </div>

                <!-- Location Info -->
                <div style="margin-bottom: 16px; font-size: 13px; color: #8fa0c9;">
                    📍 <?= htmlspecialchars($room['building']) ?>, Floor <?= htmlspecialchars($room['floor']) ?>
                </div>

                <!-- Stats Divider -->
                <div style="border-top: 1px solid #1f2a44; margin: 0 -16px 16px -16px;"></div>

                <!-- Footer Stats & Action -->
                <div style="margin-top: auto; display: flex; align-items: center; justify-content: space-between;">
                    <div style="font-size: 12px; color: #8fa0c9;">
                        Total Assets: <strong style="color: #e7ecff;"><?= $room['total_assets'] ?></strong>
                    </div>
                    <a href="<?= BASE_URL ?>views/faculty/view_room_assets.php?room_id=<?= $room['id'] ?>" class="btn small outline">
                        View Assets
                    </a>
                </div>

            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="col-span-2 empty-state" style="padding: 40px; text-align: center;">
                <div style="font-size: 40px; margin-bottom: 12px;">📭</div>
                <h3 style="color: #fff; margin: 0 0 8px;">No Rooms Assigned</h3>
                <p style="color: #8fa0c9; margin: 0;">You haven't been assigned any rooms yet.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
