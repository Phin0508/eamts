<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; background: white; }
        .header { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 30px; text-align: center; }
        .content { padding: 30px; }
        .status-change { background: #f8f9fa; padding: 20px; margin: 20px 0; border-radius: 8px; text-align: center; }
        .status-badge { display: inline-block; padding: 8px 16px; border-radius: 20px; font-weight: 600; margin: 0 10px; }
        .button { display: inline-block; padding: 12px 24px; background: #667eea; color: white !important; text-decoration: none; border-radius: 6px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; border-top: 1px solid #e2e8f0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📬 Ticket Status Updated</h1>
        </div>
        <div class="content">
            <p>Hello <?php echo htmlspecialchars($user_name); ?>,</p>
            <p>The status of your ticket has been updated.</p>
            
            <div class="status-change">
                <h3><?php echo htmlspecialchars($ticket_number); ?></h3>
                <p><?php echo htmlspecialchars($subject); ?></p>
                <p style="margin: 20px 0;">
                    <span class="status-badge" style="background: #ffc107; color: #000;"><?php echo htmlspecialchars($old_status); ?></span>
                    <span style="font-size: 24px;">→</span>
                    <span class="status-badge" style="background: #28a745; color: white;"><?php echo htmlspecialchars($new_status); ?></span>
                </p>
                <p><small>Updated: <?php echo $updated_at; ?></small></p>
            </div>
            
            <center>
                <a href="<?php echo $view_url; ?>" class="button">View Ticket</a>
            </center>
        </div>
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo SYSTEM_NAME; ?></p>
        </div>
    </div>
</body>
</html>