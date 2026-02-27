<?php
require_once __DIR__ . '/../config/init.php';
ensure_role('faculty');

header('Content-Type: application/json');

// Ensure request is POST or GET, and valid
$qr_id = $_REQUEST['qr_id'] ?? '';
$current_room_id = (int)($_REQUEST['room_id'] ?? 0);
$faculty_id = (int)$_SESSION['user']['id'];

if (empty($qr_id) || empty($current_room_id)) {
    echo json_encode(['success' => false, 'error' => 'Missing scan identifiers.']);
    exit;
}

try {
    // 1. Validate that the logged-in Faculty actually manages THIS room.
    $check_privilege = $conn->prepare("SELECT id FROM room_assignments WHERE room_id = ? AND faculty_id = ?");
    $check_privilege->bind_param("ii", $current_room_id, $faculty_id);
    $check_privilege->execute();
    if ($check_privilege->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized room scan.']);
        exit;
    }

    // 2. Query the Asset
    $stmt = $conn->prepare("
        SELECT a.id, a.asset_code, a.status, a.room_id, an.name as asset_name, c.name as category_name
        FROM assets a
        JOIN asset_names an ON a.asset_name_id = an.id
        LEFT JOIN categories c ON a.category_id = c.id
        WHERE a.asset_code = ?
    ");
    $stmt->bind_param("s", $qr_id);
    $stmt->execute();
    $asset = $stmt->get_result()->fetch_assoc();

    if (!$asset) {
        echo json_encode(['success' => false, 'error' => 'Asset not found in the database.']);
        exit;
    }

    // Capture the owner_faculty_id via room_assignments
    $owner_faculty_id = null;
    if ($asset['room_id']) {
        $o_stmt = $conn->prepare("SELECT faculty_id FROM room_assignments WHERE room_id = ? LIMIT 1");
        $o_stmt->bind_param("i", $asset['room_id']);
        $o_stmt->execute();
        $o_res = $o_stmt->get_result()->fetch_assoc();
        $owner_faculty_id = $o_res['faculty_id'] ?? null;
    }

    // 3. Resolve Match or Mismatch
    $is_match = ($asset['room_id'] == $current_room_id);

    $response = [
        'success' => true,
        'state' => $is_match ? 'match' : 'mismatch',
        'asset' => [
            'id' => $asset['id'],
            'code' => $asset['asset_code'],
            'name' => $asset['asset_name'],
            'category' => $asset['category_name'] ?? 'Uncategorized',
            'status' => $asset['status']
        ],
        'owner_faculty_id' => $owner_faculty_id,
        'scanned_room_id' => $current_room_id
    ];

    // If mismatch, fetch its ACTUAL registered room for the Alert Card
    if (!$is_match) {
        $room_stmt = $conn->prepare("SELECT building, floor, room_no FROM rooms WHERE id = ?");
        $room_stmt->bind_param("i", $asset['room_id']);
        $room_stmt->execute();
        $actual_room = $room_stmt->get_result()->fetch_assoc();

        $response['actual_room'] = $actual_room['building'] . ' / Floor ' . $actual_room['floor'] . ' / ' . $actual_room['room_no'];
    }

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'System error configuring asset data.']);
}
