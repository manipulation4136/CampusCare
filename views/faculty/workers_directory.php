<?php
require_once __DIR__ . '/../../config/init.php';
ensure_role('faculty');

// Fetch categories for filter
$cats = $conn->query("SELECT * FROM categories ORDER BY name ASC");
$categories = [];
if ($cats) {
    while ($row = $cats->fetch_assoc()) {
        $categories[] = $row;
    }
}

// Build Filter Query
$where = [];
$params = [];
$types = "";

// Search filter
$search = $_GET['search'] ?? '';
if ($search) {
    $where[] = "name LIKE ?";
    $params[] = "%$search%";
    $types .= "s";
}

// Category filter
$cat_filter = $_GET['category'] ?? '';
if ($cat_filter) {
    $where[] = "category_id = ?";
    $params[] = (int)$cat_filter;
    $types .= "i";
}

$sql = "SELECT * FROM workers";
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY name ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$results = $stmt->get_result();

$workers = [];
if ($results) {
    while ($row = $results->fetch_assoc()) {
        $workers[] = $row;
    }
}

include __DIR__ . '/../partials/header.php';
?>

<style>
    /* ... existing styles ... */
    .workers-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 24px;
    }

    /* Tablet: 2 Columns */
    @media (max-width: 900px) {
        .workers-grid {
            grid-template-columns: 1fr 1fr;
        }
    }

    /* Mobile: 1 Column */
    @media (max-width: 600px) {
        .workers-grid {
            grid-template-columns: 1fr;
        }
    }

    .worker-card {
        background: var(--card); /* #131a2b */
        border: 1px solid #1f2a44;
        border-radius: 16px;
        padding: 24px;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        transition: transform 0.2s, background 0.2s;
        box-shadow: 0 4px 20px rgba(0,0,0,0.2);
    }

    .worker-card:hover {
        transform: translateY(-4px);
        background: #1c253b;
        border-color: #6ea8fe;
    }

    .worker-avatar {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        background: linear-gradient(135deg, #6ea8fe 0%, #0072ff 100%);
        color: #fff;
        font-size: 24px;
        font-weight: bold;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 16px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    }

    .status-dot {
        height: 8px;
        width: 8px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 6px;
    }

    .status-available { background-color: #27ae60; box-shadow: 0 0 8px rgba(39, 174, 96, 0.5); }
    .status-busy { background-color: #e74c3c; box-shadow: 0 0 8px rgba(231, 76, 60, 0.5); }
    
    .role-badge {
        background: rgba(110, 168, 254, 0.1);
        color: #6ea8fe;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
        margin: 8px 0 16px;
        display: inline-block;
    }
    
    /* Search Form Styles */
    .filter-form {
        display: flex;
        gap: 12px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    
    .search-input {
        flex: 1;
        min-width: 200px;
        padding: 10px 16px;
        border-radius: 10px;
        border: 1px solid #2a3558;
        background: #0d1428;
        color: #fff;
    }
    
    .cat-select {
        width: 180px;
        padding: 10px 16px;
        border-radius: 10px;
        border: 1px solid #2a3558;
        background: #0d1428;
        color: #fff;
    }
    
    @media(max-width: 600px) {
        .filter-form {
            flex-direction: column;
        }
        .cat-select {
            width: 100%;
        }
    }
</style>

<div class="container" style="max-width: 1000px; padding-bottom: 80px;">

    <!-- Header -->
    <div style="margin-bottom: 24px;">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
            <a href="dashboard.php" style="color: #6ea8fe; font-size: 20px; text-decoration: none;">←</a>
            <h1 style="font-size: 24px; margin: 0; color: #fff;">Workers Directory</h1>
        </div>
        <p style="color: #8fa0c9; margin: 0; margin-left: 24px;">Contact Maintenance Staff Directly</p>
    </div>
    
    <!-- Search & Filter Form -->
    <form method="GET" class="filter-form">
        <input type="text" name="search" class="search-input" placeholder="Search by name..." value="<?= htmlspecialchars($search) ?>">
        <select name="category" class="cat-select" onchange="this.form.submit()">
            <option value="">All Categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $cat_filter == $c['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <!-- Retain search in URL when changing select, usually handled by form submission but just in case -->
        <button type="submit" class="btn" style="padding: 10px 20px;">Search</button>
        <?php if($search || $cat_filter): ?>
            <a href="workers_directory.php" class="btn outline" style="padding: 10px 20px; text-decoration: none;">Reset</a>
        <?php endif; ?>
    </form>

    <!-- Workers Grid -->
    <?php if (!empty($workers)): ?>
    <div class="workers-grid">
        <?php foreach ($workers as $worker): 
            $name = $worker['name'] ?? 'Unknown Worker';
            $initials = strtoupper(substr($name, 0, 1));
            
            // Handle missing columns gracefully
            $role = $worker['role'] ?? $worker['position'] ?? $worker['designation'] ?? 'Staff';
            $phone = $worker['phone'] ?? $worker['mobile'] ?? $worker['contact'] ?? '';
            $statusRaw = $worker['status'] ?? 'available';
            
            $isAvailable = strtolower($statusRaw) === 'available';
            $statusColor = $isAvailable ? 'status-available' : 'status-busy';
            $statusText = ucfirst($statusRaw);
        ?>
        <div class="worker-card">
            <div class="worker-avatar">
                <?= $initials ?>
            </div>
            
            <h3 style="margin: 0; color: #fff; font-size: 18px;"><?= htmlspecialchars($name) ?></h3>
            
            <div class="role-badge"><?= htmlspecialchars($role) ?></div>
            
            <div style="font-size: 13px; color: #8fa0c9; margin-bottom: 20px; display: flex; align-items: center; justify-content: center;">
                <span class="status-dot <?= $statusColor ?>"></span>
                <?= htmlspecialchars($statusText) ?>
            </div>
            
            <?php if ($phone): ?>
            <a href="tel:<?= htmlspecialchars($phone) ?>" class="btn" style="width: 100%; display: block; text-decoration: none;">
                📞 Call Now
            </a>
            <?php else: ?>
            <button class="btn" style="width: 100%; display: block; opacity: 0.5; cursor: not-allowed;" disabled>
                📞 No Phone
            </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <!-- Empty State -->
    <div class="empty-state" style="padding: 60px 20px; text-align: center; background: rgba(19, 26, 43, 0.5); border-radius: 16px; border: 1px solid #1f2a44;">
        <div style="font-size: 48px; margin-bottom: 16px;">🔍</div>
        <h3 style="color: #fff; margin: 0 0 8px;">No Workers Found</h3>
        <p style="color: #8fa0c9; margin: 0;">Try adjusting your search or filter.</p>
        <?php if($search || $cat_filter): ?>
            <a href="workers_directory.php" class="btn outline" style="margin-top: 16px; display: inline-block; text-decoration: none;">Clear Filters</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
