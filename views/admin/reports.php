<?php
require_once __DIR__ . '/../../config/init.php';
ensure_role('admin');

// Handle Delete Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!verify_csrf()) die('CSRF validation failed');
    $del_id = (int)$_POST['delete_id'];
    
    $stmt = $conn->prepare("DELETE FROM damage_reports WHERE id = ?");
    $stmt->bind_param("i", $del_id);
    
    if ($stmt->execute()) {
        set_flash('ok', 'Report deleted successfully');
    } else {
        set_flash('err', 'Failed to delete report');
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
    $whereSQL = "WHERE a.asset_code LIKE ? OR reporter.name LIKE ? OR u.name LIKE ? OR dr.description LIKE ?";
    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
    $types = "ssss";
}

// Count Total
$countQuery = "
    SELECT COUNT(*) as total 
    FROM damage_reports dr
    JOIN assets a ON a.id = dr.asset_id
    LEFT JOIN users u ON u.id = dr.assigned_to
    LEFT JOIN users reporter ON reporter.id = dr.reported_by
    $whereSQL
";
$stmt = $conn->prepare($countQuery);
if ($search) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$totalReports = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalReports / $limit);

// Fetch Data
$reportsQuery = "
    SELECT 
        dr.id, 
        dr.id, 
        dr.asset_id, /* Added asset_id fetch */
        dr.suggested_real_code, /* NEW */
        dr.description, 
        dr.status, 
        dr.created_at, 
        dr.image_path, 
        dr.urgency_priority,
        a.asset_code, 
        an.name AS asset_name, 
        r.building, 
        r.floor, 
        r.room_no,
        u.name AS staff_name, 
        reporter.name AS reporter_name
    FROM damage_reports dr
    JOIN assets a ON a.id = dr.asset_id
    JOIN asset_names an ON an.id = a.asset_name_id
    JOIN rooms r ON r.id = a.room_id
    LEFT JOIN users u ON u.id = dr.assigned_to
    LEFT JOIN users reporter ON reporter.id = dr.reported_by
    $whereSQL
    ORDER BY CASE dr.urgency_priority
        WHEN 'Critical' THEN 1
        WHEN 'High' THEN 2
        WHEN 'Medium' THEN 3
        WHEN 'Low' THEN 4
        ELSE 5
    END, dr.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($reportsQuery);
if ($search) {
    $stmt->bind_param($types . "ii", ...array_merge($params, [$limit, $offset]));
} else {
    $stmt->bind_param("ii", $limit, $offset);
}
$stmt->execute();
$reports = $stmt->get_result();

include __DIR__.'/../partials/header.php';
?>

<div class="main-content">
    <?php if ($m = flash('ok')): ?><div class="alert success"><?= htmlspecialchars($m) ?></div><?php endif; ?>
    <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>

    <div class="table-card">
        <style>
            .responsive-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 1rem;
                flex-wrap: wrap;
                gap: 1rem;
            }
            .responsive-header form {
                display: flex;
                gap: 0.5rem;
            }
            @media (max-width: 768px) {
                .responsive-header {
                    flex-direction: column;
                    align-items: stretch;
                }
                .responsive-header form {
                    width: 100%;
                }
                .responsive-header input[name="search"] {
                    flex-grow: 1;
                    width: auto !important;
                }
            }
        </style>
        <div class="responsive-header">
            <h3 class="card-title" style="margin: 0;">Damage Reports (<?= $totalReports ?>)</h3>
            <form method="get">
                <input class="input" name="search" placeholder="Search reports..." value="<?= htmlspecialchars($search) ?>" style="width: 280px;">
                <button class="btn small" type="submit">Search</button>
                <?php if ($search): ?>
                    <a href="reports.php" class="btn outline small">Clear</a>
                <?php endif; ?>
            </form>
        </div>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Asset</th>
                        <th>Room</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Staff</th>
                        <th>Reporter</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($reports->num_rows > 0): ?>
                        <?php while($dr = $reports->fetch_assoc()):
                            // Set urgency badge class
                            $urgencyClass = match($dr['urgency_priority']) {
                                'Critical' => 'bad',
                                'High' => 'warn',
                                'Medium' => 'na',
                                'Low' => 'good',
                                default => 'na'
                            };
                        ?>
                            <tr>
                                <td>
                                    <?php if(!empty($dr['image_path'])): ?>
                                        <img class="img-thumb"
                                             src="<?= BASE_URL . htmlspecialchars(ltrim($dr['image_path'], '/')) ?>"
                                             onclick="showImage('<?= BASE_URL . htmlspecialchars(ltrim($dr['image_path'], '/')) ?>')">
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($dr['asset_code'].' - '.$dr['asset_name']) ?></td>
                                <td><?= htmlspecialchars($dr['building'].'/'.$dr['floor'].'/'.$dr['room_no']) ?></td>
                                <td><span class="badge <?= $urgencyClass ?>"><?= htmlspecialchars($dr['urgency_priority']) ?></span></td>
                                <td><span class="badge <?= strtolower($dr['status']) ?>"><?= htmlspecialchars($dr['status']) ?></span></td>
                                <td><?= $dr['staff_name'] ? htmlspecialchars($dr['staff_name']) : '-' ?></td>
                                <td><?= $dr['reporter_name'] ? htmlspecialchars($dr['reporter_name']) : '-' ?></td>
                                <td><?= date('M j, Y', strtotime($dr['created_at'])) ?></td>
                                <td>
                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to delete this report?');">
                                        <?= get_csrf_input() ?>
                                        <input type="hidden" name="delete_id" value="<?= $dr['id'] ?>">
                                        <button type="submit" class="btn icon-btn" style="color: #e74c3c; background: none; border: none; padding: 5px;" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                    
                                    <?php if (strpos($dr['asset_code'], 'MS-') === 0): ?>
                                        <?php if (!empty($dr['suggested_real_code'])): ?>
                                            <!-- APPROVE MERGE BUTTON -->
                                            <div style="margin-top: 5px;">
                                                <button class="btn small" 
                                                        style="background-color: #27ae60; color: #fff; font-size: 11px; width: 100%; margin-bottom: 2px;"
                                                        onclick="openMergeModal(<?= $dr['asset_id'] ?>, '<?= $dr['asset_code'] ?>'); setTimeout(() => { document.getElementById('realAssetCode').value = '<?= $dr['suggested_real_code'] ?>'; }, 100);">
                                                    ✅ Approve: <?= $dr['suggested_real_code'] ?>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <!-- MANUAL MERGE BUTTON -->
                                            <button class="btn small outline" 
                                                    style="margin-left: 5px; border-color: #f1c40f; color: #f1c40f;"
                                                    onclick="openMergeModal(<?= $dr['asset_id'] ?>, '<?= $dr['asset_code'] ?>')">
                                                <i class="fas fa-link"></i> Merge
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 40px; color: #8fa0c9;">
                                No reports found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($totalPages > 1): ?>
        <div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 1rem;">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>" class="btn outline small">Previous</a>
            <?php endif; ?>
            
            <span style="align-self: center;">Page <?= $page ?> of <?= $totalPages ?></span>
            
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>" class="btn outline small">Next</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div id="imageModal" class="modal" onclick="closeModal('imageModal')" style="display:none;">
    <img id="modalImage" alt="Damage Report">
