<?php
require_once __DIR__ . '/../config/init.php';

function handle_login($conn) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf()) {
            die('CSRF validation failed');
        }
        $login = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($login && $password) {
            $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? OR name = ? LIMIT 1");
            $stmt->bind_param("ss", $login, $login);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($u = $result->fetch_assoc()) {
                // Password check (supports both hashed and legacy plain text)
                if (password_verify($password, $u['password'])) {
                    // Check verification status
                    if ($u['is_verified'] == 0) {
                        return 'Your account is pending verification by Admin.';
                    }

                    session_regenerate_id(true);
                    regenerate_csrf_token();
                    $_SESSION['user'] = [
                        'id'=>$u['id'],
                        'name'=>$u['name'],
                        'email'=>$u['email'],
                        'role'=>$u['role']
                    ];
                    redirect_by_role();
                } else {
                    $error = 'Invalid credentials';
                }
            } else {
                $error = 'User not found';
            }
        } else {
            $error = 'Please fill all fields';
        }
    }
    return $error;
}

function handle_register($conn) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf()) {
            die('CSRF validation failed');
        }
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $register_number = trim($_POST['register_number'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $role = $_POST['role'] ?? '';

        if ($name && $email && $register_number && $password && $confirm_password && $role) {
            if ($password !== $confirm_password) {
                $error = 'Passwords do not match';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email format';
            } elseif (!in_array($role, ['student', 'faculty'])) {
                $error = 'Invalid role selected';
            } else {
                // Check if email already exists
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $stmt->store_result();
                
                if ($stmt->num_rows > 0) {
                    $error = 'Email already registered';
                } else {
                    // Hash password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Insert new user
                    $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, register_number) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssss", $name, $email, $hashed_password, $role, $register_number);
                    
                    if ($stmt->execute()) {
                        $new_user_id = $stmt->insert_id;
                        
                        // Notify all admins about the new registration
                        $admin_query = $conn->query("SELECT id FROM users WHERE role = 'admin'");
                        while($admin = $admin_query->fetch_assoc()) {
                            $msg = "👤 New Registration: $name ($register_number) has registered and is pending approval.";
                            notify_user($conn, (int)$admin['id'], $msg);
                        }
                        
                        header('Location: ' . BASE_URL . 'views/login.php?msg=Registration successful. Please wait for Admin approval.');
                        exit;
                    } else {
                        $error = 'Registration failed. Please try again.';
                    }
                }
            }
        } else {
            $error = 'Please fill all fields';
        }
    }
    return $error;
}
?>
