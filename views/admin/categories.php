<?php
require_once __DIR__ . '/../../config/init.php';
ensure_role(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    if (!verify_csrf()) die('CSRF validation failed');
    $name = trim($_POST['name']);
    
    if (!empty($name)) {
        $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->bind_param("s", $name);
        
        if ($stmt->execute()) {
            $success = "Category added successfully.";
        } else {
            $error = "Category already exists or invalid name.";
        }
    } else {
        $error = "Category name is required.";
    }
}
// Handle Delete
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verify_csrf()) die('CSRF validation failed');
    $del_id = (int)$_POST['delete_id'];
    
    // Check usage
    $check = $conn->prepare("SELECT COUNT(*) as c FROM assets WHERE category_id = ?");
    $check->bind_param("i", $del_id);
    $check->execute();
    $count = $check->get_result()->fetch_assoc()['c'];
    
    if ($count > 0) {
        $error = "Cannot delete: This category is used by $count assets.";
    } else {
        $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->bind_param("i", $del_id);
        if ($stmt->execute()) {
            $success = 'Category deleted successfully.';
        } else {
            $error = 'Failed to delete category.';
        }
    }
}

$categories = $conn->query("
    SELECT c.*, COUNT(a.id) as asset_count 
    FROM categories c 
    LEFT JOIN assets a ON c.id = a.category_id 
    GROUP BY c.id 
    ORDER BY c.name
");

include __DIR__ . '/../partials/header.php';
?>

<div class="container">
    <div class="card">
        <h2 class="card-title">Add Category</h2>    
        <div class="card-body">
            <?php if (isset($success)): ?>
                <div class="alert success"><?= $success ?></div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="alert error"><?= $error ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <?= get_csrf_input() ?>
                <div class="form-group">
                    <input class="input" type="text" name="name" class="form-control" 
                           placeholder="Category Name" required maxlength="100">
                </div>
                <div class="text-center" style="margin-top: 1rem;">
                    <button type="submit" name="add_category" class="btn">
                        Add Category
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="table-card">
        <h3 class="card-title">Assets by Priority</h3>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Assets</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($cat = $categories->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($cat['name']) ?></td>
                            <td>
                                <span class="badge badge-info"><?= $cat['asset_count'] ?></span>
                            </td>
                            <td>
                                <?php if ($cat['asset_count'] == 0): ?>
                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this category?');">
                                        <?= get_csrf_input() ?>
                                        <input type="hidden" name="delete_id" value="<?= $cat['id'] ?>">
                                        <button type="submit" class="btn icon-btn" style="color: #e74c3c; background: none; border: none; padding: 5px;" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
