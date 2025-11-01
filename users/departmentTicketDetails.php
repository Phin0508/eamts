<?php
session_start();
require_once '../auth/config/database.php';
require_once '../auth/helpers/EmailHelper.php';

// CRITICAL: Validate session user_id early
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    error_log("CRITICAL ERROR: Invalid or missing user_id in session.");
    session_destroy();
    header("Location: ../auth/login.php?error=session_expired");
    exit();
}

// Check if user is logged in and is a manager
if ($_SESSION['role'] !== 'manager') {
    header("Location: login.php");
    exit();
}

$user_id = intval($_SESSION['user_id']);
$ticket_id = intval($_GET['id'] ?? 0);

// Verify user exists in database
$user_check = "SELECT user_id, department, first_name, last_name FROM users WHERE user_id = ? AND role = 'manager' AND is_active = 1";
$user_stmt = $pdo->prepare($user_check);
$user_stmt->execute([$user_id]);
$user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);

if (!$user_data) {
    error_log("ERROR: User ID $user_id not found in database or not an active manager");
    session_destroy();
    header("Location: ../auth/login.php?error=invalid_user");
    exit();
}

// Get manager's department and name
$manager_dept = $user_data['department'];
$manager_name = $user_data['first_name'] . ' ' . $user_data['last_name'];

// Initialize EmailHelper
$emailHelper = new EmailHelper();

// Log for debugging
error_log("Manager viewing ticket - user_id: $user_id, ticket_id: $ticket_id, department: $manager_dept");

// Fetch ticket details - only from manager's department
$ticket_query = "
    SELECT 
        t.*,
        CONCAT(requester.first_name, ' ', requester.last_name) as requester_name,
        requester.email as requester_email,
        requester.phone as requester_phone,
        requester.department as requester_department,
        CONCAT(assigned.first_name, ' ', assigned.last_name) as assigned_to_name,
        assigned.email as assigned_to_email,
        CONCAT(resolver.first_name, ' ', resolver.last_name) as resolved_by_name,
        CONCAT(approver.first_name, ' ', approver.last_name) as approver_name,
        a.asset_name,
        a.asset_code,
        a.category as asset_category,
        a.brand as asset_brand,
        a.model as asset_model
    FROM tickets t
    JOIN users requester ON t.requester_id = requester.user_id
    LEFT JOIN users assigned ON t.assigned_to = assigned.user_id
    LEFT JOIN users resolver ON t.resolved_by = resolver.user_id
    LEFT JOIN users approver ON t.approved_by = approver.user_id
    LEFT JOIN assets a ON t.asset_id = a.id
    WHERE t.ticket_id = ? AND t.requester_department = ?
";

$stmt = $pdo->prepare($ticket_query);
$stmt->execute([$ticket_id, $manager_dept]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    $_SESSION['dept_ticket_error'] = "Ticket not found or you don't have permission to view it.";
    header("Location: departmentTicket.php");
    exit();
}

// Fetch comments
$comments_query = "
    SELECT 
        tc.*,
        CONCAT(u.first_name, ' ', u.last_name) as user_name,
        u.role as user_role
    FROM ticket_comments tc
    JOIN users u ON tc.user_id = u.user_id
    WHERE tc.ticket_id = ?
    ORDER BY tc.created_at ASC
";

$comments_stmt = $pdo->prepare($comments_query);
$comments_stmt->execute([$ticket_id]);
$comments = $comments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch attachments
$attachments_query = "
    SELECT 
        ta.*,
        CONCAT(u.first_name, ' ', u.last_name) as uploaded_by_name
    FROM ticket_attachments ta
    JOIN users u ON ta.uploaded_by = u.user_id
    WHERE ta.ticket_id = ?
    ORDER BY ta.created_at DESC
";

$attachments_stmt = $pdo->prepare($attachments_query);
$attachments_stmt->execute([$ticket_id]);
$attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch history
$history_query = "
    SELECT 
        th.*,
        CONCAT(u.first_name, ' ', u.last_name) as performed_by_name
    FROM ticket_history th
    JOIN users u ON th.performed_by = u.user_id
    WHERE th.ticket_id = ?
    ORDER BY th.created_at DESC
";

