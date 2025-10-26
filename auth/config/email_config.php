<?php
/**
 * Email Configuration for E-Asset Management System
 * Place this file in: /auth/config/email_config.php
 */

// SMTP Configuration
define('SMTP_HOST', 'smtp.gmail.com');  // For Gmail
// For other providers:
// - Outlook/Office365: 'smtp.office365.com'
// - Yahoo: 'smtp.mail.yahoo.com'
// - SendGrid: 'smtp.sendgrid.net'
// - Mailgun: 'smtp.mailgun.org'

define('SMTP_PORT', 587);  // 587 for TLS, 465 for SSL
define('SMTP_SECURE', 'tls');  // 'tls' or 'ssl'
define('SMTP_USERNAME', 'zjenphin@gmail.com');  // Your email address
define('SMTP_PASSWORD', 'pmxk zeqw visu jrai');  // App password (not regular password!)

// Sender Information
define('MAIL_FROM_EMAIL', 'zjenphin@gmail.com');
define('MAIL_FROM_NAME', 'E-Asset Management System');

// Email Settings
define('MAIL_CHARSET', 'UTF-8');
define('MAIL_DEBUG', 0);  // 0 = off, 1 = client, 2 = server, 3 = connection

// System URL (for email links)
define('SYSTEM_URL', 'http://localhost/eamts');  // Change to your actual URL
define('SYSTEM_NAME', 'E-Asset Management');

// Admin Contact
define('ADMIN_EMAIL', 'admin@yourdomain.com');
define('SUPPORT_EMAIL', 'support@yourdomain.com');

// Email Templates Directory
define('EMAIL_TEMPLATES_DIR', __DIR__ . '/../email_templates/');

/**
 * Important Notes:
 * 
 * For Gmail:
 * 1. Enable 2-Step Verification in your Google Account
 * 2. Generate an App Password at: https://myaccount.google.com/apppasswords
 * 3. Use the App Password (16-character code) as SMTP_PASSWORD
 * 
 * For Other Providers:
 * - Check your email provider's SMTP documentation
 * - Some may require "Less secure app access" to be enabled
 * - Business email accounts may have different settings
 * 
 * Security:
 * - Never commit this file with real credentials to version control
 * - Add email_config.php to .gitignore
 * - Consider using environment variables in production
 */
?>