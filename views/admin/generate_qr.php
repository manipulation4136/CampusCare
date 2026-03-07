<?php
require_once __DIR__ . '/../../config/init.php';
ensure_role(['admin', 'faculty']);

// Fetch all assets with their names
$query = "SELECT a.id, a.asset_code, an.name as asset_name, r.room_no 
          FROM assets a 
          JOIN asset_names an ON a.asset_name_id = an.id 
          LEFT JOIN rooms r ON a.room_id = r.id
          ORDER BY a.id DESC";
$result = $conn->query($query);

include __DIR__ . '/../partials/header.php';
?>

<style>
    .qr-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 20px;
        padding: 20px;
    }
    .qr-card {
        border: 1px solid #ddd;
        padding: 15px;
        text-align: center;
        background: #fff;
        color: #000; /* Force black text */
        border-radius: 8px;
        page-break-inside: avoid;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 250px;
    }
    .qr-img {
        width: 135px;
        height: 135px;
        margin-bottom: 12px;
    }
    .asset-name {
        font-weight: 800;
        margin-bottom: 6px;
        font-size: 1.15em;
        color: #111;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .asset-code {
        font-size: 1.05em;
        font-weight: 600;
        color: #333;
        margin-bottom: 4px;
    }
    .room-number {
        font-size: 0.95em;
        color: #555;
        font-weight: 600;
    }
    @media print {
        header, .sidebar, .footer, .btn-print, .main-content > h2 {
            display: none !important;
        }
        .main-content {
            margin: 0 !important;
            padding: 0 !important;
        }
        .qr-grid {
            gap: 30px;
        }
        .qr-card {
            border: 1px solid #000;
            box-shadow: none;
        }
        body {
            background: #fff;
        }
    }
</style>

<div class="main-content">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="color: #fff;">Asset QR Codes</h2>
        <button onclick="window.print()" class="btn btn-print">
            <i class="fas fa-print"></i> Print QR Codes
        </button>
    </div>

    <div class="qr-grid">
        <?php while($asset = $result->fetch_assoc()): 
            $reportUrl = BASE_URL . 'views/student/report_new.php?qr_id=' . urlencode($asset['asset_code']);
            $qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=135x135&data=' . urlencode($reportUrl);
        ?>
            <div class="qr-card">
                <img src="<?= $qrSrc ?>" alt="QR Code" class="qr-img">
                <div class="asset-name"><?= htmlspecialchars($asset['asset_name']) ?></div>
                <div class="asset-code"><?= htmlspecialchars($asset['asset_code']) ?></div>
                <div class="room-number">Room: <?= htmlspecialchars($asset['room_no'] ?? 'Unassigned') ?></div>
            </div>
        <?php endwhile; ?>
    </div>
    
    <?php if($result->num_rows === 0): ?>
        <p style="text-align: center; color: #666;">No assets found.</p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
