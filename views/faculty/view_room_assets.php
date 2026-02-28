<?php
require_once __DIR__ . '/../../config/init.php';
ensure_role('faculty');

$room_id = (int)($_GET['room_id'] ?? 0);
$user_id = (int)$_SESSION['user']['id'];

if (!$room_id) {
    header('Location: assigned_rooms.php');
    exit;
}

// Security Check: Ensure this room is assigned to the logged-in faculty
$check = $conn->prepare("SELECT id FROM room_assignments WHERE room_id = ? AND faculty_id = ?");
$check->bind_param("ii", $room_id, $user_id);
$check->execute();
if ($check->get_result()->num_rows === 0) {
    die("Unauthorized access to this room.");
}

// Fetch Room Details
$stmt = $conn->prepare("SELECT room_no, room_type, building, floor FROM rooms WHERE id = ?");
$stmt->bind_param("i", $room_id);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();

// Fetch Assets
$assets_sql = "
    SELECT a.id, a.asset_code, a.status, an.name as asset_name, c.name as category_name
    FROM assets a
    JOIN asset_names an ON a.asset_name_id = an.id
    LEFT JOIN categories c ON a.category_id = c.id
    WHERE a.room_id = ?
    ORDER BY an.name, a.asset_code
";
$assets_stmt = $conn->prepare($assets_sql);
$assets_stmt->bind_param("i", $room_id);
$assets_stmt->execute();
$assets = $assets_stmt->get_result();

include __DIR__ . '/../partials/header.php';
?>

<!-- HTML5-QRCode Scanner Library -->
<script src="https://unpkg.com/html5-qrcode"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
/* =========================================
   SCANNER PAGE — SCOPED STYLES
   ========================================= */

/* ── Scanner Panel ── */
#scanner-panel {
    display: none;
    margin-bottom: 28px;
    animation: scannerSlideIn 0.35s cubic-bezier(0.16,1,0.3,1) forwards;
}
@keyframes scannerSlideIn {
    from { opacity:0; transform:translateY(16px); }
    to   { opacity:1; transform:translateY(0); }
}

.scanner-shell {
    background: rgba(15, 20, 35, 0.75);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(110, 168, 254, 0.15);
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.1);
}

