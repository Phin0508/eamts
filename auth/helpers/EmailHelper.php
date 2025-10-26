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
        $reset_link = SYSTEM_URL . "../public/reset_password.php?token=" . $reset_token;
        
        $body = $this->getTemplate('password_reset', [
            'name' => $user_name,
            'reset_link' => $reset_link,
            'expiry_time' => '1 hour'
        ]);
        
        return $this->sendEmail($email, $subject, $body);
    }
    
    /**
     * Send asset assignment notification
     */
    public function sendAssetAssignmentEmail($user_email, $user_name, $asset_data) {
        $subject = "New Asset Assigned - " . $asset_data['asset_name'];
        $body = $this->getTemplate('asset_assignment', [
            'user_name' => $user_name,
            'asset_name' => $asset_data['asset_name'],
            'asset_code' => $asset_data['asset_code'],
            'category' => $asset_data['category'],
            'brand' => $asset_data['brand'] ?? 'N/A',
            'model' => $asset_data['model'] ?? 'N/A',
            'assigned_date' => date('Y-m-d H:i:s'),
            'view_url' => SYSTEM_URL . '/public/asset.php'
        ]);
        
        return $this->sendEmail($user_email, $subject, $body);
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