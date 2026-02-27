<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../config/asset_helper.php';
ensure_role('admin');

// --- ONE-TIME DAILY CHECK (Warranty & Exam Sync) ---
// TiDB/Render Compatible: Uses DB instead of ephemeral file
$todayDate = date('Y-m-d');
$cronCheck = $conn->query("SELECT last_run_date FROM system_cron_logs LIMIT 1");
$cronRow = $cronCheck->fetch_assoc();
$lastRunDate = $cronRow['last_run_date'] ?? '2000-01-01';

if ($lastRunDate !== $todayDate) {
    // Run the tasks silently (Capture output to prevent UI clutter)
    ob_start();
    include __DIR__ . '/../../cron_daily_tasks.php';
    ob_get_clean(); // Discard output

    // Update DB to prevent re-running today
    // Using ON DUPLICATE to be safe even if the table was empty
    $conn->query("INSERT INTO system_cron_logs (id, last_run_date) VALUES (1, '$todayDate') ON DUPLICATE KEY UPDATE last_run_date = '$todayDate'");
}

// Notification Check Logic
if (isset($_POST['check_notifications']) && isset($_SESSION['user'])) {
    if (!verify_csrf()) die('CSRF validation failed');
    $user_id = (int) $_SESSION['user']['id'];
    $res = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0");
    exit($res->fetch_assoc()['count']);
}

// Urgency Update Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_urgency'])) {
    if (!verify_csrf()) die('CSRF validation failed');
    $report_id = (int)$_POST['report_id'];
    $new_urgency = $_POST['new_urgency_priority'];
    
    $conn->query("UPDATE damage_reports SET urgency_priority='$new_urgency' WHERE id=$report_id");
    
    // Fetch report details for notification
    $res = $conn->query("
        SELECT dr.reported_by, a.asset_code, a.room_id 
        FROM damage_reports dr 
        JOIN assets a ON dr.asset_id = a.id 
        WHERE dr.id = $report_id
    ");

    if ($row = $res->fetch_assoc()) {
        $asset_code = $row['asset_code'];
        
        // Notify Student (Reporter)
        if ($row['reported_by']) {
            notify_user($conn, $row['reported_by'], "Alert: Urgency for {$asset_code} changed to {$new_urgency}.");
        }

        // Notify Faculty
        $room_id = (int)$row['room_id'];
        $fac_res = $conn->query("SELECT faculty_id FROM room_assignments WHERE room_id = $room_id");
        while ($fac = $fac_res->fetch_assoc()) {
            notify_user($conn, $fac['faculty_id'], "Alert: Admin changed urgency for {$asset_code} to {$new_urgency}.");
        }
    }

    set_flash('ok', 'Priority Updated');
    header('Location: ' . BASE_URL . 'views/admin/dashboard.php');
    exit;
}

// --- Pagination Logic ---

// 1. Reports Pagination (Limit 5)
$report_page = isset($_GET['report_page']) ? max(1, (int)$_GET['report_page']) : 1;
$report_limit = 5;
$report_offset = ($report_page - 1) * $report_limit;

// Optimized Count Query
$rep_count_sql = "SELECT COUNT(id) as total FROM damage_reports WHERE status IN ('pending', 'in_progress')";
$total_reports = $conn->query($rep_count_sql)->fetch_assoc()['total'];
$total_report_pages = ceil($total_reports / $report_limit);

// Fetch Reports Data
$reports_sql = "SELECT dr.id, dr.description, dr.status, dr.created_at, dr.image_path, dr.urgency_priority, 
                       a.asset_code, an.name AS asset_name,
                       r.building, r.room_no,
                       u.name AS reporter 
                FROM damage_reports dr 
                JOIN assets a ON a.id = dr.asset_id 
                JOIN asset_names an ON a.asset_name_id = an.id
                JOIN rooms r ON a.room_id = r.id
                LEFT JOIN users u ON u.id = dr.reported_by 
                WHERE dr.status IN ('pending', 'in_progress') 
                ORDER BY CASE dr.urgency_priority WHEN 'Critical' THEN 1 WHEN 'High' THEN 2 WHEN 'Medium' THEN 3 ELSE 4 END, dr.created_at DESC 
                LIMIT $report_limit OFFSET $report_offset";
$reports = $conn->query($reports_sql);


// 2. Rooms Pagination (Limit 10)
$room_page = isset($_GET['room_page']) ? max(1, (int)$_GET['room_page']) : 1;
$room_limit = 10;
$room_offset = ($room_page - 1) * $room_limit;

// Count Total Rooms
$room_count_sql = "SELECT COUNT(id) as total FROM rooms";
$total_rooms = $conn->query($room_count_sql)->fetch_assoc()['total'];
$total_room_pages = ceil($total_rooms / $room_limit);

