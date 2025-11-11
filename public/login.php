<?php
// NO WHITESPACE OR BOM BEFORE THIS LINE!
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

// Start output buffering FIRST - this prevents headers already sent errors
ob_start();

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
$debug_mode = false;

// Get redirect parameter if exists
$redirect_to = isset($_GET['redirect']) ? $_GET['redirect'] : '';

/*  DEBUG logging
error_log("=== PAGE LOAD ===");
error_log("Request method: " . $_SERVER['REQUEST_METHOD']);
error_log("Session user_id exists: " . (isset($_SESSION['user_id']) ? 'Yes' : 'No'));
error_log("Cookie remember_token exists: " . (isset($_COOKIE['remember_token']) ? 'Yes' : 'No'));
error_log("All cookies: " . print_r(array_keys($_COOKIE), true)); */

// Handle logout message
if (isset($_GET['message']) && $_GET['message'] === 'logged_out') {
    $success_message = "You have been successfully logged out.";
}

// Handle registration success message
if (isset($_GET['message']) && $_GET['message'] === 'registered') {
    $success_message = "Registration successful! Please sign in with your credentials.";
}

// Auto-login with remember token
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token']) && $_SERVER['REQUEST_METHOD'] !== 'POST' && !$just_registered) {
    error_log(">>> ATTEMPTING AUTO-LOGIN <<<");

    try {
        $token = $_COOKIE['remember_token'];
        $token_hash = hash('sha256', $token);

        error_log("Remember token found: " . substr($token, 0, 10) . "...");

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
            error_log("Remember me auto-login successful for user: " . $user['username']);

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

            // Redirect
            if (!empty($redirect_to) && strpos($redirect_to, '/') === 0 && strpos($redirect_to, '//') === false) {
                header("Location: " . $redirect_to);
                exit();
            }

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
            error_log("Token not found or expired");
            setcookie('remember_token', '', time() - 3600, '/', '', false, true);
        }
    } catch (PDOException $e) {
        error_log("Auto-login error: " . $e->getMessage());
        setcookie('remember_token', '', time() - 3600, '/', '', false, true);
    }
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if (!empty($redirect_to) && strpos($redirect_to, '/') === 0 && strpos($redirect_to, '//') === false) {
        header("Location: " . $redirect_to);
        exit();
    }

    if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'manager') {
        header("Location: dashboard.php");
    } else {
        header("Location: ../users/userDashboard.php");
    }
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== FORM SUBMISSION ===");

    try {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember_me = isset($_POST['remember_me']);

        error_log("Login attempt for: " . $username);
        error_log("Remember me checked: " . ($remember_me ? 'Yes' : 'No'));

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
                    // Regenerate session
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['department'] = $user['department'];
                    $_SESSION['login_time'] = time();

                    error_log("Login successful for user: " . $user['username']);

                    // Update last login
                    $update_stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
                    $update_stmt->execute([$user['user_id']]);

                    // Handle Remember Me - MUST happen BEFORE any redirect
                    if ($remember_me) {
                        error_log("Processing remember me...");

                        try {
                            // Clear existing tokens
                            $clear_stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
                            $clear_stmt->execute([$user['user_id']]);

                            // Generate new token
                            $token = bin2hex(random_bytes(32));
                            $token_hash = hash('sha256', $token);
                            $expires = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));

                            error_log("Creating token for user: " . $user['user_id']);
                            error_log("Token (first 10): " . substr($token, 0, 10));

                            // Save to database
                            $remember_stmt = $pdo->prepare("
                                INSERT INTO remember_tokens (user_id, token_hash, expires_at, created_at) 
                                VALUES (?, ?, ?, NOW())
                            ");
                            $remember_stmt->execute([$user['user_id'], $token_hash, $expires]);

                            error_log("Token saved to database");

                            // CRITICAL: Set cookie BEFORE any output or redirect
                            // Using simple setcookie for better compatibility
                            $cookie_result = setcookie(
                                'remember_token',           // name
                                $token,                     // value
                                time() + (30 * 24 * 60 * 60), // expires
                                '/',                        // path
                                '',                         // domain (empty = current domain)
                                false,                      // secure (false for http, true for https)
                                true                        // httponly
                            );

                            error_log("setcookie() returned: " . ($cookie_result ? 'true' : 'false'));

                            // Additional check
                            if (headers_sent($file, $line)) {
                                error_log("!!! ERROR: Headers already sent in $file on line $line !!!");
                            } else {
                                error_log("Headers NOT sent yet - cookie should work");
                            }
                        } catch (PDOException $e) {
                            error_log("Remember me error: " . $e->getMessage());
                        }
                    }

                    // NOW we can redirect (after cookie is set)
                    if (empty($errors)) {
                        $redirect = !empty($_POST['redirect']) ? $_POST['redirect'] : $redirect_to;

                        if (!empty($redirect) && strpos($redirect, '/') === 0 && strpos($redirect, '//') === false) {
                            header("Location: " . $redirect);
                            exit();
                        }

                        if ($user['must_change_password'] == 1) {
                            header("Location: ../public/change_password.php");
                            exit();
                        }

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
        error_log("Database error: " . $e->getMessage());

        if ($debug_mode) {
            $error_message = "Database Error: " . $e->getMessage();
        } else {
            $error_message = "A database error occurred. Please try again later.";
        }
    } catch (Exception $e) {
        error_log("Error: " . $e->getMessage());

        if ($debug_mode) {
            $error_message = "Error: " . $e->getMessage();
        } else {
            $error_message = "An error occurred. Please try again later.";
        }
    }
}

