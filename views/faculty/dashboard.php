<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../config/asset_helper.php';
// ✅ NEW: Include Room Utils for syncing
require_once __DIR__ . '/../../config/room_utils.php';

ensure_role('faculty');

$user_id = (int)$_SESSION['user']['id'];
$user_name = $_SESSION['user']['name'];

// Check notifications AJAX
if (isset($_POST['check_notifications']) && isset($_SESSION['user'])) {
    $res = $conn->query("SELECT COUNT(*) AS count FROM notifications WHERE user_id=$user_id AND is_read = 0");
    $count = $res->fetch_assoc()['count'];
    exit($count);
}

// -----------------------------------------------------
// 1. DATA FETCHING
// -----------------------------------------------------

// A. KPI: Pending Reports Count
$pending_stmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM room_assignments ra
    JOIN rooms r ON ra.room_id = r.id
    JOIN assets a ON a.room_id = r.id
    JOIN damage_reports dr ON dr.asset_id = a.id
    WHERE ra.faculty_id = ? AND dr.status = 'pending'
");
$pending_stmt->bind_param("i", $user_id);
$pending_stmt->execute();
$pending_count = $pending_stmt->get_result()->fetch_assoc()['count'];

// B. KPI: Assigned Rooms Count
$rooms_stmt = $conn->prepare("SELECT COUNT(*) as count FROM room_assignments WHERE faculty_id = ?");
$rooms_stmt->bind_param("i", $user_id);
$rooms_stmt->execute();
$rooms_count = $rooms_stmt->get_result()->fetch_assoc()['count'];

// C. Fetch Reports List
$sql = "
    SELECT 
        dr.id, dr.description, dr.status, dr.urgency_priority, dr.issue_type, dr.created_at, dr.image_path,
        a.asset_code, an.name AS asset_name,
        r.building, r.floor, r.room_no,
        u.name AS reporter,
        cat.name AS category_name
    FROM room_assignments ra
    JOIN rooms r ON ra.room_id = r.id
    JOIN assets a ON a.room_id = r.id
    JOIN damage_reports dr ON dr.asset_id = a.id
    JOIN asset_names an ON a.asset_name_id = an.id
    LEFT JOIN categories cat ON a.category_id = cat.id
    LEFT JOIN users u ON dr.reported_by = u.id
    WHERE ra.faculty_id = ? AND dr.status != 'resolved'
    ORDER BY 
        CASE dr.urgency_priority
            WHEN 'Critical' THEN 1
            WHEN 'High' THEN 2
            WHEN 'Medium' THEN 3
            WHEN 'Low' THEN 4
            ELSE 5
        END, 
        dr.created_at DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$reports = $stmt->get_result();

// D. Check Telegram Status
$telegram_chat_id = '';
$tg_stmt = $conn->prepare("SELECT telegram_chat_id FROM users WHERE id = ?");
$tg_stmt->bind_param("i", $user_id);
$tg_stmt->execute();
if ($row = $tg_stmt->get_result()->fetch_assoc()) {
    $telegram_chat_id = $row['telegram_chat_id'];
}

// -----------------------------------------------------
// 2. HANDLE UPDATES
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    if (!verify_csrf()) die('CSRF validation failed');
    $id = (int)$_POST['id'];
    $status = $_POST['status'] ?? 'pending';
    
    if ($id > 0) {
        try {
            $conn->begin_transaction();
            
            // Update report
            $upd = $conn->prepare("UPDATE damage_reports SET status=?, assigned_to=? WHERE id=?");
            $upd->bind_param("sii", $status, $user_id, $id);
            $upd->execute();
            
            // Update asset status logic
            $assetQ = $conn->prepare("SELECT a.id, a.room_id, a.asset_code, r.room_type, dr.reported_by FROM damage_reports dr JOIN assets a ON dr.asset_id = a.id JOIN rooms r ON a.room_id = r.id WHERE dr.id=?");
            $assetQ->bind_param("i", $id);
            $assetQ->execute();
            if ($ad = $assetQ->get_result()->fetch_assoc()) {
                // Update Asset Status
                $conn->query("UPDATE assets SET status = (SELECT IF(COUNT(*)>0, 'Needs Repair', 'Good') FROM damage_reports WHERE asset_id={$ad['id']} AND status IN ('pending','in_progress')) WHERE id={$ad['id']}");
                
                // ✅ OPTIMIZED: Sync Exam Room Readiness (Replaces old manual logic)
                syncExamReadyStatus($conn, (int)$ad['room_id']);

                // Notify User
                if ($ad['reported_by']) {
                    notify_user($conn, $ad['reported_by'], "🔔 Update: Report for {$ad['asset_code']} is '$status'.");
                }
            }
            $conn->commit();
            set_flash('ok', 'Status updated successfully');
        } catch (Exception $e) {
            $conn->rollback();
            set_flash('err', $e->getMessage());
        }
    }
    header('Location: ' . BASE_URL . 'views/faculty/dashboard.php');
    exit;
}

