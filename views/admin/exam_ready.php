<?php
require_once __DIR__ . '/../../config/init.php';
ensure_role('admin');

// 1. Fetch Data
// Query specifically from exam_rooms, joined with details and active reports
$sql = "
    SELECT 
        er.id,
        r.room_no, 
        r.building, 
        r.floor, 
        r.capacity,
        COUNT(CASE WHEN dr.status IN ('pending', 'assigned', 'in_progress', 'High Priority') THEN 1 END) as issue_count,
        GROUP_CONCAT(
            DISTINCT CASE WHEN dr.status IN ('pending', 'assigned', 'in_progress', 'High Priority') THEN an.name END 
            SEPARATOR ', '
        ) as active_issues
    FROM exam_rooms er
    JOIN rooms r ON er.room_id = r.id
    LEFT JOIN assets a ON a.room_id = r.id
    LEFT JOIN damage_reports dr ON dr.asset_id = a.id
    LEFT JOIN asset_names an ON an.id = a.asset_name_id
    GROUP BY er.id
    ORDER BY r.building, r.floor, r.room_no
";

$result = $conn->query($sql);
$exam_rooms = [];
$stats = [
    'total' => 0,
    'ready' => 0,
    'blocked' => 0
];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $exam_rooms[] = $row;
        $stats['total']++;
        if ($row['issue_count'] == 0) {
            $stats['ready']++;
        } else {
            $stats['blocked']++;
        }
    }
}

include __DIR__.'/../partials/header.php';
?>

