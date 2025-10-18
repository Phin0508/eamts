<?php
// forgot-password.php
// Include database configuration
include("../auth/config/database.php");

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
                
                // Store token in database (you may need to create this table)
                /*
                CREATE TABLE password_reset_tokens (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    user_id INT NOT NULL,
                    token VARCHAR(64) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(user_id),
                    INDEX idx_token (token),
                    INDEX idx_expires (expires_at)
                );
                */
                
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
            Remember your password? <a href="login.php">Sign In</a>
        </div>

        <div class="signup-link" style="margin-top: 16px;">
            Don't have an account? <a href="signup.php">Create Account</a>
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