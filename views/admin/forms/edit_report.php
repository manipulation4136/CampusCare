<?php
require_once __DIR__ . '/../../../config/init.php';
ensure_role(['admin']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    set_flash('err', 'Invalid report ID.');
    header("Location: " . BASE_URL . "views/admin/reports.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');
    
    $status = trim($_POST['status'] ?? '');
    $urgency_priority = trim($_POST['urgency_priority'] ?? '');
    $assigned_to = (int)($_POST['assigned_to'] ?? 0);
    
    $assigned_to_val = $assigned_to > 0 ? $assigned_to : null;
    
    if (!empty($status) && !empty($urgency_priority)) {
        $stmt = $conn->prepare("UPDATE damage_reports SET status = ?, urgency_priority = ?, assigned_to = ? WHERE id = ?");
        $stmt->bind_param("ssii", $status, $urgency_priority, $assigned_to_val, $id);
        
        if ($stmt->execute()) {
            set_flash('ok', 'Report updated successfully.');
            header("Location: " . BASE_URL . "views/admin/reports.php");
            exit;
        } else {
            set_flash('err', 'Failed to update report.');
        }
    } else {
        set_flash('err', 'Status and Urgency Priority are required.');
    }
}

// Fetch report data
$stmt = $conn->prepare("
    SELECT dr.*, a.asset_code, an.name as asset_name 
    FROM damage_reports dr 
    JOIN assets a ON dr.asset_id = a.id 
    JOIN asset_names an ON a.asset_name_id = an.id 
    WHERE dr.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$report = $stmt->get_result()->fetch_assoc();

if (!$report) {
    set_flash('err', 'Report not found.');
    header("Location: " . BASE_URL . "views/admin/reports.php");
    exit;
}

// Fetch users mapping to faculty/admin to assign
$assignees = $conn->query("SELECT id, name, role FROM users WHERE role IN ('admin', 'faculty', 'worker') ORDER BY name");

include __DIR__ . '/../../partials/header.php';
?>
<div class="main-content">
    <div style="margin-bottom: 24px;">
        <a href="<?= BASE_URL ?>views/admin/reports.php" class="btn outline small"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
    
    <div class="card animate-card-entry" style="max-width: 600px; margin: 0 auto;">
        <h2 class="card-title">Edit Damage Report #<?= $report['id'] ?></h2>
        <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>
        
        <div style="margin-bottom: 20px; padding: 15px; background: rgba(52, 152, 219, 0.1); border-radius: 8px; border: 1px solid rgba(52, 152, 219, 0.2);">
            <p style="margin: 0 0 5px 0;"><strong>Asset:</strong> <?= htmlspecialchars($report['asset_code'] . ' - ' . $report['asset_name']) ?></p>
            <p style="margin: 0; color: #8fa0c9;"><strong>Description:</strong> <?= htmlspecialchars($report['description']) ?></p>
        </div>

        <form method="POST">
            <?= get_csrf_input() ?>
            
            <div style="margin-bottom: 16px;">
                <label style="color: var(--muted); display: block; margin-bottom: 8px;">Status *</label>
                <select name="status" class="input" required>
                    <option value="Reported" <?= $report['status'] === 'Reported' ? 'selected' : '' ?>>Reported</option>
                    <option value="In Progress" <?= $report['status'] === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="Resolved" <?= $report['status'] === 'Resolved' ? 'selected' : '' ?>>Resolved</option>
                    <option value="Unrepairable" <?= $report['status'] === 'Unrepairable' ? 'selected' : '' ?>>Unrepairable</option>
                </select>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="color: var(--muted); display: block; margin-bottom: 8px;">Urgency Priority *</label>
                <select name="urgency_priority" class="input" required>
                    <option value="Low" <?= $report['urgency_priority'] === 'Low' ? 'selected' : '' ?>>Low</option>
                    <option value="Medium" <?= $report['urgency_priority'] === 'Medium' ? 'selected' : '' ?>>Medium</option>
                    <option value="High" <?= $report['urgency_priority'] === 'High' ? 'selected' : '' ?>>High</option>
                    <option value="Critical" <?= $report['urgency_priority'] === 'Critical' ? 'selected' : '' ?>>Critical</option>
                </select>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="color: var(--muted); display: block; margin-bottom: 8px;">Assigned To</label>
                <select name="assigned_to" class="input">
                    <option value="">-- Unassigned --</option>
                    <?php while($assignee = $assignees->fetch_assoc()): ?>
                        <option value="<?= (int)$assignee['id'] ?>" <?= $report['assigned_to'] == $assignee['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($assignee['name'] . ' (' . ucfirst($assignee['role']) . ')') ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <div class="actions" style="text-align: right; margin-top: 20px;">
                <button type="submit" class="btn"><i class="fas fa-save"></i> Update Report</button>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
