<?php
session_start();
require_once '../auth/config/database.php';
require_once '../auth/helpers/EmailHelper.php';

// ============= VERIFY EMAIL HELPER - ADD THIS RIGHT AFTER REQUIRES =============
if (!class_exists('EmailHelper')) {
    error_log("CRITICAL: EmailHelper class not found!");
    die("Email system not configured");
}

$emailHelper = new EmailHelper();
if (!method_exists($emailHelper, 'sendEmail')) {
    error_log("CRITICAL: EmailHelper::sendEmail() method missing!");
    die("Email method not available");
}
// ============= END EMAIL HELPER VERIFICATION =============

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// SECURITY: Only employees should use this page
if ($user_role !== 'employee') {
    header("Location: ../tickets/create_ticket.php");
    exit();
}

// Get user department
$user_query = $pdo->prepare("SELECT department, first_name, last_name, email FROM users WHERE user_id = ?");
$user_query->execute([$user_id]);
$user_data = $user_query->fetch(PDO::FETCH_ASSOC);
$user_department = $user_data['department'];
$user_name = $user_data['first_name'] . ' ' . $user_data['last_name'];
$user_email = $user_data['email'];

// Fetch ONLY user's assets
$assets_query = $pdo->prepare("
    SELECT id, asset_name, asset_code, category 
    FROM assets 
    WHERE assigned_to = ? AND status = 'in_use' 
    ORDER BY asset_name
");
$assets_query->execute([$user_id]);
$user_assets = $assets_query->fetchAll(PDO::FETCH_ASSOC);

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $ticket_type = $_POST['ticket_type'];
        $subject = trim($_POST['subject']);
        $description = trim($_POST['description']);
        $priority = $_POST['priority'];
        $asset_id = !empty($_POST['asset_id']) ? $_POST['asset_id'] : null;

        // SECURITY: Verify asset belongs to user
        if ($asset_id) {
            $asset_check = $pdo->prepare("SELECT id FROM assets WHERE id = ? AND assigned_to = ?");
            $asset_check->execute([$asset_id, $user_id]);
            if (!$asset_check->fetch()) {
                throw new Exception("Invalid asset selected. You can only create tickets for your own assets.");
            }
        }

        // Validation
        $errors = [];
        if (empty($subject)) $errors[] = "Subject is required";
        if (empty($description)) $errors[] = "Description is required";
        if (strlen($description) < 10) $errors[] = "Description must be at least 10 characters";
        if (strlen($subject) > 255) $errors[] = "Subject is too long (max 255 characters)";
        if (strlen($description) > 2000) $errors[] = "Description is too long (max 2000 characters)";

        if (empty($errors)) {
            // Generate ticket number with proper locking
            $year = date('Y');
            $month = date('m');

            $pdo->beginTransaction();

            try {
                // Get the highest ticket number for this month
                $max_ticket_query = $pdo->prepare("
                    SELECT ticket_number 
                    FROM tickets 
                    WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?
                    ORDER BY ticket_id DESC
                    LIMIT 1
                    FOR UPDATE
                ");
                $max_ticket_query->execute([$year, $month]);
                $last_ticket = $max_ticket_query->fetch(PDO::FETCH_ASSOC);

                // Extract counter
                if ($last_ticket && preg_match('/TKT-\d{6}-(\d{5})/', $last_ticket['ticket_number'], $matches)) {
                    $count = intval($matches[1]) + 1;
                } else {
                    $count = 1;
                }

                // Generate unique ticket number
                $ticket_number = sprintf("TKT-%s%s-%05d", $year, $month, $count);

                // Verify uniqueness
                $check_query = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE ticket_number = ?");
                $check_query->execute([$ticket_number]);
                $exists = $check_query->fetch(PDO::FETCH_ASSOC)['count'];

                while ($exists > 0) {
                    $count++;
                    $ticket_number = sprintf("TKT-%s%s-%05d", $year, $month, $count);
                    $check_query->execute([$ticket_number]);
                    $exists = $check_query->fetch(PDO::FETCH_ASSOC)['count'];
                }

                // Insert ticket
                $insert_query = "
                    INSERT INTO tickets (
                        ticket_number, ticket_type, subject, description, 
                        priority, status, approval_status, requester_id, 
                        requester_department, asset_id, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, 'open', 'pending', ?, ?, ?, NOW(), NOW())
                ";

                $stmt = $pdo->prepare($insert_query);
                $stmt->execute([
                    $ticket_number,
                    $ticket_type,
                    $subject,
                    $description,
                    $priority,
                    $user_id,
                    $user_department,
                    $asset_id
                ]);

                $ticket_id = $pdo->lastInsertId();

                // Log history
                $history_query = "INSERT INTO ticket_history (ticket_id, action_type, new_value, performed_by, created_at) VALUES (?, 'created', ?, ?, NOW())";
                $history_stmt = $pdo->prepare($history_query);
                $history_stmt->execute([$ticket_id, "Ticket created: $ticket_number", $user_id]);

                // Commit transaction
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }

            // Handle file uploads
            if (!empty($_FILES['attachments']['name'][0])) {
                $upload_dir = '../uploads/tickets/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                $uploaded_files = 0;
                foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['attachments']['error'][$key] === 0) {
                        $file_name = $_FILES['attachments']['name'][$key];
                        $file_size = $_FILES['attachments']['size'][$key];
                        $file_tmp = $_FILES['attachments']['tmp_name'][$key];
                        $file_type = $_FILES['attachments']['type'][$key];

                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];

                        if (in_array($file_ext, $allowed_extensions) && $file_size <= 5242880) {
                            $new_file_name = $ticket_id . '_' . time() . '_' . uniqid() . '.' . $file_ext;
                            $file_path = $upload_dir . $new_file_name;

                            if (move_uploaded_file($file_tmp, $file_path)) {
                                $attach_query = "INSERT INTO ticket_attachments (ticket_id, uploaded_by, file_name, file_path, file_type, file_size, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())";
                                $attach_stmt = $pdo->prepare($attach_query);
                                $attach_stmt->execute([
                                    $ticket_id,
                                    $user_id,
                                    $file_name,
                                    $new_file_name,
                                    $file_type,
                                    $file_size
                                ]);
                                $uploaded_files++;
                            }
                        }
                    }
                }
            }

            // ==================== EMAIL NOTIFICATION - SEND TO ALL MANAGERS ====================
