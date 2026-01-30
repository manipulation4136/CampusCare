<?php
require_once __DIR__ . '/../config/init.php';
ensure_role(['student', 'faculty', 'admin']);

header('Content-Type: application/json');

$room_id = (int)($_GET['room_id'] ?? 0);
$asset_name_id = (int)($_GET['asset_name_id'] ?? 0);

if (!$room_id || !$asset_name_id) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT 
            a.asset_code, 
            a.status,
            a.parent_asset_id,
            an.name as asset_name 
        FROM assets a
        JOIN asset_names an ON a.asset_name_id = an.id
        WHERE a.room_id = ? AND a.asset_name_id = ?
        ORDER BY a.asset_code ASC
    ");
    
    $stmt->bind_param("ii", $room_id, $asset_name_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $assets = [];
    while ($row = $result->fetch_assoc()) {
        $assets[] = [
            'code' => $row['asset_code'],
            'status' => $row['status'],
            'has_parent' => !empty($row['parent_asset_id']),
            'name' => $row['asset_name']
        ];
    }
    
    echo json_encode($assets);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
