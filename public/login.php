<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt'); // Log errors to file
                    
// Start session
session_start();

// Check if user just registered (to prevent auto-login)
$just_registered = false;
if (isset($_SESSION['just_registered'])) {
    $just_registered = true;
    unset($_SESSION['just_registered']); // Clear the flag
}

// Include database configuration
include("../auth/config/database.php");

// IMPORTANT: Make sure PDO is set to throw exceptions
// Add this to your database.php file:
// $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$error_message = '';
$success_message = '';
$debug_mode = true; // Set to false in production

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
    header("Location: dashboard.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data and sanitize
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember_me = isset($_POST['remember_me']);
        
        // Server-side validation
        $errors = [];
        
        if (empty($username)) {
            $errors[] = "Username or email is required";
        }
        if (empty($password)) {
            $errors[] = "Password is required";
        }
        
        // If no errors, check credentials
        if (empty($errors)) {
            // Check if user exists (allow login with username or email)
            // FIX: Pass $username twice since we have two placeholders
            $stmt = $pdo->prepare("
                SELECT user_id, first_name, last_name, email, username, password_hash, 
                       role, department, is_active, is_verified 
                FROM users 
                WHERE (username = ? OR email = ?) 
                AND is_active = 1
            ");
            $stmt->execute([$username, $username]); // Pass the same value twice
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // Check if account is verified
                if ($user['is_verified'] == 0) {
                    $errors[] = "Your account is pending verification. Please wait for admin approval.";
                } else {
                    // Successful login - create session
                    session_regenerate_id(true); // Prevent session fixation
                    
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
                    
                    // Handle "Remember Me" functionality
                    if ($remember_me) {
                        // Create a secure remember token
                        $token = bin2hex(random_bytes(32));
                        $token_hash = hash('sha256', $token);
                        $expires = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60)); // 30 days
                        
                        // Store token in database
                        try {
                            $remember_stmt = $pdo->prepare("
                                INSERT INTO remember_tokens (user_id, token_hash, expires_at, created_at) 
                                VALUES (?, ?, ?, NOW())
                                ON DUPLICATE KEY UPDATE 
                                token_hash = VALUES(token_hash), 
                                expires_at = VALUES(expires_at),
                                created_at = NOW()
                            ");
                            $remember_stmt->execute([$user['user_id'], $token_hash, $expires]);
                            
                            // Set cookie with the actual token (not the hash)
                            setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/', '', isset($_SERVER['HTTPS']), true);
                        } catch (PDOException $e) {
                            error_log("Remember me failed: " . $e->getMessage());
                            if ($debug_mode) {
                                $errors[] = "Remember me feature error: " . $e->getMessage();
                            }
                        }
                    }
                    
                    // Only redirect if no errors occurred
                    if (empty($errors)) {
                        // Redirect based on role
                        switch ($user['role']) {
                            case 'admin':
                                header("Location: dashboard.php");
                                break;
                            case 'manager':
                                header("Location: ../users/managerDashboard.php");
                                break;
                            case 'emplyee':
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
                
                // Optional: Log failed login attempts
                if ($user) {
                    try {
                        $log_stmt = $pdo->prepare("
                            INSERT INTO login_attempts (user_id, ip_address, attempted_at, success) 
                            VALUES (?, ?, NOW(), 0)
                        ");
                        $log_stmt->execute([$user['user_id'], $_SERVER['REMOTE_ADDR']]);
                    } catch (PDOException $e) {
                        error_log("Login attempt logging failed: " . $e->getMessage());
                        // Continue silently for this optional feature
                    }
                }
            }
        }
        
        if (!empty($errors)) {
            $error_message = implode("<br>", $errors);
        }
        
    } catch (PDOException $e) {
        // Database error
        error_log("Database error in login: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        if ($debug_mode) {
            // Show detailed error in development
            $error_message = "Database Error: " . $e->getMessage() . "<br>";
            $error_message .= "Code: " . $e->getCode() . "<br>";
            $error_message .= "File: " . $e->getFile() . " on line " . $e->getLine();
        } else {
            // Show generic error in production
            $error_message = "A database error occurred. Please try again later.";
        }
    } catch (Exception $e) {
        // General error
        error_log("General error in login: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        if ($debug_mode) {
            $error_message = "Error: " . $e->getMessage() . "<br>";
            $error_message .= "File: " . $e->getFile() . " on line " . $e->getLine();
        } else {
            $error_message = "An error occurred. Please try again later.";
        }
    }
}

if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token']) && $_SERVER['REQUEST_METHOD'] !== 'POST' && !$just_registered && !isset($_GET['from'])) {
    try {
        $token = $_COOKIE['remember_token'];
        $token_hash = hash('sha256', $token);
        
        // Check if remember_tokens table exists first
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
            // Auto login
            session_regenerate_id(true);
            
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['department'] = $user['department'];
            $_SESSION['login_time'] = time();
            
            // Update last login
            $update_stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
            $update_stmt->execute([$user['user_id']]);
            
            // Redirect based on role
            switch ($user['role']) {
                case 'admin':
                    header("Location: admin/dashboard.php");
                    break;
                case 'manager':
                    header("Location: manager/dashboard.php");
                    break;
                default:
                    header("Location: dashboard.php");
                    break;
            }
            exit();
        } else {
            // Invalid or expired token - delete cookie
            setcookie('remember_token', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
        }
    } catch (PDOException $e) {
        error_log("Remember me check failed: " . $e->getMessage());
        // Clear the cookie if there's an error (table might not exist)
        setcookie('remember_token', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
        if ($debug_mode) {
            $error_message = "Remember me check error: " . $e->getMessage();
        }
    } catch (Exception $e) {
        error_log("Remember me general error: " . $e->getMessage());
        if ($debug_mode) {
            $error_message = "Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Asset Management System - Sign In</title>
    <link rel="stylesheet" href="../style/login.css">
</head>
<body>
    <div class="container">
        <div class="logo-section">
            <div class="logo">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5z"/>
                </svg>
            </div>
            <h1>Welcome Back</h1>
            <p class="subtitle">Sign in to your E-Asset Management account</p>
        </div>

        <?php if (!empty($success_message)): ?>
        <div class="success-message" style="display: block; background-color: #d4edda; color: #155724; padding: 10px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
            <?php echo htmlspecialchars($success_message); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
        <div class="error-message-box" style="display: block; background-color: #fee; color: #c33; padding: 10px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #fcc;">
            <?php echo $error_message; // Already contains HTML from errors array ?>
        </div>
        <?php endif; ?>

        <form id="loginForm" method="POST" action="">
            <div class="form-group">
                <label for="username">Username or Email *</label>
                <input type="text" id="username" name="username" placeholder="Enter username or email" 
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required autocomplete="username">
                <span class="error-message">Please enter your username or email</span>
            </div>

            <div class="form-group">
                <label for="password">Password *</label>
                <div class="password-container">
                    <input type="password" id="password" name="password" placeholder="••••••••" required autocomplete="current-password">
                    <span class="password-toggle" onclick="togglePassword('password')">👁</span>
                </div>
                <span class="error-message">Please enter your password</span>
            </div>

            <div class="form-options">
                <div class="checkbox-group">
                    <input type="checkbox" id="remember_me" name="remember_me">
                    <label for="remember_me">Remember me for 30 days</label>
                </div>
                <a href="../auth/api/forgotpassword.php" class="forgot-password">Forgot Password?</a>
            </div>

            <button type="submit" class="btn-primary">Sign In</button>
        </form>

        <div class="divider">
            <span>OR</span>
        </div>

        <div class="signup-link">
            Don't have an account? <a href="signup.php">Create Account</a>
        </div>
    </div>

    <script>
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const toggle = field.nextElementSibling;
            const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
            field.setAttribute('type', type);
            toggle.textContent = type === 'password' ? '👁' : '🙈';
        }

        // Client-side validation
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            document.querySelectorAll('.error-message').forEach(msg => {
                msg.style.display = 'none';
            });
            
            let isValid = true;
            
            const username = document.getElementById('username');
            if (username.value.trim().length < 1) {
                username.nextElementSibling.style.display = 'block';
                isValid = false;
            }
            
            const password = document.getElementById('password');
            if (password.value.length < 1) {
                password.parentElement.nextElementSibling.style.display = 'block';
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
            }
        });

        <?php if (!empty($success_message)): ?>
        setTimeout(() => {
            const successMsg = document.querySelector('.success-message');
            if (successMsg) {
                successMsg.style.opacity = '0';
                successMsg.style.transition = 'opacity 0.5s';
                setTimeout(() => successMsg.style.display = 'none', 500);
            }
        }, 5000);
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
        setTimeout(() => {
            const errorMsg = document.querySelector('.error-message-box');
            if (errorMsg) {
                errorMsg.style.opacity = '0';
                errorMsg.style.transition = 'opacity 0.5s';
                setTimeout(() => errorMsg.style.display = 'none', 500);
            }
        }, 8000);
        <?php endif; ?>
    </script>
</body>
</html>