<?php
session_start();

// Clear any existing remember me cookies
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
    unset($_COOKIE['remember_token']);
}

// IMPORTANT: Self-registration is disabled
// Only administrators can create user accounts through adminCreateUser.php
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Asset Management System - Registration Disabled</title>
    <link rel="stylesheet" href="../style/signup.css">
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
            max-width: 600px;
            width: 100%;
            padding: 60px 40px;
            text-align: center;
        }
        
        .icon {
            width: 120px;
            height: 120px;
            margin: 0 auto 30px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 60px;
        }
        
        h1 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 32px;
        }
        
        .subtitle {
            color: #6c757d;
            font-size: 18px;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 25px;
            border-radius: 8px;
            margin: 30px 0;
            text-align: left;
        }
        
        .info-box h3 {
            color: #1976D2;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .info-box ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .info-box li {
            padding: 10px 0;
            color: #495057;
            display: flex;
            align-items: start;
            font-size: 15px;
            line-height: 1.6;
        }
        
        .info-box li:before {
            content: "✓";
            color: #28a745;
            font-weight: bold;
            margin-right: 12px;
            font-size: 18px;
        }
        
        .contact-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 20px;
            border-radius: 8px;
            margin: 30px 0;
            text-align: left;
        }
        
        .contact-box h3 {
            color: #856404;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .contact-box p {
            color: #856404;
            margin: 0;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .contact-box a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        
        .contact-box a:hover {
            text-decoration: underline;
        }
        
        .btn-primary {
            display: inline-block;
            padding: 14px 40px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            margin-top: 20px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        
        .divider {
            margin: 30px 0;
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
            padding: 0 20px;
            color: #6c757d;
            position: relative;
            font-size: 14px;
        }
        
        .login-link {
            margin-top: 20px;
            color: #6c757d;
            font-size: 15px;
        }
        
        .login-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        
        .login-link a:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 40px 20px;
            }
            
            h1 {
                font-size: 26px;
            }
            
            .subtitle {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🔒</div>
        
        <h1>Self-Registration Disabled</h1>
        <p class="subtitle">
            For security and administrative control, user accounts can only be created by system administrators.
        </p>
        
        <div class="info-box">
            <h3>📋 How to Get Access</h3>
            <ul>
                <li>Contact your IT administrator or HR department</li>
                <li>Request a user account for the E-Asset Management System</li>
                <li>You will receive an email with your login credentials</li>
                <li>Set your password using the secure link provided in the email</li>
            </ul>
        </div>
        
        <div class="contact-box">
            <h3>⚠️ Need Help?</h3>
            <p>
                If you're a new employee and haven't received your account details, please contact:<br>
                <strong>IT Support:</strong> <a href="mailto:support@company.com">support@company.com</a><br>
                <strong>HR Department:</strong> <a href="mailto:hr@company.com">hr@company.com</a>
            </p>
        </div>
        
        <div class="divider">
            <span>Already have an account?</span>
        </div>
        
        <a href="login.php" class="btn-primary">Go to Login Page</a>
        
        <div class="login-link">
            Forgot your password? <a href="forgot_password.php">Reset it here</a>
        </div>
    </div>
</body>
</html>