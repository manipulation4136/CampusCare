<?php
require_once __DIR__ . '/../config/init.php';

header('Content-Type: application/json');

// 1. Security Check: Admin Only
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// 2. CSRF Check (Optional but recommended if you are submitting via form)
// For now we will assume this might be an API call, but let's be safe if verified
// if (!verify_csrf()) { ... } 

// 3. Input Validation
$input = json_decode(file_get_contents('php://input'), true);
$ghost_asset_id = (int)($input['ghost_asset_id'] ?? 0);
$real_asset_code = trim($input['real_asset_code'] ?? '');

if (!$ghost_asset_id || empty($real_asset_code)) {
    echo json_encode(['success' => false, 'error' => 'Missing ID or Asset Code']);
    exit;
}

try {
    $conn->begin_transaction();

    // 4. Find Real Asset ID
    $stmt = $conn->prepare("SELECT id, status FROM assets WHERE asset_code = ?");
    $stmt->bind_param("s", $real_asset_code);
    $stmt->execute();
    $real_asset = $stmt->get_result()->fetch_assoc();

    if (!$real_asset) {
        throw new Exception("Real asset '{$real_asset_code}' not found.");
    }
    $real_asset_id = (int)$real_asset['id'];

    if ($real_asset_id === $ghost_asset_id) {
        throw new Exception("Cannot merge asset into itself.");
    }

    // 5. Update Damage Reports (Move from Ghost to Real)
    $updateReports = $conn->prepare("UPDATE damage_reports SET asset_id = ? WHERE asset_id = ?");
    $updateReports->bind_param("ii", $real_asset_id, $ghost_asset_id);
    $updateReports->execute();

    // 6. Update Real Asset Status
    // It inherits the 'Needs Repair' status because it now has an open report
    $updateStatus = $conn->prepare("UPDATE assets SET status = 'Needs Repair' WHERE id = ?");
    $updateStatus->bind_param("i", $real_asset_id);
    $updateStatus->execute();

    // 7. Delete Ghost Asset
    $deleteGhost = $conn->prepare("DELETE FROM assets WHERE id = ?");
    $deleteGhost->bind_param("i", $ghost_asset_id);
    $deleteGhost->execute();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Asset merged successfully.']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
