<?php
// cron_daily_tasks.php
// Run this script daily via Cron Job (e.g., 0 0 * * *) to handle heavy maintenance tasks.

// 1. Initialize environment & DB
require_once __DIR__ . '/config/init.php';
require_once __DIR__ . '/config/asset_helper.php';
// Room utils is likely included in init.php, but required for syncAllExamReadyStatuses
require_once __DIR__ . '/config/room_utils.php'; 

echo "[ " . date('Y-m-d H:i:s') . " ] Starting Daily Tasks..." . PHP_EOL;
echo "-----------------------------------------------------" . PHP_EOL;

// 2. Warranty Expiration Check
try {
    echo "🔄 Running Warranty Check..." . PHP_EOL;
    checkWarrantyExpirations($conn);
    echo "✅ Warranty Check Completed." . PHP_EOL;
} catch (Exception $e) {
    echo "❌ Warranty Check Failed: " . $e->getMessage() . PHP_EOL;
}

echo "-----------------------------------------------------" . PHP_EOL;

// 3. Exam Readiness Sync
try {
    echo "🔄 Running Exam Readiness Sync..." . PHP_EOL;
    if (function_exists('syncAllExamReadyStatuses')) {
        syncAllExamReadyStatuses($conn);
        echo "✅ Exam Readiness Sync Completed." . PHP_EOL;
    } else {
        echo "❌ Error: syncAllExamReadyStatuses function not found." . PHP_EOL;
    }
} catch (Exception $e) {
    echo "❌ Exam Sync Failed: " . $e->getMessage() . PHP_EOL;
}

echo "-----------------------------------------------------" . PHP_EOL;
echo "🎉 Daily tasks (Warranty & Exam Sync) completed." . PHP_EOL;
?>
