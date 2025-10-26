<?php
/**
 * Password Reset Email Template
 * Place this file in: /auth/email_templates/password_reset.php
 * 
 * Available variables:
 * - $name: User's full name
 * - $reset_link: Password reset link
 * - $expiry_time: How long the link is valid (e.g., "1 hour")
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Request</title>
</head>
<body style="margin: 0; padding: 0; font-family: 'Segoe UI', Arial, sans-serif; background-color: #f4f4f4;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f4f4f4; padding: 40px 20px;">
        <tr>
            <td align="center">
                <!-- Main Container -->
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 40px 30px; text-align: center;">
                            <div style="width: 80px; height: 80px; margin: 0 auto 20px; background-color: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <span style="font-size: 40px;">🔐</span>
                            </div>
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: 600;">Password Reset Request</h1>
                            <p style="margin: 10px 0 0; color: rgba(255,255,255,0.9); font-size: 16px;"><?php echo SYSTEM_NAME; ?></p>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <h2 style="margin: 0 0 20px; color: #2c3e50; font-size: 22px;">Hello <?php echo htmlspecialchars($name ?? 'User'); ?>,</h2>
                            
                            <p style="margin: 0 0 20px; color: #495057; font-size: 16px; line-height: 1.6;">
                                We received a request to reset your password for your E-Asset Management System account. If you didn't make this request, you can safely ignore this email.
                            </p>
                            
                            <!-- Security Notice -->
                            <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 20px; margin: 25px 0; border-radius: 6px;">
                                <p style="margin: 0 0 10px; color: #856404; font-size: 14px; font-weight: 600;">⚠️ Security Notice</p>
                                <p style="margin: 0; color: #856404; font-size: 14px; line-height: 1.6;">
                                    This password reset link will expire in <strong><?php echo htmlspecialchars($expiry_time ?? '1 hour'); ?></strong>. For your security, never share this link with anyone.
                                </p>
                            </div>
                            
                            <p style="margin: 20px 0; color: #495057; font-size: 16px; line-height: 1.6;">
                                To reset your password, click the button below:
                            </p>
                            
                            <!-- CTA Button -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 30px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="<?php echo htmlspecialchars($reset_link ?? '#'); ?>" 
                                           style="display: inline-block; padding: 16px 40px; background: linear-gradient(135deg, #667eea, #764ba2); color: #ffffff; text-decoration: none; border-radius: 8px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);">
                                            Reset My Password
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Alternative Link -->
                            <div style="background-color: #f8f9fa; border-radius: 8px; padding: 20px; margin: 30px 0;">
                                <p style="margin: 0 0 10px; color: #2c3e50; font-size: 14px; font-weight: 600;">Button not working?</p>
                                <p style="margin: 0; color: #6c757d; font-size: 13px; line-height: 1.6;">
                                    Copy and paste this link into your browser:
                                </p>
                                <p style="margin: 10px 0 0; word-break: break-all;">
                                    <a href="<?php echo htmlspecialchars($reset_link ?? '#'); ?>" style="color: #667eea; text-decoration: none; font-size: 12px;">
                                        <?php echo htmlspecialchars($reset_link ?? '#'); ?>
                                    </a>
                                </p>
                            </div>
                            
                            <!-- What if not requested -->
                            <div style="margin-top: 30px; padding-top: 25px; border-top: 2px solid #e9ecef;">
                                <h3 style="margin: 0 0 15px; color: #2c3e50; font-size: 18px;">🛡️ Didn't Request This?</h3>
                                <p style="margin: 0; color: #495057; font-size: 14px; line-height: 1.6;">
                                    If you didn't request a password reset, your account may be at risk. Please take these steps immediately:
                                </p>
                                <ul style="margin: 15px 0; padding-left: 20px; color: #495057; font-size: 14px; line-height: 1.8;">
                                    <li>Do not click the reset link</li>
                                    <li>Contact your IT administrator immediately</li>
                                    <li>Change your password as a precaution</li>
                                    <li>Review your recent account activity</li>
                                </ul>
                            </div>
                            
                            <!-- Help Section -->
                            <div style="background-color: #e7f3ff; border-radius: 8px; padding: 20px; margin-top: 25px;">
                                <p style="margin: 0 0 10px; color: #1976D2; font-size: 14px; font-weight: 600;">Need Help?</p>
                                <p style="margin: 0; color: #1565C0; font-size: 14px; line-height: 1.6;">
                                    If you're having trouble resetting your password or have security concerns, contact our support team at 
                                    <a href="mailto:<?php echo SUPPORT_EMAIL; ?>" style="color: #667eea; text-decoration: none; font-weight: 600;"><?php echo SUPPORT_EMAIL; ?></a>
                                </p>
                            </div>
                            
                            <!-- Additional Info -->
                            <p style="margin: 25px 0 0; color: #6c757d; font-size: 13px; line-height: 1.6;">
                                This password reset was requested from IP address: <strong><?php echo htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'Unknown'); ?></strong>
                                at <?php echo date('F j, Y, g:i a'); ?>.
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8f9fa; padding: 30px; text-align: center; border-top: 1px solid #dee2e6;">
                            <p style="margin: 0 0 10px; color: #6c757d; font-size: 14px;">
                                <strong><?php echo SYSTEM_NAME; ?></strong>
                            </p>
                            <p style="margin: 0 0 15px; color: #6c757d; font-size: 12px;">
                                Secure asset management for your organization
                            </p>
                            <p style="margin: 0; color: #adb5bd; font-size: 11px;">
                                &copy; <?php echo date('Y'); ?> <?php echo SYSTEM_NAME; ?>. All rights reserved.
                            </p>
                            <p style="margin: 10px 0 0; color: #adb5bd; font-size: 11px;">
                                This is an automated security message. Please do not reply to this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>