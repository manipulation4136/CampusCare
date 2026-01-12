<?php
// ✅ FIX: env() function definition for backward compatibility
// പഴയ കോഡുകളിൽ env() വിളിക്കുമ്പോൾ എറർ വരാതിരിക്കാൻ ഇത് സഹായിക്കും
if (!function_exists('env')) {
    function env($key, $default = null) {
        $value = getenv($key);
        return $value !== false ? $value : $default;
    }
}

if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
    ob_start('ob_gzhandler');
} else {
    ob_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/room_utils.php';
require_once __DIR__ . '/../includes/csrf.php';

define('DIR', BASE_PATH);
?>