</div>

<script>
function showImage(src) {
    var modal = document.getElementById('imageModal');
    // Move to body to ensure fixed positioning works (escapes any parent transforms)
    if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }
    document.getElementById('modalImage').src = src;
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden'; // Lock scroll
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
    document.body.style.overflow = ''; // Unlock scroll
}

// Check for Merge Success
function checkMergeStatus() {
    // If needed, we can check for URL params here, but we are doing AJAX
}

let currentGhostId = null;

function openMergeModal(ghostId, ghostCode) {
    currentGhostId = ghostId;
    document.getElementById('ghostCodeDisplay').textContent = ghostCode;
    document.getElementById('realAssetCode').value = '';
    
    // Position modal in body
    const modal = document.getElementById('mergeAssetModal');
    if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }
    
    modal.style.display = 'flex';
}

async function submitMerge() {
    const realCode = document.getElementById('realAssetCode').value.trim();
    if (!realCode) {
        alert("Please enter a Real Asset Code.");
        return;
    }
    
    if (!confirm("Are you sure you want to merge this temporary asset into " + realCode + "?\nThis cannot be undone.")) {
        return;
    }
    
    try {
        const response = await fetch('<?= BASE_URL ?>includes/merge_asset.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ghost_asset_id: currentGhostId,
                real_asset_code: realCode
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert("Report transferred to " + realCode + ". Temporary asset deleted.");
            location.reload();
        } else {
            alert("Error: " + (data.error || "Unknown error occurred"));
        }
    } catch (e) {
        alert("System Error: " + e.message);
    }
}

// Add Enter key listener for the modal input
document.getElementById('realAssetCode').addEventListener('keypress', function (e) {
    if (e.key === 'Enter') {
        submitMerge();
    }
});
</script>

<!-- Merge Modal -->
<div id="mergeAssetModal" class="modal" style="display:none; justify-content: center; align-items: center;">
    <div class="glass-card modal-content" style="max-width: 450px; width: 100%; border: 1px solid #f1c40f;">
        <h3 style="color: #f1c40f; margin-bottom: 15px;"><i class="fas fa-link"></i> Identify Missing Sticker</h3>
        <p style="color: #ddd; margin-bottom: 20px;">
            You are resolving the temporary asset: <strong id="ghostCodeDisplay" style="color: #fff;"></strong>.<br>
            Please enter the <strong>Real Asset Code</strong> found on the physical item.
        </p>
        
        <div class="input-group" style="margin-bottom: 20px;">
            <input type="text" id="realAssetCode" class="input-dark" placeholder="Enter Real Asset Code (e.g., AST-101)">
            <i class="fas fa-barcode"></i>
        </div>
        
        <div style="display: flex; gap: 10px;">
            <button onclick="submitMerge()" class="btn-login" style="flex: 1; background: #f1c40f; color: #000;">Merge & Resolve</button>
            <button onclick="document.getElementById('mergeAssetModal').style.display='none'" class="btn-login outline" style="flex: 1;">Cancel</button>
        </div>
    </div>
</div>

<?php include __DIR__.'/../partials/footer.php'; ?>
