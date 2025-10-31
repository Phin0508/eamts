<?php
session_start();
require_once '../auth/config/database.php';
require_once '../auth/helpers/EmailHelper.php'; // ADD THIS LINE

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Initialize EmailHelper
$emailHelper = new EmailHelper();

// Get manager's department
$dept_query = "SELECT department FROM users WHERE user_id = ?";
$dept_stmt = $pdo->prepare($dept_query);
$manager_data = $dept_stmt->fetch(PDO::FETCH_ASSOC);
$dept_stmt->execute([$user_id]);
$manager_dept = $dept_stmt->fetchColumn();

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $ticket_id = intval($_POST['ticket_id']);
    $action = $_POST['action'];
    $manager_notes = trim($_POST['manager_notes'] ?? '');
    
    // Get manager's name for email
    $manager_query = "SELECT first_name, last_name FROM users WHERE user_id = ?";
    $manager_stmt = $pdo->prepare($manager_query);
    $manager_stmt->execute([$user_id]);
    $manager_data = $manager_stmt->fetch(PDO::FETCH_ASSOC);
    $manager_name = $manager_data['first_name'] . ' ' . $manager_data['last_name'];
    
    // Verify ticket belongs to manager's department and is pending
    // IMPORTANT: Fetch ticket data here including requester info
    $verify_query = "
        SELECT t.*, 
               CONCAT(u.first_name, ' ', u.last_name) as requester_name,
               u.email as requester_email
        FROM tickets t
        JOIN users u ON t.requester_id = u.user_id
        WHERE t.ticket_id = ? AND t.requester_department = ? AND t.approval_status = 'pending'
    ";
    $verify_stmt = $pdo->prepare($verify_query);
    $verify_stmt->execute([$ticket_id, $manager_dept]);
    $ticket = $verify_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ticket) {
        if ($action === 'approve') {
            $update_query = "UPDATE tickets SET 
                            approval_status = 'approved', 
                            approved_by = ?, 
                            approved_at = NOW(),
                            manager_notes = ?,
                            updated_at = NOW()
                            WHERE ticket_id = ?";
            $update_stmt = $pdo->prepare($update_query);
            $update_stmt->execute([$user_id, $manager_notes, $ticket_id]);
            
            // Log history
            $history_query = "INSERT INTO ticket_history (ticket_id, action_type, new_value, performed_by, notes, created_at) 
                            VALUES (?, 'approval_status_changed', 'approved', ?, ?, NOW())";
            $history_stmt = $pdo->prepare($history_query);
            $history_stmt->execute([$ticket_id, $user_id, "Manager approved: " . $manager_notes]);

            // ==================== EMAIL NOTIFICATION - START ====================
            try {
                // Send approval email to requester
                $email_subject = "Ticket Approved - " . $ticket['ticket_number'];
                $email_body = "
                <h2>Your Ticket Has Been Approved</h2>
                <p>Hello {$ticket['requester_name']},</p>
                <p>Good news! Your ticket has been approved by {$manager_name}.</p>
                <hr>
                <p><strong>Ticket Number:</strong> {$ticket['ticket_number']}</p>
                <p><strong>Subject:</strong> {$ticket['subject']}</p>
                <p><strong>Approved By:</strong> {$manager_name}</p>
                <p><strong>Approval Date:</strong> " . date('F j, Y g:i A') . "</p>
                " . (!empty($manager_notes) ? "<p><strong>Manager's Notes:</strong> {$manager_notes}</p>" : "") . "
                <hr>
                <p>Your ticket is now pending assignment to a technician who will work on resolving your request.</p>
                <p><a href='" . SYSTEM_URL . "/users/userTicket.php?id={$ticket_id}' style='display:inline-block; padding:10px 20px; background:#10b981; color:white; text-decoration:none; border-radius:5px;'>View Ticket Details</a></p>
                ";
                
                $emailHelper->sendEmail($ticket['requester_email'], $email_subject, $email_body);
                
                // Optional: Notify admins that ticket is ready for assignment
                $admin_query = $pdo->prepare("SELECT email, first_name, last_name FROM users WHERE role IN ('admin', 'superadmin') AND is_active = 1");
                $admin_query->execute();
                $admins = $admin_query->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($admins as $admin) {
                    $admin_name = $admin['first_name'] . ' ' . $admin['last_name'];
                    $admin_email = $admin['email'];
                    
                    $admin_subject = "Ticket Approved - Ready for Assignment - " . $ticket['ticket_number'];
                    $admin_body = "
                    <h2>Ticket Approved - Ready for Assignment</h2>
                    <p>Hello {$admin_name},</p>
                    <p>A ticket has been approved and is ready to be assigned to a technician.</p>
                    <p><strong>Ticket Number:</strong> {$ticket['ticket_number']}</p>
                    <p><strong>Subject:</strong> {$ticket['subject']}</p>
                    <p><strong>Priority:</strong> " . ucfirst($ticket['priority']) . "</p>
                    <p><strong>Approved By:</strong> {$manager_name}</p>
                    <p><a href='" . SYSTEM_URL . "/tickets/ticketDetails.php?id={$ticket_id}' style='display:inline-block; padding:10px 20px; background:#667eea; color:white; text-decoration:none; border-radius:5px;'>Assign Ticket</a></p>
                    ";
                    
                    $emailHelper->sendEmail($admin_email, $admin_subject, $admin_body);
                }
                
            } catch (Exception $e) {
                error_log("Failed to send approval email: " . $e->getMessage());
            }
            // ==================== EMAIL NOTIFICATION - END ====================
            
            $success_message = "Ticket approved successfully!";
            
        } elseif ($action === 'reject') {
            $update_query = "UPDATE tickets SET 
                            approval_status = 'rejected', 
                            approved_by = ?, 
                            approved_at = NOW(),
                            manager_notes = ?,
                            status = 'closed',
                            updated_at = NOW()
                            WHERE ticket_id = ?";
            $update_stmt = $pdo->prepare($update_query);
            $update_stmt->execute([$user_id, $manager_notes, $ticket_id]);
            
            // Log history
            $history_query = "INSERT INTO ticket_history (ticket_id, action_type, new_value, performed_by, notes, created_at) 
                            VALUES (?, 'approval_status_changed', 'rejected', ?, ?, NOW())";
            $history_stmt = $pdo->prepare($history_query);
            $history_stmt->execute([$ticket_id, $user_id, "Manager rejected: " . $manager_notes]);
            
            // ==================== EMAIL NOTIFICATION - START ====================
            try {
                // Send rejection email to requester
                $email_subject = "Ticket Rejected - " . $ticket['ticket_number'];
                $email_body = "
                <h2>Your Ticket Has Been Rejected</h2>
                <p>Hello {$ticket['requester_name']},</p>
                <p>Unfortunately, your ticket has been rejected by {$manager_name}.</p>
                <hr>
                <p><strong>Ticket Number:</strong> {$ticket['ticket_number']}</p>
                <p><strong>Subject:</strong> {$ticket['subject']}</p>
                <p><strong>Rejected By:</strong> {$manager_name}</p>
                <p><strong>Rejection Date:</strong> " . date('F j, Y g:i A') . "</p>
                " . (!empty($manager_notes) ? "<p><strong>Reason for Rejection:</strong><br>{$manager_notes}</p>" : "") . "
                <hr>
                <p>If you believe this rejection was made in error or if you have additional information to provide, please contact your manager or create a new ticket with more details.</p>
                <p><a href='" . SYSTEM_URL . "/users/userTicket.php?id={$ticket_id}' style='display:inline-block; padding:10px 20px; background:#ef4444; color:white; text-decoration:none; border-radius:5px;'>View Ticket Details</a></p>
                ";
                
                $emailHelper->sendEmail($ticket['requester_email'], $email_subject, $email_body);
                
            } catch (Exception $e) {
                error_log("Failed to send rejection email: " . $e->getMessage());
            }
            // ==================== EMAIL NOTIFICATION - END ====================
            
            $success_message = "Ticket rejected.";
        }
        
        // Redirect to prevent form resubmission
        $_SESSION['dept_ticket_success'] = $action === 'approve' ? 'Ticket approved successfully!' : 'Ticket rejected successfully.';
        header("Location: departmentTicket.php");
        exit();
    } else {
        $_SESSION['dept_ticket_error'] = "Invalid ticket or already processed.";
        header("Location: departmentTicket.php");
        exit();
    }
}

