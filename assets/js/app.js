document.addEventListener('DOMContentLoaded', function () {

  // 1. Sidebar Toggle Logic (Updated for Off-Canvas)
  const toggleBtn = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('adminSidebar');
  const backdrop = document.getElementById('sidebarBackdrop');
  const closeBtn = document.querySelector('.sidebar-close-btn');

  // Expose to window for onclick attribute
  window.toggleMobileMenu = function () {
    toggleSidebar();
  };

  function toggleSidebar(active) {
    if (!sidebar) return;
    const isActive = active !== undefined ? active : !sidebar.classList.contains('active');
    const menuToggle = document.querySelector('.menu-toggle');

    if (isActive) {
      sidebar.classList.add('active');
      if (backdrop) backdrop.classList.add('visible');
      if (menuToggle) menuToggle.classList.add('active'); // Animate Hamburger
    } else {
      sidebar.classList.remove('active');
      if (backdrop) backdrop.classList.remove('visible');
      if (menuToggle) menuToggle.classList.remove('active');
    }
  }

  // Legacy listener support (if button still relies on ID in other places)
  if (toggleBtn) {
    toggleBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      console.log('Sidebar toggle clicked'); // Debugging
      toggleMobileMenu();
    });
  }

  if (closeBtn) {
    closeBtn.addEventListener('click', () => toggleSidebar(false));
  }

  if (backdrop) {
    backdrop.addEventListener('click', () => toggleSidebar(false));
  }

  // Close on Escape key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') toggleSidebar(false);
  });

  // 2. Dropdown Logic (Existing for Sidebar)
  const dropdowns = document.querySelectorAll('.dropdown-toggle');
  dropdowns.forEach(trigger => {
    trigger.addEventListener('click', function () {
      this.parentElement.classList.toggle('open');
    });
  });

  // 3. NEW: Profile Circle Dropdown Toggle
  const profileBtn = document.getElementById('profileToggle');
  const profileMenu = document.getElementById('profileMenu');

  if (profileBtn && profileMenu) {
    // Toggle on click
    profileBtn.addEventListener('click', function (e) {
      e.stopPropagation(); // Prevent document click
      profileMenu.classList.toggle('show');
    });

    // Close when clicking anywhere else
    document.addEventListener('click', function (e) {
      if (!profileMenu.contains(e.target) && e.target !== profileBtn) {
        profileMenu.classList.remove('show');
      }
    });
  }
});