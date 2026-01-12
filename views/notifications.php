<?php
require_once __DIR__ . '/../config/init.php';
ensure_logged_in();

$user = $_SESSION['user'] ?? null;
$user_id = $_SESSION['user']['id'];

// --- Handle Deletion Logic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');

    // 1. Delete Single Notification
    if (isset($_POST['delete_id'])) {
        $del_id = (int)$_POST['delete_id'];
        $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $del_id, $user_id);
        if ($stmt->execute()) {
            set_flash('ok', 'Notification removed');
        } else {
            set_flash('err', 'Failed to remove notification');
        }
    }
    // 2. Clear All Notifications
    elseif (isset($_POST['delete_all'])) {
        $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            set_flash('ok', 'All notifications cleared');
        } else {
            set_flash('err', 'Failed to clear notifications');
        }
    }

    // Refresh to clear post data
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$res = $conn->query("SELECT * FROM notifications WHERE user_id = $user_id ORDER BY created_at DESC LIMIT 50");

// Mark unread notifications as read when viewing the page
// Check Telegram Connection Status
$stmt = $conn->prepare("SELECT telegram_chat_id FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$is_connected = !empty($stmt->get_result()->fetch_assoc()['telegram_chat_id']);

include __DIR__ . '/partials/header.php';
?>

<!-- Telegram Banner -->
<?php if ($is_connected): ?>
    <div class="telegram-banner connected">
        <div class="tg-icon success">
            <svg viewBox="0 0 24 24" fill="none" width="24" height="24" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke-linecap="round" stroke-linejoin="round"/><polyline points="22 4 12 14.01 9 11.01" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <div class="tg-content">
            <h4>Alerts Active</h4>
            <p>You are receiving notifications on your linked Telegram account.</p>
        </div>
        <a href="<?= BASE_URL ?>views/telegram_setup.php" class="tg-btn outline">Manage</a>
    </div>
<?php else: ?>
    <div class="telegram-banner">
        <div class="tg-icon">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M20.665 3.717L2.97 10.518C1.762 11.002 1.769 11.675 2.748 11.975L7.292 13.393L17.808 6.76C18.305 6.458 18.761 6.625 18.388 6.957L9.873 14.636L9.824 14.681L9.646 20.219C9.907 20.219 10.137 20.101 10.283 19.955L12.562 17.739L17.294 21.233C18.167 21.714 18.795 21.467 19.012 20.424L22.122 5.8C22.441 4.523 21.644 3.945 20.665 3.717Z" fill="#fff"/></svg>
        </div>
        <div class="tg-content">
            <h4>Get Instant Alerts</h4>
            <p>Connect with our Telegram Bot to receive notifications directly on your phone.</p>
        </div>
        <a href="<?= BASE_URL ?>views/telegram_setup.php" class="tg-btn">Connect Now</a>
    </div>
<?php endif; ?>

<div class="table-card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h3 class="card-title" style="margin: 0;">Your Notifications</h3>
        
        <?php if ($res->num_rows > 0): ?>
            <form method="post" onsubmit="return confirm('Are you sure you want to clear ALL notifications?');">
                <?= get_csrf_input() ?>
                <input type="hidden" name="delete_all" value="1">
                <button type="submit" class="btn outline small" style="color: #e74c3c; border-color: rgba(231, 76, 60, 0.3);">
                    <i class="fas fa-trash-alt" style="margin-right: 6px;"></i> Clear All
                </button>
            </form>
        <?php endif; ?>
    </div>

    <div class="table-scroll">
        <table class="table">
            <tr>
                <th>Message</th>
                <th>When</th>
                <th style="width: 80px; text-align: center;">Action</th>
            </tr>
            <?php while ($n = $res->fetch_assoc()): ?>
                <tr class="<?= $n['is_read'] ? '' : 'highlight' ?>">
                    <td><?= htmlspecialchars($n['message']) ?></td>
                    <td><?= date('M j, Y g:i A', strtotime($n['created_at'])) ?></td>
                    <td style="text-align: center;">
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this notification?');">
                            <?= get_csrf_input() ?>
                            <input type="hidden" name="delete_id" value="<?= $n['id'] ?>">
                            <button type="submit" class="btn icon-btn" style="color: #e74c3c; background: none; border: none; padding: 4px;">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
            <?php if ($res->num_rows === 0): ?>
                <tr>
                    <td colspan="3" style="text-align: center; color: var(--muted); padding: 30px;">
                        No notifications found.
                    </td>
                </tr>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>