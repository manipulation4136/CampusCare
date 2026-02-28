<?php
require_once __DIR__ . '/../../config/init.php';
ensure_role('student');

$user_id = (int)$_SESSION['user']['id'];
$user_name = $_SESSION['user']['name'];

// 1. Fetch Recent Activity (Last 3 Reports)
$query = "
    SELECT dr.id, dr.created_at, dr.status, dr.image_path, a.asset_code, an.name as asset_name
    FROM damage_reports dr
    JOIN assets a ON a.id = dr.asset_id
    JOIN asset_names an ON an.id = a.asset_name_id
    WHERE dr.reported_by = ?
    ORDER BY dr.created_at DESC
    LIMIT 3
";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$recent_reports = $stmt->get_result();

// 2. Fetch Telegram Status
$telegram_chat_id = '';
$stmt = $conn->prepare("SELECT telegram_chat_id FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $telegram_chat_id = $row['telegram_chat_id'];
}

include __DIR__.'/../partials/header.php';
?>
<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

<div class="container" style="max-width: 600px; padding-bottom: 80px;">
    
    <!-- 1. Header Section -->
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 28px; margin: 0; color: #fff;">Hello, <?= htmlspecialchars(ucwords($user_name)) ?></h1>
        <p style="margin: 4px 0 0; color: #8fa0c9;">Report & Track Issues</p>
    </div>

    <!-- 2. Hero Action Card -->
    <a href="javascript:void(0)" onclick="openScannerModal()" class="hero-card">
        <div class="hero-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                <circle cx="12" cy="13" r="4"></circle>
            </svg>
        </div>
        <h2 class="hero-title">Report New Damage</h2>
        <div class="hero-subtitle">Tap to identify and report a broken asset</div>
    </a>

    <!-- 3. Telegram Banner (If not connected) -->
    <?php if (empty($telegram_chat_id)): ?>
    <div class="telegram-banner">
        <div class="tg-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21.198 2.433a2.242 2.242 0 0 0-1.022.215l-8.609 3.33c-2.068.8-4.133 1.598-5.724 2.21a405.15 405.15 0 0 1-2.866 1.092c-1.424.547-2.31 1.258-2.617 2.029-.4 1.002.228 1.94 1.137 2.478l4.463 2.637.039.023.006.003c.516.304.996.793 1.25 1.341l.011.025c.01.02.02.04.03.06.326.702 1.353 2.924 1.91 4.128.536 1.159 1.517 1.458 2.373 1.115.823-.33 1.253-1.088 1.564-1.637.288-.508.625-1.101.957-1.688l5.808-10.222a2.3 2.3 0 0 0-.276-2.906 2.27 2.27 0 0 0-1.847-.63z"/>
                <path d="M10 13l6-5"/>
            </svg>
        </div>
        <div class="tg-content">
            <h4>Get Instant Updates</h4>
            <p>Connect Telegram to get notified when your reports are fixed.</p>
        </div>
        <a href="<?= BASE_URL ?>views/telegram_setup.php" class="tg-btn">Connect</a>
    </div>
    <?php endif; ?>

    <!-- 4. Recent Activity Feed -->
    <div class="section-header">
        <h3 class="section-title">Recent Activity</h3>
        <a href="<?= BASE_URL ?>views/student/history.php" class="see-all">View All</a>
    </div>

    <div class="activity-feed">
        <?php if ($recent_reports->num_rows > 0): ?>
            <?php while ($report = $recent_reports->fetch_assoc()): 
                $statusClass = strtolower($report['status']);
                if ($statusClass === 'fixed') $statusClass = 'completed'; // Normalize
            ?>
            <div class="activity-card">
                <!-- Thumbnail or Default Icon -->
                <div class="activity-thumb">
                    <?php if (!empty($report['image_path'])): ?>
                        <img src="<?= BASE_URL . htmlspecialchars(ltrim($report['image_path'], '/')) ?>" alt="Report">
                    <?php else: ?>
                        <span>📝</span>
                    <?php endif; ?>
                </div>

                <!-- Details -->
                <div class="activity-details">
                    <h4 class="activity-title"><?= htmlspecialchars($report['asset_name'] ?? 'Unknown Asset') ?></h4>
                    <div class="activity-meta">
                        <?= htmlspecialchars($report['asset_code']) ?> • <?= date('M j', strtotime($report['created_at'])) ?>
                    </div>
                </div>

                <!-- Status Badge -->
                <div class="status-badge-pill <?= $statusClass ?>">
                    <?= htmlspecialchars($report['status']) ?>
                </div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <div style="font-size: 24px; margin-bottom: 8px;">💤</div>
                <p style="margin: 0;">No reported issues yet.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Scanner Modal -->
