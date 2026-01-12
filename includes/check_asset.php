<?php
require_once __DIR__ . '/../config/init.php';
ensure_role(['student', 'faculty', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Verify CSRF token
if (!verify_csrf()) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF validation failed']);
    exit;
}

header('Content-Type: application/json');

$asset_code = trim($_POST['asset_code'] ?? '');

if (empty($asset_code)) {
    echo json_encode(['success' => false, 'error' => 'Asset code required']);
    exit;
}

try {
    // Optimized Single Query: Fetches Asset, Category, and Parent Info in one go
    $stmt = $conn->prepare("
        SELECT 
            a.id, 
            a.asset_code, 
            an.name AS asset_name,
            c.name AS category_name,
            a.parent_asset_id,
            p.asset_code AS parent_code,
            pan.name AS parent_name
        FROM assets a
        JOIN asset_names an ON a.asset_name_id = an.id
        LEFT JOIN categories c ON a.category_id = c.id
        LEFT JOIN assets p ON a.parent_asset_id = p.id
        LEFT JOIN asset_names pan ON p.asset_name_id = pan.id
        WHERE a.asset_code = ?
    ");

    $stmt->bind_param("s", $asset_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $asset = $result->fetch_assoc();

    if ($asset) {
        $has_parent = !empty($asset['parent_asset_id']);
        
        $response = [
            'success' => true,
            'exists' => true,
            'has_parent' => $has_parent,
            'asset' => [
                'id' => $asset['id'],
                'code' => $asset['asset_code'],
                'name' => $asset['asset_name'],
                'category' => $asset['category_name']
            ]
        ];

        // Include parent details if applicable
        if ($has_parent) {
            $response['parent'] = [
                'code' => $asset['parent_code'],
                'name' => $asset['parent_name']
            ];
        }

        echo json_encode($response);
    } else {
        // Asset code not found (valid for new items)
        echo json_encode([
            'success' => true, 
            'exists' => false,
            'has_parent' => false
        ]);
    }

} catch (Exception $e) {
    error_log("Database error in check_asset: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}