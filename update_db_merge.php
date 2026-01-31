<?php
require_once __DIR__ . '/config/init.php';

try {
    $conn->query("ALTER TABLE damage_reports ADD COLUMN suggested_real_code VARCHAR(50) DEFAULT NULL AFTER asset_id");
    echo "Successfully added suggested_real_code column.";
} catch (Exception $e) {
    echo "Error (or column might already exist): " . $e->getMessage();
}
?>
