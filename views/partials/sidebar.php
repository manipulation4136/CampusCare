<!-- 
  SIDEBAR THEMES:
  Change the class on the <aside> tag to switch styles:
  1. theme-glass (Default - Glow & blur)
  2. theme-solid (High contrast active states)
  3. theme-minimal (Subtle indicator lines)
-->
<aside class="sidebar theme-glass" id="adminSidebar">
    <div class="sidebar-header">
        <h3>Admin Panel</h3>
        <button class="sidebar-close-btn">&times;</button>
    </div>
    
    <ul class="sidebar-menu">
        <li class="menu-header">REPORTS</li>
        <li><a href="<?= BASE_URL ?>views/admin/dashboard.php">📊 Dashboard</a></li>
        <li><a href="<?= BASE_URL ?>views/admin/reports.php">📄 All Reports</a></li>

        <li class="menu-header">CORE DATA</li>
        
        <!-- NEW: "User" Dropdown Menu replacing former flat User link -->
        <li class="dropdown-item">
            <div class="dropdown-toggle">👥 Users <span class="arrow">▼</span></div>
            <ul class="submenu">
                <li><a href="<?= BASE_URL ?>views/admin/students.php">🎓 Student</a></li>
                <li><a href="<?= BASE_URL ?>views/admin/faculty.php">🧑‍🏫 Faculty</a></li>
            </ul>
        </li>
        
        <li><a href="<?= BASE_URL ?>views/admin/assets.php">💻 Assets</a></li>
        <li><a href="<?= BASE_URL ?>views/admin/rooms.php">🏫 Rooms</a></li>

        <!-- NEW: Parent "Settings" Dropdown wrapping nested submenus -->
        <li class="menu-header">SYSTEM OPTIONS</li>
        <li class="dropdown-item">
            <div class="dropdown-toggle">⚙️ Settings <span class="arrow">▼</span></div>
            <!-- Main Settings Submenu -->
            <ul class="submenu">
                
                <!-- Nested Dropdown: Config Data -->
                <li class="dropdown-item nested-dropdown">
                    <div class="dropdown-toggle">⚙️ Config Data <span class="arrow">▼</span></div>
                    <ul class="submenu nested-submenu">
                        <li><a href="<?= BASE_URL ?>views/admin/asset_names.php">🏷️ Asset Names</a></li>
                        <li><a href="<?= BASE_URL ?>views/admin/categories.php">📂 Categories</a></li>
                    </ul>
                </li>
                
                <!-- Nested Dropdown: Contacts -->
                <li class="dropdown-item nested-dropdown">
                    <div class="dropdown-toggle">📞 Contacts <span class="arrow">▼</span></div>
                    <ul class="submenu nested-submenu">
                        <li><a href="<?= BASE_URL ?>views/admin/dealers.php">🤝 Dealers</a></li>
                        <li><a href="<?= BASE_URL ?>views/admin/workers.php">👷 Workers</a></li>
                    </ul>
                </li>

            </ul>
        </li>

    </ul>
</aside>