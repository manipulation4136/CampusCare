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
/**
 * Generates a random unique asset code in the format AST-XXXXXX.
 *
 * @return string The generated asset code
 */
function generateUniqueAssetCode(): string {
    // Generate 3 random bytes and convert to hex (6 chars)
    $hex = bin2hex(random_bytes(3));
    return 'AST-' . strtoupper($hex);
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
    
    // No need to fetch room number for code generation anymore
    
    do {
        $code = generateUniqueAssetCode();

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

    echo "Checking for Expiry on: [Today: $today], [7 Days: $in7Days], [30 Days: $in30Days]\n";

    $intervals = [
        ['date' => $in30Days, 'type' => '30_days', 'msg_template' => "Upcoming Expiry: Warranty for %s (%s) expires in 30 days. Plan for renewal."],
        ['date' => $in7Days,  'type' => '7_days',  'msg_template' => "⚠️ Action Required: Warranty for %s (%s) expires in 1 week."],
        ['date' => $today,    'type' => 'expired', 'msg_template' => "❌ Status Update: Warranty for %s (%s) has EXPIRED today."]
    ];

    // Fetch all admins dynamically
    $admins = [];
    $adminQuery = $conn->query("SELECT id FROM users WHERE role = 'admin'");
    while ($row = $adminQuery->fetch_assoc()) {
        $admins[] = $row['id'];
    }

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
           
            // Notify EACH admin
            foreach ($admins as $admin_id) {
                // Check for duplicate for this specific admin
                $checkNotif = $conn->prepare("SELECT id FROM notifications WHERE user_id = ? AND message = ?");
                $checkNotif->bind_param("is", $admin_id, $msg);
                $checkNotif->execute();
                if ($checkNotif->get_result()->num_rows == 0) {
                    notify_user($conn, $admin_id, $msg);
                }
            }
        }
    }
}

