<?php
// 1. Enable Error Reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// 2. Exception Handler (For debugging connection issues)
set_exception_handler(function($e) {
    echo "<div style='background: #ffebee; color: #c62828; padding: 20px; border: 1px solid #c62828; margin: 20px; font-family: monospace;'>";
    echo "<h1>🛑 Database Connection Error</h1>";
    echo "<h3>Error: " . $e->getMessage() . "</h3>";
    // echo "<pre>" . $e->getTraceAsString() . "</pre>"; // Uncomment for detailed trace
    echo "</div>";
    exit;
});

// 3. Get Credentials from Render Environment
$db_host = getenv('DB_HOST');
$db_user = getenv('DB_USER');
$db_pass = getenv('DB_PASS');
$db_name = getenv('DB_NAME');
$db_port = getenv('DB_PORT') ? (int)getenv('DB_PORT') : 4000;

// 4. Initialize & SSL
$conn = mysqli_init();
$conn->ssl_set(NULL, NULL, NULL, NULL, NULL);

// 5. Connect with Persistent Connection ('p:')
// This keeps the connection open for faster performance on Render
if (!$conn->real_connect("p:" . $db_host, $db_user, $db_pass, $db_name, $db_port, NULL, MYSQLI_CLIENT_SSL)) {
    throw new Exception("Connection Failed: " . mysqli_connect_error());
}

// 6. Set Charset
$conn->set_charset('utf8mb4');

// 7. Fix for TiDB/MySQL strict modes
try {
    $conn->query("SET SESSION sql_mode = ''");
} catch (Exception $e) {
    // Ignore if fails
}
?>
