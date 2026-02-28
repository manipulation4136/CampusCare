<?php
require_once __DIR__ . '/../config/init.php';
ensure_role('faculty');

header('Content-Type: application/json');

// Validate HTTP POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

$asset_id = (int)($_POST['asset_id'] ?? 0);
$scanned_room_id = (int)($_POST['scanned_room_id'] ?? 0);
$owner_faculty_id = (int)($_POST['owner_faculty_id'] ?? 0);
$acting_user_id = (int)$_SESSION['user']['id'];
$acting_user_name = $_SESSION['user']['name'] ?? 'A Faculty Member';

if (!$asset_id || !$scanned_room_id || !$owner_faculty_id) {
    echo json_encode(['success' => false, 'error' => 'Missing required alert parameters.']);
    exit;
}

try {
    // 1. Fetch Contextual Data for the Alert String
    // Asset Name & Code
    $asset_stmt = $conn->prepare("
        SELECT a.asset_code, an.name as asset_name 
        FROM assets a
        JOIN asset_names an ON a.asset_name_id = an.id
        WHERE a.id = ?
    ");
    $asset_stmt->bind_param("i", $asset_id);
    $asset_stmt->execute();
    $asset_data = $asset_stmt->get_result()->fetch_assoc();

    // Scanned Room Details
    $room_stmt = $conn->prepare("SELECT building, floor, room_no FROM rooms WHERE id = ?");
    $room_stmt->bind_param("i", $scanned_room_id);
    $room_stmt->execute();
    $room_data = $room_stmt->get_result()->fetch_assoc();

    if (!$asset_data || !$room_data) {
        throw new Exception("Entity resolution failed.");
    }

    $asset_string = $asset_data['asset_name'] . ' (' . $asset_data['asset_code'] . ')';
    $room_string = $room_data['building'] . ' / Floor ' . $room_data['floor'] . ' / Room ' . $room_data['room_no'];

    // Construct the actual message
    $alert_message = "🚨 WARNING: Your managed asset '{$asset_string}' was just located out-of-bounds in '{$room_string}' by {$acting_user_name}.";

    // 2. Channel A: In-App System Notification
    $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
    $notif_stmt->bind_param("is", $owner_faculty_id, $alert_message);
    $notif_stmt->execute();

    // 3. Channel B: Telegram Integration
    // Retrieve the Telegram Bot Token configured in the database settings table
    $bot_token = '';
    $settings_query = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'telegram_bot_token' LIMIT 1");
    if ($settings_query && $settings_query->num_rows > 0) {
        $row = $settings_query->fetch_assoc();
        $bot_token = trim($row['setting_value']);
    }

    // If a token exists globally, see if this specific user linked their Chat ID
    if (!empty($bot_token)) {
        $tg_stmt = $conn->prepare("SELECT telegram_chat_id FROM users WHERE id = ? LIMIT 1");
        $tg_stmt->bind_param("i", $owner_faculty_id);
        $tg_stmt->execute();
        $tg_user = $tg_stmt->get_result()->fetch_assoc();
        
        $chat_id = $tg_user['telegram_chat_id'] ?? null;

        if ($chat_id) {
            // Append visual styling for Telegram payload
            $tg_message = "🚨 *CampusCare Alert*\n\nYour assigned asset `{$asset_data['asset_code']}` (*{$asset_data['asset_name']}*) was just scanned out-of-bounds!\n\n📍 *Scanned In:* {$room_string}\n👤 *Auditor:* {$acting_user_name}\n⏰ *Time:* " . date('Y-m-d H:i:s');
            
            // Execute cURL against Telegram Bot API
            $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
            $data = [
                'chat_id' => $chat_id,
                'text' => $tg_message,
                'parse_mode' => 'Markdown'
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 sec timeout to avoid hanging the UI
            $tg_result = curl_exec($ch);
            curl_close($ch);
            
            // Note: We don't strictly fail the endpoint if Telegram fails (e.g., bot blocked by user). 
            // In-app notification already succeeded.
        }
    }

    echo json_encode([
        'success' => true, 
        'message' => 'Owner strategically alerted via available channels.'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database exception during alert generation.']);
}