$history_stmt = $pdo->prepare($history_query);
$history_stmt->execute([$ticket_id]);
$history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submissions - Manager can add comments and approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ADDED: Verify user is still logged in
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        error_log("ERROR: user_id not in session during POST in details page");
        $_SESSION['error_message'] = "Session expired. Please login again.";
        header("Location: login.php");
        exit();
    }
    
    try {
        // Add comment
        if (isset($_POST['action']) && $_POST['action'] === 'add_comment') {
            $comment = trim($_POST['comment']);
            $is_internal = isset($_POST['is_internal']) ? 1 : 0;

            if (!empty($comment)) {
                $insert_comment = "INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at) VALUES (?, ?, ?, ?, NOW())";
                $comment_stmt = $pdo->prepare($insert_comment);
                $comment_stmt->execute([$ticket_id, $user_id, $comment, $is_internal]);

                // Log history
                $log_history = "INSERT INTO ticket_history (ticket_id, action_type, performed_by, notes, created_at) VALUES (?, 'commented', ?, ?, NOW())";
                $log_stmt = $pdo->prepare($log_history);
                $log_stmt->execute([$ticket_id, $user_id, $comment]);

                $_SESSION['success_message'] = "Comment added successfully!";
                header("Location: departmentTicketDetails.php?id=$ticket_id");
                exit();
            }
        }

        // Handle approval/rejection
        if (isset($_POST['action']) && in_array($_POST['action'], ['approve', 'reject'])) {
            $action = $_POST['action'];
            $manager_notes = trim($_POST['manager_notes'] ?? '');
            
            // Verify ticket is pending
            if ($ticket['approval_status'] !== 'pending') {
                $_SESSION['error_message'] = "This ticket has already been processed.";
                header("Location: departmentTicketDetails.php?id=$ticket_id");
                exit();
            }
            
            // Use transaction for data integrity
            try {
                $pdo->beginTransaction();
                
                if ($action === 'approve') {
                    // Update ticket
                    $update_query = "UPDATE tickets SET 
                                    approval_status = 'approved', 
                                    approved_by = ?, 
                                    approved_at = NOW(),
                                    manager_notes = ?,
                                    updated_at = NOW()
                                    WHERE ticket_id = ?";
                    $update_stmt = $pdo->prepare($update_query);
                    $update_stmt->execute([$user_id, $manager_notes, $ticket_id]);
                    
                    if ($update_stmt->rowCount() === 0) {
                        throw new Exception("Ticket was already processed by another manager.");
                    }
                    
                    // Log history
                    $history_query = "INSERT INTO ticket_history (ticket_id, action_type, new_value, performed_by, notes, created_at) 
                                    VALUES (?, 'approval_status_changed', 'approved', ?, ?, NOW())";
                    $history_stmt = $pdo->prepare($history_query);
                    $history_stmt->execute([$ticket_id, $user_id, "Manager approved: " . $manager_notes]);
                    
                    // Send approval emails
                    try {
                        error_log("Sending approval notification for ticket: " . $ticket['ticket_number']);
                        
                        $email_subject = "Ticket Approved - " . $ticket['ticket_number'];
                        $email_body = "
                        <!DOCTYPE html>
                        <html>
                        <head>
                            <meta charset='UTF-8'>
                            <style>
                                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                                .container { max-width: 600px; margin: 0 auto; background: white; padding: 20px; }
                                .header { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 20px; text-align: center; border-radius: 8px; }
                                .content { padding: 20px; }
                                .ticket-info { background: #f8f9fa; padding: 15px; margin: 15px 0; border-radius: 8px; }
                                .btn { display: inline-block; padding: 12px 24px; background: #10b981; color: white; text-decoration: none; border-radius: 6px; }
                            </style>
                        </head>
                        <body>
                            <div class='container'>
                                <div class='header'>
                                    <h2>✅ Your Ticket Has Been Approved</h2>
                                </div>
                                <div class='content'>
                                    <p>Hello <strong>{$ticket['requester_name']}</strong>,</p>
                                    <p>Good news! Your ticket has been approved by <strong>{$manager_name}</strong>.</p>
                                    
                                    <div class='ticket-info'>
                                        <p><strong>Ticket Number:</strong> {$ticket['ticket_number']}</p>
                                        <p><strong>Subject:</strong> {$ticket['subject']}</p>
                                        <p><strong>Priority:</strong> " . ucfirst($ticket['priority']) . "</p>
                                        <p><strong>Approved By:</strong> {$manager_name}</p>
                                        <p><strong>Approval Date:</strong> " . date('F j, Y g:i A') . "</p>
                                        " . (!empty($manager_notes) ? "<p><strong>Manager's Notes:</strong><br>" . nl2br(htmlspecialchars($manager_notes)) . "</p>" : "") . "
                                    </div>
                                    
                                    <p>Your ticket is now pending assignment to a technician.</p>
                                </div>
                            </div>
                        </body>
                        </html>
                        ";
                        
                        $emailHelper->sendEmail($ticket['requester_email'], $email_subject, $email_body);
                        
                        // Prepare ticket data for admin notification
                        $ticket_data = [
                            'id' => $ticket_id,
                            'ticket_number' => $ticket['ticket_number'],
                            'subject' => $ticket['subject'],
                            'priority' => $ticket['priority'],
                            'ticket_type' => $ticket['ticket_type'],
                            'requester_name' => $ticket['requester_name'],
                            'requester_department' => $ticket['requester_department'],
                            'description' => $ticket['description']
                        ];
                        
                        $emailHelper->sendTicketApprovedToAdminsEmail($ticket_data, $manager_name, $pdo);
                        
                    } catch (Exception $e) {
                        error_log("Failed to send approval email: " . $e->getMessage());
                    }
                    
                    $pdo->commit();
                    $_SESSION['success_message'] = "Ticket approved successfully!";
                    
                } elseif ($action === 'reject') {
                    error_log("========== DETAILS PAGE REJECTION ==========");
                    error_log("user_id: " . var_export($user_id, true));
                    
                    // Validate user_id
                    $validated_user_id = intval($user_id);
                    
                    if ($validated_user_id <= 0) {
                        throw new Exception("Invalid user_id for rejection: $validated_user_id");
                    }
                    
                    error_log("Validated user_id for rejection: $validated_user_id");
                    
                    // INSERT HISTORY FIRST - before UPDATE to avoid trigger issues
                    $history_query = "INSERT INTO ticket_history 
                                    (ticket_id, action_type, new_value, performed_by, notes, created_at) 
                                    VALUES (?, 'approval_status_changed', 'rejected', ?, ?, NOW())";
                    
                    $history_stmt = $pdo->prepare($history_query);
                    
                    error_log("Inserting history BEFORE update with performed_by: $validated_user_id");
                    
                    $history_result = $history_stmt->execute([
                        $ticket_id,
                        $validated_user_id,
                        "Manager rejected: " . $manager_notes
                    ]);
                    
                    if (!$history_result) {
                        $error_info = $history_stmt->errorInfo();
                        error_log("History insert failed: " . print_r($error_info, true));
                        throw new Exception("Failed to log history: " . ($error_info[2] ?? 'Unknown error'));
                    }
                    
                    error_log("History inserted successfully!");
                    
                    // NOW update ticket with rejection
                    $update_query = "UPDATE tickets SET 
                                    approval_status = 'rejected', 
                                    rejected_by = ?, 
                                    rejected_at = NOW(),
                                    manager_notes = ?,
                                    status = 'closed',
                                    updated_at = NOW()
                                    WHERE ticket_id = ?";
                    
                    $update_stmt = $pdo->prepare($update_query);
                    
                    error_log("Executing rejection UPDATE with params: [$validated_user_id, notes, $ticket_id]");
                    
                    $update_result = $update_stmt->execute([$validated_user_id, $manager_notes, $ticket_id]);
                    
                    if (!$update_result) {
                        $error_info = $update_stmt->errorInfo();
                        error_log("UPDATE failed with error: " . print_r($error_info, true));
                        throw new Exception("Failed to update ticket: " . ($error_info[2] ?? 'Unknown error'));
                    }
                    
                    error_log("UPDATE successful, rows affected: " . $update_stmt->rowCount());
                    
                    if ($update_stmt->rowCount() === 0) {
                        throw new Exception("Ticket was already processed by another manager or does not exist.");
                    }
                    
                    // Verify the update worked
                    $verify_update = "SELECT rejected_by FROM tickets WHERE ticket_id = ?";
                    $verify_stmt = $pdo->prepare($verify_update);
                    $verify_stmt->execute([$ticket_id]);
                    $verify_result = $verify_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    error_log("Verification - rejected_by value: " . var_export($verify_result['rejected_by'], true));
                    
                    if (!$verify_result || $verify_result['rejected_by'] === null) {
                        throw new Exception("Database verification failed - rejected_by is null after update");
                    }
                    
                    // Send rejection email
                    try {
                        $ticket_data = [
                            'id' => $ticket_id,
                            'ticket_number' => $ticket['ticket_number'],
                            'subject' => $ticket['subject']
                        ];
                        
                        $emailHelper->sendTicketRejectedEmail(
                            $ticket['requester_email'], 
                            $ticket['requester_name'], 
                            $ticket_data, 
                            $manager_name, 
                            $manager_notes
                        );
                        
                        error_log("Rejection email sent successfully");
                        
                    } catch (Exception $e) {
                        error_log("Failed to send rejection email: " . $e->getMessage());
                    }
                    
                    $pdo->commit();
                    $_SESSION['success_message'] = "Ticket rejected successfully.";
                    error_log("========== REJECTION COMPLETE ==========");
                }
                
                header("Location: departmentTicketDetails.php?id=$ticket_id");
                exit();
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Database error during approval/rejection: " . $e->getMessage());
                error_log("Error Code: " . $e->getCode());
                error_log("SQL State: " . ($e->errorInfo[0] ?? 'unknown'));
                error_log("user_id at time of error: " . var_export($user_id, true));
                error_log("Stack trace: " . $e->getTraceAsString());
                $_SESSION['error_message'] = "Database error: " . $e->getMessage();
                header("Location: departmentTicketDetails.php?id=$ticket_id");
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error during approval/rejection: " . $e->getMessage());
                error_log("user_id at time of error: " . var_export($user_id, true));
                $_SESSION['error_message'] = $e->getMessage();
                header("Location: departmentTicketDetails.php?id=$ticket_id");
                exit();
            }
        }
    } catch (PDOException $e) {
        error_log("Error in POST handling: " . $e->getMessage());
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
        header("Location: departmentTicketDetails.php?id=$ticket_id");
        exit();
    } catch (Exception $e) {
        error_log("Error in POST handling: " . $e->getMessage());
        $_SESSION['error_message'] = $e->getMessage();
        header("Location: departmentTicketDetails.php?id=$ticket_id");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?php echo htmlspecialchars($ticket['ticket_number']); ?> - E-Asset System</title>

    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        .dashboard-content {
            padding: 2rem;
            background: #f8f9fa;
            min-height: calc(100vh - 60px);
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-header h1 {
            color: #2c3e50;
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
        }

        .page-header p {
            color: #6c757d;
            margin: 0.25rem 0 0 0;
            font-size: 0.95rem;
        }

        .header-right {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

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

        .btn-icon {
            width: 36px;
            height: 36px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            color: #667eea;
            border-radius: 6px;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn-icon:hover {
            background: #667eea;
            color: white;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border-left: 4px solid #28a745;
            padding: 1rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideDown 0.3s ease;
        }

        .error-message {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-left: 4px solid #dc3545;
            padding: 1rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideDown 0.3s ease;
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

        .ticket-detail-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 30px;
        }

        .detail-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e2e8f0;
        }

        .detail-title h2 {
            font-size: 24px;
            color: #1a202c;
            margin-bottom: 8px;
        }

        .detail-meta {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .badge-type {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .badge-open {
            background: #e3f2fd;
            color: #1976d2;
        }

        .badge-in_progress {
            background: #fff3e0;
            color: #f57c00;
        }

        .badge-pending {
            background: #fff3cd;
            color: #856404;
        }

        .badge-resolved {
            background: #e8f5e9;
            color: #388e3c;
        }

        .badge-closed {
            background: #f5f5f5;
            color: #616161;
        }

        .badge-low {
            background: #e8f5e9;
            color: #388e3c;
        }

        .badge-medium {
            background: #fff3e0;
            color: #f57c00;
        }

        .badge-high {
            background: #ffebee;
            color: #d32f2f;
        }

        .badge-urgent {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .badge-approved {
            background: #d4edda;
            color: #155724;
        }

        .badge-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .info-section {
            margin-bottom: 24px;
        }

        .info-section h3 {
            font-size: 16px;
            color: #2d3748;
            margin-bottom: 12px;
            font-weight: 600;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .info-label {
            font-size: 12px;
            color: #718096;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 14px;
            color: #2d3748;
        }

        .description-box {
            background: #f7fafc;
            padding: 16px;
            border-radius: 8px;
            font-size: 14px;
            line-height: 1.6;
            color: #2d3748;
            white-space: pre-wrap;
        }

        .resolution-box {
            background: #f0fdf4;
            border-left: 4px solid #10b981;
            padding: 16px;
            border-radius: 8px;
            font-size: 14px;
            line-height: 1.6;
            color: #2d3748;
        }

        .comments-section {
            margin-top: 24px;
        }

        .comment {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
            padding: 16px;
            background: #f7fafc;
            border-radius: 8px;
        }

        .comment.internal {
            background: #fff5e6;
            border-left: 3px solid #f59e0b;
        }

        .comment-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            flex-shrink: 0;
            font-size: 16px;
        }

        .comment-content {
            flex: 1;
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .comment-author {
            font-weight: 600;
            color: #2d3748;
            font-size: 14px;
        }

        .comment-time {
            font-size: 12px;
            color: #718096;
        }

        .comment-text {
            font-size: 14px;
            color: #2d3748;
            line-height: 1.6;
            white-space: pre-wrap;
        }

        .internal-badge {
            display: inline-block;
            background: #f59e0b;
            color: white;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 4px;
            margin-left: 8px;
            font-weight: 600;
        }

        .add-comment-form {
            margin-top: 20px;
        }

        .add-comment-form textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            min-height: 100px;
            resize: vertical;
        }

        .add-comment-form textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 12px;
            align-items: center;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #2d3748;
            cursor: pointer;
        }

        .attachments-list {
            display: grid;
            gap: 12px;
        }

        .attachment-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: #f7fafc;
            border-radius: 8px;
            font-size: 14px;
        }

        .attachment-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .attachment-info {
            flex: 1;
        }

        .attachment-name {
            font-weight: 500;
            color: #2d3748;
            margin-bottom: 2px;
        }

        .attachment-meta {
            font-size: 12px;
            color: #718096;
        }

        .sidebar-section {
            margin-bottom: 24px;
            padding-bottom: 24px;
            border-bottom: 1px solid #e2e8f0;
        }

        .sidebar-section:last-child {
            border-bottom: none;
        }

        .sidebar-section h3 {
            font-size: 14px;
            color: #2d3748;
            margin-bottom: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .history-timeline {
            position: relative;
            padding-left: 24px;
        }

        .history-item {
            position: relative;
            margin-bottom: 16px;
            padding-bottom: 16px;
        }

        .history-item:before {
            content: '';
            position: absolute;
            left: -18px;
            top: 6px;
            width: 8px;
            height: 8px;
            background: #667eea;
            border-radius: 50%;
        }

        .history-item:after {
            content: '';
            position: absolute;
            left: -15px;
            top: 14px;
            width: 2px;
            height: calc(100% - 6px);
            background: #e2e8f0;
        }

        .history-item:last-child:after {
            display: none;
        }

        .history-action {
            font-size: 13px;
            color: #2d3748;
            font-weight: 500;
            margin-bottom: 4px;
        }

        .history-user {
            font-size: 12px;
            color: #718096;
        }

        .asset-info-box {
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            padding: 16px;
            border-radius: 8px;
        }

        .asset-info-box .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .asset-info-box .info-row:last-child {
            border-bottom: none;
        }

        .asset-info-box .label {
            font-size: 12px;
            color: #718096;
            font-weight: 500;
        }

        .asset-info-box .value {
            font-size: 14px;
            color: #2d3748;
            font-weight: 500;
        }

        .btn-approve {
            background: #28a745;
            color: white;
            padding: 0.625rem 1.25rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-approve:hover {
            background: #218838;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
        }

        .btn-reject {
            background: #dc3545;
            color: white;
            padding: 0.625rem 1.25rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-reject:hover {
            background: #c82333;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: white;
            margin: 10% auto;
            padding: 30px;
            width: 90%;
            max-width: 500px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-content h2 {
            margin: 0 0 1rem 0;
            color: #2c3e50;
            font-size: 1.5rem;
        }

        .modal-content p {
            margin: 0 0 1rem 0;
            color: #6c757d;
        }

        .modal-content label {
            display: block;
            margin-bottom: 0.5rem;
            color: #2d3748;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .modal textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 1rem;
            min-height: 100px;
            font-family: inherit;
            font-size: 14px;
            resize: vertical;
        }

        .modal textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .info-notice {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-left: 4px solid #2196F3;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .info-notice i {
            color: #1976d2;
            margin-right: 0.5rem;
        }

        .info-notice p {
            margin: 0;
            color: #1565c0;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        @media (max-width: 968px) {
            .ticket-detail-container {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-right {
                width: 100%;
                flex-wrap: wrap;
            }
        }

        @media (max-width: 768px) {
            .dashboard-content {
                padding: 1rem;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <?php include("../auth/inc/Msidebar.php"); ?>

    <main class="main-content">
        <div class="dashboard-content">
            <header class="page-header">
                <div class="header-left">
                    <h1>Department Ticket Details</h1>
                    <p>View ticket information (Read-Only)</p>
                </div>
                <div class="header-right">
                    <a href="departmentTicket.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Department Tickets
                    </a>
                </div>
            </header>

            <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></span>
            </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></span>
            </div>
            <?php endif; ?>

            <!-- Info Notice for Managers -->
            <div class="info-notice">
                <i class="fas fa-info-circle"></i>
                <p><strong>Manager View:</strong> You can view ticket details, add comments, and approve/reject pending tickets from your department.</p>
            </div>

            <div class="ticket-detail-container">
                <!-- Left Column - Main Details -->
                <div>
                    <div class="detail-card">
                        <div class="detail-header">
                            <div class="detail-title">
                                <h2><?php echo htmlspecialchars($ticket['subject']); ?></h2>
                                <div class="detail-meta">
                                    <span class="badge badge-<?php echo $ticket['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                    </span>
                                    <span class="badge badge-<?php echo $ticket['priority']; ?>">
                                        <?php echo ucfirst($ticket['priority']); ?>
                                    </span>
                                    <span class="badge badge-type">
                                        <?php echo ucfirst(str_replace('_', ' ', $ticket['ticket_type'])); ?>
                                    </span>
                                    <span class="badge badge-<?php echo $ticket['approval_status']; ?>">
                                        <?php echo ucfirst($ticket['approval_status']); ?>
                                    </span>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <strong style="font-size: 18px; color: #667eea;">
                                    <?php echo htmlspecialchars($ticket['ticket_number']); ?>
                                </strong>
                            </div>
                        </div>

                        <div class="info-section">
                            <h3>Ticket Information</h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">Requester</span>
                                    <span class="info-value"><?php echo htmlspecialchars($ticket['requester_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Department</span>
                                    <span class="info-value"><?php echo htmlspecialchars($ticket['requester_department']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Created</span>
                                    <span class="info-value"><?php echo date('M d, Y h:i A', strtotime($ticket['created_at'])); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Last Updated</span>
                                    <span class="info-value"><?php echo date('M d, Y h:i A', strtotime($ticket['updated_at'])); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Assigned To</span>
                                    <span class="info-value">
                                        <?php echo $ticket['assigned_to_name'] ? htmlspecialchars($ticket['assigned_to_name']) : 'Unassigned'; ?>
                                    </span>
                                </div>
                                <?php if ($ticket['status'] === 'resolved' || $ticket['status'] === 'closed'): ?>
                                    <div class="info-item">
                                        <span class="info-label">Resolved By</span>
                                        <span class="info-value">
                                            <?php echo $ticket['resolved_by_name'] ? htmlspecialchars($ticket['resolved_by_name']) : 'N/A'; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($ticket['approver_name']): ?>
                                    <div class="info-item">
                                        <span class="info-label">Approved By</span>
                                        <span class="info-value"><?php echo htmlspecialchars($ticket['approver_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($ticket['asset_id']): ?>
                            <div class="info-section">
                                <h3>Related Asset</h3>
                                <div class="asset-info-box">
                                    <div class="info-row">
                                        <span class="label">Asset Code</span>
                                        <span class="value"><?php echo htmlspecialchars($ticket['asset_code']); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="label">Asset Name</span>
                                        <span class="value"><?php echo htmlspecialchars($ticket['asset_name']); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="label">Category</span>
                                        <span class="value"><?php echo htmlspecialchars($ticket['asset_category']); ?></span>
                                    </div>
                                    <?php if ($ticket['asset_brand']): ?>
                                        <div class="info-row">
                                            <span class="label">Brand/Model</span>
                                            <span class="value"><?php echo htmlspecialchars($ticket['asset_brand'] . ' ' . $ticket['asset_model']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="info-section">
                            <h3>Description</h3>
                            <div class="description-box">
                                <?php echo nl2br(htmlspecialchars($ticket['description'])); ?>
                            </div>
                        </div>

                        <?php if (!empty($ticket['manager_notes']) && $ticket['approval_status'] !== 'pending'): ?>
                            <div class="info-section">
                                <h3>Manager Notes</h3>
                                <div class="description-box" style="border-left: 4px solid #667eea;">
                                    <?php echo nl2br(htmlspecialchars($ticket['manager_notes'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($ticket['resolution']) && ($ticket['status'] === 'resolved' || $ticket['status'] === 'closed')): ?>
                            <div class="info-section">
                                <h3>Resolution</h3>
                                <div class="resolution-box">
                                    <?php echo nl2br(htmlspecialchars($ticket['resolution'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($attachments)): ?>
                            <div class="info-section">
                                <h3>Attachments (<?php echo count($attachments); ?>)</h3>
                                <div class="attachments-list">
                                    <?php foreach ($attachments as $attachment): ?>
                                        <div class="attachment-item">
                                            <div class="attachment-icon">
                                                <i class="fas fa-file"></i>
                                            </div>
                                            <div class="attachment-info">
                                                <div class="attachment-name">
                                                    <?php echo htmlspecialchars($attachment['file_name']); ?>
                                                </div>
                                                <div class="attachment-meta">
                                                    <?php echo round($attachment['file_size'] / 1024, 2); ?> KB •
                                                    Uploaded by <?php echo htmlspecialchars($attachment['uploaded_by_name']); ?> on
                                                    <?php echo date('M d, Y', strtotime($attachment['created_at'])); ?>
                                                </div>
                                            </div>
                                            <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" class="btn-icon" download title="Download">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Comments Section -->
                    <div class="detail-card comments-section">
                        <h3>Comments (<?php echo count($comments); ?>)</h3>

                        <?php if (empty($comments)): ?>
                            <p style="color: #718096; font-size: 14px; padding: 20px; text-align: center;">
                                No comments yet. Be the first to comment!
                            </p>
                        <?php else: ?>
                            <div style="margin-top: 20px;">
                                <?php foreach ($comments as $comment): ?>
                                    <div class="comment <?php echo $comment['is_internal'] ? 'internal' : ''; ?>">
                                        <div class="comment-avatar">
                                            <?php echo strtoupper(substr($comment['user_name'], 0, 1)); ?>
                                        </div>
                                        <div class="comment-content">
                                            <div class="comment-header">
                                                <span class="comment-author">
                                                    <?php echo htmlspecialchars($comment['user_name']); ?>
                                                    <span style="color: #718096; font-weight: normal; font-size: 12px;">
                                                        (<?php echo ucfirst($comment['user_role']); ?>)
                                                    </span>
                                                    <?php if ($comment['is_internal']): ?>
                                                        <span class="internal-badge">INTERNAL</span>
                                                    <?php endif; ?>
                                                </span>
                                                <span class="comment-time">
                                                    <?php echo date('M d, Y h:i A', strtotime($comment['created_at'])); ?>
                                                </span>
                                            </div>
                                            <div class="comment-text">
                                                <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Add Comment Form -->
                        <div class="add-comment-form">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="add_comment">
                                <textarea name="comment" placeholder="Add a comment..." required></textarea>
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-comment"></i> Add Comment
                                    </button>
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="is_internal" value="1">
                                        <span>Internal comment (not visible to requester)</span>
                                    </label>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Info & History -->
                <div>
                    <!-- Approval Status Info -->
                    <div class="detail-card sidebar-section">
                        <h3>Approval Status</h3>
                        <div style="padding: 1rem; background: #f7fafc; border-radius: 8px; border-left: 4px solid <?php echo $ticket['approval_status'] === 'approved' ? '#28a745' : ($ticket['approval_status'] === 'rejected' ? '#dc3545' : '#ffc107'); ?>;">
                            <div style="margin-bottom: 0.5rem;">
                                <span class="badge badge-<?php echo $ticket['approval_status']; ?>">
                                    <?php echo ucfirst($ticket['approval_status']); ?>
                                </span>
                            </div>
                            <?php if ($ticket['approval_status'] === 'approved' && $ticket['approver_name']): ?>
                                <p style="margin: 0.5rem 0; font-size: 0.85rem; color: #4a5568;">
                                    <i class="fas fa-user-check"></i> <strong>Approved by:</strong><br>
                                    <?php echo htmlspecialchars($ticket['approver_name']); ?>
                                </p>
                                <?php if ($ticket['approved_at']): ?>
                                <p style="margin: 0.5rem 0; font-size: 0.85rem; color: #4a5568;">
                                    <i class="fas fa-calendar"></i> <?php echo date('M d, Y h:i A', strtotime($ticket['approved_at'])); ?>
                                </p>
                                <?php endif; ?>
                            <?php elseif ($ticket['approval_status'] === 'pending'): ?>
                                <p style="margin: 0.5rem 0; font-size: 0.85rem; color: #856404;">
                                    <i class="fas fa-clock"></i> Awaiting manager approval
                                </p>
                            <?php elseif ($ticket['approval_status'] === 'rejected'): ?>
                                <p style="margin: 0.5rem 0; font-size: 0.85rem; color: #721c24;">
                                    <i class="fas fa-times-circle"></i> This ticket has been rejected
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($ticket['approval_status'] === 'pending'): ?>
                        <div style="margin-top: 1rem; display: flex; gap: 0.5rem;">
                            <button onclick="openApprovalModal(<?php echo $ticket_id; ?>, 'approve', '<?php echo htmlspecialchars($ticket['ticket_number']); ?>')" 
                                    class="btn-approve" style="flex: 1; justify-content: center;">
                                <i class="fas fa-check"></i> Approve
                            </button>
                            <button onclick="openApprovalModal(<?php echo $ticket_id; ?>, 'reject', '<?php echo htmlspecialchars($ticket['ticket_number']); ?>')" 
                                    class="btn-reject" style="flex: 1; justify-content: center;">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Assignment Info (Read-Only) -->
                    <?php if ($ticket['assigned_to_name']): ?>
                    <div class="detail-card sidebar-section">
                        <h3>Assignment</h3>
                        <div style="padding: 1rem; background: linear-gradient(135deg, #e7f3ff 0%, #cfe9ff 100%); border-radius: 8px; border-left: 4px solid #2196F3;">
                            <p style="margin: 0; font-size: 0.85rem; color: #1976d2;">
                                <i class="fas fa-user-check"></i> <strong>Assigned To:</strong>
                            </p>
                            <p style="margin: 0.5rem 0; font-weight: 600; color: #1565c0;">
                                <?php echo htmlspecialchars($ticket['assigned_to_name']); ?>
                            </p>
                            <?php if ($ticket['assigned_to_email']): ?>
                            <p style="margin: 0.5rem 0; font-size: 0.85rem; color: #1976d2;">
                                <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($ticket['assigned_to_email']); ?>
                            </p>
                            <?php endif; ?>
                            <?php if ($ticket['assigned_at']): ?>
                            <p style="margin: 0.5rem 0 0 0; font-size: 0.8rem; color: #1976d2;">
                                <i class="fas fa-clock"></i> Assigned on: <?php echo date('M d, Y h:i A', strtotime($ticket['assigned_at'])); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Contact Information -->
                    <div class="detail-card sidebar-section">
                        <h3>Requester Contact</h3>
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <div class="info-item">
                                <span class="info-label">Name</span>
                                <span class="info-value"><?php echo htmlspecialchars($ticket['requester_name']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Email</span>
                                <span class="info-value">
                                    <a href="mailto:<?php echo htmlspecialchars($ticket['requester_email']); ?>"
                                        style="color: #667eea; text-decoration: none;">
                                        <?php echo htmlspecialchars($ticket['requester_email']); ?>
                                    </a>
                                </span>
                            </div>
                            <?php if ($ticket['requester_phone']): ?>
                                <div class="info-item">
                                    <span class="info-label">Phone</span>
                                    <span class="info-value">
                                        <a href="tel:<?php echo htmlspecialchars($ticket['requester_phone']); ?>"
                                            style="color: #667eea; text-decoration: none;">
                                            <?php echo htmlspecialchars($ticket['requester_phone']); ?>
                                        </a>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="info-item">
                                <span class="info-label">Department</span>
                                <span class="info-value"><?php echo htmlspecialchars($ticket['requester_department']); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Activity History -->
                    <div class="detail-card">
                        <h3 style="margin-bottom: 16px;">Activity History</h3>
                        <?php if (empty($history)): ?>
                            <p style="color: #718096; font-size: 14px; text-align: center;">
                                No activity recorded yet.
                            </p>
                        <?php else: ?>
                            <div class="history-timeline">
                                <?php foreach ($history as $item): ?>
                                    <div class="history-item">
                                        <div class="history-action">
                                            <?php
                                            $action_text = '';
                                            switch ($item['action_type']) {
                                                case 'created':
                                                    $action_text = 'Ticket created';
                                                    break;
                                                case 'status_changed':
                                                    $action_text = 'Status changed to ' . ucfirst(str_replace('_', ' ', $item['new_value']));
                                                    break;
                                                case 'approval_status_changed':
                                                    $action_text = 'Approval status changed to ' . ucfirst($item['new_value']);
                                                    break;
                                                case 'assigned':
                                                    $action_text = 'Ticket assigned';
                                                    break;
                                                case 'commented':
                                                    $action_text = 'Added a comment';
                                                    break;
                                                case 'updated':
                                                    $action_text = 'Ticket updated';
                                                    break;
                                                default:
                                                    $action_text = ucfirst(str_replace('_', ' ', $item['action_type']));
                                            }
                                            echo $action_text;
                                            ?>
                                        </div>
                                        <div class="history-user">
                                            <?php echo htmlspecialchars($item['performed_by_name']); ?> •
                                            <?php echo date('M d, Y h:i A', strtotime($item['created_at'])); ?>
                                        </div>
                                        <?php if (!empty($item['notes'])): ?>
                                            <div style="font-size: 12px; color: #4a5568; margin-top: 4px; font-style: italic;">
                                                "<?php echo htmlspecialchars(substr($item['notes'], 0, 50)) . (strlen($item['notes']) > 50 ? '...' : ''); ?>"
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Approval Modal -->
    <div id="approvalModal" class="modal">
        <div class="modal-content">
            <h2 id="modalTitle">Approve Ticket</h2>
            <p>Ticket: <strong id="modalTicketNumber"></strong></p>
            <form method="POST" action="">
                <input type="hidden" name="ticket_id" id="modalTicketId">
                <input type="hidden" name="action" id="modalAction">
                
                <label for="manager_notes">Notes <span id="notesRequired"></span>:</label>
                <textarea name="manager_notes" id="manager_notes" placeholder="Add any notes or comments..."></textarea>
                
                <div class="modal-actions">
                    <button type="button" onclick="closeModal()" class="btn btn-outline">Cancel</button>
                    <button type="submit" id="modalSubmitBtn" class="btn btn-primary">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openApprovalModal(ticketId, action, ticketNumber) {
            document.getElementById('approvalModal').style.display = 'block';
            document.getElementById('modalTicketId').value = ticketId;
            document.getElementById('modalAction').value = action;
            document.getElementById('modalTicketNumber').textContent = ticketNumber;
            
            if (action === 'approve') {
                document.getElementById('modalTitle').textContent = 'Approve Ticket';
                document.getElementById('modalSubmitBtn').textContent = 'Approve';
                document.getElementById('modalSubmitBtn').className = 'btn-approve';
                document.getElementById('manager_notes').placeholder = 'Add approval notes (optional)...';
                document.getElementById('notesRequired').textContent = '(Optional)';
            } else {
                document.getElementById('modalTitle').textContent = 'Reject Ticket';
                document.getElementById('modalSubmitBtn').textContent = 'Reject';
                document.getElementById('modalSubmitBtn').className = 'btn-reject';
                document.getElementById('manager_notes').placeholder = 'Please provide reason for rejection...';
                document.getElementById('notesRequired').textContent = '(Recommended)';
            }
        }

        function closeModal() {
            document.getElementById('approvalModal').style.display = 'none';
            document.getElementById('manager_notes').value = '';
        }

        window.onclick = function(event) {
            const modal = document.getElementById('approvalModal');
            if (event.target === modal) {
                closeModal();
            }
        }

        // Escape key closes modal
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });

        // Auto-hide success/error messages after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-success, .error-message');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Character count for comment textarea
        const commentTextarea = document.querySelector('.add-comment-form textarea[name="comment"]');
        if (commentTextarea) {
            const charCount = document.createElement('div');
            charCount.style.fontSize = '12px';
            charCount.style.color = '#718096';
            charCount.style.marginTop = '4px';
            charCount.style.textAlign = 'right';
            commentTextarea.parentNode.insertBefore(charCount, commentTextarea.nextSibling);

            commentTextarea.addEventListener('input', function() {
                charCount.textContent = this.value.length + ' characters';
            });
        }
    </script>
</body>

</html>