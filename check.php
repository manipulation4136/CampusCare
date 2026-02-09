<?php
echo "<h2>🔍 Project Root Files:</h2><pre>";
// Root folder-ൽ എന്തൊക്കെ ഉണ്ടെന്ന് പ്രിന്റ് ചെയ്യുന്നു
print_r(scandir(__DIR__));
echo "</pre>";

echo "<h2>📂 Icons Folder Status:</h2>";
$iconPath = __DIR__ . '/icons';

if (is_dir($iconPath)) {
    echo "✅ 'icons' folder exists.<br>";
    echo "<h3>Files inside 'icons':</h3><pre>";
    // Icons folder-ൽ എന്തൊക്കെ ഉണ്ടെന്ന് പ്രിന്റ് ചെയ്യുന്നു
    print_r(scandir($iconPath));
    echo "</pre>";
} else {
    echo "❌ <b>ERROR:</b> 'icons' folder does NOT exist here!";
}
?>