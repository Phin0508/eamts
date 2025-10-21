<?php
// Include database configuration
include("../config/database.php");

$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $email = trim($_POST['email']);
        
        // Validate email
        if (empty($email)) {
            $error_message = "Email address is required";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Please enter a valid email address";
        } else {
            // Check if email exists in database
            $stmt = $pdo->prepare("SELECT user_id, first_name FROM users WHERE email = ? AND is_active = 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Generate password reset token
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry
                
                
                try {
                    $reset_stmt = $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
                    $reset_stmt->execute([$user['user_id'], $token, $expires]);
                    
                    // In a real application, you would send an email here
                    // For demonstration, we'll just show a success message
                    $success_message = "If an account with this email exists, you will receive password reset instructions.";
                    
                    /*
                    // Email sending code (example with PHPMailer)
                    $reset_link = "https://yourdomain.com/reset-password.php?token=" . $token;
                    $subject = "Password Reset Request";
                    $message = "Dear " . htmlspecialchars($user['first_name']) . ",\n\n";
                    $message .= "You have requested to reset your password. Please click the link below to reset your password:\n\n";
                    $message .= $reset_link . "\n\n";
                    $message .= "This link will expire in 1 hour.\n\n";
                    $message .= "If you did not request this password reset, please ignore this email.\n\n";
                    $message .= "Best regards,\nE-Asset Management Team";
                    
                    // Send email here
                    mail($email, $subject, $message);
                    */
                    
                } catch (PDOException $e) {
                    // For security, don't reveal if the table doesn't exist
                    $success_message = "If an account with this email exists, you will receive password reset instructions.";
                }
            } else {
                // For security, show the same message whether email exists or not
                $success_message = "If an account with this email exists, you will receive password reset instructions.";
            }
        }
        
    } catch (PDOException $e) {
        $error_message = "System error occurred. Please try again later.";
        error_log("Forgot password error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Asset Management System - Forgot Password</title>
    <link rel="stylesheet" href="../style/login.css">
    <style>
        * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.container {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    width: 100%;
    max-width: 450px;
    padding: 40px;
    animation: slideUp 0.5s ease-out;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.logo-section {
    text-align: center;
    margin-bottom: 32px;
}

.logo {
    width: 80px;
    height: 80px;
    margin: 0 auto 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.logo svg {
    width: 45px;
    height: 45px;
    fill: #ffffff;
}

.logo-section h1 {
    font-size: 28px;
    font-weight: 700;
    color: #1a202c;
    margin-bottom: 8px;
}

.subtitle {
    font-size: 14px;
    color: #718096;
    line-height: 1.5;
}

/* Success and Error Messages */
.success-message,
.error-message-box {
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 24px;
    font-size: 14px;
    line-height: 1.5;
    display: none;
    animation: fadeIn 0.3s ease-in;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.success-message {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.error-message-box {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

/* Form Styles */
form {
    margin-bottom: 24px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 8px;
}

.form-group input {
    width: 100%;
    padding: 12px 16px;
    font-size: 15px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    transition: all 0.3s ease;
    background-color: #ffffff;
    color: #1a202c;
}

.form-group input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.form-group input::placeholder {
    color: #a0aec0;
}

/* Error Message under Input */
.form-group .error-message {
    display: none;
    color: #e53e3e;
    font-size: 13px;
    margin-top: 6px;
}

/* Buttons */
.btn-primary {
    width: 100%;
    padding: 14px;
    font-size: 16px;
    font-weight: 600;
    color: #ffffff;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(102, 126, 234, 0.5);
}

.btn-primary:active {
    transform: translateY(0);
}

/* Divider */
.divider {
    display: flex;
    align-items: center;
    margin: 24px 0;
    color: #a0aec0;
    font-size: 13px;
}

.divider::before,
.divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background-color: #e2e8f0;
}

.divider span {
    padding: 0 16px;
    font-weight: 500;
}

/* Links */
.signup-link {
    text-align: center;
    font-size: 14px;
    color: #4a5568;
}

.signup-link a {
    color: #667eea;
    text-decoration: none;
    font-weight: 600;
    transition: color 0.2s ease;
}

.signup-link a:hover {
    color: #764ba2;
    text-decoration: underline;
}

/* Responsive Design */
@media (max-width: 480px) {
    .container {
        padding: 30px 24px;
    }

    .logo-section h1 {
        font-size: 24px;
    }

    .logo {
        width: 70px;
        height: 70px;
    }

    .logo svg {
        width: 40px;
        height: 40px;
    }
}

/* Loading State */
.btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* Focus Visible for Accessibility */
*:focus-visible {
    outline: 2px solid #667eea;
    outline-offset: 2px;
}
    </style>

</head>
<body>
    <div class="container">
        <div class="logo-section">
            <div class="logo">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5z"/>
                </svg>
            </div>
            <h1>Reset Password</h1>
            <p class="subtitle">Enter your email to receive reset instructions</p>
        </div>

        <?php if (!empty($success_message)): ?>
        <div class="success-message" style="display: block;">
            <?php echo htmlspecialchars($success_message); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
        <div class="error-message-box" style="display: block;">
            <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>

        <?php if (empty($success_message)): ?>
        <form id="forgotPasswordForm" method="POST" action="">
            <div class="form-group">
                <label for="email">Email Address *</label>
                <input type="email" id="email" name="email" placeholder="Enter your email address" 
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                <span class="error-message">Please enter a valid email address</span>
            </div>

            <button type="submit" class="btn-primary">Send Reset Instructions</button>
        </form>
        <?php endif; ?>

        <div class="divider">
            <span>OR</span>
        </div>

        <div class="signup-link">
            Remember your password? <a href="../../public/login.php">Sign In</a>
        </div>

        <div class="signup-link" style="margin-top: 16px;">
            Don't have an account? <a href="../../public/signup.php">Create Account</a>
        </div>
    </div>

    <script>
        // Client-side validation
        document.getElementById('forgotPasswordForm')?.addEventListener('submit', function(e) {
            const email = document.getElementById('email');
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            
            // Reset error messages
            document.querySelectorAll('.error-message').forEach(msg => {
                msg.style.display = 'none';
            });
            
            // Validate email
            if (!emailRegex.test(email.value)) {
                email.nextElementSibling.style.display = 'block';
                e.preventDefault();
            }
        });

        // Auto-hide success message and redirect after 10 seconds
        <?php if (!empty($success_message)): ?>
        setTimeout(() => {
            window.location.href = 'login.php';
        }, 10000);
        <?php endif; ?>
    </script>
</body>
</html>