error_log("=== END OF PAGE LOAD ===\n");

// Flush output buffer before HTML
ob_end_flush();
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
            background: #f8f9fc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-wrapper {
            width: 100%;
            max-width: 1100px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .login-branding {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            padding: 60px 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            color: white;
        }

        .brand-logo {
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            margin-bottom: 30px;
        }

        .brand-content h1 {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 16px;
        }

        .brand-content p {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 40px;
        }

        .feature-list {
            list-style: none;
        }

        .feature-list li {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            font-size: 15px;
        }

        .feature-list li i {
            width: 32px;
            height: 32px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-container {
            padding: 60px 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-header {
            margin-bottom: 40px;
        }

        .login-header h2 {
            font-size: 32px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
        }

        .login-header p {
            color: #718096;
            font-size: 15px;
        }

        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background: #ffe6e6;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .alert-success {
            background: #d4f4dd;
            color: #155724;
            border-left: 4px solid #28a745;
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

        .required {
            color: #ef4444;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #718096;
        }

        .form-group input {
            width: 100%;
            padding: 14px 18px 14px 50px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

        .password-toggle {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #718096;
            z-index: 1;
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
            accent-color: #7c3aed;
        }

        .checkbox-group label {
            cursor: pointer;
            margin: 0;
        }

        .forgot-password {
            color: #7c3aed;
            text-decoration: none;
            font-weight: 600;
        }

        .forgot-password:hover {
            text-decoration: underline;
        }

        .btn-login {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(124, 58, 237, 0.5);
            transition: all 0.3s;
        }

        .divider {
            display: flex;
            align-items: center;
            margin: 32px 0;
            color: #718096;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #e2e8f0;
        }

        .divider span {
            padding: 0 16px;
        }

        .signup-link {
            text-align: center;
            color: #718096;
        }

        .signup-link a {
            color: #7c3aed;
            text-decoration: none;
            font-weight: 700;
        }

        .signup-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 968px) {
            .login-wrapper {
                grid-template-columns: 1fr;
            }

            .login-branding {
                display: none;
            }
        }

        .caps-lock-warning {
            display: none;
            position: absolute;
            right: 50px;
            top: 50%;
            transform: translateY(-50%);
            background: #fff3cd;
            color: #856404;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
            z-index: 2;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .caps-lock-warning i {
            margin-right: 6px;
        }

        .caps-lock-warning::after {
            content: '';
            position: absolute;
            right: -6px;
            top: 50%;
            transform: translateY(-50%);
            border-left: 6px solid #fff3cd;
            border-top: 6px solid transparent;
            border-bottom: 6px solid transparent;
        }
    </style>
</head>

<body>
    <div class="login-wrapper">
        <div class="login-branding">
            <div class="brand-content">
                <div class="brand-logo">🔐</div>
                <h1>E-Asset Management System</h1>
                <p>Streamline your asset tracking and management.</p>
                <ul class="feature-list">
                    <li><i class="fas fa-check"></i><span>Real-time asset tracking</span></li>
                    <li><i class="fas fa-check"></i><span>Comprehensive reporting</span></li>
                    <li><i class="fas fa-check"></i><span>Multi-department support</span></li>
                    <li><i class="fas fa-check"></i><span>Secure & reliable</span></li>
                </ul>
            </div>
        </div>

        <div class="login-container">
            <div class="login-header">
                <h2>Welcome Back</h2>
                <p>Enter your credentials to access your account</p>
            </div>

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
                            placeholder="Enter your username or email" required autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="password" name="password"
                            placeholder="Enter your password" required>
                        <div class="caps-lock-warning" id="capsLockWarning">
                            <i class="fas fa-exclamation-triangle"></i>
                            Caps Lock is ON
                        </div>
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

            <div class="divider"><span>OR</span></div>

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

// NEW: Caps Lock detection
const passwordField = document.getElementById('password');
const capsLockWarning = document.getElementById('capsLockWarning');

// Check on keypress
passwordField.addEventListener('keyup', function(event) {
    const isCapsLock = event.getModifierState && event.getModifierState('CapsLock');
    capsLockWarning.style.display = isCapsLock ? 'block' : 'none';
});

// Check on focus (in case Caps Lock is already on)
passwordField.addEventListener('focus', function(event) {
    // We can only detect on the first keypress after focus
    // So we'll check on the next keyup event
});

// Hide when field loses focus
passwordField.addEventListener('blur', function() {
    capsLockWarning.style.display = 'none';
});

// Debug (existing code)
console.log('Cookies:', document.cookie);
console.log('Has remember_token:', document.cookie.includes('remember_token'));

    </script>
</body>

</html>