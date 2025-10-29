<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

// Start session
session_start();

// Check if user just registered
$just_registered = false;
if (isset($_SESSION['just_registered'])) {
    $just_registered = true;
    unset($_SESSION['just_registered']);
}

// Include database configuration
include("../auth/config/database.php");

$error_message = '';
$success_message = '';
$debug_mode = false; // Set to false in production

// Get redirect parameter if exists
$redirect_to = isset($_GET['redirect']) ? $_GET['redirect'] : '';

// Handle logout message
if (isset($_GET['message']) && $_GET['message'] === 'logged_out') {
    $success_message = "You have been successfully logged out.";
}

// Handle registration success message
if (isset($_GET['message']) && $_GET['message'] === 'registered') {
    $success_message = "Registration successful! Please sign in with your credentials.";
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    // Check if there's a redirect parameter
    if (!empty($redirect_to)) {
        // Security: Validate that redirect is internal (starts with /)
        if (strpos($redirect_to, '/') === 0 && strpos($redirect_to, '//') === false) {
            header("Location: " . $redirect_to);
            exit();
        }
    }
    
    // Default redirect based on role
    if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'manager') {
        header("Location: dashboard.php");
    } else {
        header("Location: ../users/userDashboard.php");
    }
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember_me = isset($_POST['remember_me']);
        
        $errors = [];
        
        if (empty($username)) {
            $errors[] = "Username or email is required";
        }
        if (empty($password)) {
            $errors[] = "Password is required";
        }
        
        if (empty($errors)) {
            $stmt = $pdo->prepare("
                SELECT user_id, first_name, last_name, email, username, password_hash, 
                       role, department, is_active, is_verified, must_change_password
                FROM users 
                WHERE (username = ? OR email = ?) 
                AND is_active = 1
            ");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password_hash'])) {
                if ($user['is_verified'] == 0) {
                    $errors[] = "Your account is pending verification. Please wait for admin approval.";
                } else {
                    session_regenerate_id(true);
                    
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['department'] = $user['department'];
                    $_SESSION['login_time'] = time();
                    
                    // Update last login time
                    $update_stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
                    $update_stmt->execute([$user['user_id']]);
                    
                    // Handle Remember Me
                    if ($remember_me) {
                        try {
                            $token = bin2hex(random_bytes(32));
                            $token_hash = hash('sha256', $token);
                            $expires = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));
                            
                            $remember_stmt = $pdo->prepare("
                                INSERT INTO remember_tokens (user_id, token_hash, expires_at, created_at) 
                                VALUES (?, ?, ?, NOW())
                                ON DUPLICATE KEY UPDATE 
                                token_hash = VALUES(token_hash), 
                                expires_at = VALUES(expires_at),
                                created_at = NOW()
                            ");
                            $remember_stmt->execute([$user['user_id'], $token_hash, $expires]);
                            
                            setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/', '', isset($_SERVER['HTTPS']), true);
                        } catch (PDOException $e) {
                            error_log("Remember me failed: " . $e->getMessage());
                        }
                    }
                    
                    if (empty($errors)) {
                        // Check redirect parameter from POST or GET
                        $redirect = !empty($_POST['redirect']) ? $_POST['redirect'] : $redirect_to;
                        
                        if (!empty($redirect)) {
                            // Security: Validate that redirect is internal
                            if (strpos($redirect, '/') === 0 && strpos($redirect, '//') === false) {
                                header("Location: " . $redirect);
                                exit();
                            }
                        }
                        
                        // Check if must change password
                        if ($user['must_change_password'] == 1) {
                            header("Location: ../public/change_password.php");
                            exit();
                        }
                        
                        // Default redirect based on role
                        switch ($user['role']) {
                            case 'admin':
                                header("Location: dashboard.php");
                                break;
                            case 'manager':
                                header("Location: ../users/managerDashboard.php");
                                break;
                            case 'employee':
                                header("Location: ../users/userDashboard.php");
                                break;
                            default:
                                header("Location: dashboard.php");
                                break;
                        }
                        exit();
                    }
                }
            } else {
                $errors[] = "Invalid username/email or password";
                
                if ($user) {
                    try {
                        $log_stmt = $pdo->prepare("
                            INSERT INTO login_attempts (user_id, ip_address, attempted_at, success) 
                            VALUES (?, ?, NOW(), 0)
                        ");
                        $log_stmt->execute([$user['user_id'], $_SERVER['REMOTE_ADDR']]);
                    } catch (PDOException $e) {
                        error_log("Login attempt logging failed: " . $e->getMessage());
                    }
                }
            }
        }
        
        if (!empty($errors)) {
            $error_message = implode("<br>", $errors);
        }
        
    } catch (PDOException $e) {
        error_log("Database error in login: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        if ($debug_mode) {
            $error_message = "Database Error: " . $e->getMessage();
        } else {
            $error_message = "A database error occurred. Please try again later.";
        }
    } catch (Exception $e) {
        error_log("General error in login: " . $e->getMessage());
        
        if ($debug_mode) {
            $error_message = "Error: " . $e->getMessage();
        } else {
            $error_message = "An error occurred. Please try again later.";
        }
    }
}

