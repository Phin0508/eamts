<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; background: white; }
        .header { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 30px; text-align: center; }
        .content { padding: 30px; }
        .asset-card { background: #f8f9fa; border: 2px solid #667eea; padding: 20px; margin: 20px 0; border-radius: 8px; }
        .button { display: inline-block; padding: 12px 24px; background: #667eea; color: white !important; text-decoration: none; border-radius: 6px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; border-top: 1px solid #e2e8f0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎁 New Asset Assigned</h1>
        </div>
        <div class="content">
            <p>Hello <?php echo htmlspecialchars($user_name); ?>,</p>
            <p>A new asset has been assigned to you. Please review the details below:</p>
            
            <div class="asset-card">
                <h3><?php echo htmlspecialchars($asset_name); ?></h3>
                <p><strong>Asset Code:</strong> <?php echo htmlspecialchars($asset_code); ?></p>
                <p><strong>Category:</strong> <?php echo htmlspecialchars($category); ?></p>
                <p><strong>Brand:</strong> <?php echo htmlspecialchars($brand); ?></p>
                <p><strong>Model:</strong> <?php echo htmlspecialchars($model); ?></p>
                <p><strong>Assigned Date:</strong> <?php echo $assigned_date; ?></p>
            </div>
            
            <p><strong>Important:</strong> You are now responsible for this asset. Please:</p>
            <ul>
                <li>Keep the asset in good condition</li>
                <li>Report any issues immediately</li>
                <li>Return the asset when requested</li>
            </ul>
            
            <center>
                <a href="<?php echo $view_url; ?>" class="button">View My Assets</a>
            </center>
        </div>
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo SYSTEM_NAME; ?></p>
        </div>
    </div>
</body>
</html>