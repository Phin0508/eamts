<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
        }
        .email-container {
            max-width: 600px;
            margin: 20px auto;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
        }
        .header p {
            margin: 10px 0 0 0;
            font-size: 16px;
            opacity: 0.9;
        }
        .content {
            padding: 30px;
        }
        .greeting {
            font-size: 18px;
            color: #2c3e50;
            margin-bottom: 20px;
        }
        .success-message {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 20px;
            margin: 20px 0;
            border-radius: 6px;
            color: #155724;
        }
        .ticket-details {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .ticket-details h3 {
            color: #667eea;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 18px;
        }
        .detail-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            color: #6c757d;
            width: 140px;
            flex-shrink: 0;
        }
        .detail-value {
            color: #2c3e50;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-urgent { background: #fee2e2; color: #991b1b; }
        .badge-high { background: #fed7aa; color: #9a3412; }
        .badge-medium { background: #fef3c7; color: #92400e; }
        .badge-low { background: #dbeafe; color: #1e40af; }
        .badge-open { background: #dbeafe; color: #1e40af; }
        .info-box {
            background: #f0f9ff;
            border-left: 4px solid #3b82f6;
            padding: 15px;
            margin: 20px 0;
            border-radius: 6px;
        }
        .info-box h4 {
            color: #1e40af;
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 16px;
        }
        .info-box p {
            margin: 5px 0;
            color: #1e3a8a;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 14px 30px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin: 20px 0;
            text-align: center;
        }
        .footer {
            background: #f8f9fa;
            padding: 20px 30px;
            text-align: center;
            color: #6c757d;
            font-size: 14px;
            border-top: 1px solid #e9ecef;
        }
        .footer p {
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class='email-container'>
        <div class='header'>
            <h1>🎫 Ticket Created Successfully</h1>
            <p><?php echo SYSTEM_NAME; ?></p>
        </div>
        
        <div class='content'>
            <p class='greeting'>Hello <strong><?php echo htmlspecialchars($user_name); ?></strong>,</p>
            
            <div class='success-message'>
                <p style='margin: 0; font-size: 16px;'>
                    ✅ Your support ticket has been created successfully and is now pending review by your department manager.
                </p>
            </div>
            
            <div class='ticket-details'>
                <h3>📋 Ticket Details</h3>
                <div class='detail-row'>
                    <span class='detail-label'>Ticket Number:</span>
                    <span class='detail-value'><strong><?php echo htmlspecialchars($ticket_number); ?></strong></span>
                </div>
                <div class='detail-row'>
                    <span class='detail-label'>Subject:</span>
                    <span class='detail-value'><?php echo htmlspecialchars($subject); ?></span>
                </div>
                <div class='detail-row'>
                    <span class='detail-label'>Priority:</span>
                    <span class='detail-value'>
                        <span class='badge badge-<?php echo strtolower($priority); ?>'>
                            <?php echo $priority; ?>
                        </span>
                    </span>
                </div>
                <div class='detail-row'>
                    <span class='detail-label'>Status:</span>
                    <span class='detail-value'>
                        <span class='badge badge-<?php echo strtolower($status); ?>'>
                            <?php echo $status; ?>
                        </span>
                    </span>
                </div>
                <div class='detail-row'>
                    <span class='detail-label'>Created:</span>
                    <span class='detail-value'><?php echo $created_at; ?></span>
                </div>
            </div>
            
            <div class='info-box'>
                <h4>📌 What Happens Next?</h4>
                <p><strong>1. Manager Review:</strong> Your department manager will review and approve your ticket.</p>
                <p><strong>2. Assignment:</strong> Once approved, an admin will assign a technician to work on your request.</p>
                <p><strong>3. Resolution:</strong> The assigned technician will work to resolve your issue.</p>
                <p><strong>4. Updates:</strong> You'll receive email notifications for any updates or comments.</p>
            </div>
            
            <center>
                <a href='<?php echo $view_url; ?>' class='cta-button'>
                    View Ticket Details
                </a>
            </center>
            
            <p style='color: #6c757d; font-size: 14px; margin-top: 20px;'>
                You can track the progress of your ticket at any time by visiting your dashboard or clicking the button above.
            </p>
        </div>
        
        <div class='footer'>
            <p><strong><?php echo SYSTEM_NAME; ?></strong></p>
            <p>This is an automated notification. Please do not reply to this email.</p>
            <p style='margin-top: 10px;'>
                <a href='<?php echo SYSTEM_URL; ?>' style='color: #667eea; text-decoration: none;'>Access Dashboard</a>
            </p>
        </div>
    </div>
</body>
</html>