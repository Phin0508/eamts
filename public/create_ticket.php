<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Include database configuration
include("../auth/config/database.php");
require_once '../auth/helpers/EmailHelper.php';

// ============= VERIFY EMAIL HELPER =============
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

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Only managers and admins can access this page
if ($user_role === 'employee') {
    header("Location: ../users/userCreateTicket.php");
    exit();
}

$success_message = '';
$error_message = '';

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$base_path = str_replace('/tickets/createTicket.php', '', $_SERVER['SCRIPT_NAME']);
$SYSTEM_URL = $protocol . "://" . $host . $base_path;

// Get user info
$user_query = $pdo->prepare("SELECT first_name, last_name, email, department FROM users WHERE user_id = ?");
$user_query->execute([$user_id]);
$user_data = $user_query->fetch(PDO::FETCH_ASSOC);
$user_name = $user_data['first_name'] . ' ' . $user_data['last_name'];
$user_email = $user_data['email'];
$user_department = $user_data['department'];

// Fetch all ACTIVE users for assignment (if admin wants to create ticket on behalf of someone)
$users_query = $pdo->query("
    SELECT user_id, first_name, last_name, email, department, role 
    FROM users 
    WHERE is_active = 1 
    AND is_deleted = 0 
    ORDER BY 
        CASE 
            WHEN role = 'employee' THEN 1
            WHEN role = 'manager' THEN 2
            WHEN role = 'admin' THEN 3
            ELSE 4
        END,
        first_name, last_name
");
$all_users = $users_query->fetchAll(PDO::FETCH_ASSOC);

// Fetch all in-use assets
$assets_query = $pdo->query("
    SELECT id, asset_name, asset_code, category, assigned_to 
    FROM assets 
    WHERE status = 'in_use' 
    ORDER BY asset_name
");
$all_assets = $assets_query->fetchAll(PDO::FETCH_ASSOC);

// ==================== AJAX ENDPOINT FOR LOADING USER'S ASSETS ====================
if (isset($_GET['action']) && $_GET['action'] === 'get_user_assets' && isset($_GET['user_id'])) {
    header('Content-Type: application/json');

    $selected_user_id = intval($_GET['user_id']);

    // Fetch user's role and assets
    $user_check = $pdo->prepare("SELECT role, first_name, last_name FROM users WHERE user_id = ? AND is_active = 1");
    $user_check->execute([$selected_user_id]);
    $selected_user = $user_check->fetch(PDO::FETCH_ASSOC);

    if ($selected_user) {
        // Get user's assets
        $user_assets_query = $pdo->prepare("
            SELECT id, asset_name, asset_code, category 
            FROM assets 
            WHERE assigned_to = ? AND status = 'in_use' 
            ORDER BY asset_name
        ");
        $user_assets_query->execute([$selected_user_id]);
        $user_assets = $user_assets_query->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'role' => $selected_user['role'],
            'name' => $selected_user['first_name'] . ' ' . $selected_user['last_name'],
            'assets' => $user_assets
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found']);
    }
    exit();
}
// ==================== END AJAX ENDPOINT ====================

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $ticket_type = $_POST['ticket_type'];
        $subject = trim($_POST['subject']);
        $description = trim($_POST['description']);
        $priority = $_POST['priority'];
        $requester_id = !empty($_POST['requester_id']) ? $_POST['requester_id'] : $user_id;
        $asset_id = !empty($_POST['asset_id']) ? $_POST['asset_id'] : null;

        // Get requester information (department AND role)
        $requester_query = $pdo->prepare("SELECT department, role, first_name, last_name FROM users WHERE user_id = ?");
        $requester_query->execute([$requester_id]);
        $requester_info = $requester_query->fetch(PDO::FETCH_ASSOC);
        $requester_dept = $requester_info['department'];
        $requester_role = $requester_info['role'];
        $requester_name = $requester_info['first_name'] . ' ' . $requester_info['last_name'];

        // SECURITY: Verify asset belongs to requester if asset is selected
        if ($asset_id) {
            $asset_check = $pdo->prepare("SELECT id FROM assets WHERE id = ? AND assigned_to = ?");
            $asset_check->execute([$asset_id, $requester_id]);
            if (!$asset_check->fetch()) {
                throw new Exception("Invalid asset selected. Asset must belong to the selected user.");
            }
        }

        // Validation
        $errors = [];
        if (empty($subject)) $errors[] = "Subject is required";
        if (empty($description)) $errors[] = "Description is required";
        if (strlen($description) < 20) $errors[] = "Description must be at least 20 characters";
        if (strlen($subject) > 255) $errors[] = "Subject is too long (max 255 characters)";
        if (strlen($description) > 2000) $errors[] = "Description is too long (max 2000 characters)";

        if (empty($errors)) {
            // ==================== TICKET NUMBER GENERATION WITH LOCKING ====================
            $year = date('Y');
            $month = date('m');

            // START TRANSACTION
            $pdo->beginTransaction();

            try {
                // Get the highest ticket number for this month with FOR UPDATE lock
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
                error_log("Admin creating ticket - Generated: $ticket_number (count: $count)");

                // Verify uniqueness
                $check_query = $pdo->prepare("SELECT COUNT(*) as count FROM tickets WHERE ticket_number = ?");
                $check_query->execute([$ticket_number]);
                $exists = $check_query->fetch(PDO::FETCH_ASSOC)['count'];

                $attempts = 0;
                $max_attempts = 100;

                while ($exists > 0 && $attempts < $max_attempts) {
                    $count++;
                    $attempts++;
                    $ticket_number = sprintf("TKT-%s%s-%05d", $year, $month, $count);
                    error_log("Collision! Trying: $ticket_number (attempt $attempts)");
                    $check_query->execute([$ticket_number]);
                    $exists = $check_query->fetch(PDO::FETCH_ASSOC)['count'];
                }

                if ($attempts >= $max_attempts) {
                    throw new Exception("Unable to generate unique ticket number");
                }

                // ==================== APPROVAL LOGIC BASED ON REQUESTER ROLE ====================
                // 1. If requester is MANAGER or ADMIN -> Auto-approve
                // 2. If requester is EMPLOYEE -> Pending approval
                if (in_array($requester_role, ['manager', 'admin', 'superadmin'])) {
                    $approval_status = 'approved';
                    $status_message = "Auto-approved (Requester: " . strtoupper($requester_role) . ")";
                    error_log("Ticket $ticket_number: AUTO-APPROVED because requester is $requester_role");
                } else {
                    $approval_status = 'pending';
                    $status_message = "Pending approval (Requester: EMPLOYEE)";
                    error_log("Ticket $ticket_number: PENDING approval because requester is employee");
                }
                // ==================== END APPROVAL LOGIC ====================

                // Insert ticket
                $insert_query = "
                    INSERT INTO tickets (
                        ticket_number, ticket_type, subject, description, 
                        priority, status, approval_status, requester_id, 
                        requester_department, asset_id, created_by, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, 'open', ?, ?, ?, ?, ?, NOW(), NOW())
                ";

                $stmt = $pdo->prepare($insert_query);
                $stmt->execute([
                    $ticket_number,
                    $ticket_type,
                    $subject,
                    $description,
                    $priority,
                    $approval_status,
                    $requester_id,
                    $requester_dept,
                    $asset_id,
                    $user_id // Admin who created the ticket
                ]);

                $ticket_id = $pdo->lastInsertId();

                // Log history
                $history_message = "Ticket created by Admin on behalf of $requester_name ($requester_role) - $status_message";
                $history_query = "INSERT INTO ticket_history (ticket_id, action_type, new_value, performed_by, created_at) VALUES (?, 'created', ?, ?, NOW())";
                $history_stmt = $pdo->prepare($history_query);
                $history_stmt->execute([$ticket_id, $history_message, $user_id]);

                // COMMIT TRANSACTION
                $pdo->commit();

                error_log("✓ Admin ticket created: $ticket_number (ID: $ticket_id) | Requester: $requester_name ($requester_role) | Status: $approval_status");
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            // ==================== END TICKET NUMBER GENERATION ====================

            // Handle file uploads (AFTER transaction commit)
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

            // ==================== EMAIL NOTIFICATIONS ====================
            try {
                error_log("========================================");
                error_log("ADMIN CREATED TICKET - SENDING EMAILS");
                error_log("Ticket: $ticket_number | Created by: $user_name (Admin)");
                error_log("Requester: $requester_name ($requester_role) | Approval: $approval_status");

                // Get requester's email
                $requester_email_query = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
                $requester_email_query->execute([$requester_id]);
                $requester_email = $requester_email_query->fetch(PDO::FETCH_ASSOC)['email'];

                // ============= 1. EMAIL TO REQUESTER (The person the ticket is for) =============
                error_log("Sending email to REQUESTER: $requester_email");

                $requester_subject = "Ticket Created for You - $ticket_number";

                if ($approval_status === 'approved') {
                    // Auto-approved tickets (Manager/Admin requester)
                    $requester_body = "
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
                    <h1>✅ Ticket Created & Approved</h1>
                </div>
                
                <div class='content'>
                    <p style='font-size: 16px;'>Hello <strong>" . htmlspecialchars($requester_name) . "</strong>,</p>
                    
                    <div class='success-badge'>
                        <strong>✓ Auto-Approved!</strong> A support ticket has been created for you by <strong>" . htmlspecialchars($user_name) . "</strong> (Admin) and has been automatically approved.
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
                            <strong>Status:</strong> <span style='color: #28a745; font-weight: 600;'>✓ Approved</span>
                        </div>
                        <div class='info-row'>
                            <strong>Created By:</strong> " . htmlspecialchars($user_name) . " (Admin)
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
                        <strong>📌 Next Steps:</strong> This ticket has been automatically approved and will be assigned to a technician shortly.
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
                } else {
                    // Pending approval tickets (Employee requester)
                    $requester_body = "
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
                .pending-badge { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 15px 0; border-radius: 6px; color: #856404; }
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
                    <h1>🎫 Ticket Created for You</h1>
                </div>
                
                <div class='content'>
                    <p style='font-size: 16px;'>Hello <strong>" . htmlspecialchars($requester_name) . "</strong>,</p>
                    
                    <div class='pending-badge'>
                        <strong>ℹ️ Ticket Created:</strong> A support ticket has been created for you by <strong>" . htmlspecialchars($user_name) . "</strong> (Admin) and has been sent to your manager for approval.
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
                            <strong>Status:</strong> <span style='color: #f59e0b; font-weight: 600;'>⏳ Pending Approval</span>
                        </div>
                        <div class='info-row'>
                            <strong>Created By:</strong> " . htmlspecialchars($user_name) . " (Admin)
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
                        <strong>📌 Next Steps:</strong> Your ticket is pending approval from your department manager. You'll be notified once it's approved.
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
                }

                $requester_email_sent = $emailHelper->sendEmail($requester_email, $requester_subject, $requester_body);
                error_log("Requester email result: " . ($requester_email_sent ? "SUCCESS ✓" : "FAILED ✗"));

                // ============= 2. EMAIL TO DEPARTMENT MANAGER(S) =============
                error_log("Searching for managers in department: '$requester_dept'");

                $managers_query = $pdo->prepare("
        SELECT user_id, email, first_name, last_name 
        FROM users 
        WHERE department = ? 
        AND role = 'manager' 
        AND is_active = 1 
        AND is_deleted = 0
        ORDER BY user_id
    ");
                $managers_query->execute([$requester_dept]);
                $managers = $managers_query->fetchAll(PDO::FETCH_ASSOC);

                error_log("Found " . count($managers) . " manager(s) for department '$requester_dept'");

                if (!empty($managers)) {
                    $manager_emails_sent = 0;
                    $manager_emails_failed = 0;

                    foreach ($managers as $manager) {
                        $manager_name = $manager['first_name'] . ' ' . $manager['last_name'];
                        $manager_email_address = $manager['email'];

                        error_log("Processing manager: $manager_name ($manager_email_address)");

                        if ($approval_status === 'approved') {
                            // Manager notification for AUTO-APPROVED tickets
                            $manager_subject = "🎫 New Approved Ticket - $ticket_number";
                            $manager_body = "
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                        .container { max-width: 600px; margin: 0 auto; background: white; }
                        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
                        .content { padding: 30px; background: #f8f9fa; }
                        .alert { background: #d1ecf1; border-left: 4px solid #0c5460; padding: 15px; margin: 15px 0; border-radius: 6px; color: #0c5460; }
                        .ticket-info { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #28a745; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
                        .info-row { padding: 10px 0; border-bottom: 1px solid #e9ecef; }
                        .info-row:last-child { border-bottom: none; }
                        .btn { display: inline-block; padding: 14px 28px; background: #667eea; color: white; text-decoration: none; border-radius: 6px; font-weight: 600; margin: 20px 0; }
                        .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; border-top: 1px solid #e9ecef; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h1>✅ New Auto-Approved Ticket</h1>
                        </div>
                        
                        <div class='content'>
                            <p>Hello <strong>" . htmlspecialchars($manager_name) . "</strong>,</p>
                            
                            <p>A new support ticket has been created by Admin <strong>" . htmlspecialchars($user_name) . "</strong> for <strong>" . htmlspecialchars($requester_name) . "</strong> (" . strtoupper($requester_role) . ") from your <strong>" . htmlspecialchars($requester_dept) . "</strong> department.</p>
                            
                            <div class='alert'>
                                <strong>ℹ️ FYI:</strong> This ticket was automatically approved (Requester is " . strtoupper($requester_role) . "). No action needed from you.
                            </div>
                            
                            <div class='ticket-info'>
                                <h3 style='margin-top: 0; color: #28a745;'>📋 Ticket Details</h3>
                                <div class='info-row'>
                                    <strong>Ticket Number:</strong> " . htmlspecialchars($ticket_number) . "
                                </div>
                                <div class='info-row'>
                                    <strong>Status:</strong> <span style='color: #28a745; font-weight: 600;'>✓ Auto-Approved</span>
                                </div>
                                <div class='info-row'>
                                    <strong>Subject:</strong> " . htmlspecialchars($subject) . "
                                </div>
                                <div class='info-row'>
                                    <strong>Priority:</strong> " . strtoupper($priority) . "
                                </div>
                                <div class='info-row'>
                                    <strong>Requester:</strong> " . htmlspecialchars($requester_name) . " (" . strtoupper($requester_role) . ")
                                </div>
                                <div class='info-row'>
                                    <strong>Created By:</strong> " . htmlspecialchars($user_name) . " (Admin)
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
                                    🔍 View Ticket
                                </a>
                            </center>
                        </div>
                        
                        <div class='footer'>
                            <p><strong>E-Asset Management System</strong></p>
                            <p>This is an informational notification.</p>
                        </div>
                    </div>
                </body>
                </html>
                ";
                        } else {
                            // Manager notification for PENDING APPROVAL tickets
                            $manager_subject = "⏰ New Ticket Requires Your Approval - $ticket_number";
                            $manager_body = "
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                        .container { max-width: 600px; margin: 0 auto; background: white; }
                        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
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
                            <h1>⏰ Ticket Awaiting Your Approval</h1>
                        </div>
                        
                        <div class='content'>
                            <p>Hello <strong>" . htmlspecialchars($manager_name) . "</strong>,</p>
                            
                            <p>A new support ticket has been created by Admin <strong>" . htmlspecialchars($user_name) . "</strong> for <strong>" . htmlspecialchars($requester_name) . "</strong> (EMPLOYEE) from your <strong>" . htmlspecialchars($requester_dept) . "</strong> department.</p>
                            
                            <div class='alert'>
                                <strong>⚡ Action Required:</strong> Please review and approve or reject this ticket.
                            </div>
                            
                            <div class='ticket-info'>
                                <h3 style='margin-top: 0; color: #667eea;'>📋 Ticket Details</h3>
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
                                    <strong>Requester:</strong> " . htmlspecialchars($requester_name) . " (EMPLOYEE)
                                </div>
                                <div class='info-row'>
                                    <strong>Created By:</strong> " . htmlspecialchars($user_name) . " (Admin)
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
                                <strong>📌 Note:</strong> This ticket will remain in Pending status until you approve or reject it.
                            </p>
                        </div>
                        
                        <div class='footer'>
                            <p><strong>E-Asset Management System</strong></p>
                            <p>This is an automated notification.</p>
                        </div>
                    </div>
                </body>
                </html>
                ";
                        }

                        error_log("Attempting to send email to manager: $manager_email_address");
                        $manager_sent = $emailHelper->sendEmail($manager_email_address, $manager_subject, $manager_body);

                        if ($manager_sent) {
                            error_log("✓✓✓ SUCCESS! Email sent to manager: $manager_email_address");
                            $manager_emails_sent++;
                        } else {
                            error_log("✗✗✗ FAILED! Email NOT sent to manager: $manager_email_address");
                            $manager_emails_failed++;
                        }
                    }

                    error_log("Manager email summary: $manager_emails_sent sent, $manager_emails_failed failed out of " . count($managers) . " total managers");
                } else {
                    error_log("✗✗✗ NO MANAGERS FOUND FOR DEPARTMENT: '$requester_dept'");

                    // FALLBACK: Notify admins if no managers
                    error_log("Sending fallback notification to admins...");

                    $admin_fallback_query = $pdo->prepare("
            SELECT email, first_name, last_name 
            FROM users 
            WHERE role IN ('admin', 'superadmin')
            AND is_active = 1 
            AND is_deleted = 0
            AND user_id != ?
        ");
                    $admin_fallback_query->execute([$user_id]);
                    $admins = $admin_fallback_query->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($admins)) {
                        foreach ($admins as $admin) {
                            $admin_name = $admin['first_name'] . ' ' . $admin['last_name'];
                            $admin_subject = "⚠️ New Ticket (No Manager) - $ticket_number";
                            $admin_body = "
                <!DOCTYPE html>
                <html>
                <body style='font-family: Arial, sans-serif;'>
                    <h2 style='color: #dc3545;'>⚠️ Ticket Created - No Manager Available</h2>
                    <p>Hello <strong>$admin_name</strong>,</p>
                    <p>A new ticket has been created but <strong>no active manager</strong> was found for the <strong>" . htmlspecialchars($requester_dept) . "</strong> department.</p>
                    
                    <div style='background: #f8f9fa; padding: 15px; border-left: 4px solid #dc3545; margin: 20px 0;'>
                        <p><strong>Ticket:</strong> " . htmlspecialchars($ticket_number) . "</p>
                        <p><strong>Created By:</strong> " . htmlspecialchars($user_name) . " (Admin)</p>
                        <p><strong>Requester:</strong> " . htmlspecialchars($requester_name) . " ($requester_role)</p>
                        <p><strong>Department:</strong> " . htmlspecialchars($requester_dept) . "</p>
                        <p><strong>Subject:</strong> " . htmlspecialchars($subject) . "</p>
                    </div>
                    
                    <p><strong>Action Required:</strong> Please assign a manager to this department or handle the ticket approval manually.</p>
                    
                    <p><a href='" . $SYSTEM_URL . "/admin/adminTicketDetails.php?id=" . $ticket_id . "' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>View Ticket</a></p>
                </body>
                </html>
                ";

                            $emailHelper->sendEmail($admin['email'], $admin_subject, $admin_body);
                        }
                    }
                }

                error_log("EMAIL NOTIFICATION COMPLETE");
                error_log("========================================");
            } catch (Exception $e) {
                error_log("❌❌❌ CRITICAL EMAIL ERROR: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                // Don't fail ticket creation, just log the error
            }
            // ==================== END EMAIL NOTIFICATIONS ====================

            // Success message with approval status
            if ($approval_status === 'approved') {
                $success_message = "✓ Ticket created successfully! Ticket Number: $ticket_number | Status: AUTO-APPROVED (Requester: " . strtoupper($requester_role) . ")";
            } else {
                $success_message = "✓ Ticket created successfully! Ticket Number: $ticket_number | Status: PENDING APPROVAL (Sent to manager for review)";
            }

            $_POST = array(); // Clear form
        } else {
            $error_message = implode("<br>", $errors);
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
        error_log("Admin ticket creation error: " . $e->getMessage());
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
        error_log("Admin ticket creation error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Support Ticket - E-Asset Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8f9fc;
            color: #2d3748;
            min-height: 100vh;
        }

        /* Main Container */
        .container {
            margin-left: 260px;
            padding: 30px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }

        .container.sidebar-collapsed {
            margin-left: 80px;
        }

        /* Header */
        .header {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-left h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-left h1 i {
            color: #7c3aed;
        }

        .header-left p {
            color: #718096;
            font-size: 15px;
        }

        .header-right .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .header-right .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
        }

        /* Messages */
        .success-message,
        .error-message {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
            font-weight: 500;
        }

        .success-message {
            background: linear-gradient(135deg, #d4f4dd 0%, #c3e6cb 100%);
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .error-message {
            background: linear-gradient(135deg, #ffe6e6 0%, #ffd4d4 100%);
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Info Banner */
        .info-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 24px;
            border-radius: 16px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .info-banner i {
            font-size: 32px;
            opacity: 0.9;
        }

        .info-banner-content h3 {
            margin: 0 0 8px 0;
            font-size: 18px;
            font-weight: 600;
        }

        .info-banner-content p {
            margin: 0;
            opacity: 0.9;
            font-size: 14px;
        }

        /* Form Section */
        .form-section {
            background: white;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            max-width: 1000px;
            margin: 0 auto;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            color: #2d3748;
            font-weight: 600;
            font-size: 14px;
        }

        .form-group label i {
            margin-right: 6px;
            color: #7c3aed;
        }

        .required {
            color: #ef4444;
            margin-left: 4px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s;
            background: white;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 150px;
        }

        .form-group input:read-only {
            background: #f7fafc;
            cursor: not-allowed;
            color: #718096;
        }

        .form-help {
            font-size: 13px;
            color: #718096;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-help i {
            color: #7c3aed;
        }

        .char-counter {
            font-size: 12px;
            color: #718096;
            text-align: right;
            margin-top: 5px;
        }

        /* Priority Options */
        .priority-options {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }

        .priority-option {
            position: relative;
        }

        .priority-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .priority-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
            background: white;
            text-align: center;
        }

        .priority-option input[type="radio"]:checked+.priority-label {
            border-color: #7c3aed;
            background: linear-gradient(135deg, #f7f4fe 0%, #ede9fe 100%);
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.2);
        }

        .priority-label:hover {
            border-color: #cbd5e0;
            transform: translateY(-2px);
        }

        .priority-icon {
            font-size: 24px;
            margin-bottom: 8px;
        }

        .priority-text {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
        }

        .priority-low .priority-icon {
            color: #10b981;
        }

        .priority-medium .priority-icon {
            color: #f59e0b;
        }

        .priority-high .priority-icon {
            color: #ef4444;
        }

        .priority-urgent .priority-icon {
            color: #dc2626;
        }

        /* File Upload */
        .file-upload-area {
            border: 2px dashed #cbd5e0;
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .file-upload-area:hover {
            border-color: #7c3aed;
            background: #f7f4fe;
        }

        .file-upload-area i {
            font-size: 48px;
            color: #7c3aed;
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
            border-radius: 8px;
            font-size: 13px;
            border: 1px solid #e2e8f0;
        }

        .file-item span {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .file-item i.fa-file {
            color: #7c3aed;
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

        /* Admin Badge */
        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        /* Submit Button */
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 2px solid #e2e8f0;
        }

        .btn-submit {
            padding: 14px 32px;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(124, 58, 237, 0.4);
        }

        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-reset {
            padding: 14px 32px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: white;
            color: #718096;
        }

        .btn-reset:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .container {
                margin-left: 80px;
            }
        }

        @media (max-width: 768px) {
            .container {
                margin-left: 0;
                padding: 20px;
            }

            .header {
                padding: 20px;
            }

            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-left h1 {
                font-size: 22px;
            }

            .form-section {
                padding: 24px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .priority-options {
                grid-template-columns: repeat(2, 1fr);
            }

            .form-actions {
                flex-direction: column;
            }

            .btn-submit,
            .btn-reset {
                width: 100%;
                justify-content: center;
            }
        }

        /* Approval Status Styling */
        #approval-notice {
            padding: 10px 12px;
            background: #f7fafc;
            border-radius: 8px;
            border-left: 3px solid #718096;
            transition: all 0.3s ease;
        }

        #approval-notice i.fa-check-circle {
            color: #28a745 !important;
        }

        #approval-notice i.fa-clock {
            color: #f59e0b !important;
        }
    </style>
</head>

<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="container" id="mainContainer">
        <div class="header">
            <div class="header-content">
                <div class="header-left">
                    <h1>
                        <i class="fas fa-ticket-alt"></i> Create Support Ticket
                        <?php if ($user_role === 'admin'): ?>
                            <span class="admin-badge"><i class="fas fa-crown"></i> Admin</span>
                        <?php endif; ?>
                    </h1>
                    <p>Submit a new support request or report an issue</p>
                </div>
                <div class="header-right">
                    <a href="ticket.php" class="btn">
                        <i class="fas fa-list"></i> View All Tickets
                    </a>
                </div>
            </div>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error_message; ?></span>
            </div>
        <?php endif; ?>

        <div class="info-banner">
            <i class="fas fa-user-shield"></i>
            <div class="info-banner-content">
                <h3>Welcome, <?php echo htmlspecialchars($user_name); ?>!</h3>
                <p><?php echo $user_role === 'admin' ? 'As an admin, you can create tickets for yourself or on behalf of other users. Your tickets are automatically approved.' : 'As a manager, you can create tickets that will be reviewed by administrators.'; ?></p>
            </div>
        </div>

        <div class="form-section">
            <form method="POST" enctype="multipart/form-data" id="ticketForm">
                <div class="form-grid">
                    <?php if ($user_role === 'admin'): ?>
                        <div class="form-group">
                            <label for="requester_id">
                                <i class="fas fa-user"></i> Create For <span class="required">*</span>
                            </label>
                            <select id="requester_id" name="requester_id" onchange="loadUserAssets()">
                                <option value="<?php echo $user_id; ?>">Myself (<?php echo htmlspecialchars($user_name); ?>) - <?php echo strtoupper($user_role); ?></option>
                                <optgroup label="Employees (Requires Approval)">
                                    <?php foreach ($all_users as $user): ?>
                                        <?php if ($user['user_id'] != $user_id && $user['role'] === 'employee'): ?>
                                            <option value="<?php echo $user['user_id']; ?>" data-role="<?php echo $user['role']; ?>">
                                                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                                - <?php echo htmlspecialchars($user['department']); ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="Managers & Admins (Auto-Approved)">
                                    <?php foreach ($all_users as $user): ?>
                                        <?php if ($user['user_id'] != $user_id && in_array($user['role'], ['manager', 'admin', 'superadmin'])): ?>
                                            <option value="<?php echo $user['user_id']; ?>" data-role="<?php echo $user['role']; ?>">
                                                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                                - <?php echo htmlspecialchars($user['department']); ?>
                                                (<?php echo strtoupper($user['role']); ?>)
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                            <div class="form-help" id="approval-notice">
                                <i class="fas fa-info-circle"></i>
                                <span id="approval-text">Creating ticket for yourself - will be auto-approved</span>
                            </div>
                        </div>
                    <?php endif; ?>

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
                        <div class="form-help">
                            <i class="fas fa-lightbulb"></i> Choose the type that best describes your request
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="asset_id">
                        <i class="fas fa-laptop"></i> Related Asset (Optional)
                    </label>
                    <select id="asset_id" name="asset_id">
                        <option value="">No specific asset</option>
                        <?php foreach ($all_assets as $asset): ?>
                            <option value="<?php echo $asset['id']; ?>">
                                <?php echo htmlspecialchars($asset['asset_code'] . ' - ' . $asset['asset_name'] . ' (' . $asset['category'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-help">
                        <i class="fas fa-info-circle"></i>
                        <span id="asset-help-text">Select an asset if this request is related to a specific item</span>
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
                        placeholder="Brief description of your issue (e.g., 'Laptop keyboard not working')"
                        required
                        maxlength="255"
                        oninput="updateCharCount('subject', 255)"
                        value="<?php echo isset($_POST['subject']) ? htmlspecialchars($_POST['subject']) : ''; ?>">
                    <div class="char-counter" id="subject-counter">0 / 255 characters</div>
                </div>

                <div class="form-group">
                    <label>
                        <i class="fas fa-exclamation-triangle"></i> Priority Level <span class="required">*</span>
                    </label>
                    <div class="priority-options">
                        <div class="priority-option">
                            <input type="radio" id="priority-low" name="priority" value="low">
                            <label for="priority-low" class="priority-label priority-low">
                                <div class="priority-icon">🟢</div>
                                <div class="priority-text">Low</div>
                            </label>
                        </div>
                        <div class="priority-option">
                            <input type="radio" id="priority-medium" name="priority" value="medium" checked>
                            <label for="priority-medium" class="priority-label priority-medium">
                                <div class="priority-icon">🟡</div>
                                <div class="priority-text">Medium</div>
                            </label>
                        </div>
                        <div class="priority-option">
                            <input type="radio" id="priority-high" name="priority" value="high">
                            <label for="priority-high" class="priority-label priority-high">
                                <div class="priority-icon">🔴</div>
                                <div class="priority-text">High</div>
                            </label>
                        </div>
                        <div class="priority-option">
                            <input type="radio" id="priority-urgent" name="priority" value="urgent">
                            <label for="priority-urgent" class="priority-label priority-urgent">
                                <div class="priority-icon">🔥</div>
                                <div class="priority-text">Urgent</div>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-group full-width">
                    <label for="description">
                        <i class="fas fa-align-left"></i> Description <span class="required">*</span>
                    </label>
                    <textarea
                        id="description"
                        name="description"
                        placeholder="Please provide detailed information:&#10;- What is the problem or request?&#10;- When did it start?&#10;- What have you tried?&#10;- Any error messages or additional context?"
                        required
                        minlength="20"
                        maxlength="2000"
                        oninput="updateCharCount('description', 2000)"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                    <div class="char-counter" id="description-counter">0 / 2000 characters (minimum 20)</div>
                </div>

                <div class="form-group full-width">
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
                    <button type="reset" class="btn-reset">
                        <i class="fas fa-redo"></i> Reset Form
                    </button>
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-paper-plane"></i> Submit Ticket
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Handle sidebar toggle
        function updateMainContainer() {
            const mainContainer = document.getElementById('mainContainer');
            const sidebar = document.querySelector('.sidebar');

            if (sidebar && sidebar.classList.contains('collapsed')) {
                mainContainer.classList.add('sidebar-collapsed');
            } else {
                mainContainer.classList.remove('sidebar-collapsed');
            }
        }

        // Check on load
        document.addEventListener('DOMContentLoaded', updateMainContainer);

        // Listen for sidebar toggle clicks
        document.addEventListener('click', function(e) {
            if (e.target.closest('.toggle-sidebar')) {
                setTimeout(updateMainContainer, 50);
            }
        });

        // Observe sidebar changes
        const observer = new MutationObserver(updateMainContainer);
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) {
            observer.observe(sidebar, {
                attributes: true,
                attributeFilter: ['class']
            });
        }

        // Auto-hide success/error messages
        setTimeout(() => {
            const successMsg = document.querySelector('.success-message');
            const errorMsg = document.querySelector('.error-message');
            if (successMsg) {
                successMsg.style.transition = 'opacity 0.5s';
                successMsg.style.opacity = '0';
                setTimeout(() => successMsg.style.display = 'none', 500);
            }
            if (errorMsg) {
                errorMsg.style.transition = 'opacity 0.5s';
                errorMsg.style.opacity = '0';
                setTimeout(() => errorMsg.style.display = 'none', 500);
            }
        }, 5000);

        // Character counter
        function updateCharCount(fieldId, maxLength) {
            const field = document.getElementById(fieldId);
            const counter = document.getElementById(fieldId + '-counter');
            const length = field.value.length;

            if (fieldId === 'description') {
                counter.textContent = `${length} / ${maxLength} characters (minimum 20)`;
                if (length < 20) {
                    counter.style.color = '#ef4444';
                } else if (length > maxLength * 0.9) {
                    counter.style.color = '#d69e2e';
                } else {
                    counter.style.color = '#718096';
                }
            } else {
                counter.textContent = `${length} / ${maxLength} characters`;
                if (length > maxLength * 0.9) {
                    counter.style.color = '#d69e2e';
                } else {
                    counter.style.color = '#718096';
                }
            }

            if (length === maxLength) {
                counter.style.color = '#ef4444';
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
            this.style.borderColor = '#7c3aed';
            this.style.background = '#f7f4fe';
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
            const subject = document.getElementById('subject').value.trim();
            const description = document.getElementById('description').value.trim();
            const ticketType = document.getElementById('ticket_type').value;

            if (subject.length < 5) {
                e.preventDefault();
                alert('Subject must be at least 5 characters long');
                document.getElementById('subject').focus();
                return;
            }

            if (description.length < 20) {
                e.preventDefault();
                alert('Description must be at least 20 characters long. Please provide more details.');
                document.getElementById('description').focus();
                return;
            }

            if (!ticketType) {
                e.preventDefault();
                alert('Please select a request type');
                document.getElementById('ticket_type').focus();
                return;
            }

            // Disable submit button to prevent double submission
            const submitBtn = this.querySelector('.btn-submit');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        });

        // Initialize character counters
        document.addEventListener('DOMContentLoaded', function() {
            updateCharCount('subject', 255);
            updateCharCount('description', 2000);
        });

        // Character counter live update for description
        const descriptionField = document.getElementById('description');
        descriptionField.addEventListener('input', function() {
            updateCharCount('description', 2000);
        });

        // Character counter live update for subject
        const subjectField = document.getElementById('subject');
        subjectField.addEventListener('input', function() {
            updateCharCount('subject', 255);
        });
        // ==================== DYNAMIC ASSET LOADING & APPROVAL NOTICE ====================
        function loadUserAssets() {
            const select = document.getElementById('requester_id');
            const selectedUserId = select.value;
            const selectedOption = select.options[select.selectedIndex];
            const selectedRole = selectedOption.dataset.role || '<?php echo $user_role; ?>';

            const assetSelect = document.getElementById('asset_id');
            const assetHelpText = document.getElementById('asset-help-text');
            const approvalNotice = document.getElementById('approval-text');
            const approvalIcon = document.querySelector('#approval-notice i');

            // Update approval notice based on role
            if (selectedUserId == '<?php echo $user_id; ?>') {
                approvalNotice.textContent = 'Creating ticket for yourself - will be auto-approved';
                approvalIcon.className = 'fas fa-check-circle';
                approvalIcon.style.color = '#28a745';
            } else if (selectedRole === 'employee') {
                approvalNotice.textContent = 'Creating for EMPLOYEE - will require manager approval';
                approvalIcon.className = 'fas fa-clock';
                approvalIcon.style.color = '#f59e0b';
            } else if (selectedRole === 'manager' || selectedRole === 'admin') {
                approvalNotice.textContent = 'Creating for ' + selectedRole.toUpperCase() + ' - will be auto-approved';
                approvalIcon.className = 'fas fa-check-circle';
                approvalIcon.style.color = '#28a745';
            }

            // Load user's assets via AJAX
            if (selectedUserId == '<?php echo $user_id; ?>') {
                // Admin's own assets - already loaded
                restoreDefaultAssets();
                return;
            }

            // Show loading state
            assetSelect.innerHTML = '<option value="">Loading assets...</option>';
            assetSelect.disabled = true;
            assetHelpText.textContent = 'Loading user assets...';

            // Fetch user's assets
            fetch(`?action=get_user_assets&user_id=${selectedUserId}`)
                .then(response => response.json())
                .then(data => {
                    assetSelect.disabled = false;

                    if (data.success) {
                        assetSelect.innerHTML = '<option value="">No specific asset</option>';

                        if (data.assets.length === 0) {
                            const option = document.createElement('option');
                            option.value = '';
                            option.textContent = 'No assets assigned to ' + data.name;
                            option.disabled = true;
                            assetSelect.appendChild(option);
                            assetHelpText.textContent = data.name + ' has no assets assigned';
                        } else {
                            data.assets.forEach(asset => {
                                const option = document.createElement('option');
                                option.value = asset.id;
                                option.textContent = `${asset.asset_code} - ${asset.asset_name} (${asset.category})`;
                                assetSelect.appendChild(option);
                            });
                            assetHelpText.textContent = `Showing assets assigned to ${data.name} (${data.role})`;
                        }
                    } else {
                        assetSelect.innerHTML = '<option value="">Error loading assets</option>';
                        assetHelpText.textContent = 'Failed to load user assets';
                    }
                })
                .catch(error => {
                    console.error('Error loading assets:', error);
                    assetSelect.disabled = false;
                    assetSelect.innerHTML = '<option value="">Error loading assets</option>';
                    assetHelpText.textContent = 'Error occurred while loading assets';
                });
        }

        // Restore default assets (admin's own)
        function restoreDefaultAssets() {
            const assetSelect = document.getElementById('asset_id');
            const assetHelpText = document.getElementById('asset-help-text');

            assetSelect.innerHTML = '<option value="">No specific asset</option>';

            <?php foreach ($all_assets as $asset): ?>
                const option<?php echo $asset['id']; ?> = document.createElement('option');
                option<?php echo $asset['id']; ?>.value = '<?php echo $asset['id']; ?>';
                option<?php echo $asset['id']; ?>.textContent = '<?php echo htmlspecialchars($asset['asset_code'] . ' - ' . $asset['asset_name'] . ' (' . $asset['category'] . ')'); ?>';
                assetSelect.appendChild(option<?php echo $asset['id']; ?>);
            <?php endforeach; ?>

            assetHelpText.textContent = 'Select an asset if this request is related to a specific item';
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadUserAssets(); // Set initial state
        });
        // ==================== END DYNAMIC ASSET LOADING ====================
    </script>
</body>

</html>