try {
    error_log("========================================");
    error_log("STARTING EMAIL NOTIFICATION");
    error_log("Ticket: $ticket_number | User: $user_name | Dept: '$user_department'");
    
    // Get system URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $base_path = str_replace('/users/userCreateTicket.php', '', $_SERVER['SCRIPT_NAME']);
    $SYSTEM_URL = $protocol . "://" . $host . $base_path;
    
    // ============= 1. EMAIL TO USER =============
    error_log("Sending email to USER: $user_email");
    
    $user_subject = "Ticket Created Successfully - $ticket_number";
    $user_body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 0 auto; background: white; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
            .header h1 { margin: 0; font-size: 24px; }
            .content { padding: 30px; background: #f8f9fa; }
            .success-badge { background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 15px 0; border-radius: 6px; color: #155724; }
            .ticket-info { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #667eea; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            .info-row { padding: 10px 0; border-bottom: 1px solid #e9ecef; }
            .info-row:last-child { border-bottom: none; }
            .btn { display: inline-block; padding: 14px 28px; background: #667eea; color: white; text-decoration: none; border-radius: 6px; font-weight: 600; margin: 20px 0; }
            .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; border-top: 1px solid #e9ecef; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>✅ Ticket Created Successfully</h1>
            </div>
            
            <div class='content'>
                <p style='font-size: 16px;'>Hello <strong>" . htmlspecialchars($user_name) . "</strong>,</p>
                
                <div class='success-badge'>
                    <strong>✓ Success!</strong> Your support ticket has been created and submitted for approval.
                </div>
                
                <div class='ticket-info'>
                    <h3 style='margin-top: 0; color: #667eea; font-size: 18px;'>📋 Ticket Details</h3>
                    <div class='info-row'>
                        <strong>Ticket Number:</strong> " . htmlspecialchars($ticket_number) . "
                    </div>
                    <div class='info-row'>
                        <strong>Subject:</strong> " . htmlspecialchars($subject) . "
                    </div>
                    <div class='info-row'>
                        <strong>Priority:</strong> " . strtoupper($priority) . "
                    </div>
                    <div class='info-row'>
                        <strong>Type:</strong> " . htmlspecialchars(ucfirst(str_replace('_', ' ', $ticket_type))) . "
                    </div>
                    <div class='info-row'>
                        <strong>Status:</strong> Pending Approval
                    </div>
                    <div class='info-row'>
                        <strong>Created:</strong> " . date('F j, Y \a\t g:i A') . "
                    </div>
                </div>
                
                <div style='background: white; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                    <h4 style='margin-top: 0; color: #2c3e50;'>Description:</h4>
                    <p style='margin: 0; color: #4a5568;'>" . nl2br(htmlspecialchars($description)) . "</p>
                </div>
                
                <center>
                    <a href='" . $SYSTEM_URL . "/users/userTicket.php?id=" . $ticket_id . "' class='btn'>
                        🔍 View Ticket Details
                    </a>
                </center>
                
                <p style='color: #6c757d; font-size: 13px; margin-top: 25px; padding: 15px; background: #fff; border-radius: 6px;'>
                    <strong>📌 Next Steps:</strong> Your ticket has been sent to your department managers for approval. Once approved, it will be assigned to a technician.
                </p>
            </div>
            
            <div class='footer'>
                <p><strong>E-Asset Management System</strong></p>
                <p>This is an automated notification. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $user_sent = $emailHelper->sendEmail($user_email, $user_subject, $user_body);
    error_log("User email result: " . ($user_sent ? "SUCCESS ✓" : "FAILED ✗"));
    
    // ============= 2. EMAIL TO ALL MANAGERS (REMOVED LIMIT 1) =============
    error_log("Searching for ALL managers in department: '$user_department'");
    
    $managers_query = $pdo->prepare("
        SELECT user_id, email, first_name, last_name 
        FROM users 
        WHERE department = ? 
        AND role = 'manager' 
        AND is_active = 1 
        AND is_deleted = 0
        ORDER BY user_id
    ");
    $managers_query->execute([$user_department]);
    $managers = $managers_query->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Found " . count($managers) . " manager(s) for department '$user_department'");
    
    if (!empty($managers)) {
        $manager_emails_sent = 0;
        $manager_emails_failed = 0;
        
        foreach ($managers as $manager) {
            $manager_name = $manager['first_name'] . ' ' . $manager['last_name'];
            $manager_email = $manager['email'];
            
            error_log("Processing manager: $manager_name ($manager_email)");
            
            $subject_manager = "⏰ New Ticket Requires Your Approval - $ticket_number";
            $body_manager = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <style>
                    body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                    .container { max-width: 600px; margin: 0 auto; background: white; }
                    .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
                    .header h1 { margin: 0; font-size: 24px; }
                    .content { padding: 30px; background: #f8f9fa; }
                    .alert { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 15px 0; border-radius: 6px; color: #856404; }
                    .ticket-info { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #667eea; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
                    .info-row { padding: 10px 0; border-bottom: 1px solid #e9ecef; }
                    .info-row:last-child { border-bottom: none; }
                    .btn { display: inline-block; padding: 14px 28px; background: #667eea; color: white; text-decoration: none; border-radius: 6px; font-weight: 600; margin: 20px 0; }
                    .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; border-top: 1px solid #e9ecef; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>⏰ New Ticket Awaiting Your Approval</h1>
                    </div>
                    
                    <div class='content'>
                        <p style='font-size: 16px;'>Hello <strong>" . htmlspecialchars($manager_name) . "</strong>,</p>
                        
                        <p>A new support ticket has been submitted by <strong>" . htmlspecialchars($user_name) . "</strong> from your <strong>" . htmlspecialchars($user_department) . "</strong> department.</p>
                        
                        <div class='alert'>
                            <strong>⚡ Action Required:</strong> Please review and approve or reject this ticket.
                        </div>
                        
                        <div class='ticket-info'>
                            <h3 style='margin-top: 0; color: #667eea; font-size: 18px;'>📋 Ticket Details</h3>
                            <div class='info-row'>
                                <strong>Ticket Number:</strong> " . htmlspecialchars($ticket_number) . "
                            </div>
                            <div class='info-row'>
                                <strong>Subject:</strong> " . htmlspecialchars($subject) . "
                            </div>
                            <div class='info-row'>
                                <strong>Priority:</strong> " . strtoupper($priority) . "
                            </div>
                            <div class='info-row'>
                                <strong>Type:</strong> " . htmlspecialchars(ucfirst(str_replace('_', ' ', $ticket_type))) . "
                            </div>
                            <div class='info-row'>
                                <strong>Submitted By:</strong> " . htmlspecialchars($user_name) . " (" . htmlspecialchars($user_email) . ")
                            </div>
                            <div class='info-row'>
                                <strong>Department:</strong> " . htmlspecialchars($user_department) . "
                            </div>
                            <div class='info-row'>
                                <strong>Created:</strong> " . date('F j, Y \a\t g:i A') . "
                            </div>
                        </div>
                        
                        <div style='background: white; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                            <h4 style='margin-top: 0; color: #2c3e50;'>Description:</h4>
                            <p style='margin: 0; color: #4a5568;'>" . nl2br(htmlspecialchars(substr($description, 0, 300))) . (strlen($description) > 300 ? '...' : '') . "</p>
                        </div>
                        
                        <center>
                            <a href='" . $SYSTEM_URL . "/managers/departmentTicket.php?id=" . $ticket_id . "' class='btn'>
                                🔍 Review & Approve Ticket
                            </a>
                        </center>
                        
                        <p style='color: #6c757d; font-size: 13px; margin-top: 25px; padding: 15px; background: #fff; border-radius: 6px;'>
                            <strong>📌 Note:</strong> This ticket will remain in Pending status until a manager approves or rejects it.
                        </p>
                    </div>
                    
                    <div class='footer'>
                        <p><strong>E-Asset Management System</strong></p>
                        <p>This is an automated notification. Please do not reply to this email.</p>
                        <p style='margin-top: 10px;'>
                            <a href='" . $SYSTEM_URL . "' style='color: #667eea; text-decoration: none;'>Access Dashboard</a>
                        </p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            error_log("Attempting to send email to manager: $manager_email");
            $manager_sent = $emailHelper->sendEmail($manager_email, $subject_manager, $body_manager);
            
            if ($manager_sent) {
                error_log("✓✓✓ SUCCESS! Email sent to manager: $manager_email");
                $manager_emails_sent++;
            } else {
                error_log("✗✗✗ FAILED! Email NOT sent to manager: $manager_email");
                $manager_emails_failed++;
            }
        }
        
        error_log("Manager email summary: $manager_emails_sent sent, $manager_emails_failed failed out of " . count($managers) . " total managers");
        
    } else {
        error_log("✗✗✗ NO MANAGERS FOUND FOR DEPARTMENT: '$user_department'");
        
        // FALLBACK: Notify admins
        error_log("Sending fallback notification to admins...");
        
        $admin_query = $pdo->prepare("
            SELECT email, first_name, last_name 
            FROM users 
            WHERE role IN ('admin', 'superadmin')
            AND is_active = 1 
            AND is_deleted = 0
        ");
        $admin_query->execute();
        $admins = $admin_query->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($admins)) {
            error_log("Found " . count($admins) . " admin(s) for fallback notification");
            
            foreach ($admins as $admin) {
                $admin_name = $admin['first_name'] . ' ' . $admin['last_name'];
                $admin_subject = "⚠️ New Ticket (No Manager) - $ticket_number";
                $admin_body = "
                <!DOCTYPE html>
                <html>
                <body style='font-family: Arial, sans-serif;'>
                    <h2 style='color: #dc3545;'>⚠️ Ticket Created - No Manager Available</h2>
                    <p>Hello <strong>$admin_name</strong>,</p>
                    <p>A new ticket has been created but <strong>no active manager</strong> was found for the <strong>" . htmlspecialchars($user_department) . "</strong> department.</p>
                    
                    <div style='background: #f8f9fa; padding: 15px; border-left: 4px solid #dc3545; margin: 20px 0;'>
                        <p><strong>Ticket:</strong> " . htmlspecialchars($ticket_number) . "</p>
                        <p><strong>Created By:</strong> " . htmlspecialchars($user_name) . " (" . htmlspecialchars($user_email) . ")</p>
                        <p><strong>Department:</strong> " . htmlspecialchars($user_department) . "</p>
                        <p><strong>Subject:</strong> " . htmlspecialchars($subject) . "</p>
                        <p><strong>Priority:</strong> " . strtoupper($priority) . "</p>
                    </div>
                    
                    <p><strong>Action Required:</strong> Please assign a manager to this department or handle the ticket approval manually.</p>
                    
                    <p><a href='" . $SYSTEM_URL . "/tickets/ticketDetails.php?id=" . $ticket_id . "' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>View Ticket</a></p>
                </body>
                </html>
                ";
                
                $admin_sent = $emailHelper->sendEmail($admin['email'], $admin_subject, $admin_body);
                if ($admin_sent) {
                    error_log("  ✓ Fallback email sent to admin: " . $admin['email']);
                } else {
                    error_log("  ✗ Failed to send fallback email to admin: " . $admin['email']);
                }
            }
        } else {
            error_log("WARNING: No admins found for fallback notification!");
        }
    }
    
    error_log("EMAIL NOTIFICATION COMPLETE");
    error_log("========================================");
    
} catch (Exception $e) {
    error_log("❌❌❌ CRITICAL EMAIL ERROR: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    // Don't fail ticket creation, just log the error
}
// ==================== EMAIL NOTIFICATION - END ====================


            $_SESSION['ticket_created'] = true;
            header("Location: ../users/userTicket.php?id=$ticket_id");
            exit();
        } else {
            $error_message = implode("<br>", $errors);
        }
    } catch (Exception $e) {
        $error_message = "Error creating ticket: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Ticket - E-Asset System</title>

    <!-- Include navigation styles -->
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* Dashboard Content Styles */
        .dashboard-content {
            padding: 2rem;
            background: #f8f9fa;
            min-height: calc(100vh - 60px);
        }

        .form-container {
            max-width: 900px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header-left h1 {
            color: #2c3e50;
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
        }

        .header-left p {
            color: #6c757d;
            margin: 0.25rem 0 0 0;
            font-size: 0.95rem;
        }

        .header-right {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        /* Buttons */
        .btn {
            padding: 0.625rem 1.25rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #5a67d8, #6b46c1);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-outline {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .btn-outline:hover {
            background: #667eea;
            color: white;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .info-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .info-banner i {
            font-size: 2rem;
        }

        .info-banner-content h3 {
            margin: 0 0 0.5rem 0;
            font-size: 1.1rem;
        }

        .info-banner-content p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #2d3748;
            font-size: 14px;
        }

        .form-group label .required {
            color: #e53e3e;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s ease;
        }

        .form-group textarea {
            min-height: 150px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .file-upload-area {
            border: 2px dashed #cbd5e0;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .file-upload-area:hover {
            border-color: #667eea;
            background: #f0f3ff;
        }

        .file-upload-area i {
            font-size: 48px;
            color: #667eea;
            margin-bottom: 10px;
        }

        .file-upload-area p {
            margin: 10px 0 5px 0;
            color: #2d3748;
            font-weight: 500;
        }

        .file-upload-area small {
            color: #718096;
        }

        .file-list {
            margin-top: 15px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            background: #f7fafc;
            border-radius: 6px;
            font-size: 13px;
            border: 1px solid #e2e8f0;
        }

        .file-item span {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .file-item i.fa-file {
            color: #667eea;
        }

        .file-item button {
            background: none;
            border: none;
            color: #e53e3e;
            cursor: pointer;
            font-size: 18px;
            padding: 4px 8px;
            transition: all 0.2s;
        }

        .file-item button:hover {
            color: #c53030;
            transform: scale(1.1);
        }

        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background: #fff5f5;
            color: #c53030;
            border: 1px solid #feb2b2;
        }

        .help-text {
            font-size: 12px;
            color: #718096;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .help-text i {
            font-size: 11px;
        }

        .priority-info {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-top: 10px;
            font-size: 12px;
        }

        .priority-badge {
            padding: 6px 10px;
            border-radius: 6px;
            text-align: center;
            font-weight: 500;
        }

        .priority-badge.low {
            background: #e6fffa;
            color: #047857;
        }

        .priority-badge.medium {
            background: #fef3c7;
            color: #b45309;
        }

        .priority-badge.high {
            background: #fee2e2;
            color: #b91c1c;
        }

        .priority-badge.urgent {
            background: #fce7f3;
            color: #9f1239;
        }

        .char-counter {
            font-size: 12px;
            color: #718096;
            text-align: right;
            margin-top: 5px;
        }

        @media (max-width: 768px) {
            .dashboard-content {
                padding: 1rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-right {
                width: 100%;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .form-card {
                padding: 20px;
            }

            .priority-info {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>

<body>
    <!-- Include Sidebar -->
    <?php include("../auth/inc/Usidebar.php"); ?>

    <!-- Main Content -->
    <main class="main-content">
        <div class="dashboard-content">
            <header class="page-header">
                <div class="header-left">
                    <h1><i class="fas fa-plus-circle"></i> Create Support Ticket</h1>
                    <p>Submit a request for assistance or report an issue</p>
                </div>
                <div class="header-right">
                    <a href="userTicket.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to My Tickets
                    </a>
                </div>
            </header>

            <div class="form-container">
                <div class="info-banner">
                    <i class="fas fa-info-circle"></i>
                    <div class="info-banner-content">
                        <h3>Welcome, <?php echo htmlspecialchars($user_name); ?>!</h3>
                        <p>Please provide detailed information about your request. This helps us resolve your issue faster.</p>
                    </div>
                </div>

                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <div><?php echo $error_message; ?></div>
                    </div>
                <?php endif; ?>

                <div class="form-card">
                    <form method="POST" action="" enctype="multipart/form-data" id="ticketForm">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="ticket_type">
                                    <i class="fas fa-tag"></i> Request Type <span class="required">*</span>
                                </label>
                                <select id="ticket_type" name="ticket_type" required>
                                    <option value="">Select Type</option>
                                    <option value="repair">🔧 Repair</option>
                                    <option value="maintenance">⚙️ Maintenance</option>
                                    <option value="request_item">📦 Request New Item</option>
                                    <option value="request_replacement">🔄 Request Replacement</option>
                                    <option value="inquiry">❓ Inquiry</option>
                                    <option value="other">📝 Other</option>
                                </select>
                                <div class="help-text">
                                    <i class="fas fa-lightbulb"></i> Choose the type that best describes your request
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="priority">
                                    <i class="fas fa-exclamation-triangle"></i> Priority <span class="required">*</span>
                                </label>
                                <select id="priority" name="priority" required>
                                    <option value="low">Low - Not urgent</option>
                                    <option value="medium" selected>Medium - Normal priority</option>
                                    <option value="high">High - Important</option>
                                    <option value="urgent">Urgent - Critical</option>
                                </select>
                                <div class="priority-info">
                                    <div class="priority-badge low">Low</div>
                                    <div class="priority-badge medium">Medium</div>
                                    <div class="priority-badge high">High</div>
                                    <div class="priority-badge urgent">Urgent</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="asset_id">
                                <i class="fas fa-laptop"></i> Related Asset (Optional)
                            </label>
                            <select id="asset_id" name="asset_id">
                                <option value="">No specific asset</option>
                                <?php if (empty($user_assets)): ?>
                                    <option value="" disabled>You have no assets assigned</option>
                                <?php else: ?>
                                    <?php foreach ($user_assets as $asset): ?>
                                        <option value="<?php echo $asset['id']; ?>">
                                            <?php echo htmlspecialchars($asset['asset_code'] . ' - ' . $asset['asset_name'] . ' (' . $asset['category'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <div class="help-text">
                                <i class="fas fa-info-circle"></i> Select an asset only if this request is about a specific item you have
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="subject">
                                <i class="fas fa-heading"></i> Subject <span class="required">*</span>
                            </label>
                            <input
                                type="text"
                                id="subject"
                                name="subject"
                                placeholder="Brief summary of your request (e.g., 'Laptop keyboard not working')"
                                required
                                maxlength="255"
                                oninput="updateCharCount('subject', 255)">
                            <div class="char-counter" id="subject-counter">0 / 255 characters</div>
                        </div>

                        <div class="form-group">
                            <label for="description">
                                <i class="fas fa-align-left"></i> Description <span class="required">*</span>
                            </label>
                            <textarea
                                id="description"
                                name="description"
                                placeholder="Please provide detailed information:&#10;- What is the problem?&#10;- When did it start?&#10;- What have you tried?&#10;- Any error messages?"
                                required
                                maxlength="2000"
                                oninput="updateCharCount('description', 2000)"></textarea>
                            <div class="char-counter" id="description-counter">0 / 2000 characters (minimum 10)</div>
                        </div>

                        <div class="form-group">
                            <label>
                                <i class="fas fa-paperclip"></i> Attachments (Optional)
                            </label>
                            <div class="file-upload-area" onclick="document.getElementById('fileInput').click()">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <p>Click to upload files or drag and drop</p>
                                <small>Supported: JPG, PNG, PDF, DOC, DOCX, XLS, XLSX (Max 5MB each)</small>
                            </div>
                            <input type="file" id="fileInput" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx" style="display: none;">
                            <div class="file-list" id="fileList"></div>
                        </div>

                        <div class="form-actions">
                            <a href="userTickets.php" class="btn btn-outline">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Submit Ticket
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Character counter
        function updateCharCount(fieldId, maxLength) {
            const field = document.getElementById(fieldId);
            const counter = document.getElementById(fieldId + '-counter');
            const length = field.value.length;

            if (fieldId === 'description') {
                counter.textContent = `${length} / ${maxLength} characters (minimum 10)`;
                if (length < 10) {
                    counter.style.color = '#e53e3e';
                } else {
                    counter.style.color = '#718096';
                }
            } else {
                counter.textContent = `${length} / ${maxLength} characters`;
            }

            if (length > maxLength * 0.9) {
                counter.style.color = '#d69e2e';
            }
            if (length === maxLength) {
                counter.style.color = '#e53e3e';
            }
        }

        // File upload handling
        const fileInput = document.getElementById('fileInput');
        const fileList = document.getElementById('fileList');
        const uploadArea = document.querySelector('.file-upload-area');

        fileInput.addEventListener('change', function() {
            displayFiles(this.files);
        });

        // Drag and drop
        uploadArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.style.borderColor = '#667eea';
            this.style.background = '#f0f3ff';
        });

        uploadArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.style.borderColor = '#cbd5e0';
            this.style.background = '#f8f9fa';
        });

        uploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.style.borderColor = '#cbd5e0';
            this.style.background = '#f8f9fa';

            const dt = new DataTransfer();
            const files = e.dataTransfer.files;

            for (let i = 0; i < files.length; i++) {
                dt.items.add(files[i]);
            }

            fileInput.files = dt.files;
            displayFiles(dt.files);
        });

        function displayFiles(files) {
            fileList.innerHTML = '';

            if (files.length === 0) return;

            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const fileSize = (file.size / 1024 / 1024).toFixed(2);

                const fileItem = document.createElement('div');
                fileItem.className = 'file-item';
                fileItem.innerHTML = `
                    <span><i class="fas fa-file"></i> ${file.name} <small>(${fileSize} MB)</small></span>
                    <button type="button" onclick="removeFile(${i})" title="Remove file"><i class="fas fa-times"></i></button>
                `;

                fileList.appendChild(fileItem);
            }
        }

        function removeFile(index) {
            const dt = new DataTransfer();
            const files = fileInput.files;

            for (let i = 0; i < files.length; i++) {
                if (i !== index) {
                    dt.items.add(files[i]);
                }
            }

            fileInput.files = dt.files;
            displayFiles(dt.files);
        }

        // Form validation
        document.getElementById('ticketForm').addEventListener('submit', function(e) {
            const description = document.getElementById('description').value;
            const subject = document.getElementById('subject').value;

            if (description.length < 10) {
                e.preventDefault();
                alert('Description must be at least 10 characters long. Please provide more details about your request.');
                document.getElementById('description').focus();
                return false;
            }

            if (subject.trim().length < 5) {
                e.preventDefault();
                alert('Subject is too short. Please provide a brief but descriptive subject.');
                document.getElementById('subject').focus();
                return false;
            }

            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        });

        // Initialize character counters
        document.addEventListener('DOMContentLoaded', function() {
            updateCharCount('subject', 255);
            updateCharCount('description', 2000);
        });
    </script>
</body>

</html>