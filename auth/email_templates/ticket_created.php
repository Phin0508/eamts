<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; background: white; }
        .header { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 30px; text-align: center; }
        .content { padding: 30px; }
        .ticket-info { background: #f8f9fa; border-left: 4px solid #28a745; padding: 20px; margin: 20px 0; }
        .button { display: inline-block; padding: 12px 24px; background: #667eea; color: white !important; text-decoration: none; border-radius: 6px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; border-top: 1px solid #e2e8f0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✓ Ticket Created Successfully</h1>
        </div>
        <div class="content">
            <p>Hello <?php echo htmlspecialchars($user_name); ?>,</p>
            <p>Your support ticket has been created and our team will review it shortly.</p>
            
            <div class="ticket-info">
                <h3>Ticket Details</h3>
                <p><strong>Ticket Number:</strong> <?php echo htmlspecialchars($ticket_number); ?></p>
                <p><strong>Subject:</strong> <?php echo htmlspecialchars($subject); ?></p>
                <p><strong>Priority:</strong> <span style="color: <?php echo $priority === 'urgent' ? '#dc3545' : ($priority === 'high' ? '#fd7e14' : '#28a745'); ?>"><?php echo htmlspecialchars($priority); ?></span></p>
                <p><strong>Status:</strong> <?php echo htmlspecialchars($status); ?></p>
                <p><strong>Created:</strong> <?php echo $created_at; ?></p>
            </div>
            
            <p>You will receive email notifications when there are updates to your ticket.</p>
            
            <center>
                <a href="<?php echo $view_url; ?>" class="button">View Ticket Details</a>
            </center>
        </div>
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo SYSTEM_NAME; ?></p>
            <p>Need immediate assistance? Contact <?php echo SUPPORT_EMAIL; ?></p>
        </div>
    </div>
</body>
</html>
