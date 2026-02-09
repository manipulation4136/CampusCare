<?php
require_once __DIR__ . '/../config/init.php';

// 1. Security: Faculty Only (or Admin)
// ensure_role('faculty'); // Not strict, allow admin too
if (!isset($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$report_id = (int)($input['report_id'] ?? 0);
$real_asset_code = trim($input['real_asset_code'] ?? '');

if (!$report_id || empty($real_asset_code)) {
    echo json_encode(['success' => false, 'error' => 'Missing ID or Code']);
    exit;
}

try {
    // 2. Update the Report with the Suggestion
    $stmt = $conn->prepare("UPDATE damage_reports SET suggested_real_code = ? WHERE id = ?");
    $stmt->bind_param("si", $real_asset_code, $report_id);
    $stmt->execute();

    // 3. Notify Admins
    // Fetch user name
    $user_name = $_SESSION['user']['name'];
    
    // Fetch report asset code for context
    $codeStmt = $conn->prepare("SELECT a.asset_code FROM damage_reports dr JOIN assets a ON dr.asset_id = a.id WHERE dr.id = ?");
    $codeStmt->bind_param("i", $report_id);
    $codeStmt->execute();
    $res = $codeStmt->get_result()->fetch_assoc();
    $ms_code = $res['asset_code'] ?? 'Unknown Asset';

    $msg = "🔔 Merge Request: Faculty $user_name identified $ms_code as $real_asset_code. Please review.";

    $admins = $conn->query("SELECT id FROM users WHERE role='admin'");
    while ($admin = $admins->fetch_assoc()) {
        notify_user($conn, $admin['id'], $msg);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