// Fetch Rooms Data
$rooms_sql = "SELECT r.id, r.building, r.floor, r.room_no, r.room_type, 
              COUNT(DISTINCT CASE WHEN dr.status IN ('pending', 'assigned', 'in_progress') THEN dr.id END) AS open_reports, 
              CASE 
                WHEN COUNT(DISTINCT CASE WHEN a.status IN ('damaged', 'broken', 'Needs Repair') THEN a.id END) > 0 THEN 'Issues' 
                WHEN COUNT(DISTINCT CASE WHEN dr.status IN ('pending', 'assigned', 'in_progress') THEN dr.id END) > 0 THEN 'Issues' 
                ELSE 'Good' 
              END AS room_status 
              FROM rooms r 
              LEFT JOIN assets a ON a.room_id = r.id 
              LEFT JOIN damage_reports dr ON dr.asset_id = a.id 
              GROUP BY r.id, r.building, r.floor, r.room_no, r.room_type 
              ORDER BY r.building, r.floor, r.room_no 
              LIMIT $room_limit OFFSET $room_offset";
$rooms = $conn->query($rooms_sql);

// 🚀 TURBO CHARGED KPI QUERY
// പഴയത് പോലെ ലൈവ് ആയി കണക്കുകൂട്ടുന്നതിന് പകരം, 'exam_rooms' ടേബിളിൽ നിന്ന് നേരിട്ട് എടുക്കുന്നു.
// ഇത് 100ms ലാഭിക്കും.
$kpiResult = $conn->query("
    SELECT 
        (SELECT COUNT(id) FROM assets) AS total_assets, 
        (SELECT COUNT(id) FROM damage_reports WHERE status IN ('pending', 'assigned', 'in_progress')) AS open_reports, 
        (SELECT COUNT(id) FROM rooms) AS total_rooms, 
        (SELECT COUNT(id) FROM exam_rooms WHERE status_exam_ready = 'Yes') AS rooms_ok
")->fetch_assoc();

include __DIR__ . '/../partials/header.php';
?>

<h2 style="margin-top:0; border-bottom:1px solid #1f2a44; padding-bottom:10px;">Dashboard Overview</h2>

<div class="grid-2x2">
    <a href="assets.php" class="stat-card theme-blue">
        <div class="stat-icon-wrapper">
            <i class="fa-solid fa-box"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?= (int) $kpiResult['total_assets'] ?></div>
            <div class="stat-label">Total Assets</div>
        </div>
    </a>

    <a href="reports.php?status=pending" class="stat-card theme-red">
        <div class="stat-icon-wrapper">
            <i class="fa-solid fa-exclamation-triangle"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?= (int) $kpiResult['open_reports'] ?></div>
            <div class="stat-label">Open Reports</div>
        </div>
    </a>

    <a href="exam_ready.php" class="stat-card theme-green">
        <div class="stat-icon-wrapper">
            <i class="fa-solid fa-clipboard-check"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?= (int) $kpiResult['rooms_ok'] ?></div>
            <div class="stat-label">Exam Ready</div>
        </div>
    </a>

    <a href="rooms.php" class="stat-card theme-teal">
        <div class="stat-icon-wrapper">
            <i class="fa-solid fa-building"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?= (int) $kpiResult['total_rooms'] ?></div>
            <div class="stat-label">Total Rooms</div>
        </div>
    </a>
</div>

<?php if ($m = flash('ok')): ?>
    <div class="alert success"><?= htmlspecialchars($m) ?></div>
<?php endif; ?>

<div class="table-card">
    <h3 class="card-title">Recent Reports</h3>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th>Asset</th>
                    <th>Details</th>
                    <th>Status</th>
                    <th>Reporter</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($r = $reports->fetch_assoc()): ?>
                <tr>
                    <td style="vertical-align: top;">
                        <div style="font-weight: 600; color: #fff;"><?= htmlspecialchars($r['asset_name']) ?></div>
                        <div style="font-size: 11px; color: #8fa0c9;"><?= htmlspecialchars($r['asset_code']) ?></div>
                        <div style="font-size: 11px; color: #6ea8fe; margin-top: 2px;">
                            <?= htmlspecialchars($r['building'] . ' - ' . $r['room_no']) ?>
                        </div>
                    </td>
                    <td style="vertical-align: top;">
                        <div style="font-size: 12px; color: #8fa0c9; line-height: 1.4; cursor: pointer;" onclick="showDescription(<?= htmlspecialchars(json_encode($r['description'] ?? '')) ?>)">
                            <?= htmlspecialchars(substr($r['description'] ?? '-', 0, 50)) . (strlen($r['description'] ?? '') > 50 ? '...' : '') ?>
                        </div>
                        <?php if($r['image_path']): ?>
                            <a href="#" onclick="showImage('<?= BASE_URL . ltrim($r['image_path'], '/') ?>'); return false;" style="font-size: 11px; color: #6ea8fe;">View Image</a>
                        <?php endif; ?>
                    </td>
                    <td style="vertical-align: top;"><span class="badge <?= strtolower($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                    <td style="vertical-align: top;"><?= htmlspecialchars($r['reporter'] ?? '-') ?></td>
                    <td style="vertical-align: top;">
                        <form method="post" style="display:inline;">
                            <?= get_csrf_input() ?>
                            <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                            <select class="urgency-select" name="new_urgency_priority" onchange="this.form.submit()">
                                <option value="Critical" <?= $r['urgency_priority']=='Critical'?'selected':'' ?>>Critical</option>
                                <option value="High" <?= $r['urgency_priority']=='High'?'selected':'' ?>>High</option>
                                <option value="Medium" <?= $r['urgency_priority']=='Medium'?'selected':'' ?>>Medium</option>
                                <option value="Low" <?= $r['urgency_priority']=='Low'?'selected':'' ?>>Low</option>
                            </select>
                            <input type="hidden" name="update_urgency" value="1">
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <div style="padding:10px; display:flex; justify-content:space-between; align-items:center; border-top:1px solid #2a3558;">
        <?php if ($report_page > 1): ?>
            <a href="?report_page=<?= $report_page - 1 ?>&room_page=<?= $room_page ?>" class="btn outline small">Previous</a>
        <?php else: ?>
            <span class="btn outline small disabled" style="opacity:0.5; pointer-events:none;">Previous</span>
        <?php endif; ?>

        <span style="font-size:0.9rem; color:#8899ac;">Page <?= $report_page ?> of <?= $total_report_pages ?></span>

        <?php if ($report_page < $total_report_pages): ?>
            <a href="?report_page=<?= $report_page + 1 ?>&room_page=<?= $room_page ?>" class="btn outline small">Next</a>
        <?php else: ?>
            <span class="btn outline small disabled" style="opacity:0.5; pointer-events:none;">Next</span>
        <?php endif; ?>
    </div>
</div>

<div class="table-card">
    <h3 class="card-title">Rooms Status</h3>
    <div class="table-scroll">
        <table class="table">
            <tr><th>Room</th><th>Type</th><th>Status</th><th>Issues</th></tr>
            <?php while ($r = $rooms->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($r['building'] . '/' . $r['room_no']) ?></td>
                    <td><?= htmlspecialchars($r['room_type']) ?></td>
                    <td><span class="badge <?= $r['room_status'] === 'Good' ? 'good' : 'bad' ?>"><?= $r['room_status'] ?></span></td>
                    <td><?= (int) $r['open_reports'] ?></td>
                </tr>
            <?php endwhile; ?>
        </table>
    </div>
    <div style="padding:10px; display:flex; justify-content:space-between; align-items:center; border-top:1px solid #2a3558;">
        <?php if ($room_page > 1): ?>
            <a href="?room_page=<?= $room_page - 1 ?>&report_page=<?= $report_page ?>" class="btn outline small">Previous</a>
        <?php else: ?>
            <span class="btn outline small disabled" style="opacity:0.5; pointer-events:none;">Previous</span>
        <?php endif; ?>

        <span style="font-size:0.9rem; color:#8899ac;">Page <?= $room_page ?> of <?= $total_room_pages ?></span>

        <?php if ($room_page < $total_room_pages): ?>
            <a href="?room_page=<?= $room_page + 1 ?>&report_page=<?= $report_page ?>" class="btn outline small">Next</a>
        <?php else: ?>
            <span class="btn outline small disabled" style="opacity:0.5; pointer-events:none;">Next</span>
        <?php endif; ?>
    </div>
</div>

<div id="imageModal" class="modal" onclick="closeModal('imageModal')" style="display:none;">
    <img id="modalImage" alt="Damage Report">
</div>

<div id="textModal" class="modal" onclick="closeModal('textModal')" style="display:none;">
    <div class="modal-content" onclick="event.stopPropagation()">
        <h3>Report Details</h3>
        <p id="modalText"></p>
        <button class="btn small outline" onclick="closeModal('textModal')" style="margin-top:16px; float:right;">Close</button>
    </div>
</div>

<script>
function showImage(src) {
    var modal = document.getElementById('imageModal');
    // Move to body to ensure fixed positioning works (escapes any parent transforms)
    if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }
    document.getElementById('modalImage').src = src;
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden'; // Lock scroll
}

function showDescription(text) {
    var modal = document.getElementById('textModal');
    // Move to body to ensure fixed positioning works (escapes any parent transforms)
    if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }
    document.getElementById('modalText').innerText = text;
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden'; // Lock scroll
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
    document.body.style.overflow = ''; // Unlock scroll
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
