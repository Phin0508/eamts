<?php
session_start();

require_once '../auth/config/database.php';
require_once '../auth/helpers/EmailHelper.php';

$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $email = trim($_POST['email']);
        
        // Validate email
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Please enter a valid email address";
        } else {
            // Check if user exists
            $stmt = $pdo->prepare("
                SELECT user_id, first_name, last_name, email, username, is_active 
                FROM users 
                WHERE email = ?
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                if ($user['is_active'] == 0) {
                    $error_message = "This account is inactive. Please contact your administrator.";
                } else {
                    // Generate reset token
                    $reset_token = bin2hex(random_bytes(32));
                    $reset_token_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    
                    // Update user with reset token
                    $update_stmt = $pdo->prepare("
                        UPDATE users 
                        SET password_reset_token = ?,
                            password_reset_expiry = ?,
                            updated_at = NOW()
                        WHERE user_id = ?
                    ");
                    
                    if ($update_stmt->execute([$reset_token, $reset_token_expiry, $user['user_id']])) {
                        // Send password reset email
                        $emailHelper = new EmailHelper();
                        $user_name = $user['first_name'] . ' ' . $user['last_name'];
                        
                        if ($emailHelper->sendPasswordResetEmail($email, $reset_token, $user_name)) {
                            $success_message = "Password reset instructions have been sent to your email address. Please check your inbox.";
                            
                            // Log the password reset request
                            $log_stmt = $pdo->prepare("
                                INSERT INTO activity_logs (user_id, action, details, ip_address, created_at)
                                VALUES (?, 'password_reset_requested', 'Password reset requested', ?, NOW())
                            ");
                            $log_stmt->execute([$user['user_id'], $_SERVER['REMOTE_ADDR']]);
                        } else {
                            $error_message = "Failed to send reset email. Please try again or contact support.";
                        }
                    } else {
                        $error_message = "An error occurred. Please try again.";
                    }
                }
            } else {
                // Don't reveal if email exists or not (security best practice)
                $success_message = "If an account exists with that email, password reset instructions have been sent.";
            }
        }
        
    } catch (PDOException $e) {
        $error_message = "Database error. Please try again later.";
        error_log("Forgot password error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - E-Asset Management</title>
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
            font-size: 28px;
        }
        
        .subtitle {
            text-align: center;
            color: #6c757d;
            margin-bottom: 30px;
            line-height: 1.6;
            font-size: 14px;
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
        
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 25px;
        }
        
        .info-box p {
            color: #1565C0;
            font-size: 13px;
            line-height: 1.6;
            margin: 0;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #2c3e50;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
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
            margin-top: 10px;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .divider {
            margin: 25px 0;
            text-align: center;
            position: relative;
        }
        
        .divider:before {
            content: "";
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #dee2e6;
        }
        
        .divider span {
            background: white;
            padding: 0 15px;
            color: #6c757d;
            position: relative;
            font-size: 13px;
        }
        
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        
        .back-link a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }
        
        .back-link a:hover {
            text-decoration: underline;
        }
        
        .steps {
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid #e9ecef;
        }
        
        .steps h3 {
            color: #2c3e50;
            font-size: 16px;
            margin-bottom: 15px;
        }
        
        .step {
            display: flex;
            align-items: start;
            margin-bottom: 12px;
        }
        
        .step-number {
            width: 24px;
            height: 24px;
            background: #667eea;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            margin-right: 12px;
            flex-shrink: 0;
        }
        
        .step-text {
            color: #495057;
            font-size: 13px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">🔑</div>
        
        <h1>Forgot Password?</h1>
        <p class="subtitle">
            Enter your email address and we'll send you instructions to reset your password.
        </p>
        
        <?php if (!empty($success_message)): ?>
        <div class="alert alert-success">
            <?php echo $success_message; ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
        <div class="alert alert-error">
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>
        
        <?php if (empty($success_message)): ?>
        <div class="info-box">
            <p>
                💡 <strong>Tip:</strong> Make sure to check your spam folder if you don't see the email in your inbox within a few minutes.
            </p>
        </div>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="email">Email Address</label>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    placeholder="your.email@company.com"
                    value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                    required
                >
            </div>
            
            <button type="submit" class="btn-primary">
                📧 Send Reset Instructions
            </button>
        </form>
        
        <div class="steps">
            <h3>What happens next?</h3>
            <div class="step">
                <div class="step-number">1</div>
                <div class="step-text">We'll send a password reset link to your email</div>
            </div>
            <div class="step">
                <div class="step-number">2</div>
                <div class="step-text">Click the link in the email (valid for 1 hour)</div>
            </div>
            <div class="step">
                <div class="step-number">3</div>
                <div class="step-text">Create your new secure password</div>
            </div>
            <div class="step">
                <div class="step-number">4</div>
                <div class="step-text">Log in with your new password</div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="divider">
            <span>OR</span>
        </div>
        
        <div class="back-link">
            <a href="../public/login.php">← Back to Login</a>
        </div>
    </div>
</body>
</html>