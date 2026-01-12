<?php
// 🛑 PATH FIX: views/ folder-ൽ നിന്ന് config-ലേക്ക് പോകാൻ ഒരു സ്റ്റെപ്പ് (../) മതി.
require_once __DIR__ . '/../config/init.php'; 
ensure_logged_in();

$user_id = (int)$_SESSION['user']['id'];
$telegram_chat_id = '';

// 🛑 ENV FIX: Used getenv() instead of env()
$bot_token = getenv('TELEGRAM_BOT_TOKEN'); 

// Generate random verification code
$verify_code = rand(100000, 999999);

// Fetch current ID
$stmt = $conn->prepare("SELECT telegram_chat_id FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $telegram_chat_id = $row['telegram_chat_id'];
}

// 1. Handle Auto-Detect (Poll getUpdates with Verification)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_code'])) {
    if (!verify_csrf()) die('CSRF validation failed');
    
    $expected_code = $_POST['expected_code']; 
    
    // Poll Telegram API
    $url = "https://api.telegram.org/bot$bot_token/getUpdates";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        set_flash('err', "Connection error: $error");
    } else {
        $json = json_decode($response, true);
        if ($json['ok'] && !empty($json['result'])) {
            $found_match = false;
            $ignore_older_than = 600; // 10 minutes
            
            foreach (array_reverse($json['result']) as $update) {
                $msg = $update['message'] ?? $update['edited_message'] ?? null;
                if (!$msg) continue;
                
                $text = trim($msg['text'] ?? '');
                $msg_time = $msg['date'];
                
                if ($text === $expected_code && (time() - $msg_time < $ignore_older_than)) {
                    $chat_id = $msg['chat']['id'];
                    $username = $msg['from']['first_name'] ?? 'User';
                    
                    $stmt = $conn->prepare("UPDATE users SET telegram_chat_id = ? WHERE id = ?");
                    $stmt->bind_param("si", $chat_id, $user_id);
                    try {
                        if ($stmt->execute()) {
                            $telegram_chat_id = $chat_id;
                            $found_match = true;
                            set_flash('ok', "Success! Verified code from $username. Chat ID $chat_id saved.");
                            break; 
                        }
                    } catch (mysqli_sql_exception $e) {
                        if ($e->getCode() == 1062) { 
                            set_flash('err', "This Telegram ID is already linked to another account.");
                            $found_match = true; 
                            break;
                        } else {
                            set_flash('err', "Database error: " . $e->getMessage()); 
                        }
                    }
                }
            }
            
            if (!$found_match) {
                set_flash('err', "Verification code '$expected_code' not found in recent messages. Please send the code to the bot and try again.");
            }
            
        } else {
            set_flash('err', "No recent messages found. Please send the verification code to the bot.");
        }
    }
}

// 2. Handle Manual Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_telegram'])) {
    if (!verify_csrf()) die('CSRF validation failed');
    $new_id = trim($_POST['telegram_chat_id']);
    
    $stmt = $conn->prepare("UPDATE users SET telegram_chat_id = ? WHERE id = ?");
    $stmt->bind_param("si", $new_id, $user_id);
    
    try {
        if ($stmt->execute()) {
            $telegram_chat_id = $new_id;
            set_flash('ok', 'Telegram Chat ID saved manually!');
        } else {
            set_flash('err', 'Error saving Chat ID.');
        }
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1062) {
            if (strpos($e->getMessage(), 'telegram_chat_id') !== false) {
                set_flash('err', "This Telegram ID is already linked to another account.");
            } else {
                set_flash('err', "This record already exists.");
            }
        } else {
            set_flash('err', "An error occurred while saving.");
        }
    }
}

// 🛑 PATH FIX: 'partials/' is in the same directory as 'views/' logic? 
// No, telegram_setup.php is in 'views/'. 'partials/' is in 'views/partials/'.
// So correct path is simply 'partials/header.php'.
include __DIR__ . '/partials/header.php'; 
?>

