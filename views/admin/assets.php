<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../config/asset_helper.php';
ensure_role(['admin', 'faculty']);
// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verify_csrf()) die('CSRF validation failed');
    $del_id = (int)$_POST['delete_id'];
    
    // Optional: Check if it has children assets or other constraints if needed
    try {
        $stmt = $conn->prepare("DELETE FROM assets WHERE id = ?");
        $stmt->bind_param("i", $del_id);
        if ($stmt->execute()) {
            set_flash('ok', 'Asset deleted successfully');
        } else {
            set_flash('err', 'Failed to delete asset');
        }
    } catch (Exception $e) {
        set_flash('err', 'Error: ' . $e->getMessage());
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
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
    $whereSQL = "WHERE a.asset_code LIKE ? OR an.name LIKE ? OR r.room_no LIKE ? OR c.name LIKE ? OR d.name LIKE ?";
    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];
    $types = "sssss";
}

// Count Total
$countQuery = "
    SELECT COUNT(*) as total
    FROM assets a
    JOIN asset_names an ON an.id = a.asset_name_id
    JOIN rooms r ON r.id = a.room_id
    JOIN categories c ON c.id = a.category_id
    JOIN dealers d ON d.id = a.dealer_id
    $whereSQL
";
$stmt = $conn->prepare($countQuery);
if ($search) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$totalAssets = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalAssets / $limit);

// Fetch Data
$dataQuery = "
    SELECT 
        a.id,
        a.asset_code,
        a.status,
        an.name AS asset_name,
        c.name AS category_name,
        r.building,
        r.floor,
        r.room_no,
        d.name AS dealer_name,
        d.contact AS dealer_contact,
        p.asset_code AS parent_code
    FROM assets a
    JOIN asset_names an ON an.id = a.asset_name_id
    JOIN categories c ON c.id = a.category_id
    JOIN rooms r ON r.id = a.room_id
    JOIN dealers d ON d.id = a.dealer_id
    LEFT JOIN assets p ON p.id = a.parent_asset_id
    $whereSQL
    ORDER BY a.asset_code ASC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($dataQuery);
if ($search) {
    $stmt->bind_param($types . "ii", ...array_merge($params, [$limit, $offset]));
} else {
    $stmt->bind_param("ii", $limit, $offset);
}
$stmt->execute();
$assets = $stmt->get_result();

include __DIR__ . '/../partials/header.php';
?>

<div class="main-content">
    <!-- Professional Header with Icon Button -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2 style="margin: 0; color: #fff; font-size: 24px;">Asset Management</h2>
        <div style="display: flex; gap: 12px; align-items: center;">
            <a href="<?= BASE_URL ?>views/admin/asset_names.php" class="btn outline small">Manage Asset Names</a>
            <a href="<?= BASE_URL ?>views/admin/forms/add_asset.php" class="icon-btn-fab" title="Add New Asset">
                <i class="fas fa-plus"></i>
            </a>
        </div>
    </div>

    <?php if ($m = flash('ok')): ?>
        <div class="alert success"><?= htmlspecialchars($m) ?></div>
    <?php endif; ?>
    <?php if ($m = flash('err')): ?>
        <div class="alert error"><?= htmlspecialchars($m) ?></div>
    <?php endif; ?>

    <!-- Assets Table Card -->
    <div class="table-card">
        <!-- Search Bar -->
        <!-- Search Bar -->
        <div class="table-header-row">
            <h3 class="card-title">All Assets (<?= $totalAssets ?>)</h3>
            <form method="get" class="search-form">
                <input class="input search-input" name="search" placeholder="Search assets..." value="<?= htmlspecialchars($search) ?>">
                
                <?php if ($search): ?>
                    <a href="assets.php" class="btn outline small">Clear</a>
                <?php endif; ?>
                
                <button class="btn small" type="submit">Search</button>
            </form>
        </div>

        <!-- Table -->
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Room</th>
                        <th>Dealer</th>
                        <th>Parent</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($assets->num_rows > 0): ?>
                        <?php while ($a = $assets->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($a['asset_code']) ?></td>
                                <td><?= htmlspecialchars($a['asset_name']) ?></td>
                                <td><?= htmlspecialchars($a['category_name']) ?></td>
                                <td><?= htmlspecialchars($a['building'] . '/' . $a['floor'] . '/' . $a['room_no']) ?></td>
                                <td>
                                    <?= htmlspecialchars($a['dealer_name']) ?>
                                    <?php if ($a['dealer_contact']): ?>
                                        <br><small style="color: #8fa0c9;"><?= htmlspecialchars($a['dealer_contact']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($a['parent_code'] ?? '-') ?></td>
                                <td><span class="badge <?= $a['status'] == 'Good' ? 'good' : 'bad' ?>"><?= htmlspecialchars($a['status']) ?></span></td>
                                <td>
                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this asset? This action cannot be undone.');">
                                        <?= get_csrf_input() ?>
                                        <input type="hidden" name="delete_id" value="<?= $a['id'] ?>">
                                        <button type="submit" class="btn icon-btn" style="color: #e74c3c; background: none; border: none; padding: 5px;" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: #8fa0c9;">
                                <?= $search ? 'No assets found matching your search.' : 'No assets found. Click the + button to add your first asset.' ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
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

<?php include __DIR__ . '/../partials/footer.php'; ?>