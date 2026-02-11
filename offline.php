<?php require_once __DIR__ . '/config/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline - CampusCare</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">

    <style>
        /* (നിങ്ങളുടെ പഴയ മനോഹരമായ CSS ഇവിടെ അതേപടി നിലനിർത്തുന്നു) */
        :root { --bg: #0b1020; --text: #e7ecff; --accent: #6ea8fe; }
        body, html { height: 100%; margin: 0; background: linear-gradient(180deg, #0b1020, #101631); color: var(--text); overflow: hidden; font-family: sans-serif; }
        .offline-wrapper { display: flex; align-items: center; justify-content: center; height: 100vh; }
        .glass-card { background: rgba(19, 26, 43, 0.8); backdrop-filter: blur(12px); border: 1px solid rgba(110, 168, 254, 0.1); border-radius: 24px; padding: 40px 30px; text-align: center; max-width: 400px; width: 90%; }
        .hero-icon { font-size: 50px; margin-bottom: 20px; }
        .btn { display: block; width: 100%; padding: 15px; border-radius: 12px; margin-bottom: 15px; border: none; font-size: 16px; font-weight: bold; cursor: pointer; text-decoration: none; box-sizing: border-box; }
        .btn-primary { background: var(--accent); color: #000; }
        .btn-outline { background: transparent; border: 2px solid var(--accent); color: #fff; }
        .hidden { display: none !important; }
        h1 { margin-top: 0; color: white; }
        p { color: #8fa0c9; }
    </style>
</head>
<body>

    <div class="offline-wrapper">
        <div class="glass-card">
            <div class="hero-icon">📡</div>
            <h1>Connection Lost</h1>
            <p>You seem to be offline.</p>

            <div id="admin-msg" class="hidden">
                <p style="color: #ff9e93; background: rgba(231,76,60,0.2); padding: 10px; border-radius: 8px;">
                    Admin access requires internet.
                </p>
                <button onclick="window.location.reload()" class="btn btn-primary">🔄 Try Reconnecting</button>
            </div>

            <div id="student-msg" class="hidden">
                <p style="color: #6ef5a3; margin-bottom: 20px;">You can still report damages!</p>
                
                <a href="<?php echo BASE_URL; ?>views/student/report_new.php" class="btn btn-primary">
                    📝 Report Damage
                </a>

                <a href="<?php echo BASE_URL; ?>views/student/dashboard.php" class="btn btn-outline">
                    🏠 Go to Dashboard
                </a>
            </div>

            <div id="default-msg" class="hidden">
                <button onclick="window.location.reload()" class="btn btn-primary">🔄 Reload</button>
            </div>
        </div>
    </div>

    <script>
        // 1. Role Logic
        const role = localStorage.getItem('userRole');
        document.getElementById('admin-msg').classList.add('hidden');
        document.getElementById('student-msg').classList.add('hidden');
        document.getElementById('default-msg').classList.add('hidden');

        if (role === 'admin' || role === 'faculty') {
            document.getElementById('admin-msg').classList.remove('hidden');
        } else if (role === 'student') {
            document.getElementById('student-msg').classList.remove('hidden');
        } else {
            document.getElementById('default-msg').classList.remove('hidden');
        }
    </script>
</body>
</html>