include __DIR__ . '/../partials/header.php';
?>

<div class="container" style="max-width: 1000px; padding-bottom: 80px;">

    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 28px; margin: 0; color: #fff;">Hello, <?= htmlspecialchars(ucwords($user_name)) ?></h1>
        <p style="margin: 4px 0 0; color: #8fa0c9;">Manage Your Assigned Areas</p>
    </div>

    <div style="margin-bottom: 12px;">
        <?php
        $is_pending_clear = ($pending_count == 0);
        $p_bg = $is_pending_clear ? 'rgba(39, 174, 96, 0.2)' : 'rgba(243, 156, 18, 0.2)';
        $p_badge_cls = $is_pending_clear ? 'good' : 'warn';
        $p_badge_txt = $is_pending_clear ? 'All Good' : 'Action Needed';
        ?>
        <a href="#reports-table" class="kpi-card" style="text-decoration: none; display: flex; align-items: center; justify-content: space-between; padding: 20px;">
            <div style="display: flex; align-items: center; gap: 16px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: <?= $p_bg ?>; display: flex; align-items: center; justify-content: center;">
                    <?php if ($is_pending_clear): ?>
                        <i class="fas fa-check-circle" style="font-size: 24px; color: #27ae60;"></i>
                    <?php else: ?>
                        <span style="font-size: 24px;">⚠️</span>
                    <?php endif; ?>
                </div>
                <div style="text-align: left;">
                    <div class="kpi" style="color: #fff; font-size: 24px; line-height: 1;"><?= $pending_count ?></div>
                    <div class="kpi-label" style="margin: 0;">Pending Reports</div>
                </div>
            </div>
            <span class="badge <?= $p_badge_cls ?>"><?= $p_badge_txt ?></span>
        </a>
    </div>

    <div class="dashboard-grid-row" style="margin-bottom: 24px;">
        <a href="<?= BASE_URL ?>views/faculty/assigned_rooms.php" class="kpi-card" style="text-decoration: none; display: block;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: rgba(110, 168, 254, 0.2); display: flex; align-items: center; justify-content: center;">
                    <span style="font-size: 20px;">🏫</span>
                </div>
                <span class="badge nice">View All</span>
            </div>
            <div class="kpi" style="color: #fff;"><?= $rooms_count ?></div>
            <div class="kpi-label">Assigned Rooms</div>
        </a>

        <a href="<?= BASE_URL ?>views/faculty/workers_directory.php" class="kpi-card" style="text-decoration: none; display: block;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: rgba(39, 174, 96, 0.2); display: flex; align-items: center; justify-content: center;">
                    <span style="font-size: 20px;">👷</span>
                </div>
                <span class="badge good">Contact</span>
            </div>
            <div class="kpi" style="color: #fff;">Directory</div>
            <div class="kpi-label">Maintenance Info</div>
        </a>
    </div>

    <?php if (empty($telegram_chat_id)): ?>
    <div class="telegram-banner" style="margin-bottom: 24px;">
        <div class="tg-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21.198 2.433a2.242 2.242 0 0 0-1.022.215l-8.609 3.33c-2.068.8-4.133 1.598-5.724 2.21a405.15 405.15 0 0 1-2.866 1.092c-1.424.547-2.31 1.258-2.617 2.029-.4 1.002.228 1.94 1.137 2.478l4.463 2.637.039.023.006.003c.516.304.996.793 1.25 1.341l.011.025c.01.02.02.04.03.06.326.702 1.353 2.924 1.91 4.128.536 1.159 1.517 1.458 2.373 1.115.823-.33 1.253-1.088 1.564-1.637.288-.508.625-1.101.957-1.688l5.808-10.222a2.3 2.3 0 0 0-.276-2.906 2.27 2.27 0 0 0-1.847-.63z"/>
                <path d="M10 13l6-5"/>
            </svg>
        </div>
        <div class="tg-content">
            <h4>Get Instant Alerts</h4>
            <p>Connect Telegram to get notified about new reports.</p>
        </div>
        <a href="<?= BASE_URL ?>views/telegram_setup.php" class="tg-btn">Connect</a>
    </div>
    <?php endif; ?>

    <div id="reports-table" class="table-card">
        <div style="padding: 16px; border-bottom: 1px solid #1f2a44; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 16px; color: #fff;">Assigned Rooms Reports</h3>
            <div style="font-size: 12px; color: #8fa0c9;">Sorted by Urgency</div>
        </div>

        <?php if ($m = flash('ok')): ?>
            <div class="alert success"><?= htmlspecialchars($m) ?></div>
        <?php endif; ?>
        <?php if ($m = flash('err')): ?>
            <div class="alert error"><?= htmlspecialchars($m) ?></div>
        <?php endif; ?>

        <?php if ($reports && $reports->num_rows > 0): ?>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Asset</th>
                        <th>Details</th>
                        <th>Category</th>
                        <th>Urgency</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($dr = $reports->fetch_assoc()): 
                        $urgencyClass = match($dr['urgency_priority']) {
                            'Critical' => 'bad',
                            'High' => 'bad',
                            'Medium' => 'warn',
                            default => 'good'
                        };
                    ?>
                    <tr>
                        <td style="vertical-align: top;">
                            <div style="font-weight: 600; color: #fff;"><?= htmlspecialchars($dr['asset_name']) ?></div>
                            <div style="font-size: 11px; color: #8fa0c9;"><?= htmlspecialchars($dr['asset_code']) ?></div>
                            <div style="font-size: 11px; color: #6ea8fe; margin-top: 2px;">
                                <?= htmlspecialchars($dr['building'] . ' - ' . $dr['room_no']) ?>
                            </div>
                        </td>
                        
                        <td style="vertical-align: top;">
                            <div style="font-size: 13px; color: #e7ecff; margin-bottom: 4px;"><?= htmlspecialchars($dr['issue_type']) ?></div>
                            <div style="font-size: 12px; color: #8fa0c9; line-height: 1.4;">
                                <?= htmlspecialchars(substr($dr['description'], 0, 50)) . (strlen($dr['description']) > 50 ? '...' : '') ?>
                            </div>
                            <?php if($dr['image_path']): ?>
                                <a href="#" onclick="showImage('<?= BASE_URL . ltrim($dr['image_path'], '/') ?>'); return false;" style="font-size: 11px; color: #6ea8fe;">View Image</a>
                            <?php endif; ?>
                        </td>
                        <td style="vertical-align: top;">
                            <span class="badge" style="background: rgba(110, 168, 254, 0.1); color: #6ea8fe; font-weight: 500;">
                                <?= htmlspecialchars($dr['category_name'] ?? 'General') ?>
                            </span>
                        </td>
                        <td style="vertical-align: top;">
                            <span class="badge <?= $urgencyClass ?>"><?= htmlspecialchars($dr['urgency_priority']) ?></span>
                        </td>
                        <form method="post">
                            <?= get_csrf_input() ?>
                            <input type="hidden" name="id" value="<?= $dr['id'] ?>">
                            <td style="vertical-align: top;">
                                <select class="input" name="status" style="padding: 4px; font-size: 12px; height: auto;">
                                    <?php foreach (['pending','in_progress','resolved'] as $s): ?>
                                        <option value="<?= $s ?>" <?= $dr['status'] === $s ? 'selected' : '' ?>>
                                            <?= ucfirst(str_replace('_', ' ', $s)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td style="vertical-align: top;">
                                <button class="btn small">Update</button>
                            </td>
                        </form>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="empty-state" style="padding: 40px 20px;">
                <div style="font-size: 40px; margin-bottom: 12px;">🎉</div>
                <h3 style="color: #fff; margin: 0 0 4px;">All Clear!</h3>
                <p style="color: #8fa0c9; font-size: 14px; margin: 0;">No active damage reports in your assigned rooms.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<div id="imageModal" class="modal" onclick="this.style.display='none'">
    <img id="modalImage" alt="Damage Report">
</div>
<script>
function showImage(src) {
    document.getElementById('modalImage').src = src;
    document.getElementById('imageModal').style.display = 'block';
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