<div id="scannerModal" class="modal" style="display:none;" onclick="handleModalClick(event)">
    <div class="glass-card modal-content scanner-modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Scan QR Code</h3>
            <span class="close-btn" onclick="closeScannerModal()">&times;</span>
        </div>
        <p class="modal-subtitle">Align the QR code within the frame to scan.</p>
        
        <div class="scanner-wrapper">
             <div id="reader"></div>
             <div class="scanner-overlay">
                 <div class="scanner-corner top-left"></div>
                 <div class="scanner-corner top-right"></div>
                 <div class="scanner-corner bottom-left"></div>
                 <div class="scanner-corner bottom-right"></div>
             </div>
             <!-- Laser sweeper -->
             <div class="laser-line" id="laser-line" style="display: none;"></div>
        </div>
        
        <!-- Image Upload -->
        <div class="upload-wrapper">
            <label class="upload-btn">
                <i class="fas fa-image"></i> Scan from Image
                <input type="file" id="qr-upload-input" accept="image/*" style="display:none;">
            </label>
        </div>
        
        <div class="modal-actions">
             <a href="<?= BASE_URL ?>views/student/report_new.php" class="btn-manual-link">
                Enter Details Manually
            </a>
        </div>
    </div>
</div>

<style>
/* Professional Scanner Modal Styling */
/* Professional Scanner Modal Styling */
.modal {
    position: fixed; z-index: 99999; left: 0; top: 0; width: 100%; height: 100%;
    background-color: rgba(5, 8, 15, 0.9); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
    display: flex; /* Flexbox for perfect centering */
    align-items: flex-start; /* Stick to top */
    justify-content: center; /* Center horizontally */
    padding-top: 0; 
    opacity: 0; pointer-events: none; transition: opacity 0.3s ease;
}

.modal.active {
    opacity: 1; pointer-events: auto;
}

.scanner-modal-content {
    position: relative; /* Reset absolute */
    top: auto; left: auto; transform: translateY(-100%); /* Only animate Y */
    
    background: linear-gradient(180deg, rgba(20, 26, 45, 0.98) 0%, rgba(10, 14, 25, 0.99) 100%);
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    box-shadow: 0 20px 50px rgba(0,0,0,0.8);
    
    width: 100%; 
    max-width: 480px; 
    margin: 0; /* No auto margin, let flex handle it */
    padding: 24px 24px 32px;
    border-radius: 0 0 24px 24px;
    text-align: center; 
    
    transition: transform 0.4s cubic-bezier(0.19, 1, 0.22, 1);
    z-index: 100000;
}

/* Ensure it covers full width on small mobile screens */
@media (max-width: 480px) {
    .scanner-modal-content {
        max-width: 100%;
        border-radius: 0 0 20px 20px;
    }
}

.modal.active .scanner-modal-content {
    transform: translateY(0); /* Slide down to natural position */
}

.modal-header {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;
    padding-top: env(safe-area-inset-top, 20px); /* Respect notch or add default */
}

.modal-title {
    color: #fff; font-size: 1.2rem; margin: 0; font-weight: 600; letter-spacing: 0.5px;
}

.modal-subtitle {
    color: #8aa0d0; font-size: 0.9rem; margin: 0 0 24px; text-align: left;
}

