<?php
/**
 * Email Helper Class for E-Asset Management System
 * Place this file in: /auth/helpers/EmailHelper.php
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../config/email_config.php';
require_once __DIR__ . '/../../vendor/autoload.php'; // Adjust path as needed

class EmailHelper {
    private $mail;
    
    public function __construct() {
        $this->mail = new PHPMailer(true);
        $this->configureMailer();
    }
    
    private function configureMailer() {
        try {
            // Server settings
            $this->mail->isSMTP();
            $this->mail->Host = SMTP_HOST;
            $this->mail->SMTPAuth = true;
            $this->mail->Username = SMTP_USERNAME;
            $this->mail->Password = SMTP_PASSWORD;
            $this->mail->SMTPSecure = SMTP_SECURE;
            $this->mail->Port = SMTP_PORT;
            $this->mail->CharSet = MAIL_CHARSET;
            
            // Debug settings
            $this->mail->SMTPDebug = MAIL_DEBUG;
            
            // Default sender
            $this->mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
            
        } catch (Exception $e) {
            error_log("Email configuration error: " . $e->getMessage());
        }
    }
    
    public function sendNewUserWelcomeEmail($user_data) {
        $subject = "Welcome to " . SYSTEM_NAME . " - Set Your Password";
        
        // Build reset link
        $reset_link = SYSTEM_URL . "/public/reset_password.php?token=" . $user_data['reset_token'];
        
        // Prepare variables for template
        $name = $user_data['first_name'] . ' ' . $user_data['last_name'];
        $username = $user_data['username'];
        $email = $user_data['email'];
        $role = $user_data['role'];
        $department = $user_data['department'];
        $temporary_password = $user_data['temporary_password']; // Optional: remove if you don't want to show it
        
        // Load template
        $body = $this->getTemplate('welcome', [
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'role' => $role,
            'department' => $department,
            'temporary_password' => $temporary_password,
            'reset_link' => $reset_link
        ]);
        
        return $this->sendEmail($user_data['email'], $subject, $body);
    }

    
    /**
     * Send a generic email
     */
    public function sendEmail($to, $subject, $body, $altBody = '', $attachments = []) {
        try {
            // Recipients
            $this->mail->addAddress($to);
            
            // Content
            $this->mail->isHTML(true);
            $this->mail->Subject = $subject;
            $this->mail->Body = $body;
            $this->mail->AltBody = $altBody ?: strip_tags($body);
            
            // Attachments
            foreach ($attachments as $file) {
                if (file_exists($file)) {
                    $this->mail->addAttachment($file);
                }
            }
            
            $result = $this->mail->send();
            $this->mail->clearAddresses();
            $this->mail->clearAttachments();
            
            return $result;
        } catch (Exception $e) {
            error_log("Email sending failed: " . $this->mail->ErrorInfo);
            return false;
        }
    }
    
    /**
     * Send welcome email to new user
     */
    public function sendWelcomeEmail($user_data) {
        $subject = "Welcome to " . SYSTEM_NAME;
        $body = $this->getTemplate('welcome', [
            'name' => $user_data['first_name'] . ' ' . $user_data['last_name'],
            'username' => $user_data['username'],
            'email' => $user_data['email'],
            'role' => ucfirst($user_data['role']),
            'department' => $user_data['department'],
            'login_url' => SYSTEM_URL . '/auth/login.php'
        ]);
        
        return $this->sendEmail($user_data['email'], $subject, $body);
    }
    
    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail($email, $reset_token, $user_name) {
        $subject = "Password Reset Request - " . SYSTEM_NAME;
        $reset_link = SYSTEM_URL . "/public/reset_password.php?token=" . $reset_token;
        
        $body = $this->getTemplate('password_reset', [
            'name' => $user_name,
            'reset_link' => $reset_link,
            'expiry_time' => '1 hour'
        ]);
        
        return $this->sendEmail($email, $subject, $body);
    }
    
    /**
     * Send asset assignment notification (Updated signature to accept single array parameter)
     */
    public function sendAssetAssignmentEmail($assignment_data) {
        // Extract data
        $user_name = $assignment_data['user_name'];
        $user_email = $assignment_data['user_email'];
        $asset_name = $assignment_data['asset_name'];
        $asset_code = $assignment_data['asset_code'];
        $asset_category = $assignment_data['asset_category'];
        $assigned_by = $assignment_data['assigned_by'];
        $assigned_date = date('F j, Y');
        $brand_model = $assignment_data['brand_model'] ?? '-';
        $serial_number = $assignment_data['serial_number'] ?? '-';
        $location = $assignment_data['location'] ?? '-';
        
        $subject = "Asset Assigned to You - " . $asset_code;
        
        $body = "
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
                .message {
                    background: #f8f9fa;
                    border-left: 4px solid #667eea;
                    padding: 20px;
                    margin: 20px 0;
                    border-radius: 6px;
                }
                .asset-details {
                    background: white;
                    border: 2px solid #e9ecef;
                    border-radius: 8px;
                    padding: 20px;
                    margin: 20px 0;
                }
                .asset-details h3 {
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
                .info-box {
                    background: #e7f3ff;
                    border-left: 4px solid #2196F3;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 6px;
                }
                .info-box h4 {
                    color: #1976D2;
                    margin-top: 0;
                    margin-bottom: 10px;
                    font-size: 16px;
                }
                .info-box ul {
                    margin: 10px 0;
                    padding-left: 20px;
                }
                .info-box li {
                    color: #495057;
                    margin: 5px 0;
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
                    <h1>📦 Asset Assignment</h1>
                    <p>" . SYSTEM_NAME . "</p>
                </div>
                
                <div class='content'>
                    <p class='greeting'>Hello <strong>$user_name</strong>,</p>
                    
                    <div class='message'>
                        <p style='margin: 0; font-size: 16px;'>
                            An asset has been assigned to you by <strong>$assigned_by</strong> on <strong>$assigned_date</strong>.
                        </p>
                    </div>
                    
                    <div class='asset-details'>
                        <h3>📋 Asset Details</h3>
                        <div class='detail-row'>
                            <span class='detail-label'>Asset Name:</span>
                            <span class='detail-value'><strong>$asset_name</strong></span>
                        </div>
                        <div class='detail-row'>
                            <span class='detail-label'>Asset Code:</span>
                            <span class='detail-value'><strong>$asset_code</strong></span>
                        </div>
                        <div class='detail-row'>
                            <span class='detail-label'>Category:</span>
                            <span class='detail-value'>$asset_category</span>
                        </div>
                        <div class='detail-row'>
                            <span class='detail-label'>Brand/Model:</span>
                            <span class='detail-value'>$brand_model</span>
                        </div>
                        <div class='detail-row'>
                            <span class='detail-label'>Serial Number:</span>
                            <span class='detail-value'>$serial_number</span>
                        </div>
                        <div class='detail-row'>
                            <span class='detail-label'>Location:</span>
                            <span class='detail-value'>$location</span>
                        </div>
                    </div>
                    
                    <div class='info-box'>
                        <h4>📌 Important Reminders:</h4>
                        <ul>
                            <li>You are now responsible for this asset</li>
                            <li>Please inspect the asset and report any issues immediately</li>
                            <li>Keep the asset in good condition and follow company policies</li>
                            <li>Report any damage, loss, or maintenance needs promptly</li>
                            <li>Return the asset when requested or when leaving the organization</li>
                        </ul>
                    </div>
                    
                    <center>
                        <a href='" . SYSTEM_URL . "/auth/login.php?redirect=" . urlencode("/users/userAsset.php") . "' class='cta-button'>
                            Login to View Your Assets
                        </a>
                    </center>
                    
                    <p style='color: #6c757d; font-size: 14px; margin-top: 20px;'>
                        If you have any questions about this assignment, please contact your supervisor or the IT department.
                    </p>
                </div>
                
                <div class='footer'>
                    <p><strong>" . SYSTEM_NAME . "</strong></p>
                    <p>This is an automated notification. Please do not reply to this email.</p>
                    <p style='margin-top: 10px;'>
                        <a href='" . SYSTEM_URL . "' style='color: #667eea; text-decoration: none;'>Access Dashboard</a>
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $altBody = "Hello $user_name,\n\n" .
                  "An asset has been assigned to you by $assigned_by on $assigned_date.\n\n" .
                  "Asset Details:\n" .
                  "- Asset Name: $asset_name\n" .
                  "- Asset Code: $asset_code\n" .
                  "- Category: $asset_category\n" .
                  "- Brand/Model: $brand_model\n" .
                  "- Serial Number: $serial_number\n" .
                  "- Location: $location\n\n" .
                  "Important Reminders:\n" .
                  "- You are now responsible for this asset\n" .
                  "- Please inspect the asset and report any issues immediately\n" .
                  "- Keep the asset in good condition and follow company policies\n" .
                  "- Report any damage, loss, or maintenance needs promptly\n" .
                  "- Return the asset when requested or when leaving the organization\n\n" .
                  "Login to view asset details: " . SYSTEM_URL . "/auth/login.php\n\n" .
                  SYSTEM_NAME . "\n" .
                  "This is an automated notification. Please do not reply to this email.";
        
        return $this->sendEmail($user_email, $subject, $body, $altBody);
    }
    
    /**
     * Send asset unassignment notification (NEW METHOD)
     */
    public function sendAssetUnassignmentEmail($unassignment_data) {
        $user_name = $unassignment_data['user_name'];
        $user_email = $unassignment_data['user_email'];
        $asset_name = $unassignment_data['asset_name'];
        $asset_code = $unassignment_data['asset_code'];
        $unassigned_by = $unassignment_data['unassigned_by'];
        $unassigned_date = date('F j, Y');
        
        $subject = "Asset Unassigned - " . $asset_code;
        
        $body = "
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
                    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
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
                .message {
                    background: #fff3cd;
                    border-left: 4px solid #f59e0b;
                    padding: 20px;
                    margin: 20px 0;
                    border-radius: 6px;
                }
                .asset-info {
                    background: #f8f9fa;
                    padding: 15px;
                    border-radius: 6px;
                    margin: 20px 0;
                }
                .footer {
                    background: #f8f9fa;
                    padding: 20px 30px;
                    text-align: center;
                    color: #6c757d;
                    font-size: 14px;
                    border-top: 1px solid #e9ecef;
                }
            </style>
        </head>
        <body>
            <div class='email-container'>
                <div class='header'>
                    <h1>📤 Asset Unassigned</h1>
                    <p>" . SYSTEM_NAME . "</p>
                </div>
                
                <div class='content'>
                    <p class='greeting'>Hello <strong>$user_name</strong>,</p>
                    
                    <div class='message'>
                        <p style='margin: 0; font-size: 16px;'>
                            The following asset has been unassigned from you by <strong>$unassigned_by</strong> on <strong>$unassigned_date</strong>.
                        </p>
                    </div>
                    
                    <div class='asset-info'>
                        <p style='margin: 5px 0;'><strong>Asset Name:</strong> $asset_name</p>
                        <p style='margin: 5px 0;'><strong>Asset Code:</strong> $asset_code</p>
                    </div>
                    
                    <p style='color: #6c757d; font-size: 14px;'>
                        You are no longer responsible for this asset. If you have any questions, please contact your supervisor.
                    </p>
                </div>
                
                <div class='footer'>
                    <p><strong>" . SYSTEM_NAME . "</strong></p>
                    <p>This is an automated notification. Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $altBody = "Hello $user_name,\n\n" .
                  "The following asset has been unassigned from you by $unassigned_by on $unassigned_date.\n\n" .
                  "Asset Name: $asset_name\n" .
                  "Asset Code: $asset_code\n\n" .
                  "You are no longer responsible for this asset.\n\n" .
                  SYSTEM_NAME;
        
        return $this->sendEmail($user_email, $subject, $body, $altBody);
    }
    
    /**
     * Send ticket created notification
     */
    public function sendTicketCreatedEmail($user_email, $user_name, $ticket_data) {
        $subject = "Ticket Created: " . $ticket_data['ticket_number'];
        $body = $this->getTemplate('ticket_created', [
            'user_name' => $user_name,
            'ticket_number' => $ticket_data['ticket_number'],
            'subject' => $ticket_data['subject'],
            'priority' => ucfirst($ticket_data['priority']),
            'status' => ucfirst($ticket_data['status']),
            'created_at' => date('Y-m-d H:i:s'),
            'view_url' => SYSTEM_URL . '/public/ticketDetails.php?id=' . $ticket_data['id']
        ]);
        
        return $this->sendEmail($user_email, $subject, $body);
    }
    
    /**
     * Send ticket status update notification
     */
    public function sendTicketStatusUpdateEmail($user_email, $user_name, $ticket_data, $old_status, $new_status) {
        $subject = "Ticket Updated: " . $ticket_data['ticket_number'];
        $body = $this->getTemplate('ticket_status_update', [
            'user_name' => $user_name,
            'ticket_number' => $ticket_data['ticket_number'],
            'subject' => $ticket_data['subject'],
            'old_status' => ucfirst($old_status),
            'new_status' => ucfirst($new_status),
            'updated_at' => date('Y-m-d H:i:s'),
            'view_url' => SYSTEM_URL . '/public/ticketDetails.php?id=' . $ticket_data['id']
        ]);
        
        return $this->sendEmail($user_email, $subject, $body);
    }
    
    /**
     * Send ticket reply notification
     */
    public function sendTicketReplyEmail($user_email, $user_name, $ticket_data, $reply_message) {
        $subject = "New Reply on Ticket: " . $ticket_data['ticket_number'];
        $body = $this->getTemplate('ticket_reply', [
            'user_name' => $user_name,
            'ticket_number' => $ticket_data['ticket_number'],
            'subject' => $ticket_data['subject'],
            'reply_preview' => substr(strip_tags($reply_message), 0, 200),
            'view_url' => SYSTEM_URL . '/public/ticketDetails.php?id=' . $ticket_data['id']
        ]);
        
        return $this->sendEmail($user_email, $subject, $body);
    }
    
    /**
     * Load email template
     */
    private function getTemplate($template_name, $variables = []) {
        $template_file = EMAIL_TEMPLATES_DIR . $template_name . '.php';
        
        if (file_exists($template_file)) {
            ob_start();
            extract($variables);
            include $template_file;
            return ob_get_clean();
        }
        
        // Fallback to basic template
        return $this->getBasicTemplate($template_name, $variables);
    }
    
    /**
     * Basic template fallback
     */
    private function getBasicTemplate($type, $data) {
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
                .button { display: inline-block; padding: 12px 24px; background: #667eea; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
                .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6; color: #6c757d; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>' . SYSTEM_NAME . '</h1>
                </div>
                <div class="content">';
        
        // Add content based on type
        if (isset($data['name'])) {
            $html .= '<p>Hello ' . htmlspecialchars($data['name']) . ',</p>';
        }
        
        foreach ($data as $key => $value) {
            if ($key !== 'name' && !is_array($value)) {
                $html .= '<p><strong>' . ucwords(str_replace('_', ' ', $key)) . ':</strong> ' . htmlspecialchars($value) . '</p>';
            }
        }
        
        $html .= '
                </div>
                <div class="footer">
                    <p>&copy; ' . date('Y') . ' ' . SYSTEM_NAME . '. All rights reserved.</p>
                    <p>If you have any questions, contact us at ' . SUPPORT_EMAIL . '</p>
                </div>
            </div>
        </body>
        </html>';
        
        return $html;
    }
    
    /**
     * Test email configuration
     */
    public function testConnection($test_email) {
        $subject = "SMTP Test Email - " . SYSTEM_NAME;
        $body = "
            <h2>SMTP Configuration Test</h2>
            <p>This is a test email to verify your SMTP configuration is working correctly.</p>
            <p><strong>Timestamp:</strong> " . date('Y-m-d H:i:s') . "</p>
            <p>If you receive this email, your SMTP settings are configured properly!</p>
        ";
        
        return $this->sendEmail($test_email, $subject, $body);
    }
}

?>