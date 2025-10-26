<?php
/**
 * Welcome Email Template
 * Place this file in: /auth/email_templates/welcome.php
 * 
 * Available variables:
 * - $name: Full name
 * - $username: Generated username
 * - $email: User email
 * - $temporary_password: Auto-generated password
 * - $reset_link: Password reset link
 * - $role: User role
 * - $department: User department
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to <?php echo SYSTEM_NAME; ?></title>
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
                                <span style="font-size: 40px;">🎉</span>
                            </div>
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: 600;">Welcome to Our Team!</h1>
                            <p style="margin: 10px 0 0; color: rgba(255,255,255,0.9); font-size: 16px;"><?php echo SYSTEM_NAME; ?></p>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <h2 style="margin: 0 0 20px; color: #2c3e50; font-size: 22px;">Hello <?php echo htmlspecialchars($name ?? 'User'); ?>,</h2>
                            
                            <p style="margin: 0 0 20px; color: #495057; font-size: 16px; line-height: 1.6;">
                                Your account has been successfully created in our E-Asset Management System. We're excited to have you on board!
                            </p>
                            
                            <!-- Account Details Box -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f8f9fa; border-radius: 8px; margin: 30px 0;">
                                <tr>
                                    <td style="padding: 25px;">
                                        <h3 style="margin: 0 0 15px; color: #667eea; font-size: 18px; font-weight: 600;">📋 Your Account Details</h3>
                                        
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                            <tr>
                                                <td style="padding: 8px 0; color: #6c757d; font-size: 14px; font-weight: 600;">Username:</td>
                                                <td style="padding: 8px 0; color: #2c3e50; font-size: 14px; font-weight: 700; text-align: right;"><?php echo htmlspecialchars($username ?? 'N/A'); ?></td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 8px 0; color: #6c757d; font-size: 14px; font-weight: 600;">Email:</td>
                                                <td style="padding: 8px 0; color: #2c3e50; font-size: 14px; text-align: right;"><?php echo htmlspecialchars($email ?? 'N/A'); ?></td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 8px 0; color: #6c757d; font-size: 14px; font-weight: 600;">Department:</td>
                                                <td style="padding: 8px 0; color: #2c3e50; font-size: 14px; text-align: right;"><?php echo htmlspecialchars($department ?? 'N/A'); ?></td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 8px 0; color: #6c757d; font-size: 14px; font-weight: 600;">Role:</td>
                                                <td style="padding: 8px 0; color: #2c3e50; font-size: 14px; text-align: right;"><?php echo htmlspecialchars(ucfirst($role ?? 'employee')); ?></td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Temporary Password (Optional - only if you want to show it) -->
                            <?php if (isset($temporary_password)): ?>
                            <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 20px; margin: 20px 0; border-radius: 6px;">
                                <p style="margin: 0 0 10px; color: #856404; font-size: 14px; font-weight: 600;">⚠️ Temporary Password</p>
                                <p style="margin: 0; color: #856404; font-size: 14px; line-height: 1.6;">
                                    Your temporary password is: <strong style="font-family: 'Courier New', monospace; background-color: rgba(0,0,0,0.05); padding: 4px 8px; border-radius: 4px;"><?php echo htmlspecialchars($temporary_password); ?></strong>
                                </p>
                                <p style="margin: 10px 0 0; color: #856404; font-size: 13px;">
                                    For security reasons, you must change this password on your first login.
                                </p>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Important Notice -->
                            <div style="background-color: #e7f3ff; border-left: 4px solid #2196F3; padding: 20px; margin: 20px 0; border-radius: 6px;">
                                <p style="margin: 0 0 10px; color: #1976D2; font-size: 14px; font-weight: 600;">🔐 Important Security Notice</p>
                                <p style="margin: 0; color: #1565C0; font-size: 14px; line-height: 1.6;">
                                    You must set a new password before accessing the system. Click the button below to create your secure password.
                                </p>
                            </div>
                            
                            <!-- CTA Button -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin: 30px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="<?php echo htmlspecialchars($reset_link ?? '#'); ?>" 
                                           style="display: inline-block; padding: 16px 40px; background: linear-gradient(135deg, #667eea, #764ba2); color: #ffffff; text-decoration: none; border-radius: 8px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);">
                                            Set My Password
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="margin: 20px 0 0; color: #6c757d; font-size: 13px; line-height: 1.6;">
                                <strong>Note:</strong> This link will expire in 24 hours. If you don't set your password within this time, please contact your administrator.
                            </p>
                            
                            <!-- Getting Started -->
                            <div style="margin-top: 40px; padding-top: 30px; border-top: 2px solid #e9ecef;">
                                <h3 style="margin: 0 0 20px; color: #2c3e50; font-size: 18px;">🚀 Getting Started</h3>
                                
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                    <tr>
                                        <td style="padding: 12px 0;">
                                            <span style="display: inline-block; width: 30px; height: 30px; background-color: #667eea; color: white; border-radius: 50%; text-align: center; line-height: 30px; margin-right: 15px; font-weight: 600;">1</span>
                                            <span style="color: #495057; font-size: 14px;">Set your new password using the link above</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 12px 0;">
                                            <span style="display: inline-block; width: 30px; height: 30px; background-color: #667eea; color: white; border-radius: 50%; text-align: center; line-height: 30px; margin-right: 15px; font-weight: 600;">2</span>
                                            <span style="color: #495057; font-size: 14px;">Log in with your username and new password</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 12px 0;">
                                            <span style="display: inline-block; width: 30px; height: 30px; background-color: #667eea; color: white; border-radius: 50%; text-align: center; line-height: 30px; margin-right: 15px; font-weight: 600;">3</span>
                                            <span style="color: #495057; font-size: 14px;">Explore the dashboard and start managing assets</span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            
                            <!-- Help Section -->
                            <div style="background-color: #f8f9fa; border-radius: 8px; padding: 20px; margin-top: 30px;">
                                <p style="margin: 0 0 10px; color: #2c3e50; font-size: 14px; font-weight: 600;">Need Help?</p>
                                <p style="margin: 0; color: #6c757d; font-size: 14px; line-height: 1.6;">
                                    If you have any questions or need assistance, please don't hesitate to contact our support team at 
                                    <a href="mailto:<?php echo SUPPORT_EMAIL; ?>" style="color: #667eea; text-decoration: none;"><?php echo SUPPORT_EMAIL; ?></a>
                                </p>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8f9fa; padding: 30px; text-align: center; border-top: 1px solid #dee2e6;">
                            <p style="margin: 0 0 10px; color: #6c757d; font-size: 14px;">
                                <strong><?php echo SYSTEM_NAME; ?></strong>
                            </p>
                            <p style="margin: 0 0 15px; color: #6c757d; font-size: 12px;">
                                Managing assets efficiently, one system at a time
                            </p>
                            <p style="margin: 0; color: #adb5bd; font-size: 11px;">
                                &copy; <?php echo date('Y'); ?> <?php echo SYSTEM_NAME; ?>. All rights reserved.
                            </p>
                            <p style="margin: 10px 0 0; color: #adb5bd; font-size: 11px;">
                                This is an automated message. Please do not reply to this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>