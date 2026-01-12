<?php
require_once __DIR__ . '/init.php';

/**
 * Generates the next available asset code based on the pattern NAME-ROOM-NUMBER.
 *
 * @param mysqli $conn Database connection
 * @param int $asset_name_id ID of the asset name
 * @param string $room_no Room number
 * @return string|null The generated asset code or null if asset name not found
 */
function getNextAssetCode(mysqli $conn, int $asset_name_id, string $room_no): ?string {
    // Get asset name
    $stmt = $conn->prepare("SELECT name FROM asset_names WHERE id = ?");
    $stmt->bind_param("i", $asset_name_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if (!$row = $res->fetch_assoc()) {
        return null;
    }

    // Clean name: uppercase, alphanumeric only
    $cleanName = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $row['name']));
    $baseCode = $cleanName . '-' . $room_no . '-';

    // Find last used number
    // Sort by length first to handle 10 > 2 correctly, then by value
    $stmt = $conn->prepare("SELECT asset_code FROM assets WHERE asset_code LIKE ? ORDER BY LENGTH(asset_code) DESC, asset_code DESC LIMIT 1");
    $pattern = $baseCode . '%';
    $stmt->bind_param("s", $pattern);
    $stmt->execute();
    $res = $stmt->get_result();

    $nextNum = 1;
    if ($row = $res->fetch_assoc()) {
        $parts = explode('-', $row['asset_code']);
        // Get the last part as integer
        $lastNum = (int)end($parts);
        $nextNum = $lastNum + 1;
    }

    return $baseCode . $nextNum;
}

/**
 * Safely inserts an asset with retry logic for duplicate codes.
 *
 * @param mysqli $conn Database connection
 * @param array $data Asset data [asset_name_id, category_id, room_id, parent_asset_id, warranty_end, dealer_id]
 * @return array ['id' => int, 'code' => string]
 * @throws Exception If insertion fails after retries or due to other errors
 */
function insertAssetSafe(mysqli $conn, array $data): array {
    $retries = 0;
    $maxRetries = 3;
    
    // Get room number for code generation
    $stmt = $conn->prepare("SELECT room_no FROM rooms WHERE id = ?");
    $stmt->bind_param("i", $data['room_id']);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$room = $res->fetch_assoc()) {
        throw new Exception("Invalid room ID");
    }
    $room_no = $room['room_no'];

    do {
        $code = getNextAssetCode($conn, $data['asset_name_id'], $room_no);
        if (!$code) {
            throw new Exception("Invalid asset name ID");
        }

        try {
            $stmt = $conn->prepare("
                INSERT INTO assets (asset_code, asset_name_id, category_id, room_id, parent_asset_id, warranty_end, dealer_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->bind_param(
                "siiiisi",
                $code,
                $data['asset_name_id'],
                $data['category_id'],
                $data['room_id'],
                $data['parent_asset_id'],
                $data['warranty_end'],
                $data['dealer_id']
            );

            $stmt->execute();
            
            // Return success
            return [
                'id' => $conn->insert_id,
                'code' => $code
            ];

        } catch (mysqli_sql_exception $e) {
            // Check for duplicate entry error (1062)
            if ($e->getCode() === 1062) {
                $retries++;
                if ($retries >= $maxRetries) {
                    throw new Exception("Failed to generate unique asset code after $maxRetries attempts. Last tried: $code");
                }
                // Continue loop to generate next number
                continue;
            }
            // Throw other errors
            throw $e;
        }
    } while ($retries < $maxRetries);
    
    throw new Exception("Unexpected error in asset insertion");
}
function checkWarrantyExpirations(mysqli $conn) {
    $today = date('Y-m-d');
    $in30Days = date('Y-m-d', strtotime('+30 days'));
    $in7Days = date('Y-m-d', strtotime('+7 days'));

    $intervals = [
        ['date' => $in30Days, 'type' => '30_days', 'msg_template' => "Upcoming Expiry: Warranty for %s (%s) expires in 30 days. Plan for renewal."],
        ['date' => $in7Days,  'type' => '7_days',  'msg_template' => "⚠️ Action Required: Warranty for %s (%s) expires in 1 week."],
        ['date' => $today,    'type' => 'expired', 'msg_template' => "❌ Status Update: Warranty for %s (%s) has EXPIRED today."]
    ];

    $admin_id = 1; // Assuming Admin ID is always 1

    foreach ($intervals as $interval) {
        $checkDate = $interval['date'];
        $type = $interval['type'];

        // Find assets matching this exact date
        $stmt = $conn->prepare("
            SELECT a.id, a.asset_code, an.name 
            FROM assets a 
            JOIN asset_names an ON a.asset_name_id = an.id 
            WHERE a.warranty_end = ?
        ");
        $stmt->bind_param("s", $checkDate);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($asset = $result->fetch_assoc()) {
            $msg = sprintf($interval['msg_template'], $asset['name'], $asset['asset_code']);
           
            // Actually, simply checking if a notification with this exact message exists for this user is safer.
            $checkNotif = $conn->prepare("SELECT id FROM notifications WHERE user_id = ? AND message = ?");
            $checkNotif->bind_param("is", $admin_id, $msg);
            $checkNotif->execute();
            if ($checkNotif->get_result()->num_rows == 0) {
                notify_user($conn, $admin_id, $msg);
            }
        }
    }
}

