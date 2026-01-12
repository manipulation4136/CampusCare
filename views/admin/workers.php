<?php
require_once __DIR__ . '/../../config/init.php';
ensure_role(['admin']);

$message = '';
$messageType = '';

// Handle delete request
// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verify_csrf()) die('CSRF validation failed');
    $worker_id = (int)$_POST['delete_id'];
    try {
        $stmt = $conn->prepare("DELETE FROM workers WHERE worker_id = ?");
        $stmt->bind_param("i", $worker_id);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $message = 'Worker deleted successfully';
            $messageType = 'success';
        } else {
            $message = 'Worker not found';
            $messageType = 'error';
        }
    } catch (Exception $e) {
        $message = 'Error deleting worker: ' . $e->getMessage();
        $messageType = 'error';
    }
    header('Location: ' . BASE_URL . 'views/admin/workers.php?msg=' . urlencode($message) . '&type=' . $messageType);
    exit;
}

// Handle form submission (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');
    $worker_id = isset($_POST['worker_id']) ? (int)$_POST['worker_id'] : 0;
    $name = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    
    if (empty($name) || $category_id <= 0) {
        $message = 'Name and Category are required';
        $messageType = 'error';
    } else {
        try {
            if ($worker_id > 0) {
                // Edit existing worker
                $stmt = $conn->prepare("UPDATE workers SET name = ?, contact = ?, category_id = ? WHERE worker_id = ?");
                $stmt->bind_param("ssii", $name, $contact, $category_id, $worker_id);
                $stmt->execute();
                $message = 'Worker updated successfully';
            } else {
                // Add new worker
                $stmt = $conn->prepare("INSERT INTO workers (name, contact, category_id) VALUES (?, ?, ?)");
                $stmt->bind_param("ssi", $name, $contact, $category_id);
                $stmt->execute();
                $message = 'Worker added successfully';
            }
            $messageType = 'success';
            $_POST = []; // Clear form
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get worker for editing if ID is provided
$editWorker = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM workers WHERE worker_id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $editWorker = $result->fetch_assoc();
}

// Get categories for dropdown
$categories = $conn->query("SELECT id, name FROM categories ORDER BY name");

// Get workers with category names
$workers = $conn->query("
    SELECT w.*, c.name as category_name 
    FROM workers w 
    JOIN categories c ON c.id = w.category_id 
    ORDER BY c.name, w.name
");

// Handle URL messages
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $messageType = $_GET['type'] ?? 'info';
}

include __DIR__ . '/../partials/header.php';
?>

<div class="card">
    <h3 class="card-title"><?= $editWorker ? 'Edit Worker' : 'Add New Worker' ?></h3>
    
    <?php if ($message): ?>
        <div class="alert <?= $messageType === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    
    <form method="post" class="grid cols-3">
        <?= get_csrf_input() ?>
        <?php if ($editWorker): ?>
            <input type="hidden" name="worker_id" value="<?= (int)$editWorker['worker_id'] ?>">
        <?php endif; ?>
        
        <div>
            <label>Worker Name <span style="color: red;">*</span></label>
            <input class="input" name="name" 
                   value="<?= htmlspecialchars($editWorker['name'] ?? $_POST['name'] ?? '') ?>" 
                   required 
                   placeholder="e.g., John Doe - Electrician">
        </div>
        
        <div>
            <label>Contact Information</label>
            <input class="input" name="contact" 
                   value="<?= htmlspecialchars($editWorker['contact'] ?? $_POST['contact'] ?? '') ?>" 
                   placeholder="Phone number or email">
        </div>
        
        <div>
            <label>Work Category <span style="color: red;">*</span></label>
            <select class="input" name="category_id" required>
                <option value="">Select Category</option>
                <?php 
                $categories->data_seek(0); // Reset pointer
                while ($category = $categories->fetch_assoc()): 
                    $selected = '';
                    if ($editWorker && $category['id'] == $editWorker['category_id']) {
                        $selected = 'selected';
                    } elseif (isset($_POST['category_id']) && $category['id'] == $_POST['category_id']) {
                        $selected = 'selected';
                    }
                ?>
                    <option value="<?= (int)$category['id'] ?>" <?= $selected ?>>
                        <?= htmlspecialchars($category['name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>
        
        <div class="actions" style="text-align: center;">
            <button class="btn" type="submit">
                <?= $editWorker ? 'Update Worker' : 'Add Worker' ?>
            </button>
            <?php if ($editWorker): ?>
                <a href="<?= BASE_URL ?>views/admin/workers.php" class="btn outline">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="table-card">
    <h3 class="card-title">All Workers (<?= $workers->num_rows ?>)</h3>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Contact</th>
                    <th>Category</th>
                    <th>Added</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($workers->num_rows > 0): ?>
                    <?php while ($worker = $workers->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($worker['name']) ?></td>
                            <td>
                                <?php if (!empty($worker['contact'])): ?>
                                    <?= htmlspecialchars($worker['contact']) ?>
                                <?php else: ?>
                                    <span style="color: #8fa0c9;">No contact</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="tag"><?= htmlspecialchars($worker['category_name']) ?></span>
                            </td>
                            <td><?= date('M j, Y', strtotime($worker['created_at'])) ?></td>
                            <td>
                                <a href="?edit=<?= (int)$worker['worker_id'] ?>" class="btn small">Edit</a>
                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this worker?');">
                                        <?= get_csrf_input() ?>
                                        <input type="hidden" name="delete_id" value="<?= $worker['worker_id'] ?>">
                                        <button type="submit" class="btn small outline" style="color: #ff6b6b; border: 1px solid #ff6b6b;" title="Delete">Delete</button>
                                    </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: #8fa0c9;">
                            No workers found. Add your first worker above.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>