.close-btn {
    color: #8aa0d0; font-size: 24px; line-height: 1; cursor: pointer; padding: 4px;
    border-radius: 50%; background: rgba(255,255,255,0.05); width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center; transition: all 0.2s;
}
.close-btn:hover { background: rgba(255,255,255,0.1); color: #fff; }

.scanner-wrapper {
    width: 100%; border-radius: 16px; overflow: hidden; margin-bottom: 20px;
    background: #000; position: relative; aspect-ratio: 1;
    box-shadow: inset 0 0 20px rgba(0,0,0,0.5);
}

/* Scanner Overlay Frame */
.scanner-overlay {
    position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none;
    box-shadow: inset 0 0 0 40px rgba(0,0,0,0.3); /* Dim edges */
    border-radius: 16px;
}

.scanner-corner {
    position: absolute; width: 24px; height: 24px; border-color: #6ea8fe; border-style: solid;
}
.top-left { top: 20px; left: 20px; border-width: 3px 0 0 3px; border-top-left-radius: 12px; }
.top-right { top: 20px; right: 20px; border-width: 3px 3px 0 0; border-top-right-radius: 12px; }
.bottom-left { bottom: 20px; left: 20px; border-width: 0 0 3px 3px; border-bottom-left-radius: 12px; }
.bottom-right { bottom: 20px; right: 20px; border-width: 0 3px 3px 0; border-bottom-right-radius: 12px; }

#reader video {
    object-fit: cover; width: 100% !important; height: 100% !important;
}

.modal-actions {
    margin-top: 10px;
}

.btn-manual-link {
    color: #6ea8fe; font-size: 0.95rem; text-decoration: none; font-weight: 500;
    display: inline-block; padding: 10px 20px; border-radius: 8px;
    transition: all 0.2s;
}
.btn-manual-link:hover {
    background: rgba(110, 168, 254, 0.1);
}

/* Laser scan line */
.laser-line {
    position: absolute;
    left: 10px; right: 10px;
    height: 2px;
    background: linear-gradient(90deg, transparent, #e74c3c 20%, #ff6b6b 50%, #e74c3c 80%, transparent);
    box-shadow: 0 0 8px #e74c3c, 0 0 16px rgba(231,76,60,0.5);
    border-radius: 2px;
    z-index: 5;
    animation: laserSweep 2s ease-in-out infinite;
    top: 10px;
}
@keyframes laserSweep {
    0%   { top: 10%;  opacity: 0.9; }
    50%  { top: 85%;  opacity: 1; }
    100% { top: 10%;  opacity: 0.9; }
}

/* ── Custom File Upload Button ── */
.upload-wrapper {
    text-align: center;
    margin: 16px 0 0;
}
.upload-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 24px;
    width: 100%;
    background: rgba(110, 168, 254, 0.08);
    color: #6ea8fe;
    border: 1px dashed rgba(110, 168, 254, 0.4);
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
.upload-btn:hover {
    background: rgba(110, 168, 254, 0.15);
    border-color: #6ea8fe;
    transform: translateY(-2px);
}
.upload-btn:active {
    transform: translateY(0);
}
.upload-btn i {
    font-size: 16px;
}
</style>

<script>
let html5QrcodeScanner = null;
let isScannerActive = false;

// Fix 3: Camera Permission Polyfill for Old Phones
if (!navigator.mediaDevices) {
    navigator.mediaDevices = {};
}
if (!navigator.mediaDevices.getUserMedia) {
    navigator.mediaDevices.getUserMedia = function(constraints) {
        const oldGetUserMedia = navigator.webkitGetUserMedia || navigator.mozGetUserMedia;
        if (!oldGetUserMedia) {
            return Promise.reject(new Error('Camera API not implemented in this browser'));
        }
        return new Promise((resolve, reject) => {
            oldGetUserMedia.call(navigator, constraints, resolve, reject);
        });
    }
}

function openScannerModal() {
    const modal = document.getElementById('scannerModal');
    modal.style.display = 'flex';
    requestAnimationFrame(() => {
        modal.classList.add('active');
    });
    
    if (!isScannerActive) {
        startScanner();
    }
}

function startScanner() {
    if (!html5QrcodeScanner) {
        html5QrcodeScanner = new Html5Qrcode("reader");
    }

    // Reset visual state
    const readerContainer = document.getElementById("reader");
    if (readerContainer) readerContainer.style.display = 'block';
    
    const overlay = document.querySelector(".scanner-overlay");
    if (overlay) overlay.style.display = 'block';

    const fallbackMsg = document.getElementById("camera-fallback-msg");
    if (fallbackMsg) fallbackMsg.remove();

    // Check WebRTC
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        handleCameraFallback("Live camera not supported by your browser.");
        return;
    }

    const config = { 
        fps: 15, 
        qrbox: { width: 220, height: 220 }, 
        aspectRatio: 1.0,
        experimentalFeatures: {
            useBarCodeDetectorIfSupported: true
        }
    };
    
    html5QrcodeScanner.start({ facingMode: "environment" }, config, onScanSuccess, onScanFailure)
    .then(() => {
        isScannerActive = true;
        const laserLine = document.getElementById("laser-line");
        if (laserLine) laserLine.style.display = 'block';
    })
    .catch(err => {
        console.error("Error starting scanner", err);
        handleCameraFallback("Live camera access denied or unsupported.");
    });
}

