<?php
// views/partials/header.php
require_once __DIR__ . '/../../config/init.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$user = $_SESSION['user'] ?? null;

// Determine home URL based on role
$homeUrl = BASE_URL . 'index.php';
if (!empty($user['role'])) {
    if ($user['role'] === 'admin') {
        $homeUrl = BASE_URL . 'views/admin/dashboard.php';
    } elseif ($user['role'] === 'faculty') {
        $homeUrl = BASE_URL . 'views/faculty/dashboard.php';
    } else {
        $homeUrl = BASE_URL . 'views/student/dashboard.php';
    }
}

function getRoleClass($role) {
    $role = strtolower($role);
    if ($role === 'admin') return 'bg-admin';
    if ($role === 'faculty') return 'bg-faculty';
    return 'bg-student'; 
}
?>
<!doctype html>
<html lang="en">
<head>
  <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>img/logo.png">
  <link rel="icon" type="image/png" sizes="16x16" href="<?= BASE_URL ?>img/logo.png">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#101531">
  <title>College Asset Damage Reporting</title>
  <meta name="csrf-token" content="<?= generate_csrf_token() ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  
  <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
  
  <script defer src="<?= BASE_URL ?>assets/js/app.js"></script>
  <link rel="manifest" href="<?= BASE_URL ?>manifest.json">
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => {
        navigator.serviceWorker.register('<?= BASE_URL ?>service-worker.js')
          .then(reg => console.log('SW Registered!', reg.scope))
          .catch(err => console.log('SW Failed:', err));
      });
    }
  </script>
  <script>
    // Local Login Check Logic
    // Since this header is only loaded for logged-in users (mostly), we set the flag here.
    localStorage.setItem('isLoggedIn', 'true');
  </script>

<script>
/* =========================================
   NOTIFICATION POLLING (1 MINUTE INTERVAL)
   ========================================= */
let lastCount = 0;
let audioEnabled = false;
const audioPath = '<?= BASE_URL ?>sounds/notification.mp3';

const enableAudio = () => {
    if (audioEnabled) return;
    audioEnabled = true;
    console.log('🔊 Audio Context Unlocked');
    const audio = new Audio(audioPath);
    audio.volume = 0;
    audio.play().then(() => {
        audio.pause();
        audio.currentTime = 0;
    }).catch(e => console.log('Audio unlock failed:', e));
    ['click', 'keydown', 'touchstart'].forEach(e => 
        document.removeEventListener(e, enableAudio)
    );
};

['click', 'keydown', 'touchstart'].forEach(e => 
    document.addEventListener(e, enableAudio)
);

function checkNewNotifications() {
    if (document.visibilityState === 'hidden') return;

    <?php if(isset($_SESSION['user'])): ?>
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (!csrfMeta) return;
    
    const csrfToken = csrfMeta.getAttribute('content');
    
    fetch('<?= BASE_URL ?>includes/check_notif.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'check_notifications=1&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(r => r.text())
    .then(data => {
        const count = parseInt(data) || 0;
        
        // Update Badge logic
        const badges = document.querySelectorAll('.notif-badge');
        badges.forEach(badge => {
            if (count > 0) {
                badge.style.display = 'flex';
                badge.innerText = count > 99 ? '99+' : count;
            } else {
                badge.style.display = 'none';
            }
        });

        if (count > lastCount && lastCount > 0) {
            const newCount = count - lastCount;
            if (audioEnabled) {
                const audio = new Audio(audioPath);
                audio.volume = 0.6;
                audio.play().catch(e => console.error('🚫 Autoplay blocked:', e));
            }
            
            // New Toast Logic
            const toast = document.createElement('div');
            toast.className = 'notif-toast';
            toast.innerHTML = `<i class="fa-solid fa-bell"></i> <div><strong>New Notification</strong><br><small>${newCount} new message(s)</small></div>`;
            toast.onclick = () => { 
                window.location.href = '<?= BASE_URL ?>views/notifications.php'; 
            };
            document.body.appendChild(toast);
            
            // Auto hide
            setTimeout(() => { 
                toast.style.animation = 'slideInRight 0.5s reverse forwards';
                setTimeout(() => toast.remove(), 500);
            }, 5000);
        }
        lastCount = count;
    })
    .catch(e => console.log('❌ Polling Error:', e));
    <?php endif; ?>
}

function markNotificationsRead(event) {
    event.preventDefault();
    const link = event.currentTarget.href;
    
    // Immediate UI Update
    const badges = document.querySelectorAll('.notif-badge');
    badges.forEach(b => b.style.display = 'none');
    lastCount = 0;

    // Async Update
    fetch('<?= BASE_URL ?>includes/ajax_mark_read.php', { method: 'POST' })
        .then(() => {
            window.location.href = link;
        })
        .catch(() => {
             window.location.href = link;
        });
}

// 🛑 POLLING INTERVAL SET TO 1 MINUTE
setInterval(checkNewNotifications, 60000); 

// Initial check after 2 seconds (so user sees badges on load)
setTimeout(checkNewNotifications, 2000);  
</script>
<script>
function toggleDropdown(event) {
    event.stopPropagation();
    const dropdown = document.getElementById('userDropdown');
    if (dropdown) dropdown.classList.toggle('show');
}

// Close Dropdown on Click Outside
window.addEventListener('click', function(event) {
    const dropdown = document.getElementById('userDropdown');
    if (dropdown && !event.target.closest('.user-profile')) {
        dropdown.classList.remove('show');
    }
});
</script>
</head>
<body>

<header class="topbar <?= isset($user['role']) ? 'role-' . $user['role'] : '' ?>">
  <?php if (($user['role'] ?? '') === 'admin'): ?>
  <div class="menu-toggle" onclick="toggleMobileMenu()">
      <span></span>
      <span></span>
      <span></span>
  </div>
  <?php endif; ?>

  <div class="brand">
      <a href="<?= $homeUrl ?>">Campus<span>Care</span></a>
  </div>
  
  <div class="header-right">
    <?php if ($user): ?>
      <a href="<?= BASE_URL ?>views/notifications.php" class="icon-btn" title="Notifications" onclick="markNotificationsRead(event)">
        🔔 
        <span class="notif-badge" style="display: <?= ($unread_count ?? 0) > 0 ? 'flex' : 'none' ?>;">
            <?= ($unread_count ?? 0) > 99 ? '99+' : ($unread_count ?? 0) ?>
        </span>
      </a>

      <div class="user-profile" id="userProfile">
          <div class="user-avatar <?= getRoleClass($user['role']) ?>" onclick="toggleDropdown(event)">
              <?= strtoupper(substr($user['name'], 0, 1)) ?>
          </div>

          <div class="dropdown-menu" id="userDropdown">
              <div class="dropdown-header">
                  <span class="user-name"><?= htmlspecialchars($user['name']) ?></span>
                  <span class="user-role"><?= htmlspecialchars($user['role']) ?></span>
              </div>
              
              <a href="<?= BASE_URL ?>views/profile.php" class="dropdown-item">
                  ⚙️ Account Settings
              </a>
              
              <div class="dropdown-divider"></div>

              <a href="<?= BASE_URL ?>includes/logout.php" class="dropdown-item" style="color: #ff6b6b;">
    🚪 Exit
</a>
          </div>
      </div>
    <?php endif; ?>
  </div>
</header>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<div class="page-wrapper">
    <?php 
    // Include Sidebar ONLY if user is logged in (specifically Admin based on your logic)
    if ($user && $user['role'] === 'admin') {
        include __DIR__ . '/sidebar.php';
    }
    ?>
    

    <main class="main-content">