$success_message = '';
$error_message = '';

// Check for session messages
if (isset($_SESSION['dept_ticket_success'])) {
    $success_message = $_SESSION['dept_ticket_success'];
    unset($_SESSION['dept_ticket_success']);
}

if (isset($_SESSION['dept_ticket_error'])) {
    $error_message = $_SESSION['dept_ticket_error'];
    unset($_SESSION['dept_ticket_error']);
}

// Check if there's a newly created ticket to highlight
$highlight_ticket_id = $_SESSION['highlight_ticket_id'] ?? null;

// Fetch department tickets
$filter_approval = $_GET['approval'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$filter_type = $_GET['type'] ?? 'all';
$search = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'default';
$sort_order = $_GET['order'] ?? 'desc';

$where_clauses = ["t.requester_department = ?"];
$params = [$manager_dept];

if ($filter_approval !== 'all') {
    $where_clauses[] = "t.approval_status = ?";
    $params[] = $filter_approval;
}

if ($filter_status !== 'all') {
    $where_clauses[] = "t.status = ?";
    $params[] = $filter_status;
}

if ($filter_type !== 'all') {
    $where_clauses[] = "t.ticket_type = ?";
    $params[] = $filter_type;
}

if (!empty($search)) {
    if (is_numeric($search)) {
        $where_clauses[] = "(t.ticket_number LIKE ? OR t.subject LIKE ? OR t.description LIKE ? OR 
                           requester.first_name LIKE ? OR requester.last_name LIKE ? OR 
                           CONCAT(requester.first_name, ' ', requester.last_name) LIKE ? OR 
                           requester.user_id = ? OR t.requester_id = ? OR 
                           a.asset_code LIKE ? OR a.asset_name LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = intval($search);
        $params[] = intval($search);
        $params[] = $search_param;
        $params[] = $search_param;
    } else {
        $where_clauses[] = "(t.ticket_number LIKE ? OR t.subject LIKE ? OR t.description LIKE ? OR 
                           requester.first_name LIKE ? OR requester.last_name LIKE ? OR 
                           CONCAT(requester.first_name, ' ', requester.last_name) LIKE ? OR 
                           requester.email LIKE ? OR 
                           a.asset_code LIKE ? OR a.asset_name LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// Build ORDER BY clause based on sort selection
$order_by_sql = "";
switch ($sort_by) {
    case 'ticket_number':
        $order_by_sql = "ORDER BY t.ticket_number " . ($sort_order === 'asc' ? 'ASC' : 'DESC');
        break;
    case 'subject':
        $order_by_sql = "ORDER BY t.subject " . ($sort_order === 'asc' ? 'ASC' : 'DESC');
        break;
    case 'requester':
        $order_by_sql = "ORDER BY requester.first_name " . ($sort_order === 'asc' ? 'ASC' : 'DESC') . ", requester.last_name " . ($sort_order === 'asc' ? 'ASC' : 'DESC');
        break;
    case 'priority':
        $order_by_sql = "ORDER BY 
            CASE t.priority
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
            END " . ($sort_order === 'asc' ? 'ASC' : 'DESC');
        break;
    case 'status':
        $order_by_sql = "ORDER BY t.status " . ($sort_order === 'asc' ? 'ASC' : 'DESC');
        break;
    case 'approval':
        $order_by_sql = "ORDER BY 
            CASE t.approval_status
                WHEN 'pending' THEN 1
                WHEN 'approved' THEN 2
                WHEN 'rejected' THEN 3
            END " . ($sort_order === 'asc' ? 'ASC' : 'DESC');
        break;
    case 'created':
        $order_by_sql = "ORDER BY t.created_at " . ($sort_order === 'asc' ? 'ASC' : 'DESC');
        break;
    case 'type':
        $order_by_sql = "ORDER BY t.ticket_type " . ($sort_order === 'asc' ? 'ASC' : 'DESC');
        break;
    default: // 'default'
        // Default sorting: pending first, then by priority, then by created date
        $order_by_sql = "ORDER BY 
            CASE t.approval_status
                WHEN 'pending' THEN 1
                WHEN 'approved' THEN 2
                WHEN 'rejected' THEN 3
            END,
            CASE t.priority
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
            END,
            t.created_at DESC";
        break;
}

$query = "
    SELECT 
        t.*,
        CONCAT(requester.first_name, ' ', requester.last_name) as requester_name,
        requester.email as requester_email,
        requester.department as requester_department,
        CONCAT(approver.first_name, ' ', approver.last_name) as approver_name,
        CONCAT(assigned.first_name, ' ', assigned.last_name) as assigned_to_name,
        a.asset_name,
        a.asset_code,
        TIMESTAMPDIFF(MINUTE, t.created_at, NOW()) as minutes_old
    FROM tickets t
    JOIN users requester ON t.requester_id = requester.user_id
    LEFT JOIN users approver ON t.approved_by = approver.user_id
    LEFT JOIN users assigned ON t.assigned_to = assigned.user_id
    LEFT JOIN assets a ON t.asset_id = a.id
    $where_sql
    $order_by_sql
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN approval_status = 'pending' THEN 1 ELSE 0 END) as pending_approval,
        SUM(CASE WHEN approval_status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN approval_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN priority = 'urgent' AND approval_status = 'pending' THEN 1 ELSE 0 END) as urgent_pending
    FROM tickets
    WHERE requester_department = ?
";

$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute([$manager_dept]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Tickets - E-Asset System</title>
    
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
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

        .container {
            margin-left: 260px;
            padding: 30px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }

        .container.sidebar-collapsed {
            margin-left: 80px;
        }

        .header {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-content h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-content h1 i {
            color: #7c3aed;
        }

        .header-content p {
            color: #718096;
            font-size: 15px;
        }

        .alert {
            padding: 16px 20px;
            margin-bottom: 24px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            font-weight: 500;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-error {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-10px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.3s;
            border-left: 4px solid #7c3aed;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(124, 58, 237, 0.15);
        }

        .stat-card.total { border-left-color: #7c3aed; }
        .stat-card.pending { border-left-color: #f59e0b; }
        .stat-card.approved { border-left-color: #10b981; }
        .stat-card.urgent { border-left-color: #ef4444; }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .stat-card.total .stat-icon { background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%); }
        .stat-card.pending .stat-icon { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .stat-card.approved .stat-icon { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .stat-card.urgent .stat-icon { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }

        .stat-info h3 {
            font-size: 32px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 4px;
        }

        .stat-info p {
            color: #718096;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filters-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .filters-form {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 250px;
        }

        .search-box i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #718096;
        }

        .search-box input {
            width: 100%;
            padding: 12px 16px 12px 44px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .search-box input:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

        .filters-form select {
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s;
            cursor: pointer;
            background: white;
        }

        .filters-form select:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

        .btn {
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
        }

        .btn-secondary {
            background: white;
            color: #718096;
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
        }

        .btn-outline {
            background: transparent;
            color: #718096;
            border: 2px solid #e2e8f0;
        }

        .btn-outline:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
        }

        .section {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .table-container {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table thead {
            background: linear-gradient(135deg, #f7f4fe 0%, #ede9fe 100%);
        }

        .table thead th {
            padding: 16px;
            text-align: left;
            font-weight: 700;
            font-size: 13px;
            color: #6d28d9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
            cursor: pointer;
            user-select: none;
            position: relative;
        }

        .table thead th.sortable:hover {
            background: rgba(124, 58, 237, 0.1);
        }

        .table thead th .sort-icon {
            margin-left: 8px;
            opacity: 0.3;
            transition: opacity 0.3s;
        }

        .table thead th.active .sort-icon {
            opacity: 1;
        }

        .table tbody tr {
            border-bottom: 1px solid #e2e8f0;
            transition: all 0.2s;
        }

        .table tbody tr:hover {
            background: #fafbfc;
        }

        .table tbody tr.highlight-new {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            animation: highlightPulse 2s ease-in-out;
            border-left: 4px solid #f59e0b;
        }

        @keyframes highlightPulse {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4);
            }
            50% {
                box-shadow: 0 0 20px 10px rgba(245, 158, 11, 0.2);
            }
        }

        .table tbody td {
            padding: 16px;
            font-size: 14px;
            color: #2d3748;
        }

        .new-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            animation: pulse 1.5s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.7;
            }
        }

        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
            font-size: 15px;
        }

        .ticket-subject {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .asset-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            border-radius: 6px;
            font-size: 12px;
            color: #4b5563;
            font-weight: 600;
        }

        .asset-tag i {
            font-size: 10px;
        }

        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .badge-type {
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
            color: #3730a3;
        }

        .badge-urgent {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }

        .badge-high {
            background: linear-gradient(135deg, #fed7aa 0%, #fdba74 100%);
            color: #9a3412;
        }

        .badge-medium {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }

        .badge-low {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        .badge-open {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        .badge-in_progress {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }

        .badge-resolved {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }

        .badge-closed {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #374151;
        }

        .badge-pending {
            background: linear-gradient(135deg, #fff3cd 0%, #fff3cd 100%);
            color: #856404;
        }

        .badge-approved {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
        }

        .badge-rejected {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
        }

        .text-muted {
            color: #718096;
            font-style: italic;
        }

        .date-time {
            color: #718096;
            font-size: 13px;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: white;
            border: 2px solid #e2e8f0;
            color: #718096;
            transition: all 0.2s;
            text-decoration: none;
            cursor: pointer;
        }

        .btn-icon:hover {
            background: #7c3aed;
            border-color: #7c3aed;
            color: white;
            transform: translateY(-2px);
        }

        .btn-approve {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
        }

        .btn-approve:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
        }

        .btn-reject {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            border: none;
        }

        .btn-reject:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: white;
            margin: 10% auto;
            padding: 40px;
            width: 90%;
            max-width: 500px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                transform: translateY(30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-content h2 {
            font-size: 24px;
            color: #1a202c;
            margin-bottom: 8px;
        }

        .modal-content p {
            color: #718096;
            margin-bottom: 24px;
        }

        .modal-content label {
            display: block;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .modal textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            margin-bottom: 24px;
            min-height: 120px;
            font-family: inherit;
            font-size: 14px;
            transition: all 0.3s;
            resize: vertical;
        }

        .modal textarea:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

        .modal-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 20px;
            color: #1a202c;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #718096;
            font-size: 15px;
        }

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

            .header-content h1 {
                font-size: 22px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filters-form {
                flex-direction: column;
            }

            .search-box {
                width: 100%;
            }

            .filters-form select,
            .filters-form .btn {
                width: 100%;
            }

            .table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .table {
                min-width: 1200px;
            }

            .section {
                padding: 20px;
            }

            .modal-content {
                width: 95%;
                padding: 24px;
                margin: 20% auto;
            }
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/Msidebar.php"); ?>

    <div class="container" id="mainContainer">
        <div class="header">
            <div class="header-content">
                <h1><i class="fas fa-building"></i> Department Tickets</h1>
                <p>Review and approve tickets from your department</p>
            </div>
        </div>

        <?php if (!empty($success_message)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-icon">
                    <i class="fas fa-ticket-alt"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total']; ?></h3>
                    <p>Total Department Tickets</p>
                </div>
            </div>
            <div class="stat-card pending">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['pending_approval']; ?></h3>
                    <p>Pending Approval</p>
                </div>
            </div>
            <div class="stat-card approved">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['approved']; ?></h3>
                    <p>Approved</p>
                </div>
            </div>
            <div class="stat-card urgent">
                <div class="stat-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['urgent_pending']; ?></h3>
                    <p>Urgent Pending</p>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-section">
            <form method="GET" action="" class="filters-form">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Search by ticket, subject, requester, asset..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <select name="approval" onchange="this.form.submit()">
                    <option value="all" <?php echo $filter_approval === 'all' ? 'selected' : ''; ?>>All Approval Status</option>
                    <option value="pending" <?php echo $filter_approval === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo $filter_approval === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="rejected" <?php echo $filter_approval === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>

                <select name="status" onchange="this.form.submit()">
                    <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="open" <?php echo $filter_status === 'open' ? 'selected' : ''; ?>>Open</option>
                    <option value="in_progress" <?php echo $filter_status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="resolved" <?php echo $filter_status === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                    <option value="closed" <?php echo $filter_status === 'closed' ? 'selected' : ''; ?>>Closed</option>
                </select>

                <select name="type" onchange="this.form.submit()">
                    <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                    <option value="repair" <?php echo $filter_type === 'repair' ? 'selected' : ''; ?>>Repair</option>
                    <option value="maintenance" <?php echo $filter_type === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                    <option value="request_item" <?php echo $filter_type === 'request_item' ? 'selected' : ''; ?>>Request Item</option>
                    <option value="request_replacement" <?php echo $filter_type === 'request_replacement' ? 'selected' : ''; ?>>Request Replacement</option>
                    <option value="inquiry" <?php echo $filter_type === 'inquiry' ? 'selected' : ''; ?>>Inquiry</option>
                    <option value="other" <?php echo $filter_type === 'other' ? 'selected' : ''; ?>>Other</option>
                </select>

                <button type="submit" class="btn btn-secondary">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a href="departmentTicket.php" class="btn btn-outline">
                    <i class="fas fa-redo"></i> Reset
                </a>
            </form>
        </div>

        <!-- Tickets Table -->
        <div class="section">
            <?php if (empty($tickets)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🎫</div>
                <h3>No Tickets Found</h3>
                <p>No department tickets match your current filters.</p>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="sortable" onclick="sortTable('ticket_number')">
                                Ticket # 
                                <i class="fas fa-sort sort-icon <?php echo $sort_by === 'ticket_number' ? 'active' : ''; ?>"></i>
                            </th>
                            <th class="sortable" onclick="sortTable('subject')">
                                Subject 
                                <i class="fas fa-sort sort-icon <?php echo $sort_by === 'subject' ? 'active' : ''; ?>"></i>
                            </th>
                            <th class="sortable" onclick="sortTable('type')">
                                Type 
                                <i class="fas fa-sort sort-icon <?php echo $sort_by === 'type' ? 'active' : ''; ?>"></i>
                            </th>
                            <th class="sortable" onclick="sortTable('requester')">
                                Requester 
                                <i class="fas fa-sort sort-icon <?php echo $sort_by === 'requester' ? 'active' : ''; ?>"></i>
                            </th>
                            <th class="sortable" onclick="sortTable('priority')">
                                Priority 
                                <i class="fas fa-sort sort-icon <?php echo $sort_by === 'priority' ? 'active' : ''; ?>"></i>
                            </th>
                            <th class="sortable" onclick="sortTable('status')">
                                Status 
                                <i class="fas fa-sort sort-icon <?php echo $sort_by === 'status' ? 'active' : ''; ?>"></i>
                            </th>
                            <th class="sortable" onclick="sortTable('approval')">
                                Approval Status 
                                <i class="fas fa-sort sort-icon <?php echo $sort_by === 'approval' ? 'active' : ''; ?>"></i>
                            </th>
                            <th class="sortable" onclick="sortTable('created')">
                                Created 
                                <i class="fas fa-sort sort-icon <?php echo $sort_by === 'created' ? 'active' : ''; ?>"></i>
                            </th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets as $ticket): 
                            $is_new = ($highlight_ticket_id && $ticket['ticket_id'] == $highlight_ticket_id) || ($ticket['minutes_old'] <= 5);
                        ?>
                        <tr class="<?php echo $is_new ? 'highlight-new' : ''; ?>" data-ticket-id="<?php echo $ticket['ticket_id']; ?>">
                            <td>
                                <strong><?php echo htmlspecialchars($ticket['ticket_number']); ?></strong>
                                <?php if ($is_new): ?>
                                    <span class="new-badge">
                                        <i class="fas fa-star"></i> NEW
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="ticket-subject">
                                    <span><?php echo htmlspecialchars($ticket['subject']); ?></span>
                                    <?php if ($ticket['asset_code']): ?>
                                    <span class="asset-tag">
                                        <i class="fas fa-laptop"></i> <?php echo htmlspecialchars($ticket['asset_code']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-type"><?php echo ucfirst(str_replace('_', ' ', $ticket['ticket_type'])); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($ticket['requester_name']); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $ticket['priority']; ?>">
                                    <?php echo ucfirst($ticket['priority']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $ticket['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $ticket['approval_status']; ?>">
                                    <?php echo ucfirst($ticket['approval_status']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="date-time"><?php echo date('M d, Y', strtotime($ticket['created_at'])); ?></span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="departmentTicketDetails.php?id=<?php echo $ticket['ticket_id']; ?>" class="btn-icon" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($ticket['approval_status'] === 'pending'): ?>
                                    <button onclick="openApprovalModal(<?php echo $ticket['ticket_id']; ?>, 'approve', '<?php echo htmlspecialchars($ticket['ticket_number']); ?>')" 
                                            class="btn-icon btn-approve" title="Approve">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button onclick="openApprovalModal(<?php echo $ticket['ticket_id']; ?>, 'reject', '<?php echo htmlspecialchars($ticket['ticket_number']); ?>')" 
                                            class="btn-icon btn-reject" title="Reject">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Approval Modal -->
    <div id="approvalModal" class="modal">
        <div class="modal-content">
            <h2 id="modalTitle">Approve Ticket</h2>
            <p>Ticket: <strong id="modalTicketNumber"></strong></p>
            <form method="POST" action="">
                <input type="hidden" name="ticket_id" id="modalTicketId">
                <input type="hidden" name="action" id="modalAction">
                
                <label for="manager_notes">Notes:</label>
                <textarea name="manager_notes" id="manager_notes" placeholder="Add any notes or comments..."></textarea>
                
                <div class="modal-buttons">
                    <button type="button" onclick="closeModal()" class="btn btn-outline">Cancel</button>
                    <button type="submit" id="modalSubmitBtn" class="btn btn-secondary">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Current sort parameters
        let currentSort = '<?php echo $sort_by; ?>';
        let currentOrder = '<?php echo $sort_order; ?>';

        // Sidebar toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContainer = document.getElementById('mainContainer');
            const toggleBtn = document.querySelector('.sidebar-toggle');
            
            if (toggleBtn && sidebar && mainContainer) {
                toggleBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('collapsed');
                    mainContainer.classList.toggle('sidebar-collapsed');
                });
            }

            // Mobile menu toggle
            const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
            if (mobileMenuBtn && sidebar) {
                mobileMenuBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('mobile-open');
                });
            }

            // Close mobile sidebar when clicking outside
            document.addEventListener('click', function(event) {
                if (window.innerWidth <= 768) {
                    if (sidebar && mobileMenuBtn && !sidebar.contains(event.target) && !mobileMenuBtn.contains(event.target)) {
                        sidebar.classList.remove('mobile-open');
                    }
                }
            });

            // Remove highlight after first click on highlighted row
            const highlightedRows = document.querySelectorAll('.highlight-new');
            highlightedRows.forEach(row => {
                row.addEventListener('click', function(e) {
                    // Don't remove highlight if clicking on action buttons
                    if (!e.target.closest('.action-buttons')) {
                        this.classList.remove('highlight-new');
                        // Store in sessionStorage that this ticket has been clicked
                        const ticketId = this.dataset.ticketId;
                        sessionStorage.setItem('ticket_clicked_' + ticketId, 'true');
                    }
                });

                // Check if ticket was already clicked in this session
                const ticketId = row.dataset.ticketId;
                if (sessionStorage.getItem('ticket_clicked_' + ticketId)) {
                    row.classList.remove('highlight-new');
                }
            });

            // Clear highlight from session if specified
            <?php if ($highlight_ticket_id): ?>
                sessionStorage.removeItem('ticket_clicked_<?php echo $highlight_ticket_id; ?>');
                // Clear the session variable after first load
                <?php unset($_SESSION['highlight_ticket_id']); ?>
            <?php endif; ?>
        });

        function sortTable(column) {
            const url = new URL(window.location.href);
            
            // If clicking the same column, toggle order
            if (currentSort === column) {
                currentOrder = currentOrder === 'asc' ? 'desc' : 'asc';
            } else {
                // New column, default to descending (or ascending for text fields)
                currentSort = column;
                currentOrder = (column === 'subject' || column === 'requester' || column === 'type') ? 'asc' : 'desc';
            }
            
            url.searchParams.set('sort', currentSort);
            url.searchParams.set('order', currentOrder);
            
            window.location.href = url.toString();
        }

        function openApprovalModal(ticketId, action, ticketNumber) {
            document.getElementById('approvalModal').style.display = 'block';
            document.getElementById('modalTicketId').value = ticketId;
            document.getElementById('modalAction').value = action;
            document.getElementById('modalTicketNumber').textContent = ticketNumber;
            
            const submitBtn = document.getElementById('modalSubmitBtn');
            const notesField = document.getElementById('manager_notes');
            
            if (action === 'approve') {
                document.getElementById('modalTitle').textContent = 'Approve Ticket';
                submitBtn.textContent = 'Approve';
                submitBtn.className = 'btn-icon btn-approve';
                submitBtn.style.width = 'auto';
                submitBtn.style.padding = '12px 24px';
                notesField.placeholder = 'Add approval notes (optional)...';
            } else {
                document.getElementById('modalTitle').textContent = 'Reject Ticket';
                submitBtn.textContent = 'Reject';
                submitBtn.className = 'btn-icon btn-reject';
                submitBtn.style.width = 'auto';
                submitBtn.style.padding = '12px 24px';
                notesField.placeholder = 'Please provide reason for rejection...';
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
        
        // Auto-hide success/error messages after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
        
        // Escape key closes modal
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });

        // Enhanced search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        this.value = '';
                        this.form.submit();
                    }
                });
            }
        });

        // Table row click functionality
        document.addEventListener('DOMContentLoaded', function() {
            const tableRows = document.querySelectorAll('.table tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('click', function(e) {
                    if (!e.target.closest('.action-buttons')) {
                        const viewLink = this.querySelector('a[href*="departmentTicketDetails.php"]');
                        if (viewLink) {
                            window.location.href = viewLink.href;
                        }
                    }
                });
                
                row.style.cursor = 'pointer';
            });
        });
    </script>
</body>
</html>