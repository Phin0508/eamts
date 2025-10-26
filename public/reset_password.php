<?php
session_start();

require_once '../auth/config/database.php';

$error_message = '';
$success_message = '';
$token = $_GET['token'] ?? '';
$valid_token = false;
$user_data = null;

// Verify token
if (!empty($token)) {
    try {
        $stmt = $pdo->prepare("
            SELECT user_id, first_name, last_name, email, username, password_reset_expiry 
            FROM users 
            WHERE password_reset_token = ? 
            AND is_active = 1
        ");
        $stmt->execute([$token]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user_data) {
            // Check if token is expired
            if (strtotime($user_data['password_reset_expiry']) > time()) {
                $valid_token = true;
            } else {
                $error_message = "This password reset link has expired. Please request a new one.";
            }
        } else {
            $error_message = "Invalid password reset link.";
        }
    } catch (PDOException $e) {
        $error_message = "Database error. Please try again later.";
    }
} else {
    $error_message = "No reset token provided.";
}

// Enhanced password validation
function validatePassword($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    
    if (strlen($password) > 128) {
        $errors[] = "Password must not exceed 128 characters";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'",.<>?\/\\|`~]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }
    
    return $errors;
}

// Handle password reset submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    try {
        $new_password = $_POST['password'];
        $confirm_password = $_POST['confirmPassword'];
        
        $errors = [];
        
        // Validate passwords match
        if ($new_password !== $confirm_password) {
            $errors[] = "Passwords do not match";
        }
        
        // Validate password strength
        $password_errors = validatePassword($new_password);
        $errors = array_merge($errors, $password_errors);
        
        // Check if password contains username or name
        if (stripos($new_password, $user_data['username']) !== false) {
            $errors[] = "Password cannot contain your username";
        }
        if (strlen($user_data['first_name']) > 2 && stripos($new_password, $user_data['first_name']) !== false) {
            $errors[] = "Password cannot contain your first name";
        }
        if (strlen($user_data['last_name']) > 2 && stripos($new_password, $user_data['last_name']) !== false) {
            $errors[] = "Password cannot contain your last name";
        }
        
        if (empty($errors)) {
            // Hash new password
            $password_hash = password_hash($new_password, PASSWORD_ARGON2ID);
            
            // Update password and clear reset token
            $update_stmt = $pdo->prepare("
                UPDATE users 
                SET password_hash = ?,
                    password_reset_token = NULL,
                    password_reset_expiry = NULL,
                    must_change_password = 0,
                    is_verified = 1,
                    password_changed_at = NOW(),
                    updated_at = NOW()
                WHERE user_id = ?
            ");
            
            if ($update_stmt->execute([$password_hash, $user_data['user_id']])) {
                // Log the password change
                $log_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, action, details, ip_address, created_at)
                    VALUES (?, 'password_reset', 'Password reset successfully', ?, NOW())
                ");
                $log_stmt->execute([$user_data['user_id'], $_SERVER['REMOTE_ADDR']]);
                
                $success_message = "Password reset successfully! You can now log in with your new password.";
                
                // Redirect to login after 3 seconds
                header("refresh:3;url=login.php?message=password_reset");
            } else {
                $errors[] = "Failed to update password. Please try again.";
            }
        }
        
        if (!empty($errors)) {
            $error_message = implode("<br>", $errors);
        }
        
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - E-Asset Management</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 100%;
            padding: 40px;
        }
        
        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 40px;
        }
        
        h1 {
            text-align: center;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .subtitle {
            text-align: center;
            color: #6c757d;
            margin-bottom: 30px;
        }
        
        .user-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .user-info p {
            color: #495057;
            margin: 5px 0;
        }
        
        .user-info strong {
            color: #2c3e50;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #2c3e50;
            font-weight: 600;
        }
        
        .password-container {
            position: relative;
        }
        
        .password-container input {
            width: 100%;
            padding: 12px 45px 12px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .password-container input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            user-select: none;
            font-size: 20px;
        }
        
        .password-strength {
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
            display: none;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: all 0.3s;
        }
        
        .strength-weak { width: 25%; background: #dc3545; }
        .strength-fair { width: 50%; background: #ffc107; }
        .strength-good { width: 75%; background: #17a2b8; }
        .strength-strong { width: 100%; background: #28a745; }
        
        .strength-text {
            font-size: 12px;
            margin-top: 5px;
            font-weight: 600;
        }
        
        .password-requirements {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px;
            margin-top: 10px;
            font-size: 13px;
            display: none;
        }
        
        .password-requirements.show {
            display: block;
        }
        
        .requirement {
            padding: 4px 0;
            color: #6c757d;
            position: relative;
            padding-left: 20px;
        }
        
        .requirement:before {
            content: "○";
            position: absolute;
            left: 0;
            color: #6c757d;
        }
        
        .requirement.met {
            color: #28a745;
        }
        
        .requirement.met:before {
            content: "✓";
            color: #28a745;
        }
        
        .btn-primary {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
            margin-top: 20px;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        
        .back-link a {
            color: #667eea;
            text-decoration: none;
        }
        
        .back-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">🔐</div>
        <h1>Reset Your Password</h1>
        <p class="subtitle">Create a new secure password</p>
        
        <?php if (!empty($success_message)): ?>
        <div class="alert alert-success">
            <?php echo $success_message; ?>
            <p style="margin-top: 10px;">Redirecting to login page...</p>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
        <div class="alert alert-error">
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($valid_token && empty($success_message)): ?>
        <div class="user-info">
            <p><strong>Welcome, <?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?>!</strong></p>
            <p>Username: <strong><?php echo htmlspecialchars($user_data['username']); ?></strong></p>
        </div>
        
        <form method="POST" action="" id="resetForm">
            <div class="form-group">
                <label for="password">New Password</label>
                <div class="password-container">
                    <input type="password" id="password" name="password" required>
                    <span class="password-toggle" onclick="togglePassword('password')">👁</span>
                </div>
                <div class="password-strength" id="passwordStrength">
                    <div class="password-strength-bar" id="passwordStrengthBar"></div>
                </div>
                <div class="strength-text" id="strengthText"></div>
                <div class="password-requirements" id="passwordRequirements">
                    <div class="requirement" id="req-length">At least 8 characters</div>
                    <div class="requirement" id="req-uppercase">One uppercase letter</div>
                    <div class="requirement" id="req-lowercase">One lowercase letter</div>
                    <div class="requirement" id="req-number">One number</div>
                    <div class="requirement" id="req-special">One special character (!@#$%^&*)</div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="confirmPassword">Confirm New Password</label>
                <div class="password-container">
                    <input type="password" id="confirmPassword" name="confirmPassword" required>
                    <span class="password-toggle" onclick="togglePassword('confirmPassword')">👁</span>
                </div>
            </div>
            
            <button type="submit" class="btn-primary">Reset Password</button>
        </form>
        <?php endif; ?>
        
        <div class="back-link">
            <a href="login.php">← Back to Login</a>
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
        
        function checkPasswordStrength(password) {
            const requirements = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[!@#$%^&*()_+\-=\[\]{};:'",.<>?\/\\|`~]/.test(password)
            };
            
            document.getElementById('req-length').classList.toggle('met', requirements.length);
            document.getElementById('req-uppercase').classList.toggle('met', requirements.uppercase);
            document.getElementById('req-lowercase').classList.toggle('met', requirements.lowercase);
            document.getElementById('req-number').classList.toggle('met', requirements.number);
            document.getElementById('req-special').classList.toggle('met', requirements.special);
            
            const metCount = Object.values(requirements).filter(Boolean).length;
            let strengthClass = '';
            let strengthText = '';
            
            if (password.length === 0) {
                strengthText = '';
            } else if (metCount <= 2) {
                strengthClass = 'strength-weak';
                strengthText = 'Weak';
            } else if (metCount === 3) {
                strengthClass = 'strength-fair';
                strengthText = 'Fair';
            } else if (metCount === 4) {
                strengthClass = 'strength-good';
                strengthText = 'Good';
            } else if (metCount === 5) {
                strengthClass = 'strength-strong';
                strengthText = 'Strong';
            }
            
            const strengthBar = document.getElementById('passwordStrengthBar');
            const strengthTextEl = document.getElementById('strengthText');
            
            strengthBar.className = 'password-strength-bar ' + strengthClass;
            strengthTextEl.textContent = strengthText;
            
            return { metCount, requirements };
        }
        
        document.getElementById('password')?.addEventListener('input', function(e) {
            const password = e.target.value;
            const strengthIndicator = document.getElementById('passwordStrength');
            const requirements = document.getElementById('passwordRequirements');
            
            if (password.length > 0) {
                strengthIndicator.style.display = 'block';
                requirements.classList.add('show');
                checkPasswordStrength(password);
            } else {
                strengthIndicator.style.display = 'none';
                requirements.classList.remove('show');
            }
        });
        
        document.getElementById('password')?.addEventListener('focus', function() {
            if (this.value.length > 0) {
                document.getElementById('passwordRequirements').classList.add('show');
            }
        });
        
        document.getElementById('resetForm')?.addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            
            const passwordCheck = checkPasswordStrength(password);
            
            if (passwordCheck.metCount < 5) {
                e.preventDefault();
                alert('Password must meet all requirements');
                return false;
            }
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match');
                return false;
            }
        });
    </script>
</body>
</html>