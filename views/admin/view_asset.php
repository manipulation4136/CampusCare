<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../config/asset_helper.php';
ensure_role(['admin', 'faculty']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    set_flash('err', 'Invalid Asset ID');
    header('Location: ' . BASE_URL . 'views/admin/assets.php');
    exit;
}

// Fetch Main Asset Details
$assetQuery = "
    SELECT 
        a.id,
        a.asset_code,
        a.status,
        a.warranty_end,
        an.created_at,
        an.name AS asset_name,
        c.name AS category_name,
        r.id AS room_id,
        r.building,
        r.floor,
        r.room_no,
        r.room_type,
        d.id AS dealer_id,
        d.name AS dealer_name,
        d.contact AS dealer_contact,
        p.id AS parent_id,
        p.asset_code AS parent_code,
        pan.name AS parent_name
    FROM assets a
    JOIN asset_names an ON an.id = a.asset_name_id
    LEFT JOIN categories c ON c.id = a.category_id
    JOIN rooms r ON r.id = a.room_id
    JOIN dealers d ON d.id = a.dealer_id
    LEFT JOIN assets p ON p.id = a.parent_asset_id
    LEFT JOIN asset_names pan ON pan.id = p.asset_name_id
    WHERE a.id = ?
";
$stmt = $conn->prepare($assetQuery);
$stmt->bind_param("i", $id);
$stmt->execute();
$asset = $stmt->get_result()->fetch_assoc();

if (!$asset) {
    set_flash('err', 'Asset not found');
    header('Location: ' . BASE_URL . 'views/admin/assets.php');
    exit;
}

