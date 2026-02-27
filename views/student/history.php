<?php
require_once __DIR__ . '/../../config/init.php';
ensure_role(['student', 'faculty']);

$uid = $_SESSION['user']['id'];
$role = $_SESSION['user']['role'];

// UPDATED: Queries now include asset_names JOIN
if ($role === 'student') {
    $stmt = $conn->prepare("SELECT dr.*, a.asset_code, an.name AS asset_name 
                           FROM damage_reports dr 
                           JOIN assets a ON a.id = dr.asset_id 
                           JOIN asset_names an ON an.id = a.asset_name_id
                           WHERE dr.reported_by = ? 
                           ORDER BY dr.created_at DESC");
    $stmt->bind_param("i", $uid);
} else {
    // faculty can view reports they are assigned to
    $stmt = $conn->prepare("SELECT dr.*, a.asset_code, an.name AS asset_name 
                           FROM damage_reports dr 
                           JOIN assets a ON a.id = dr.asset_id 
                           JOIN asset_names an ON an.id = a.asset_name_id
                           WHERE dr.assigned_to = ? 
                           ORDER BY dr.created_at DESC");
    $stmt->bind_param("i", $uid);
}

$stmt->execute();
$rows = $stmt->get_result();

include __DIR__ . '/../partials/header.php';
?>

<div class="table-card">
    <h3 class="card-title"><?= $role === 'student' ? 'My Reports' : 'Assigned Reports' ?></h3>
    <div class="table-scroll">
        <table class="table">
            <tr><th>Image</th><th>Asset</th><th>Status</th><th>Created</th></tr>
            <?php while ($r = $rows->fetch_assoc()): ?>
                <tr>
                    <td>
                        <?php if (!empty($r['image_path'])): ?>
                            <img class="img-thumb"
                                 src="<?= BASE_URL . htmlspecialchars(ltrim($r['image_path'], '/')) ?>"
                                 onclick="showImage('<?= BASE_URL . htmlspecialchars(ltrim($r['image_path'], '/')) ?>')">
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($r['asset_code'] . ' - ' . $r['asset_name']) ?></td>
                    <td><span class="badge <?= strtolower($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                    <td><?= htmlspecialchars($r['created_at']) ?></td>
                </tr>
            <?php endwhile; ?>
        </table>
    </div>
</div>

<div id="imageModal" class="modal" onclick="this.style.display='none'">
    <img id="modalImage">
</div>

<script>
function showImage(src) {
    document.getElementById('modalImage').src = src;
    document.getElementById('imageModal').style.display = 'block';
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>