<div class="main-content">

    <style>
        .stat-card {
            cursor: pointer;
            transition: transform 0.2s, border-color 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .active-filter {
            border-color: var(--text) !important;
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .animate-entry {
            opacity: 0;
            animation: slideUpCard 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        @keyframes slideUpCard {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
    
    <!-- War Room Header -->
    <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div>
            <h2 style="margin: 0; font-size: 1.5rem; color: var(--text);">Exam Control Room</h2>
            <p style="margin: 4px 0 0 0; color: var(--muted); font-size: 0.9rem;">Live readiness status of designated exam halls</p>
        </div>
        <button class="btn outline icon-btn" onclick="location.reload()">
            <i class="fas fa-sync-alt"></i> Refresh Data
        </button>
    </div>

    <!-- Status Bar -->
    <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 32px;">
        <div id="filter-all" class="stat-card active-filter" onclick="filterRooms('all')" style="background: var(--card); padding: 20px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
            <div style="color: var(--muted); font-size: 0.85rem; text-transform: uppercase;">Total Halls</div>
            <div style="font-size: 2rem; font-weight: 700; color: var(--text); margin-top: 8px;"><?= $stats['total'] ?></div>
        </div>
        <div id="filter-ready" class="stat-card" onclick="filterRooms('ready')" style="background: var(--card); padding: 20px; border-radius: 12px; border-top: 4px solid #27ae60; position: relative; overflow: hidden;">
            <div style="position: absolute; right: -10px; top: -10px; opacity: 0.1; font-size: 5rem; color: #27ae60;">
                <i class="fas fa-check-circle"></i>
            </div>
            <div style="color: #27ae60; font-size: 0.85rem; text-transform: uppercase; font-weight: 600;">✅ Ready</div>
            <div style="font-size: 2rem; font-weight: 700; color: var(--text); margin-top: 8px;"><?= $stats['ready'] ?></div>
        </div>
        <div id="filter-blocked" class="stat-card" onclick="filterRooms('blocked')" style="background: var(--card); padding: 20px; border-radius: 12px; border-top: 4px solid #e74c3c; position: relative; overflow: hidden;">
            <div style="position: absolute; right: -10px; top: -10px; opacity: 0.1; font-size: 5rem; color: #e74c3c;">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div style="color: #e74c3c; font-size: 0.85rem; text-transform: uppercase; font-weight: 600;">⚠️ Blocked</div>
            <div style="font-size: 2rem; font-weight: 700; color: var(--text); margin-top: 8px;"><?= $stats['blocked'] ?></div>
        </div>
    </div>

    <!-- The Grid -->
    <div class="rooms-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px;">
        <?php foreach ($exam_rooms as $room): 
            $isReady = $room['issue_count'] == 0;
            $borderColor = $isReady ? '#27ae60' : '#e74c3c';
            $bgColor = $isReady ? '#131a2b' : '#1a1520';
            $icon = $isReady ? 'fa-check' : 'fa-exclamation-triangle';
            $iconColor = $isReady ? '#27ae60' : '#e74c3c';
            $issues = array_filter(explode(', ', $room['active_issues'] ?? ''));
        ?>
        <div class="room-card room-grid-item" data-status="<?= $isReady ? 'ready' : 'blocked' ?>" style="
            background: <?= $bgColor ?>; 
            border-radius: 8px; 
            padding: 24px; 
            border-top: 4px solid <?= $borderColor ?>;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            position: relative;
        ">
            <!-- Header -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                <div>
                    <h3 style="margin: 0; font-size: 1.6rem; font-weight: 700; color: var(--text);">
                        <?= htmlspecialchars($room['room_no']) ?>
                    </h3>
                    <div style="font-size: 0.9rem; color: var(--muted); margin-top: 4px;">
                        Capacity: <?= $room['capacity'] ? $room['capacity'] : '-' ?>
                    </div>
                </div>
                <!-- Status Icon -->
                <div style="
                    width: 44px; height: 44px; 
                    border-radius: 50%; 
                    background: rgba(<?= $isReady ? '39, 174, 96' : '231, 76, 60' ?>, 0.15); 
                    display: flex; align-items: center; justify-content: center;
                ">
                    <i class="fas <?= $icon ?>" style="font-size: 1.2rem; color: <?= $iconColor ?>;"></i>
                </div>
            </div>

            <!-- Content -->
            <?php if ($isReady): ?>
                <div style="min-height: 60px;">
                    <div style="color: #27ae60; font-size: 1.1rem; font-weight: 600; margin-bottom: 4px;">
                        Ready for Allocation
                    </div>
                </div>
                <!-- Action -->
                 <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.05);">
                    <div style="color: var(--muted); font-size: 0.8rem; text-align: center;">No active issues</div>
                </div>
            <?php else: ?>
                <div style="min-height: 60px;">
                    <div style="color: #e74c3c; font-size: 1.1rem; font-weight: 600; margin-bottom: 8px;">
                        Maintenance Required
                    </div>
                    <!-- Issue Badges -->
                    <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                        <?php foreach (array_slice($issues, 0, 3) as $issue): ?>
                            <span style="
                                background: rgba(231, 76, 60, 0.15); 
                                color: #ffadad; 
                                padding: 3px 8px; 
                                border-radius: 4px; 
                                font-size: 0.75rem; 
                                border: 1px solid rgba(231, 76, 60, 0.3);
                            ">
                                <?= htmlspecialchars($issue) ?>
                            </span>
                        <?php endforeach; ?>
                        <?php if (count($issues) > 3): ?>
                            <span style="font-size: 0.75rem; color: var(--muted);">+<?= count($issues)-3 ?> more</span>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Action -->
                <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.05);">
                    <a href="reports.php?search=<?= urlencode($room['room_no']) ?>" class="btn small" style="width: 100%; justify-content: center; background: rgba(231, 76, 60, 0.1); color: #e74c3c; border: 1px solid rgba(231, 76, 60, 0.3);">
                        View Reports <i class="fas fa-arrow-right" style="margin-left: 6px; font-size: 0.8rem;"></i>
                    </a>
                </div>
            <?php endif; ?>

        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include __DIR__.'/../partials/footer.php'; ?>

<script>
function filterRooms(status) {
    // 1. Update Active State
    document.querySelectorAll('.stat-card').forEach(card => card.classList.remove('active-filter'));
    document.getElementById('filter-' + status).classList.add('active-filter');

    // 2. Filter & Animate Grid Items
    const items = document.querySelectorAll('.room-card');
    let delayCounter = 0;

    items.forEach(item => {
        const shouldShow = (status === 'all' || item.dataset.status === status);

        if (shouldShow) {
            // Reset display first
            item.style.display = 'block';
            
            // Remove animation class
            item.classList.remove('animate-card-entry');
            
            // Trigger Reflow (The Magic Trick)
            void item.offsetWidth;
            
            // Add animation class
            item.classList.add('animate-card-entry');
            
            // Set Dynamic Delay
            item.style.animationDelay = (delayCounter * 0.05) + 's';
            delayCounter++;
        } else {
            // Hide and reset
            item.style.display = 'none';
            item.classList.remove('animate-card-entry');
            item.style.animationDelay = '0s';
        }
    });
}

// Initial Animation on Load
document.addEventListener('DOMContentLoaded', () => {
    filterRooms('all');
});
</script>