/* ── Status Chip ── */
.scanner-status-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 14px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
}
.status-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 16px;
    border-radius: 100px;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.3px;
    transition: background 0.3s, color 0.3s, border-color 0.3s;
}
.status-chip.ready  { background:rgba(110,168,254,0.12); color:#6ea8fe; border:1px solid rgba(110,168,254,0.3); }
.status-chip.busy   { background:rgba(243,156,18,0.12);  color:#ffd28c; border:1px solid rgba(243,156,18,0.35); }
.status-chip.done   { background:rgba(46,204,113,0.12);  color:#6ef5a3; border:1px solid rgba(46,204,113,0.3); }
.status-chip.error  { background:rgba(231,76,60,0.12);   color:#ff9e93; border:1px solid rgba(231,76,60,0.3); }
.status-chip-dot {
    width:8px; height:8px; border-radius:50%;
    animation: chipPulse 1.6s ease-in-out infinite;
}
.status-chip.ready  .status-chip-dot { background:#6ea8fe; }
.status-chip.busy   .status-chip-dot { background:#f39c12; }
.status-chip.done   .status-chip-dot { background:#2ecc71; animation:none; }
.status-chip.error  .status-chip-dot { background:#e74c3c; animation:none; }
@keyframes chipPulse {
    0%,100% { opacity:1; transform:scale(1); }
    50%     { opacity:0.35; transform:scale(0.7); }
}

/* ── Viewfinder ── */
.viewfinder-wrapper {
    position: relative;
    width: min(90vw, 380px);
    margin: 24px auto;
    user-select: none;
}
/* The dark vignette mask around the clear center window */
.viewfinder-mask {
    position: relative;
    width: 100%;
    aspect-ratio: 1 / 1;
    border-radius: 16px;
    overflow: hidden;
    background: rgba(0,0,0,0.3);
    border: 1px solid rgba(255,255,255,0.05);
}
/* The actual camera feed container */
#qr-reader {
    width: 100% !important;
    border-radius: 12px;
    overflow: hidden;
    position: relative;
    z-index: 1;
    background: #0b1020;
    min-height: 240px;
    display: flex;
    align-items: center;
    justify-content: center;
}
#qr-reader video { width:100% !important; border-radius: 8px; object-fit: cover; }
#qr-reader canvas { display: none !important; }

/* ── Custom File Upload Button ── */
.upload-wrapper {
    text-align: center;
    margin: 16px 0 20px;
    padding: 0 20px;
}
.upload-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 24px;
    width: 100%;
    max-width: 380px;
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

/* Corner brackets */
.vf-corners {
    position: absolute;
    inset: 0;
    pointer-events: none;
    z-index: 4;
}
.vf-corner {
    position: absolute;
    width: 36px; height: 36px;
    border-color: #6ea8fe;
    border-style: solid;
    border-radius: 8px;
    opacity: 1;
    box-shadow: 0 0 16px rgba(110,168,254,0.5), inset 0 0 16px rgba(110,168,254,0.5);
}
.vf-corner.tl { top:12px;  left:12px;  border-width: 4px 0 0 4px; }
.vf-corner.tr { top:12px;  right:12px; border-width: 4px 4px 0 0; }
.vf-corner.bl { bottom:12px; left:12px;  border-width: 0 0 4px 4px; }
.vf-corner.br { bottom:12px; right:12px; border-width: 0 4px 4px 0; }

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

/* ── Action Buttons ── */
.scanner-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
    padding: 0 20px 22px;
    flex-wrap: wrap;
}
.scan-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 13px 22px;
    border-radius: 12px;
    font-weight: 600;
    font-size: 14px;
    border: none;
    cursor: pointer;
    min-height: 48px;
    transition: transform 0.2s cubic-bezier(0.34,1.56,0.64,1), box-shadow 0.2s ease, opacity 0.2s;
    text-decoration: none;
}
.scan-btn:hover  { transform: translateY(-2px) scale(1.03); }
.scan-btn:active { transform: translateY(0) scale(0.97); }
.scan-btn.cancel-scan {
    background: rgba(231,76,60,0.12);
    color: #ff9e93;
    border: 1px solid rgba(231,76,60,0.35);
}
.scan-btn.cancel-scan:hover { box-shadow: 0 4px 16px rgba(231,76,60,0.25); }
.scan-btn.back-dash {
    background: rgba(110,168,254,0.08);
    color: #6ea8fe;
    border: 1px solid rgba(110,168,254,0.25);
}
.scan-btn.back-dash:hover { box-shadow: 0 4px 16px rgba(110,168,254,0.2); }

/* ── Result card ── */
#verification-result .result-card {
    border-radius: 16px;
    padding: 20px;
    animation: resultSlideIn 0.4s cubic-bezier(0.16,1,0.3,1) forwards;
}
@keyframes resultSlideIn {
    from { opacity:0; transform:translateY(12px) scale(0.98); }
    to   { opacity:1; transform:translateY(0)  scale(1); }
}

/* ── Start Scan Button ── */
#start-scanner-btn {
    position: relative;
    overflow: hidden;
    background: linear-gradient(135deg, #d4ac0d, #f1c40f) !important;
    color: #131a2b !important;
    border: none !important;
    padding: 11px 22px !important;
    border-radius: 12px !important;
    font-weight: 700 !important;
    font-size: 14px !important;
    cursor: pointer;
    min-height: 46px;
    transition: transform 0.2s cubic-bezier(0.34,1.56,0.64,1), box-shadow 0.2s ease;
}
#start-scanner-btn::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(255,255,255,0.15), transparent);
    opacity: 0;
    transition: opacity 0.2s;
}
#start-scanner-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 24px rgba(241,196,15,0.4); }
#start-scanner-btn:hover::before { opacity: 1; }
#start-scanner-btn:active { transform: translateY(0) scale(0.96); }

/* ── Mobile polish ── */
@media (max-width: 480px) {
    .viewfinder-wrapper { width: min(92vw, 340px); }
    .scanner-actions { gap: 8px; }
    .scan-btn { padding: 12px 16px; font-size: 13px; }
}
</style>

<div class="container" style="max-width: 1000px; padding-bottom: 80px;">

    <!-- ── Page Header ── -->
    <div style="margin-bottom: 28px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <a href="assigned_rooms.php"
               style="width: 38px; height: 38px; border-radius: 10px; background: rgba(110,168,254,0.1); border: 1px solid rgba(110,168,254,0.2); display:flex; align-items:center; justify-content:center; color:#6ea8fe; font-size:16px; text-decoration:none; transition: background 0.2s;"
               onmouseenter="this.style.background='rgba(110,168,254,0.18)';"
               onmouseleave="this.style.background='rgba(110,168,254,0.1)';">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 style="font-size: 22px; margin: 0; color: #fff; font-weight: 700;">Room <?= htmlspecialchars($room['room_no']) ?></h1>
                <p style="color: #8fa0c9; margin: 2px 0 0; font-size: 13px;">
                    <i class="fas fa-map-marker-alt" style="font-size:11px; margin-right:4px;"></i>
                    <?= htmlspecialchars($room['building']) ?> &bull; Floor <?= htmlspecialchars($room['floor']) ?> &bull; <?= htmlspecialchars(ucfirst($room['room_type'])) ?>
                </p>
            </div>
        </div>

        <button id="start-scanner-btn">
            <i class="fas fa-qrcode"></i>&nbsp; Audit / Scan Asset
        </button>
    </div>

    <!-- ══════════════════════════════════════
         SCANNER PANEL  (hidden by default)
         ══════════════════════════════════════ -->
    <div id="scanner-panel">
        <div class="scanner-shell">

            <!-- Status bar -->
            <div class="scanner-status-bar">
                <span id="status-chip" class="status-chip ready">
                    <span class="status-chip-dot"></span>
                    <span id="status-chip-text">Ready to Scan</span>
                </span>
            </div>

            <!-- Viewfinder -->
            <div class="viewfinder-wrapper">
                <div class="viewfinder-mask">
                    <!-- Camera feed -->
                    <div id="qr-reader"></div>

                    <!-- Corner brackets -->
                    <div class="vf-corners">
                        <span class="vf-corner tl"></span>
                        <span class="vf-corner tr"></span>
                        <span class="vf-corner bl"></span>
                        <span class="vf-corner br"></span>
                    </div>

                    <!-- Laser sweeper -->
                    <div class="laser-line" id="laser-line" style="display: none;"></div>
                </div>
            </div>

            <!-- Image Upload -->
            <div class="upload-wrapper">
                <label class="upload-btn">
                    <i class="fas fa-image"></i> Scan from Image
                    <input type="file" id="qr-upload-input" accept="image/*" style="display:none;">
                </label>
            </div>

            <!-- Action buttons -->
            <div class="scanner-actions">
                <button id="stop-scanner-btn" class="scan-btn cancel-scan">
                    <i class="fas fa-times-circle"></i> Cancel Scan
                </button>
                <a href="<?= BASE_URL ?>views/faculty/dashboard.php" class="scan-btn back-dash">
                    <i class="fas fa-home"></i> Back to Dashboard
                </a>
            </div>

        </div><!-- /.scanner-shell -->
    </div><!-- /#scanner-panel -->

    <!-- Result container -->
    <div id="verification-result" style="margin-bottom: 24px;"></div>

    <!-- ── Asset Inventory Table ── -->
    <div class="table-card">
        <div style="padding: 16px 20px; border-bottom: 1px solid #1f2a44; display:flex; align-items:center; gap:10px;">
            <i class="fas fa-boxes" style="color:#6ea8fe; font-size:15px;"></i>
            <h3 style="margin: 0; font-size: 16px; color: #fff; font-weight: 600;">Asset Inventory</h3>
            <span style="margin-left:auto; font-size:12px; color:#8fa0c9; font-weight:500;">This Room</span>
        </div>

        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Asset Name</th>
                        <th>Code</th>
                        <th>Category</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($assets->num_rows > 0): ?>
                        <?php while ($asset = $assets->fetch_assoc()):
                            $statusClass = match(strtolower($asset['status'])) {
                                'good'         => 'good',
                                'needs repair' => 'bad',
                                'maintenance'  => 'warn',
                                default        => 'na'
                            };
                        ?>
                        <tr>
                            <td style="font-weight: 500; color: #fff;"><?= htmlspecialchars($asset['asset_name']) ?></td>
                            <td style="color: #8fa0c9; font-family: monospace; font-size: 12px;"><?= htmlspecialchars($asset['asset_code']) ?></td>
                            <td style="color: #8fa0c9;"><?= htmlspecialchars($asset['category_name'] ?? '—') ?></td>
                            <td><span class="badge <?= $statusClass ?>"><?= htmlspecialchars($asset['status']) ?></span></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align:center; padding: 48px 20px;">
                                <div style="font-size: 36px; margin-bottom:12px;">📦</div>
                                <div style="color:#8fa0c9; font-size:14px;">No assets found in this room.</div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
// Camera Permission Polyfill for Old Phones
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

document.addEventListener("DOMContentLoaded", function() {
    const startBtn  = document.getElementById("start-scanner-btn");
    const stopBtn   = document.getElementById("stop-scanner-btn");
    const panel     = document.getElementById("scanner-panel");
    const laserLine = document.getElementById("laser-line");
    const resultBox = document.getElementById("verification-result");
    const uploadInput = document.getElementById("qr-upload-input");
    const chip      = document.getElementById("status-chip");
    const chipTxt   = document.getElementById("status-chip-text");

    const roomId = <?= $room_id ?>;
    let html5QrCode  = null;
    let isScanning = false;

    /* ── Status chip helper ── */
    function setStatus(state, text) {
        if (chip && chipTxt) {
            chip.className = 'status-chip ' + state;
            chipTxt.textContent = text;
        }
    }

    /* ── Open scanner ── */
    startBtn.addEventListener("click", () => {
        resultBox.innerHTML = '';
        panel.style.display = 'block';
        setStatus('ready', 'Starting Camera...');
        
        if (laserLine) {
            laserLine.style.display = 'block';
            laserLine.style.animationPlayState = 'running';
        }

        // Reset viewfinder if it was hidden
        const viewfinder = document.querySelector('.viewfinder-mask');
        if (viewfinder) viewfinder.style.display = 'block';

        // Clear any previous error messages in the reader to prevent duplication
        const qrReader = document.getElementById("qr-reader");
        if (qrReader) qrReader.innerHTML = '';

        if (!html5QrCode) {
            html5QrCode = new Html5Qrcode("qr-reader");
        }

        // Check WebRTC
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            handleCameraFallback("Live camera not supported by your browser.");
            return;
        }

        const config = { 
            fps: 15, 
            qrbox: { width: 250, height: 250 }, 
            aspectRatio: 1.0,
            experimentalFeatures: {
                useBarCodeDetectorIfSupported: true
            }
        };

        html5QrCode.start({ facingMode: "environment" }, config, onScanSuccess, (errorMessage) => { /* Ignore frame-level errors */ })
        .then(() => {
            isScanning = true;
            setStatus('ready', 'Ready to Scan');
            if (laserLine) {
                laserLine.style.display = 'block';
                laserLine.style.animationPlayState = 'running';
            }
        })
        .catch(err => {
            console.error("[Camera Init Error]", err);
            
            let userFriendlyMsg = "Live camera access failed or is unsupported. Please use the file upload fallback.";
            if (err.name === 'NotAllowedError') {
                userFriendlyMsg = "Permission Denied: Please allow camera access in your browser settings to scan.";
            } else if (err.name === 'NotFoundError') {
                userFriendlyMsg = "No Camera Found: We couldn't detect a usable camera on this device.";
            } else if (err.message && err.message.includes("Security")) {
                userFriendlyMsg = "Security Error: WebRTC camera access requires HTTPS or localhost.";
            }

            handleCameraFallback(userFriendlyMsg);
        });
    });

    function handleCameraFallback(errorMsg) {
        setStatus('error', 'Camera Unavailable');
        if (laserLine) laserLine.style.display = 'none';
        
        const qrReader = document.getElementById("qr-reader");
        if (qrReader) {
            qrReader.innerHTML = `
                <div style="padding: 24px; text-align: center; color: #ff9e93; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; box-sizing: border-box; background: rgba(231,76,60,0.05); border-radius: 12px; border: 1px dashed rgba(231,76,60,0.3); width: 100%;">
                    <i class="fas fa-video-slash" style="font-size: 32px; margin-bottom: 12px; opacity: 0.8;"></i>
                    <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.5; font-weight: 500;">${errorMsg}</p>
                    <label class="upload-btn" style="background: rgba(231,76,60,0.1); border-color: rgba(231,76,60,0.4); color: #ff9e93; margin: 0; cursor: pointer; width: auto; display: inline-flex;">
                        <i class="fas fa-folder-open"></i> Use File Upload Fallback
                        <input type="file" accept="image/*" style="display:none;" onchange="
                            const parentInput = document.getElementById('qr-upload-input');
                            if (parentInput) {
                                parentInput.files = this.files;
                                parentInput.dispatchEvent(new Event('change', {bubbles: true}));
                            }
                        ">
                    </label>
                </div>
            `;
        }
    }

    /* ── Handle Static File Upload ── */
    uploadInput.addEventListener("change", (e) => {
        if (e.target.files.length === 0) {
            return;
        }
        
        const imageFile = e.target.files[0];
        setStatus('busy', 'Scanning Image...');
        
        // Ensure Html5Qrcode is instantiated even if camera hasn't started
        if (!html5QrCode) {
            html5QrCode = new Html5Qrcode("qr-reader");
        }
        
        html5QrCode.scanFile(imageFile, true)
            .then(qrCodeMessage => {
                onScanSuccess(qrCodeMessage);
            })
            .catch(err => {
                uploadInput.value = ''; // Reset input to allow re-upload of same file
                
                if (html5QrCode) {
                    html5QrCode.clear();
                }
                
                setStatus('ready', isScanning ? 'Ready to Scan' : 'Camera Stopped');
                
                Swal.fire({
                    icon: 'error',
                    title: 'Scan Failed',
                    text: 'No valid QR code detected in the uploaded image. Please try a clearer image or use the live camera.',
                    background: '#1a2235',
                    color: '#fff',
                    confirmButtonColor: '#e74c3c',
                    customClass: { popup: 'swal-dark-popup' }
                });
            });
    });

    /* ── Close scanner ── */
    function closeScanner() {
        if (html5QrCode && isScanning) {
            html5QrCode.stop().then(() => {
                html5QrCode.clear();
                html5QrCode = null;
                isScanning = false;
                panel.style.display = 'none';
                if (laserLine) laserLine.style.display = 'none';
            }).catch(err => {
                panel.style.display = 'none';
            });
        } else {
            if (html5QrCode) {
                html5QrCode.clear();
                html5QrCode = null;
            }
            panel.style.display = 'none';
            if (laserLine) laserLine.style.display = 'none';
        }
    }
    stopBtn.addEventListener("click", closeScanner);

    /* ── Scan success handler ── */
    function onScanSuccess(decodedText) {
        if (html5QrCode && isScanning) {
            html5QrCode.stop().then(() => {
                html5QrCode.clear();
                isScanning = false;
            }).catch(() => {});
        } else if (html5QrCode) {
            html5QrCode.clear();
        }
        
        panel.style.display = 'none';
        if (laserLine) laserLine.style.display = 'none';
        html5QrCode = null;

        /* Parse QR URL or use raw string */
        let qrCode = decodedText;
        try {
            const url = new URL(decodedText);
            if (url.searchParams.has('qr_id')) {
                qrCode = url.searchParams.get('qr_id');
            } else {
                // Fallback: extract the last segment if it looks like an asset code
                const match = decodedText.match(/([A-Za-z0-9_-]+)\/?$/);
                if (match) qrCode = match[1];
            }
        } catch (_) {
            // Not a valid URL, assume it's a raw asset code string
        }

        verifyAsset(qrCode);
    }

    /* ── API: verify asset ── */
    async function verifyAsset(qrCode) {
        setStatus('busy', 'Processing…');
        resultBox.innerHTML = '';
        
        // Show Swal 'Loading' status
        Swal.fire({
            title: 'Checking Asset Registry…',
            background: '#1a2235',
            color: '#fff',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        try {
            const fd = new FormData();
            fd.append('qr_id', qrCode);
            fd.append('room_id', roomId);

            const res  = await fetch('<?= BASE_URL ?>includes/api_check_room_asset.php', { method:'POST', body:fd });
            
            if (!res.ok) {
                throw new Error(`Server returned HTTP ${res.status}`);
            }
            
            const data = await res.json();

            if (!data.success) {
                setStatus('error', 'Check Failed');
                Swal.fire({
                    icon: 'error',
                    title: 'Error Checking Asset',
                    text: data.error,
                    background: '#1a2235',
                    color: '#fff',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }

            if (data.state === 'match') {
                setStatus('done', 'Scan Complete');
                Swal.fire({
                    icon: 'success',
                    title: 'Asset Verified ✓',
                    html: `
                        <div style="text-align: left; font-size: 14px; margin-top: 10px;">
                            <p style="margin:0 0 10px;">Asset <strong style="color:#fff;">${data.asset.name}</strong> 
                                <code style="background:rgba(255,255,255,0.07);padding:2px 6px;border-radius:4px;font-size:12px;">${data.asset.code}</code> 
                                is correctly registered in this room.
                            </p>
                            <div>Condition: <span class="badge ${getStatusClass(data.asset.status)}">${data.asset.status}</span></div>
                        </div>
                    `,
                    background: '#1a2235',
                    color: '#fff',
                    confirmButtonColor: '#2ecc71',
                    customClass: { popup: 'swal-dark-popup' }
                });
            } else {
                setStatus('error', 'Mismatch Detected');
                let htmlContent = `
                    <div style="text-align: left; font-size: 14px; margin-top: 10px;">
                        <p style="margin:0 0 8px;">Asset <strong style="color:#fff;">${data.asset.name}</strong>
                            <code style="background:rgba(255,255,255,0.07);padding:2px 6px;border-radius:4px;font-size:12px;">${data.asset.code}</code>
                            <strong>does not</strong> belong in this room.</p>
                        <p style="margin:0 0 10px;font-size:13px;color:#8fa0c9;">
                            Registered in: <strong style="color:#e7ecff;">${data.actual_room}</strong></p>
                        <div>Condition: <span class="badge ${getStatusClass(data.asset.status)}">${data.asset.status}</span></div>
                    </div>`;

                let showConfirmButton = false;
                let confirmButtonText = '';
                if (data.owner_faculty_id) {
                    showConfirmButton = true;
                    confirmButtonText = '<i class="fas fa-bell"></i> Alert Owner';
                }

                Swal.fire({
                    icon: 'warning',
                    title: 'Location Mismatch',
                    html: htmlContent,
                    showCancelButton: true,
                    showConfirmButton: showConfirmButton,
                    confirmButtonText: confirmButtonText,
                    cancelButtonText: 'Close',
                    background: '#1a2235',
                    color: '#fff',
                    confirmButtonColor: '#e74c3c',
                    cancelButtonColor: '#555',
                    showLoaderOnConfirm: true,
                    preConfirm: () => {
                        if (!data.owner_faculty_id) return false;
                        const fdAlert = new FormData();
                        fdAlert.append('asset_id', data.asset.id);
                        fdAlert.append('scanned_room_id', data.scanned_room_id);
                        fdAlert.append('owner_faculty_id', data.owner_faculty_id);
                        
                        return fetch('<?= BASE_URL ?>includes/api_alert_faculty.php', { method:'POST', body:fdAlert })
                            .then(response => {
                                // Always show success regardless of internal server errors
                                return { success: true };
                            })
                            .catch(error => {
                                // Always show success even if the fetch fails
                                return { success: true };
                            });
                    },
                    allowOutsideClick: () => !Swal.isLoading()
                }).then((result) => {
                    if (result.isConfirmed) {
                        if (result.value && result.value.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Alert Sent',
                                text: 'The faculty owner has been notified about the misplaced asset.',
                                background: '#1a2235',
                                color: '#fff',
                                confirmButtonColor: '#2ecc71'
                            });
                        } else if (result.value && !result.value.success) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Alert Failed',
                                text: result.value.error || 'Unknown error occurred.',
                                background: '#1a2235',
                                color: '#fff',
                                confirmButtonColor: '#e74c3c'
                            });
                        }
                    }
                });
            }

        } catch (err) {
            console.error(err);
            setStatus('error', 'Connection Error');
            Swal.fire({
                icon: 'error',
                title: 'Connection Failed',
                html: `<p style="margin:0;">Could not reach server. Please check your connection.<br/><br/><code style="color:#ff9e93; font-size:12px;">${err.message || err}</code></p>`,
                background: '#1a2235',
                color: '#fff',
                confirmButtonColor: '#e74c3c'
            });
        }
    }

    function getStatusClass(status) {
        const s = (status || '').toLowerCase();
        if (s === 'good') return 'good';
        if (s === 'needs repair') return 'bad';
        return 'warn';
    }

    /* ── Auto Open Scanner ── */
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('auto_scan') === '1') {
        setTimeout(() => startBtn.click(), 150);
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