<div class="container" style="max-width: 600px; margin: 40px auto;">
    <div class="card" style="text-align: center; padding: 30px;">
        <h2 class="telegram-header animate-card-entry" style="font-size: 24px; margin-bottom: 20px;">🔔 Connect Telegram</h2>
        
        <?php if ($m = flash('ok')): ?>
            <div class="alert success animate-card-entry" style="margin-bottom: 20px; animation-delay: 0.1s;"><?= htmlspecialchars($m) ?></div>
        <?php endif; ?>
        <?php if ($m = flash('err')): ?>
            <div class="alert error animate-card-entry" style="margin-bottom: 20px; animation-delay: 0.1s;"><?= htmlspecialchars($m) ?></div>
        <?php endif; ?>
        
        <p class="animate-card-entry" style="color: var(--muted); margin-bottom: 30px; animation-delay: 0.1s;">
            Securely link your account to receive instant notifications.
        </p>

        <div class="telegram-steps animate-card-entry" style="background: rgba(110, 168, 254, 0.05); padding: 25px; border-radius: 12px; border: 1px dashed var(--accent); margin-bottom: 30px; text-align: left; animation-delay: 0.2s;">
            <h4 style="margin-top: 0; color: var(--accent); margin-bottom: 15px;">Verification Steps:</h4>
            <ol style="padding-left: 20px; line-height: 1.8;">
                <li>Open <strong><a href="https://t.me/College_Asset_Info_Bot" target="_blank" style="text-decoration: underline;">@College_Asset_Info_Bot</a></strong> in Telegram.</li>
                <li>Tap <strong>START</strong> if you haven't already.</li>
                <li>Send this exact code to the bot:</li>
            </ol>
            
            <div class="otp-box animate-card-entry" style="text-align: center; margin: 20px 0; animation-delay: 0.3s;">
                <span class="badge bad" style="font-size: 24px; padding: 10px 20px; letter-spacing: 2px; font-family: monospace; display: inline-block;">
                    <?= $verify_code ?>
                </span>
            </div>
            
            <div style="text-align: center;">
                <form method="POST" action="">
                    <?= get_csrf_input() ?>
                    <input type="hidden" name="expected_code" value="<?= $verify_code ?>">
                    <button type="submit" name="verify_code" class="btn verify-btn animate-card-entry" style="background: #27ae60; width: 100%; padding: 12px; font-size: 16px; animation-delay: 0.4s;">
                        ✅ Verify & Save ID
                    </button>
                </form>
            </div>
        </div>

        <?php if (!empty($telegram_chat_id)): ?>
            <div class="animate-card-entry" style="background: rgba(39, 174, 96, 0.1); color: #27ae60; padding: 10px; border-radius: 8px; margin-bottom: 20px; animation-delay: 0.5s;">
                <strong>✅ Connected Chat ID:</strong> <?= htmlspecialchars($telegram_chat_id) ?>
            </div>
        <?php endif; ?>

        <div class="manual-input-section animate-card-entry" style="border-top: 1px solid #2a3558; padding-top: 20px; margin-top: 30px; animation-delay: 0.5s;">
            <p style="font-size: 14px; color: var(--muted); margin-bottom: 15px;">Or enter Chat ID manually (not recommended):</p>
            <form method="POST" action="">
                <?= get_csrf_input() ?>
                <div style="display: flex; gap: 8px; justify-content: center;">
                    <input type="text" name="telegram_chat_id" class="form-control" 
                           placeholder="Enter Chat ID" 
                           value="" 
                           style="max-width: 150px;">
                    <button type="submit" name="update_telegram" class="btn outline small">Save Manual</button>
                </div>
            </form>
        </div>
        
        <div style="margin-top: 20px;" class="animate-card-entry" style="animation-delay: 0.6s;">
             <a href="javascript:history.back()" style="color: var(--muted); font-size: 14px; text-decoration: underline;">Back to Dashboard</a>
        </div>
    </div>
</div>

<?php 
// 🛑 PATH FIX: Same here, 'partials/' is a subdirectory of 'views/'
include __DIR__ . '/partials/footer.php'; 
?>
