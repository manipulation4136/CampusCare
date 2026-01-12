<?php
require_once __DIR__ . '/../../config/init.php';
ensure_role('student');

$user_id = (int)$_SESSION['user']['id'];
$user_name = $_SESSION['user']['name'];

// 1. Fetch Recent Activity (Last 3 Reports)
$query = "
    SELECT dr.id, dr.created_at, dr.status, dr.image_path, a.asset_code, an.name as asset_name
    FROM damage_reports dr
    JOIN assets a ON a.id = dr.asset_id
    JOIN asset_names an ON an.id = a.asset_name_id
    WHERE dr.reported_by = ?
    ORDER BY dr.created_at DESC
    LIMIT 3
";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$recent_reports = $stmt->get_result();

// 2. Fetch Telegram Status
$telegram_chat_id = '';
$stmt = $conn->prepare("SELECT telegram_chat_id FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $telegram_chat_id = $row['telegram_chat_id'];
}

include __DIR__.'/../partials/header.php';
?>

<div class="container" style="max-width: 600px; padding-bottom: 80px;">
    
    <!-- 1. Header Section -->
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 28px; margin: 0; color: #fff;">Hello, <?= htmlspecialchars(ucwords($user_name)) ?></h1>
        <p style="margin: 4px 0 0; color: #8fa0c9;">Report & Track Issues</p>
    </div>

    <!-- 2. Hero Action Card -->
    <a href="<?= BASE_URL ?>views/student/report_new.php" class="hero-card">
        <div class="hero-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                <circle cx="12" cy="13" r="4"></circle>
            </svg>
        </div>
        <h2 class="hero-title">Report New Damage</h2>
        <div class="hero-subtitle">Tap to identify and report a broken asset</div>
    </a>

    <!-- 3. Telegram Banner (If not connected) -->
    <?php if (empty($telegram_chat_id)): ?>
    <div class="telegram-banner">
        <div class="tg-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21.198 2.433a2.242 2.242 0 0 0-1.022.215l-8.609 3.33c-2.068.8-4.133 1.598-5.724 2.21a405.15 405.15 0 0 1-2.866 1.092c-1.424.547-2.31 1.258-2.617 2.029-.4 1.002.228 1.94 1.137 2.478l4.463 2.637.039.023.006.003c.516.304.996.793 1.25 1.341l.011.025c.01.02.02.04.03.06.326.702 1.353 2.924 1.91 4.128.536 1.159 1.517 1.458 2.373 1.115.823-.33 1.253-1.088 1.564-1.637.288-.508.625-1.101.957-1.688l5.808-10.222a2.3 2.3 0 0 0-.276-2.906 2.27 2.27 0 0 0-1.847-.63z"/>
                <path d="M10 13l6-5"/>
            </svg>
        </div>
        <div class="tg-content">
            <h4>Get Instant Updates</h4>
            <p>Connect Telegram to get notified when your reports are fixed.</p>
        </div>
        <a href="<?= BASE_URL ?>views/telegram_setup.php" class="tg-btn">Connect</a>
    </div>
    <?php endif; ?>

    <!-- 4. Recent Activity Feed -->
    <div class="section-header">
        <h3 class="section-title">Recent Activity</h3>
        <a href="<?= BASE_URL ?>views/student/history.php" class="see-all">View All</a>
    </div>

    <div class="activity-feed">
        <?php if ($recent_reports->num_rows > 0): ?>
            <?php while ($report = $recent_reports->fetch_assoc()): 
                $statusClass = strtolower($report['status']);
                if ($statusClass === 'fixed') $statusClass = 'completed'; // Normalize
            ?>
            <div class="activity-card">
                <!-- Thumbnail or Default Icon -->
                <div class="activity-thumb">
                    <?php if (!empty($report['image_path'])): ?>
                        <img src="<?= BASE_URL . htmlspecialchars(ltrim($report['image_path'], '/')) ?>" alt="Report">
                    <?php else: ?>
                        <span>📝</span>
                    <?php endif; ?>
                </div>

                <!-- Details -->
                <div class="activity-details">
                    <h4 class="activity-title"><?= htmlspecialchars($report['asset_name'] ?? 'Unknown Asset') ?></h4>
                    <div class="activity-meta">
                        <?= htmlspecialchars($report['asset_code']) ?> • <?= date('M j', strtotime($report['created_at'])) ?>
                    </div>
                </div>

                <!-- Status Badge -->
                <div class="status-badge-pill <?= $statusClass ?>">
                    <?= htmlspecialchars($report['status']) ?>
                </div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <div style="font-size: 24px; margin-bottom: 8px;">💤</div>
                <p style="margin: 0;">No reported issues yet.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php include __DIR__.'/../partials/footer.php'; ?>