// Auto-login with remember token
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token']) && $_SERVER['REQUEST_METHOD'] !== 'POST' && !$just_registered && !isset($_GET['from'])) {
    try {
        $token = $_COOKIE['remember_token'];
        $token_hash = hash('sha256', $token);
        
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.first_name, u.last_name, u.email, u.username, 
                   u.role, u.department, u.is_active, u.is_verified
            FROM users u
            JOIN remember_tokens rt ON u.user_id = rt.user_id
            WHERE rt.token_hash = ? 
            AND rt.expires_at > NOW()
            AND u.is_active = 1
            AND u.is_verified = 1
        ");
        $stmt->execute([$token_hash]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            session_regenerate_id(true);
            
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['department'] = $user['department'];
            $_SESSION['login_time'] = time();
            
            $update_stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
            $update_stmt->execute([$user['user_id']]);
            
            // Redirect based on role
            switch ($user['role']) {
                case 'admin':
                    header("Location: dashboard.php");
                    break;
                case 'manager':
                    header("Location: ../users/managerDashboard.php");
                    break;
                default:
                    header("Location: ../users/userDashboard.php");
                    break;
            }
            exit();
        } else {
            setcookie('remember_token', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
        }
    } catch (PDOException $e) {
        error_log("Remember me check failed: " . $e->getMessage());
        setcookie('remember_token', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - E-Asset Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 480px;
            width: 100%;
            overflow: hidden;
        }

        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 50px 40px;
            text-align: center;
        }

        .login-header .logo {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
        }

        .login-header h1 {
            font-size: 32px;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .login-header p {
            opacity: 0.9;
            font-size: 15px;
        }

        .login-body {
            padding: 40px;
        }

        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 24px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-error {
            background: linear-gradient(135deg, #ffe6e6 0%, #ffd4d4 100%);
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4f4dd 0%, #c3e6cb 100%);
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .redirect-notice {
            background: linear-gradient(135deg, #e7f3ff 0%, #d4e9ff 100%);
            border-left: 4px solid #2196F3;
            padding: 16px 18px;
            border-radius: 10px;
            margin-bottom: 24px;
            font-size: 14px;
            color: #1976D2;
        }

        .redirect-notice strong {
            display: block;
            margin-bottom: 5px;
            font-size: 15px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            color: #2d3748;
            font-weight: 600;
            font-size: 14px;
        }

        .form-group label .required {
            color: #ef4444;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #718096;
            font-size: 16px;
        }

        .form-group input {
            width: 100%;
            padding: 14px 16px 14px 46px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            font-family: inherit;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        .password-wrapper {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #718096;
            font-size: 18px;
            transition: color 0.3s;
        }

        .password-toggle:hover {
            color: #667eea;
        }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            font-size: 14px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-group label {
            cursor: pointer;
            color: #2d3748;
            margin: 0;
        }

        .forgot-password {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }

        .forgot-password:hover {
            color: #764ba2;
            text-decoration: underline;
        }

        .btn-login {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 30px 0;
            color: #718096;
            font-size: 14px;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #e2e8f0;
        }

        .divider span {
            padding: 0 15px;
            font-weight: 600;
        }

        .signup-link {
            text-align: center;
            color: #718096;
            font-size: 15px;
        }

        .signup-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 700;
            transition: color 0.3s;
        }

        .signup-link a:hover {
            color: #764ba2;
            text-decoration: underline;
        }

        @media (max-width: 600px) {
            .login-container {
                max-width: 100%;
            }

            .login-header {
                padding: 40px 30px;
            }

            .login-header h1 {
                font-size: 26px;
            }

            .login-body {
                padding: 30px 25px;
            }

            .form-options {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">
                🔐
            </div>
            <h1>Welcome Back</h1>
            <p>Sign in to E-Asset Management System</p>
        </div>

        <div class="login-body">
            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error_message; ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($redirect_to)): ?>
            <div class="redirect-notice">
                <strong>📦 Asset Assignment Notice</strong>
                Please login to view your assigned assets.
            </div>
            <?php endif; ?>

            <form method="POST" action="">
                <?php if (!empty($redirect_to)): ?>
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect_to); ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="username">Username or Email <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" id="username" name="username" 
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                               placeholder="Enter your username or email" 
                               required autofocus autocomplete="username">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password <span class="required">*</span></label>
                    <div class="input-wrapper password-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="password" name="password" 
                               placeholder="Enter your password" 
                               required autocomplete="current-password">
                        <span class="password-toggle" onclick="togglePassword()">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </span>
                    </div>
                </div>

                <div class="form-options">
                    <div class="checkbox-group">
                        <input type="checkbox" id="remember_me" name="remember_me">
                        <label for="remember_me">Remember me for 30 days</label>
                    </div>
                    <a href="forgotPassword.php" class="forgot-password">Forgot Password?</a>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
            </form>

            <div class="divider">
                <span>OR</span>
            </div>

            <div class="signup-link">
                Don't have an account? <a href="signup.php">Create Account</a>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // Auto-hide messages
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.style.display = 'none', 500);
            });
        }, 5000);
    </script>
</body>
</html>