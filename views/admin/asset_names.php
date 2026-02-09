<?php
require_once __DIR__ . '/../../config/init.php';
ensure_role(['admin']);

$message = '';
$messageType = '';

// Handle delete request
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $asset_name_id = (int)$_GET['delete'];
    
    try {
        $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM assets WHERE asset_name_id = ?");
        $checkStmt->bind_param("i", $asset_name_id);
        $checkStmt->execute();
        $result = $checkStmt->get_result()->fetch_assoc();
        
        if ($result['count'] > 0) {
            $message = 'Cannot delete this asset name. It is being used by ' . $result['count'] . ' asset(s).';
            $messageType = 'error';
        } else {
            $stmt = $conn->prepare("DELETE FROM asset_names WHERE id = ?");
            $stmt->bind_param("i", $asset_name_id);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $message = 'Asset name deleted successfully';
                $messageType = 'success';
            } else {
                $message = 'Asset name not found';
                $messageType = 'error';
            }
        }
    } catch (Exception $e) {
        $message = 'Error deleting asset name: ' . $e->getMessage();
        $messageType = 'error';
    }
    
    header('Location: ' . BASE_URL . 'views/admin/asset_names.php?msg=' . urlencode($message) . '&type=' . $messageType);
    exit;
}

// Handle form submission (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF validation failed');
    $asset_name_id = isset($_POST['asset_name_id']) ? (int)$_POST['asset_name_id'] : 0;
    $name = trim($_POST['name'] ?? '');
    
    if (empty($name)) {
        $message = 'Asset name is required';
        $messageType = 'error';
    } else {
        try {
            if ($asset_name_id > 0) {
                $stmt = $conn->prepare("UPDATE asset_names SET name = ? WHERE id = ?");
                $stmt->bind_param("si", $name, $asset_name_id);
                $stmt->execute();
                $message = 'Asset name updated successfully';
            } else {
                $stmt = $conn->prepare("INSERT INTO asset_names (name) VALUES (?)");
                $stmt->bind_param("s", $name);
                $stmt->execute();
                $message = 'Asset name added successfully';
            }
            $messageType = 'success';
            // Redirect to avoid form resubmission and clear POST
            header('Location: ' . BASE_URL . 'views/admin/asset_names.php?msg=' . urlencode($message) . '&type=' . $messageType);
            exit;
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $message = 'This asset name already exists';
            } else {
                $message = 'Error: ' . $e->getMessage();
            }
            $messageType = 'error';
        }
    }
}

// ✅ OPTIMIZATION: Close session early to prevent locking
session_write_close();

// Get asset name for editing if ID is provided
$editAssetName = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM asset_names WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $editAssetName = $result->fetch_assoc();
}

// ✅ OPTIMIZATION: Fetch all data ONCE
$query = "
    SELECT an.*, COUNT(a.id) as usage_count
    FROM asset_names an
    LEFT JOIN assets a ON a.asset_name_id = an.id
    GROUP BY an.id
    ORDER BY an.name
";
$result = $conn->query($query);
$allAssetNames = $result->fetch_all(MYSQLI_ASSOC); // Fetch into array for reuse

// Create a lookup array for the "Quick Add" section (Faster than DB queries)
$existingNamesMap = [];
foreach ($allAssetNames as $an) {
    $existingNamesMap[strtolower($an['name'])] = true;
}

// Handle URL messages
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $messageType = $_GET['type'] ?? 'info';
}

include __DIR__ . '/../partials/header.php';
?>

<div class="card">
    <h3 class="card-title"><?= $editAssetName ? 'Edit Asset Name' : 'Add New Asset Name' ?></h3>
    
    <?php if ($message): ?>
        <div class="alert <?= $messageType === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    
    <form method="post" class="grid">
        <?= get_csrf_input() ?>
        <?php if ($editAssetName): ?>
            <input type="hidden" name="asset_name_id" value="<?= (int)$editAssetName['id'] ?>">
        <?php endif; ?>
        
        <div>
            <label>Asset Name <span style="color: red;">*</span></label>
            <input class="input" name="name" 
                   value="<?= htmlspecialchars($editAssetName['name'] ?? $_POST['name'] ?? '') ?>"
                   required
                   placeholder="e.g., Chair, Computer, Projector"
                   maxlength="255">
            <small style="color: #8fa0c9;">Enter a standardized asset name to reduce typos</small>
        </div>
        
        <div class="actions" style="text-align: center;">
            <button class="btn" type="submit">
                <?= $editAssetName ? 'Update Asset Name' : 'Add Asset Name' ?>
            </button>
            <?php if ($editAssetName): ?>
                <a href="<?= BASE_URL ?>views/admin/asset_names.php" class="btn outline">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="table-card">
    <h3 class="card-title">All Asset Names (<?= count($allAssetNames) ?>)</h3>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th>Asset Name</th>
                    <th>Usage Count</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($allAssetNames) > 0): ?>
                    <?php foreach ($allAssetNames as $assetName): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($assetName['name']) ?></strong></td>
                            <td>
                                <?php if ($assetName['usage_count'] > 0): ?>
                                    <span class="badge good"><?= $assetName['usage_count'] ?> assets</span>
                                <?php else: ?>
                                    <span class="badge na">0 assets</span>
                                <?php endif; ?>
                            </td>
                            <td><?= date('M j, Y', strtotime($assetName['created_at'])) ?></td>
                            <td>
                                <a href="?edit=<?= (int)$assetName['id'] ?>" class="btn small">Edit</a>
                                <?php if ($assetName['usage_count'] == 0): ?>
                                    <a href="?delete=<?= (int)$assetName['id'] ?>" 
                                       class="btn small outline"
                                       onclick="return confirm('Are you sure you want to delete this asset name?')"
                                       style="color: #ff6b6b;">Delete</a>
                                <?php else: ?>
                                    <span class="btn small outline" 
                                          style="opacity: 0.5; cursor: not-allowed;"
                                          title="Cannot delete - in use by <?= $assetName['usage_count'] ?> assets">Delete</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: #8fa0c9;">
                            No asset names found. Add your first asset name above.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3 class="card-title">Quick Add Common Asset Names</h3>
    <p style="color: #8fa0c9; margin-bottom: 1rem;">
        Click to quickly add common asset names to your system:
    </p>
    <div class="button-row" style="gap: 8px; flex-wrap: wrap;">
        <?php
        $commonNames = [
            'Chair', 'Desk', 'Table', 'Bench', 'Cabinet', 'Locker',
            'Computer', 'Monitor', 'Keyboard', 'Mouse', 'Printer', 'Scanner',
            'Projector', 'Screen', 'Whiteboard', 'Blackboard',
            'Fan', 'Air Conditioner', 'Light', 'Switch',
            'Speaker', 'Microphone', 'Camera', 'TV'
        ];
        
        foreach ($commonNames as $commonName):
            // ✅ OPTIMIZED: Check against PHP array instead of DB query
            if (!isset($existingNamesMap[strtolower($commonName)])): 
        ?>
            <form method="post" style="display: inline;">
                <?= get_csrf_input() ?>
                <input type="hidden" name="name" value="<?= htmlspecialchars($commonName) ?>">
                <button type="submit" class="btn small outline" style="margin: 2px;">
                    + <?= htmlspecialchars($commonName) ?>
                </button>
            </form>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
