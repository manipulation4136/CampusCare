<?php
// Hide footer on specific pages (e.g., Report Damage)
$current_page = basename($_SERVER['PHP_SELF']);
$hide_footer_pages = ['report_new.php'];

if (!in_array($current_page, $hide_footer_pages) && !isset($hide_footer)):
?>
<footer class="footer">
  <p>&copy; 2026 CampusCare</p>
</footer>
<?php endif; ?>
</body>
</html>