function handleCameraFallback(errorMsg) {
    const laserLine = document.getElementById("laser-line");
    if (laserLine) laserLine.style.display = 'none';

    const fallbackMsg = document.getElementById("camera-fallback-msg");
    if (fallbackMsg) fallbackMsg.remove();
    
    // Kept the reader and overlay visible to maintain the clean black viewfinder aesthetic.
}

document.addEventListener("DOMContentLoaded", () => {
    const uploadInput = document.getElementById("qr-upload-input");
    if (uploadInput) {
        uploadInput.addEventListener("change", (e) => {
            if (e.target.files.length === 0) return;
            
            const imageFile = e.target.files[0];
            if (!html5QrcodeScanner) {
                html5QrcodeScanner = new Html5Qrcode("reader");
            }
            
            const uploadLabel = uploadInput.parentElement;
            const originalText = uploadLabel.innerHTML;
            uploadLabel.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scanning Image...';
            
            html5QrcodeScanner.scanFile(imageFile, true)
                .then(qrCodeMessage => {
                    uploadLabel.innerHTML = originalText;
                    onScanSuccess(qrCodeMessage);
                })
                .catch(err => {
                    uploadInput.value = ''; // Reset input
                    uploadLabel.innerHTML = '<i class="fas fa-exclamation-circle"></i> No QR Found';
                    setTimeout(() => uploadLabel.innerHTML = originalText, 3000);
                });
        });
    }
});

function closeScannerModal() {
    const modal = document.getElementById('scannerModal');
    modal.classList.remove('active');
    
    const laserLine = document.getElementById("laser-line");
    if (laserLine) laserLine.style.display = 'none';
    
    setTimeout(() => {
        modal.style.display = 'none';
        if (html5QrcodeScanner && isScannerActive) {
            html5QrcodeScanner.stop().then(() => {
                isScannerActive = false;
            }).catch(console.error);
        }
    }, 300);
}

function onScanSuccess(decodedText, decodedResult) {
    let code = decodedText;
    
    // Attempt to extract qr_id if it's a URL
    try {
        // Create a dummy base if it's a relative URL, though QR is likely absolute
        const url = new URL(decodedText, window.location.origin);
        if (url.searchParams.has('qr_id')) {
            code = url.searchParams.get('qr_id');
        } else {
            // Fallback: Check if the text mimics our URL structure via simple regex
            // Useful if the URL constructor fails or it's a weird string
            const match = decodedText.match(/[?&]qr_id=([^&]+)/);
            if (match && match[1]) {
                code = decodeURIComponent(match[1]);
            }
        }
    } catch (e) {
        // Not a URL, try regex just in case
        const match = decodedText.match(/[?&]qr_id=([^&]+)/);
        if (match && match[1]) {
            code = decodeURIComponent(match[1]);
        }
    }
    
    // Sanitize: If code is still a full URL (no qr_id found), we might want to alert or just use it?
    // Project requirement implies we want the Asset Code. 
    // If it's just "AST-001", regex won't match, code remains "AST-001", which is correct.
    
    window.location.href = "<?= BASE_URL ?>views/student/report_new.php?qr_id=" + encodeURIComponent(code);
    closeScannerModal(); 
}

function onScanFailure(error) {
    // Silent
}

function handleModalClick(event) {
    const modalContent = document.querySelector('.scanner-modal-content');
    if (modalContent && !modalContent.contains(event.target)) {
        closeScannerModal();
    }
}
</script>

<?php include __DIR__.'/../partials/footer.php'; ?>
