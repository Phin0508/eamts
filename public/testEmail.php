<?php
/**
 * SMTP Configuration Test Page
 * Place this in: /public/test_email.php
 * IMPORTANT: Remove or restrict access after testing!
 */

session_start();

// Restrict access to admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Access denied. Admin only.");
}

require_once '../auth/helpers/EmailHelper.php';

$result = '';
$test_email = $_POST['test_email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($test_email)) {
    $emailHelper = new EmailHelper();
    
    if ($emailHelper->testConnection($test_email)) {
        $result = '<div class="alert alert-success">✓ Email sent successfully! Check your inbox.</div>';
    } else {
        $result = '<div class="alert alert-error">✗ Failed to send email. Check your SMTP configuration.</div>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMTP Test - E-Asset System</title>
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
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 600px;
            width: 100%;
            padding: 40px;
        }
        
        h1 {
            color: #2c3e50;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .subtitle {
            color: #6c757d;
            margin-bottom: 30px;
        }
        
        .config-info {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 6px;
        }
        
        .config-info h3 {
            color: #667eea;
            margin-bottom: 15px;
        }
        
        .config-info p {
            margin: 8px 0;
            color: #495057;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #2c3e50;
            font-weight: 500;
        }
        
        input[type="email"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        input[type="email"]:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
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
        
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #667eea;
            text-decoration: none;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        .warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            <span>📧</span> SMTP Configuration Test
        </h1>
        <p class="subtitle">Test your email settings before going live</p>
        
        <?php echo $result; ?>
        
        <div class="config-info">
            <h3>Current Configuration</h3>
            <p><strong>SMTP Host:</strong> <?php echo SMTP_HOST; ?></p>
            <p><strong>SMTP Port:</strong> <?php echo SMTP_PORT; ?></p>
            <p><strong>Encryption:</strong> <?php echo strtoupper(SMTP_SECURE); ?></p>
            <p><strong>Username:</strong> <?php echo SMTP_USERNAME; ?></p>
            <p><strong>From Email:</strong> <?php echo MAIL_FROM_EMAIL; ?></p>
            <p><strong>From Name:</strong> <?php echo MAIL_FROM_NAME; ?></p>
        </div>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="test_email">Test Email Address</label>
                <input 
                    type="email" 
                    id="test_email" 
                    name="test_email" 
                    placeholder="Enter your email address"
                    value="<?php echo htmlspecialchars($test_email); ?>"
                    required
                >
            </div>
            
            <button type="submit" class="btn">
                🚀 Send Test Email
            </button>
        </form>
        
        <div class="warning">
            <strong>⚠️ Security Warning:</strong> This page should be removed or restricted in production. 
            It exposes sensitive SMTP configuration information.
        </div>
        
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
    </div>
</body>
</html>