// Fetch Child Assets (if this asset is a parent)
$childrenStmt = $conn->prepare("
    SELECT 
        c_a.id, 
        c_a.asset_code, 
        c_a.status, 
        c_an.name AS asset_name,
        cat.name AS category_name
    FROM assets c_a
    JOIN asset_names c_an ON c_an.id = c_a.asset_name_id
    LEFT JOIN categories cat ON cat.id = c_a.category_id
    WHERE c_a.parent_asset_id = ?
    ORDER BY c_an.name ASC
");
$childrenStmt->bind_param("i", $id);
$childrenStmt->execute();
$children = $childrenStmt->get_result();

// Fetch Maintenance & Repair History
$historyStmt = $conn->prepare("
    SELECT 
        issue_type,
        description,
        urgency_priority,
        status,
        created_at AS reported_date,
        updated_at AS resolved_date
    FROM damage_reports
    WHERE asset_id = ? 
      AND status = 'resolved'
    ORDER BY updated_at DESC
");
$historyStmt->bind_param("i", $id);
$historyStmt->execute();
$history = $historyStmt->get_result();

include __DIR__ . '/../partials/header.php';
?>

<div class="main-content">
    
    <!-- Header / Breadcrumb -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div style="display: flex; gap: 16px; align-items: center;">
            <a href="<?= BASE_URL ?>views/admin/assets.php" class="btn outline small">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <h2 style="margin: 0; color: #fff; font-size: 24px;">Asset Details</h2>
        </div>
        
        <div style="display: flex; gap: 12px; align-items: center;">
            <!-- Get QR -->
            <button onclick="downloadPrintReadyQR()" class="btn small outline" style="color: #2ecc71; border-color: #2ecc71; cursor: pointer;">
                <i class="fas fa-download"></i> Download QR
            </button>
            <!-- Edit Button -->
            <a href="<?= BASE_URL ?>views/admin/forms/edit_asset.php?id=<?= $asset['id'] ?>" class="btn small" style="background: #3498db; color: #fff;">
                <i class="fas fa-edit"></i> Edit Asset
            </a>
            <!-- Delete Button Form -->
            <form method="POST" action="<?= BASE_URL ?>views/admin/assets.php" style="margin: 0;" onsubmit="return confirm('Are you sure you want to completely delete this asset and its history? This action cannot be undone.');">
                <?= get_csrf_input() ?>
                <input type="hidden" name="delete_id" value="<?= $asset['id'] ?>">
                <button type="submit" class="btn small" style="background: rgba(231, 76, 60, 0.1); color: #e74c3c; border: 1px solid rgba(231, 76, 60, 0.3);">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </form>
        </div>
    </div>

    <?php if ($m = flash('ok')): ?><div class="alert success"><?= htmlspecialchars($m) ?></div><?php endif; ?>
    <?php if ($m = flash('err')): ?><div class="alert error"><?= htmlspecialchars($m) ?></div><?php endif; ?>

    <!-- Main Detail Card -->
    <div class="card" style="margin-bottom: 24px; padding: 32px;">
        <div class="grid cols-2" style="gap: 32px;">
            
            <!-- Left Info Pane -->
            <div>
                <!-- Primary Asset Identity with Inline QR Code -->
                <div style="display: flex; gap: 20px; align-items: flex-start; margin-bottom: 24px;">
                    <!-- Inline QR Code Display (Native Generation) -->
                    <div style="flex-shrink: 0; background: #ffffff; border-radius: 8px; padding: 6px; border: 1px solid rgba(255,255,255,0.1); cursor: pointer;" onclick="downloadPrintReadyQR()" title="Click to Download Print-Ready QR">
                        <div id="inlineQrCode" style="display: block; width: 80px; height: 80px;"></div>
                    </div>
                    
                    <div>
                        <h3 style="color: var(--text); font-size: 1.8rem; margin: 0 0 8px 0; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                            <?= htmlspecialchars($asset['asset_name']) ?>
                            <?php 
                            $status = $asset['status'];
                            $badge_class = 'neutral';
                            if ($status === 'Working' || $status === 'Good') $badge_class = 'good';
                            elseif ($status === 'Needs Repair' || $status === 'Bad') $badge_class = 'bad';
                            elseif ($status === 'In Repair' || $status === 'Maintenance') $badge_class = 'warning';
                            ?>
                            <span class="badge <?= $badge_class ?>" style="font-size: 0.9rem; padding: 4px 12px;"><?= htmlspecialchars($status) ?></span>
                        </h3>
                        <div style="color: var(--muted); font-size: 1.1rem; font-family: monospace; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-barcode"></i> <?= htmlspecialchars($asset['asset_code']) ?>
                        </div>
                    </div>
                </div>

                <!-- Specs -->
                <h4 style="color: var(--text); border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 8px; margin-bottom: 16px;">
                    <i class="fas fa-info-circle" style="color: #3498db; margin-right: 8px;"></i> Specifications
                </h4>
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 24px;">
                    <tbody>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <td style="padding: 10px 0; color: var(--muted); width: 35%;">Category</td>
                            <td style="padding: 10px 0; color: var(--text); font-weight: 500;"><?= htmlspecialchars($asset['category_name'] ?? 'Uncategorized') ?></td>
                        </tr>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <td style="padding: 10px 0; color: var(--muted);">Added On</td>
                            <td style="padding: 10px 0; color: var(--text); font-weight: 500;"><?= date('M j, Y', strtotime($asset['created_at'])) ?></td>
                        </tr>
                        <?php if ($asset['parent_id']): ?>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <td style="padding: 10px 0; color: var(--muted);">Linked Parent</td>
                            <td style="padding: 10px 0; color: #3498db; font-weight: 500;">
                                <a href="view_asset.php?id=<?= (int)$asset['parent_id'] ?>" style="color: inherit; text-decoration: none;">
                                    <i class="fas fa-link"></i> <?= htmlspecialchars($asset['parent_name'] . ' (' . $asset['parent_code'] . ')') ?>
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Right Info Pane -->
            <div>
                <!-- Location Info -->
                <h4 style="color: var(--text); border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 8px; margin-bottom: 16px;">
                    <i class="fas fa-map-marker-alt" style="color: #e74c3c; margin-right: 8px;"></i> Current Location
                </h4>
                <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); padding: 16px; border-radius: 8px; margin-bottom: 24px;">
                    <div style="font-size: 1.1rem; font-weight: 500; color: var(--text); margin-bottom: 4px;">
                        Room <?= htmlspecialchars($asset['room_no']) ?>
                        <a href="view_room.php?id=<?= $asset['room_id'] ?>" style="color: #3498db; font-size: 0.9rem; margin-left: 8px;"><i class="fas fa-external-link-alt"></i> View Room</a>
                    </div>
                    <div style="color: var(--muted); font-size: 0.9rem;">
                        <?= htmlspecialchars($asset['building']) ?> Building &bull; <?= htmlspecialchars($asset['floor']) ?> &bull; <?= htmlspecialchars($asset['room_type']) ?>
                    </div>
                    <div style="margin-top: 12px;">
                        <a href="<?= BASE_URL ?>views/admin/forms/move_asset.php?id=<?= $asset['id'] ?>" class="btn small outline">
                            <i class="fas fa-exchange-alt"></i> Relocate Asset
                        </a>
                    </div>
                </div>

                <!-- Dealer Info & Warranty -->
                <h4 style="color: var(--text); border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 8px; margin-bottom: 16px;">
                    <i class="fas fa-truck" style="color: #f39c12; margin-right: 8px;"></i> Dealer Information
                </h4>
                <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); padding: 16px; border-radius: 8px;">
                    <div style="font-size: 1.1rem; font-weight: 500; color: var(--text); margin-bottom: 4px;">
                        <?= htmlspecialchars($asset['dealer_name']) ?>
                    </div>
                    <?php if (!empty($asset['dealer_contact'])): ?>
                    <div style="color: var(--muted); font-size: 0.95rem; margin-bottom: 4px;">
                        <i class="fas fa-phone-alt" style="width: 16px; text-align: center; margin-right: 6px;"></i> <?= htmlspecialchars($asset['dealer_contact']) ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($asset['dealer_email'])): ?>
                    <div style="color: var(--muted); font-size: 0.95rem;">
                        <i class="fas fa-envelope" style="width: 16px; text-align: center; margin-right: 6px;"></i> <?= htmlspecialchars($asset['dealer_email']) ?>
                    </div>
                    <?php endif; ?>

                    <!-- Warranty Section -->
                    <?php if (!empty($asset['warranty_end'])): ?>
                    <?php 
                        $warranty_end = new DateTime($asset['warranty_end']);
                        $today = new DateTime('today');
                        
                        $is_active = $warranty_end >= $today;
                        $interval = $today->diff($warranty_end);
                        
                        $duration_str = '';
                        if ($interval->y > 0) {
                            $duration_str .= $interval->y . ' year' . ($interval->y > 1 ? 's ' : ' ');
                        }
                        if ($interval->m > 0) {
                            $duration_str .= $interval->m . ' month' . ($interval->m > 1 ? 's ' : ' ');
                        }
                        if ($interval->d > 0 && $interval->y == 0 && $interval->m == 0) {
                            $duration_str .= $interval->d . ' day' . ($interval->d > 1 ? 's ' : ' ');
                        }
                        $duration_str = trim($duration_str);
                        
                        if (empty($duration_str)) {
                            $duration_text = $is_active ? "Expires today" : "Expired today";
                        } else {
                            $duration_text = $is_active ? "Expires in " . $duration_str : "Expired " . $duration_str . " ago";
                        }

                        $w_badge_class = $is_active ? 'good' : 'bad';
                        $w_status_text = $is_active ? 'Active' : 'Expired';
                    ?>
                    <div style="margin-top: 16px; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 16px;">
                        <h5 style="color: var(--text); margin: 0 0 8px 0; font-size: 1rem;"><i class="fas fa-shield-alt" style="color: #2ecc71; margin-right: 6px;"></i> Warranty Information</h5>
                        
                        <div style="color: var(--muted); font-size: 0.95rem; margin-bottom: 6px;">
                            Status: <span style="color: var(--text); font-weight: 500;"><?= htmlspecialchars($duration_text) ?></span>
                        </div>
                        
                        <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.95rem;">
                            <span style="color: var(--muted);">End Date: <span style="color: var(--text); font-weight: 500;"><?= $warranty_end->format('M j, Y') ?></span></span>
                            <span class="badge <?= $w_badge_class ?>" style="font-size: 0.8rem; padding: 3px 10px;"><?= $w_status_text ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
        </div>
    </div>

    <!-- Maintenance & Repair History Card -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="table-header-row" style="padding: 24px; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: space-between;">
            <h3 class="card-title" style="margin: 0; display: flex; align-items: center; gap: 8px; color: var(--text);">
                <i class="fas fa-tools" style="color: #e67e22;"></i> 
                Maintenance & Repair History
            </h3>
            <span class="badge neutral" style="font-size: 0.9rem; padding: 4px 12px;"><?= $history->num_rows ?> Record(s)</span>
        </div>
        
        <?php if ($history->num_rows > 0): ?>
            <div class="table-scroll">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Issue Type</th>
                            <th>Description</th>
                            <th>Resolved Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($record = $history->fetch_assoc()): ?>
                            <tr>
                                <td style="font-weight: 500; color: var(--text);">
                                    <?= htmlspecialchars($record['issue_type']) ?>
                                    <?php if ($record['urgency_priority'] === 'Critical'): ?>
                                        <span class="badge bad" style="margin-left: 8px; font-size: 0.75rem;">Critical</span>
                                    <?php elseif ($record['urgency_priority'] === 'High'): ?>
                                        <span class="badge warning" style="margin-left: 8px; font-size: 0.75rem;">High</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color: var(--muted); max-width: 300px; white-space: normal;">
                                    <?= htmlspecialchars($record['description'] ?? 'No description provided') ?>
                                </td>
                                <td style="color: #2ecc71; font-weight: 500;">
                                    <?php 
                                    $res_date = strtotime($record['resolved_date'] ?? $record['reported_date']);
                                    echo date('M j, Y g:i A', $res_date);
                                    ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="padding: 40px; text-align: center; color: var(--muted);">
                <i class="fas fa-clipboard-check" style="font-size: 3rem; opacity: 0.3; margin-bottom: 16px; display: block;"></i>
                <p style="margin: 0; font-size: 1.1rem;">No past damages or repairs recorded for this asset.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Linked Child Assets Wrapper -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="table-header-row" style="padding: 24px; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: space-between;">
            <h3 class="card-title" style="margin: 0; display: flex; align-items: center; gap: 8px; color: var(--text);">
                <i class="fas fa-sitemap" style="color: #9b59b6;"></i> 
                Child Components & Linked Assets (<?= $children->num_rows ?>)
            </h3>
        </div>
        
        <?php if ($children->num_rows > 0): ?>
            <div class="table-scroll">
                <table class="table">
                    <thead>
                        <tr>
                            <th>View</th>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($child = $children->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <a href="view_asset.php?id=<?= $child['id'] ?>" class="btn small outline" style="color: #3498db; border-color: #3498db;">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                                <td style="font-weight: 500; font-family: monospace; color: #3498db;"><?= htmlspecialchars($child['asset_code']) ?></td>
                                <td><?= htmlspecialchars($child['asset_name']) ?></td>
                                <td><?= htmlspecialchars($child['category_name'] ?? 'Uncategorized') ?></td>
                                <td>
                                    <?php 
                                    $c_status = $child['status'];
                                    $c_badge = 'neutral';
                                    if ($c_status === 'Working' || $c_status === 'Good') $c_badge = 'good';
                                    elseif ($c_status === 'Needs Repair' || $c_status === 'Bad') $c_badge = 'bad';
                                    elseif ($c_status === 'In Repair' || $c_status === 'Maintenance') $c_badge = 'warning';
                                    ?>
                                    <span class="badge <?= $c_badge ?>"><?= htmlspecialchars($c_status) ?></span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="padding: 40px; text-align: center; color: var(--muted);">
                <i class="fas fa-plug" style="font-size: 3rem; opacity: 0.3; margin-bottom: 16px; display: block;"></i>
                <p style="margin: 0; font-size: 1.1rem;">No child components are currently linked to this parent asset.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Include local QRCode generating library -->
<script src="<?= BASE_URL ?>assets/js/qrcode.min.js"></script>
<script>
    // Asset Data needed for QR logic
    const assetCode = <?= json_encode($asset['asset_code']) ?>;
    const assetName = <?= json_encode($asset['asset_name']) ?>;
    // URL identical to the logic found in generate_qr.php
    const reportUrl = <?= json_encode(BASE_URL . 'views/student/report_new.php?qr_id=' . urlencode($asset['asset_code'])) ?>;

    // Render the inline small QR code on the page (80x80)
    document.addEventListener("DOMContentLoaded", () => {
        const qrContainer = document.getElementById("inlineQrCode");
        qrContainer.innerHTML = ""; // Clear fallback
        new QRCode(qrContainer, {
            text: reportUrl,
            width: 80,
            height: 80,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.L
        });
    });

    // Native Function to generate & download Print-Ready Card directly from browser
    function downloadPrintReadyQR() {
        // Create an off-screen container for a 150x150 code (similar to generate_qr.php's visual)
        const tempDiv = document.createElement('div');
        tempDiv.style.position = 'absolute';
        tempDiv.style.left = '-9999px';
        document.body.appendChild(tempDiv);
        
        new QRCode(tempDiv, {
            text: reportUrl,
            width: 150,
            height: 150,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.L
        });
        
        // Wait briefly for the library to draw onto its sub-canvas
        setTimeout(() => {
            const qrCanvas = tempDiv.querySelector('canvas');
            if (!qrCanvas) {
                alert("Failed to extract Generated QR Code.");
                return;
            }
            
            // Replicate the exact visual layout of generate_qr.php .qr-card
            const cardWidth = 190;
            const cardHeight = 250;
            const finalCanvas = document.createElement('canvas');
            finalCanvas.width = cardWidth;
            finalCanvas.height = cardHeight;
            const ctx = finalCanvas.getContext('2d');
            
            // White Card Background
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, cardWidth, cardHeight);
            
            // Card Border (#ddd equivalent)
            ctx.strokeStyle = '#dddddd';
            ctx.lineWidth = 1;
            ctx.strokeRect(0, 0, cardWidth, cardHeight);
            
            // Draw the QR Code centered near the top (15px padding as per .qr-card)
            ctx.drawImage(qrCanvas, 20, 15, 150, 150);
            
            // Common text setups ensuring clarity
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';
            
            // Asset Name (.asset-name: bold, 1.1em -> ~17px, #000)
            ctx.fillStyle = '#000000';
            ctx.font = 'bold 17px sans-serif';
            // Simple string clipping if it's too long
            const truncatedName = assetName.length > 20 ? assetName.substring(0, 18) + '...' : assetName;
            ctx.fillText(truncatedName, cardWidth / 2, 180);
            
            // Asset Code (.asset-code: monospace, 0.9em -> ~14px, #333)
            ctx.fillStyle = '#333333';
            ctx.font = '14px monospace';
            ctx.fillText(assetCode, cardWidth / 2, 205);
            
            // Create a downloadable anchor
            const link = document.createElement('a');
            link.download = `Asset_QR_${assetCode}.png`;
            link.href = finalCanvas.toDataURL('image/png');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            document.body.removeChild(tempDiv);
            
        }, 150);
    }
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>

