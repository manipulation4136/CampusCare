<?php
// pages/faculty/workers.php
require_once __DIR__ . '/../../config/init.php';
ensure_role(['faculty', 'admin']); // Allow both faculty and admin to view

// Get selected category filter
$selected_category = isset($_GET['category']) ? (int)$_GET['category'] : 0;

// Build query with optional category filter
if ($selected_category > 0) {
    $workers_query = "
        SELECT w.*, c.name as category_name 
        FROM workers w 
        JOIN categories c ON c.id = w.category_id 
        WHERE w.category_id = ? 
        ORDER BY w.name
    ";
    $stmt = $conn->prepare($workers_query);
    $stmt->bind_param("i", $selected_category);
    $stmt->execute();
    $workers = $stmt->get_result();
} else {
    $workers = $conn->query("
        SELECT w.*, c.name as category_name 
        FROM workers w 
        JOIN categories c ON c.id = w.category_id 
        ORDER BY c.name, w.name
    ");
}

// Get all categories for filter dropdown
$categories = $conn->query("
    SELECT c.id, c.name, COUNT(w.worker_id) as worker_count
    FROM categories c 
    LEFT JOIN workers w ON w.category_id = c.id 
    GROUP BY c.id, c.name 
    HAVING worker_count > 0
    ORDER BY c.name
");

// Get total worker count
$total_workers = $conn->query("SELECT COUNT(*) as count FROM workers")->fetch_assoc()['count'];

include __DIR__ . '/../partials/header.php';
?>

<div class="card">
    <h3 class="card-title">Workers Directory</h3>
    
    <p style="color: #8fa0c9; margin-bottom: 1rem;">
        Contact information for maintenance and repair workers organized by work category.
    </p>
    
    <!-- Category Filter -->
    <form method="GET" class="grid cols-2" style="margin-bottom: 1rem;">
        <div>
            <label>Filter by Category</label>
            <select class="input" name="category" onchange="this.form.submit()">
                <option value="">All Categories (<?= $total_workers ?> workers)</option>
                <?php while ($category = $categories->fetch_assoc()): ?>
                    <option value="<?= (int)$category['id'] ?>" 
                            <?= $selected_category == $category['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($category['name']) ?> (<?= $category['worker_count'] ?>)
                    </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div style="display: flex; align-items: end;">
            <?php if ($selected_category > 0): ?>
                <a href="<?= BASE_URL ?>views/faculty/workers.php" class="btn outline">Clear Filter</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="table-card">
    <h3 class="card-title">
        <?php if ($selected_category > 0): ?>
            <?php 
            // Get category name for title
            $cat_stmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
            $cat_stmt->bind_param("i", $selected_category);
            $cat_stmt->execute();
            $cat_name = $cat_stmt->get_result()->fetch_assoc()['name'];
            echo htmlspecialchars($cat_name) . ' Workers';
            ?>
        <?php else: ?>
            All Workers
        <?php endif ?>
        (<?= $workers->num_rows ?>)
    </h3>
    
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th>Worker Name</th>
                    <th>Contact Information</th>
                    <th>Work Category</th>
                    <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                        <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($workers->num_rows > 0): ?>
                    <?php while ($worker = $workers->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($worker['name']) ?></strong>
                            </td>
                            <td>
                                <?php if (!empty($worker['contact'])): ?>
                                    <span style="color: #6ea8fe;"><?= htmlspecialchars($worker['contact']) ?></span>
                                <?php else: ?>
                                    <span style="color: #8fa0c9;">No contact available</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge good"><?= htmlspecialchars($worker['category_name']) ?></span>
                            </td>
                            <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                <td>
                                    <a href="<?= BASE_URL ?>views/admin/workers.php?edit=<?= (int)$worker['worker_id'] ?>" 
                                       class="btn small">Edit</a>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?= $_SESSION['user']['role'] === 'admin' ? '4' : '3' ?>" 
                            style="text-align: center; padding: 2rem; color: #8fa0c9;">
                            <?php if ($selected_category > 0): ?>
                                No workers found for this category.
                                <br><a href="<?= BASE_URL ?>views/faculty/workers.php" class="btn small" style="margin-top: 0.5rem;">View All Workers</a>
                            <?php else: ?>
                                No workers have been added to the system yet.
                                <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                    <br><a href="<?= BASE_URL ?>views/admin/workers.php" class="btn small" style="margin-top: 0.5rem;">Add First Worker</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Quick Reference Card for Popular Categories -->
<?php if ($selected_category == 0 && $workers->num_rows > 0): ?>
    <?php
    // Get worker counts by category for quick reference
    $category_summary = $conn->query("
        SELECT c.name, COUNT(w.worker_id) as worker_count
        FROM categories c 
        JOIN workers w ON w.category_id = c.id 
        GROUP BY c.id, c.name 
        ORDER BY worker_count DESC, c.name
        LIMIT 6
    ");
    ?>
    
    <div class="card">
        <h3 class="card-title">Quick Category Access</h3>
        <div class="button-row">
            <?php while ($summary = $category_summary->fetch_assoc()): ?>
                <?php 
                $category_id = $conn->query("SELECT id FROM categories WHERE name = '" . 
                                          $conn->real_escape_string($summary['name']) . "'")->fetch_assoc()['id'];
                ?>
                <a href="?category=<?= (int)$category_id ?>" class="btn outline small">
                    <?= htmlspecialchars($summary['name']) ?> (<?= $summary['worker_count'] ?>)
                </a>
            <?php endwhile; ?>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>