<?php
require_once __DIR__ . '/config/config.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline - CampusCare</title>

    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">

    <style>
        /* Critical Glassmorphism & Layout CSS (Embedded for Offline Fallback) */
        :root {
            --bg: #0b1020;
            --card: #131a2b;
            --text: #e7ecff;
            --muted: #8fa0c9;
            --accent: #6ea8fe;
            --good: #27ae60;
            --bad: #e74c3c;
            --warn: #f39c12;
        }

        body, html {
            height: 100%;
            margin: 0;
            font-family: system-ui, -apple-system, sans-serif;
            background: linear-gradient(180deg, #0b1020, #101631);
            color: var(--text);
            overflow: hidden; /* Prevent scrolling on offline page */
        }

        .offline-fallback-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
            box-sizing: border-box;
            position: relative;
        }

        .ambient-glow {
            position: absolute;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(110, 168, 254, 0.15) 0%, rgba(0, 0, 0, 0) 70%);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 0;
            pointer-events: none;
        }

        .glass-card {
            background: rgba(19, 26, 43, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(110, 168, 254, 0.1);
            border-radius: 24px;
            padding: 40px 30px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            position: relative;
            z-index: 1;
            text-align: center;
            max-width: 400px;
            width: 100%;
            animation: slideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .hero-icon {
            width: 80px;
            height: 80px;
            font-size: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(110, 168, 254, 0.1);
            border-radius: 50%;
            color: var(--accent);
            margin: 0 auto 20px auto;
            box-shadow: 0 0 20px rgba(110, 168, 254, 0.1);
        }

        h1 {
            color: #fff;
            margin: 0 0 8px 0;
            font-size: 24px;
            font-weight: 700;
        }

        p {
            color: var(--muted);
            margin: 0 0 25px 0;
            line-height: 1.5;
        }

        .btn {
            display: block;
            width: 100%;
            padding: 14px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
            border: none;
            font-size: 16px;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn.primary {
            background: var(--accent);
            color: #071226;
            box-shadow: 0 4px 15px rgba(110, 168, 254, 0.3);
            margin-bottom: 12px;
        }

        .btn.outline {
            background: transparent;
            border: 1px solid rgba(110, 168, 254, 0.3);
            color: var(--text);
        }
        
        .btn.outline:hover {
            background: rgba(110, 168, 254, 0.05);
            border-color: var(--accent);
        }

        .alert {
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .alert.error {
            background: rgba(231, 76, 60, 0.15);
            border: 1px solid rgba(231, 76, 60, 0.3);
            color: #ff9e93;
        }

        .alert.success {
            background: rgba(39, 174, 96, 0.15);
            border: 1px solid rgba(39, 174, 96, 0.3);
            color: #6ef5a3;
        }

        .hidden {
            display: none !important;
        }
    </style>
</head>

<body>

    <div class="offline-fallback-wrapper">
        <div class="ambient-glow"></div>

        <div class="glass-card">
            <!-- Icon -->
            <div class="hero-icon">
                📡
            </div>

            <!-- Header -->
            <div class="login-header">
                <h1>Connection Lost</h1>
                <p>You seem to be offline.</p>
            </div>

            <!-- Admin/Faculty Message -->
            <div id="admin-msg" class="hidden">
                <div class="alert error">
                   <span>Admin access requires an internet connection.</span>
                </div>
                <button onclick="window.location.reload()" class="btn primary">
                    🔄 Try Reconnecting
                </button>
            </div>

            <!-- Student Message -->
            <div id="student-msg" class="hidden">
                <div class="alert success">
                    <span>Don't worry! You can still report damages.</span>
                </div>

                <a href="<?php echo BASE_URL; ?>views/student/report_new.php" class="btn primary">
                    📝 Report Damage
                </a>

                <a href="<?php echo BASE_URL; ?>views/student/dashboard.php" class="btn outline">
                    🏠 Go to Dashboard
                </a>
            </div>

            <!-- Default Message -->
            <div id="default-msg" class="hidden">
                 <p>Please check your internet connection.</p>
                <button onclick="window.location.reload()" class="btn primary">
                    🔄 Reload
                </button>
            </div>

        </div>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
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
        });
    </script>
</body>
</html>
