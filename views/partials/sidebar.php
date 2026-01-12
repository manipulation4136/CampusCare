<aside class="sidebar" id="adminSidebar">
    <div class="sidebar-header">
        <h3>Admin Panel</h3>
        <button class="sidebar-close-btn">&times;</button>
    </div>
    
    <ul class="sidebar-menu">
        <li class="menu-header">REPORTS</li>
        <li><a href="<?= BASE_URL ?>views/admin/dashboard.php">📊 Dashboard</a></li>
        <li><a href="<?= BASE_URL ?>views/admin/reports.php">📄 All Reports</a></li>

        <li class="menu-header">CORE DATA</li>
        <li><a href="<?= BASE_URL ?>views/admin/users.php">👥 Users</a></li>
        <li><a href="<?= BASE_URL ?>views/admin/assets.php">💻 Assets</a></li>
        <li><a href="<?= BASE_URL ?>views/admin/rooms.php">🏫 Rooms</a></li>

        <li class="menu-header">ACTION</li>
        <li><a href="<?= BASE_URL ?>views/admin/assignments.php" class="highlight-link">🔗 Assign Faculty</a></li>

        <li class="menu-header">SETTINGS</li>
        <li class="dropdown-item">
            <div class="dropdown-toggle">⚙️ Config Data <span class="arrow">▼</span></div>
            <ul class="submenu">
                <li><a href="<?= BASE_URL ?>views/admin/asset_names.php">Asset Names</a></li>
                <li><a href="<?= BASE_URL ?>views/admin/categories.php">Categories</a></li>
            </ul>
        </li>
        <li class="dropdown-item">
            <div class="dropdown-toggle">📞 Contacts <span class="arrow">▼</span></div>
            <ul class="submenu">
                <li><a href="<?= BASE_URL ?>views/admin/dealers.php">Dealers</a></li>
                <li><a href="<?= BASE_URL ?>views/admin/workers.php">Workers</a></li>
            </ul>
        </li>


    </ul>
</aside>