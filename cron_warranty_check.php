<?php
// cron_warranty_check.php
// Run this script daily via Cron Job (e.g., 0 8 * * *)

// 1. Initialize environment & DB
require_once __DIR__ . '/config/init.php';
require_once __DIR__ . '/config/asset_helper.php';

// 2. Run the check
echo "[ " . date('Y-m-d H:i:s') . " ] Starting Warranty Check..." . PHP_EOL;

try {
    checkWarrantyExpirations($conn);
    echo "✅ Warranty check executed successfully." . PHP_EOL;
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . PHP_EOL;
}
?>
