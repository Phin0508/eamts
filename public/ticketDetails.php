<?php
session_start();
require_once '../auth/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'employee';
$ticket_id = $_GET['id'] ?? 0;

// Fetch ticket details
$ticket_query = "
    SELECT 
        t.*,
        CONCAT(requester.first_name, ' ', requester.last_name) as requester_name,
        requester.email as requester_email,
        requester.phone as requester_phone,
        CONCAT(assigned.first_name, ' ', assigned.last_name) as assigned_to_name,
        CONCAT(resolver.first_name, ' ', resolver.last_name) as resolved_by_name,
        a.asset_name,
        a.asset_code,
        a.category as asset_category,
        a.brand as asset_brand,
        a.model as asset_model
    FROM tickets t
    JOIN users requester ON t.requester_id = requester.user_id
    LEFT JOIN users assigned ON t.assigned_to = assigned.user_id
    LEFT JOIN users resolver ON t.resolved_by = resolver.user_id
    LEFT JOIN assets a ON t.asset_id = a.id
    WHERE t.ticket_id = ?
";

$stmt = $pdo->prepare($ticket_query);
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    header("Location: ticket.php");
    exit();
}

// Check permissions
if ($user_role === 'employee' && $ticket['requester_id'] != $user_id) {
    header("Location: ticket.php");
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

// Fetch assignable users (for admins and managers)
$assignable_users = [];
if ($user_role !== 'employee') {
    $users_query = "SELECT user_id, CONCAT(first_name, ' ', last_name) as name, role FROM users WHERE is_active = 1 AND role IN ('admin', 'manager') ORDER BY first_name";
    $users_stmt = $pdo->prepare($users_query);
    $users_stmt->execute();
    $assignable_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Add comment
        if (isset($_POST['action']) && $_POST['action'] === 'add_comment') {
            $comment = trim($_POST['comment']);
            $is_internal = isset($_POST['is_internal']) && $user_role !== 'employee' ? 1 : 0;
            
            if (!empty($comment)) {
                $insert_comment = "INSERT INTO ticket_comments (ticket_id, user_id, comment, is_internal, created_at) VALUES (?, ?, ?, ?, NOW())";
                $comment_stmt = $pdo->prepare($insert_comment);
                $comment_stmt->execute([$ticket_id, $user_id, $comment, $is_internal]);
                
                // Log history
                $log_history = "INSERT INTO ticket_history (ticket_id, action_type, performed_by, notes, created_at) VALUES (?, 'commented', ?, ?, NOW())";
                $log_stmt = $pdo->prepare($log_history);
                $log_stmt->execute([$ticket_id, $user_id, $comment]);
                
                header("Location: ticketDetails.php?id=$ticket_id");
                exit();
            }
        }
        
        // Update status
        if (isset($_POST['action']) && $_POST['action'] === 'update_status' && $user_role !== 'employee') {
            $new_status = $_POST['status'];
            $resolution = trim($_POST['resolution'] ?? '');
            
            $update_query = "UPDATE tickets SET status = ?, updated_at = NOW()";
            $params = [$new_status];
            
            if ($new_status === 'resolved' && !empty($resolution)) {
                $update_query .= ", resolution = ?, resolved_by = ?, resolved_at = NOW()";
                $params[] = $resolution;
                $params[] = $user_id;
            }
            
            if ($new_status === 'closed') {
                $update_query .= ", closed_by = ?, closed_at = NOW()";
                $params[] = $user_id;
            }
            
            $update_query .= " WHERE ticket_id = ?";
            $params[] = $ticket_id;
            
            $update_stmt = $pdo->prepare($update_query);
            $update_stmt->execute($params);
            
            // Log history
            $log_history = "INSERT INTO ticket_history (ticket_id, action_type, new_value, performed_by, created_at) VALUES (?, 'status_changed', ?, ?, NOW())";
            $log_stmt = $pdo->prepare($log_history);
            $log_stmt->execute([$ticket_id, $new_status, $user_id]);
            
            header("Location: ticketDetails.php?id=$ticket_id&updated=1");
            exit();
        }
        
        // Assign ticket
        if (isset($_POST['action']) && $_POST['action'] === 'assign_ticket' && $user_role !== 'employee') {
            $assigned_to = $_POST['assigned_to'];
            
            $assign_query = "UPDATE tickets SET assigned_to = ?, assigned_at = NOW(), status = 'in_progress', updated_at = NOW() WHERE ticket_id = ?";
            $assign_stmt = $pdo->prepare($assign_query);
            $assign_stmt->execute([$assigned_to, $ticket_id]);
            
            // Log history
            $log_history = "INSERT INTO ticket_history (ticket_id, action_type, new_value, performed_by, created_at) VALUES (?, 'assigned', ?, ?, NOW())";
            $log_stmt = $pdo->prepare($log_history);
            $log_stmt->execute([$ticket_id, $assigned_to, $user_id]);
            
            header("Location: ticketDetails.php?id=$ticket_id&assigned=1");
            exit();
        }
        
    } catch (PDOException $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

$success_message = '';
if (isset($_GET['created'])) $success_message = "Ticket created successfully!";
if (isset($_GET['updated'])) $success_message = "Ticket updated successfully!";
if (isset($_GET['assigned'])) $success_message = "Ticket assigned successfully!";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?php echo htmlspecialchars($ticket['ticket_number']); ?> - E-Asset System</title>
    
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

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-1px);
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
        }

        .btn-icon:hover {
            background: #667eea;
            color: white;
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
        }

        .badge-status {
            background: #e3f2fd;
            color: #1976d2;
        }

        .badge-priority {
            background: #fff3e0;
            color: #f57c00;
        }

        .badge-type {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .badge-open { background: #e3f2fd; color: #1976d2; }
        .badge-in_progress { background: #fff3e0; color: #f57c00; }
        .badge-pending { background: #fff9c4; color: #f57f17; }
        .badge-resolved { background: #e8f5e9; color: #388e3c; }
        .badge-closed { background: #f5f5f5; color: #616161; }
        .badge-low { background: #e8f5e9; color: #388e3c; }
        .badge-medium { background: #fff3e0; color: #f57c00; }
        .badge-high { background: #ffebee; color: #d32f2f; }
        .badge-urgent { background: #f3e5f5; color: #7b1fa2; }
        
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
        
        .action-form {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .action-form select,
        .action-form textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }
        
        .action-form textarea {
            min-height: 80px;
            resize: vertical;
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
        
        .alert-success {
            background: #f0fdf4;
            color: #065f46;
            border: 1px solid #10b981;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
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
    
    <!-- Include Sidebar -->
    <?php include("../auth/inc/sidebar.php"); ?>

    <!-- Main Content Area -->
    <main class="main-content">
        <div class="dashboard-content">
            <header class="page-header">
                <div class="header-left">
                    <h1>Ticket Details</h1>
                    <p>View and manage ticket information</p>
                </div>
                <div class="header-right">
                    <a href="ticket.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Tickets
                    </a>
                    <?php if ($user_role !== 'employee' || $ticket['requester_id'] == $user_id): ?>
                    <a href="edit_ticket.php?id=<?php echo $ticket_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-edit"></i> Edit Ticket
                    </a>
                    <?php endif; ?>
                </div>
            </header>

            <?php if (!empty($success_message)): ?>
            <div class="alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
            <?php endif; ?>

            <div class="ticket-detail-container">
                <!-- Left Column - Main Details -->
                <div>
                    <div class="detail-card">
                        <div class="detail-header">
                            <div class="detail-title">
                                <h2><?php echo htmlspecialchars($ticket['subject']); ?></h2>
                                <div class="detail-meta">
                                    <span class="badge badge-status badge-<?php echo $ticket['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                    </span>
                                    <span class="badge badge-priority badge-<?php echo $ticket['priority']; ?>">
                                        <?php echo ucfirst($ticket['priority']); ?>
                                    </span>
                                    <span class="badge badge-type">
                                        <?php echo ucfirst(str_replace('_', ' ', $ticket['ticket_type'])); ?>
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
                            <?php if ($comment['is_internal'] && $user_role === 'employee') continue; ?>
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
                                    <?php if ($user_role !== 'employee'): ?>
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="is_internal" value="1">
                                        <span>Internal comment (not visible to requester)</span>
                                    </label>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Actions & History -->
                <div>
                    <!-- Status Update -->
                    <?php if ($user_role !== 'employee'): ?>
                    <div class="detail-card sidebar-section">
                        <h3>Update Status</h3>
                        <form method="POST" action="" class="action-form">
                            <input type="hidden" name="action" value="update_status">
                            <select name="status" required>
                                <option value="open" <?php echo $ticket['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                                <option value="in_progress" <?php echo $ticket['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="pending" <?php echo $ticket['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="resolved" <?php echo $ticket['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                <option value="closed" <?php echo $ticket['status'] === 'closed' ? 'selected' : ''; ?>>Closed</option>
                            </select>
                            <textarea name="resolution" placeholder="Resolution notes (required for resolved status)"></textarea>
                            <button type="submit" class="btn btn-primary" style="width: 100%;">
                                <i class="fas fa-save"></i> Update Status
                            </button>
                        </form>
                    </div>

                    <!-- Assign Ticket -->
                    <div class="detail-card sidebar-section">
                        <h3>Assign Ticket</h3>
                        <form method="POST" action="" class="action-form">
                            <input type="hidden" name="action" value="assign_ticket">
                            <select name="assigned_to" required>
                                <option value="">Select User</option>
                                <?php foreach ($assignable_users as $user): ?>
                                <option value="<?php echo $user['user_id']; ?>" 
                                    <?php echo $ticket['assigned_to'] == $user['user_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['name']); ?> (<?php echo ucfirst($user['role']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-secondary" style="width: 100%;">
                                <i class="fas fa-user-check"></i> Assign
                            </button>
                        </form>
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
                                    switch($item['action_type']) {
                                        case 'created':
                                            $action_text = 'Ticket created';
                                            break;
                                        case 'status_changed':
                                            $action_text = 'Status changed to ' . ucfirst(str_replace('_', ' ', $item['new_value']));
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
                                            $action_text = ucfirst($item['action_type']);
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

    <script>
        // Auto-hide success messages after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-success');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Confirm before changing status to resolved/closed
        document.querySelectorAll('form[action=""] select[name="status"]').forEach(select => {
            select.closest('form').addEventListener('submit', function(e) {
                const status = select.value;
                const resolution = this.querySelector('textarea[name="resolution"]');
                
                if (status === 'resolved' && resolution && resolution.value.trim() === '') {
                    e.preventDefault();
                    alert('Please provide resolution notes before marking as resolved.');
                    resolution.focus();
                    return false;
                }
                
                if (status === 'closed') {
                    if (!confirm('Are you sure you want to close this ticket? This action should only be done after the issue is fully resolved.')) {
                        e.preventDefault();
                        return false;
                    }
                }
            });
        });

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