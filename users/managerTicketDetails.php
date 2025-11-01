<?php
session_start();
require_once '../auth/config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Only managers and admins can access this page
if ($user_role === 'employee') {
    header("Location: ../users/userTicket.php");
    exit();
}

// Get ticket ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: ../users/departmentTicket.php");
    exit();
}

$ticket_id = $_GET['id'];

// Get manager info
$manager_query = $pdo->prepare("SELECT first_name, last_name, email, department FROM users WHERE user_id = ?");
$manager_query->execute([$user_id]);
$manager_data = $manager_query->fetch(PDO::FETCH_ASSOC);
$manager_name = $manager_data['first_name'] . ' ' . $manager_data['last_name'];

// Fetch ticket details - ONLY tickets where manager is the requester
$ticket_query = $pdo->prepare("
    SELECT t.*, 
           u.first_name as req_first_name, u.last_name as req_last_name, u.email as req_email,
           a.asset_name, a.asset_code, a.category as asset_category,
           creator.first_name as creator_first_name, creator.last_name as creator_last_name,
           tech.first_name as tech_first_name, tech.last_name as tech_last_name
    FROM tickets t
    LEFT JOIN users u ON t.requester_id = u.user_id
    LEFT JOIN assets a ON t.asset_id = a.id
    LEFT JOIN users creator ON t.created_by = creator.user_id
    LEFT JOIN users tech ON t.assigned_to = tech.user_id
    WHERE t.ticket_id = ? AND t.requester_id = ?
");
$ticket_query->execute([$ticket_id, $user_id]);
$ticket = $ticket_query->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    $_SESSION['error'] = "Ticket not found or you don't have permission to view it.";
    header("Location: ../tickets/tickets.php");
    exit();
}

// Fetch ticket history
$history_query = $pdo->prepare("
    SELECT th.*, u.first_name, u.last_name, u.role
    FROM ticket_history th
    LEFT JOIN users u ON th.performed_by = u.user_id
    WHERE th.ticket_id = ?
    ORDER BY th.created_at DESC
");
$history_query->execute([$ticket_id]);
$history = $history_query->fetchAll(PDO::FETCH_ASSOC);

// Fetch attachments
$attachments_query = $pdo->prepare("
    SELECT ta.*, u.first_name, u.last_name 
    FROM ticket_attachments ta
    LEFT JOIN users u ON ta.uploaded_by = u.user_id
    WHERE ta.ticket_id = ?
    ORDER BY ta.created_at DESC
");
$attachments_query->execute([$ticket_id]);
$attachments = $attachments_query->fetchAll(PDO::FETCH_ASSOC);

// Fetch comments
$comments_query = $pdo->prepare("
    SELECT tc.*, u.first_name, u.last_name, u.role 
    FROM ticket_comments tc
    LEFT JOIN users u ON tc.user_id = u.user_id
    WHERE tc.ticket_id = ?
    ORDER BY tc.created_at ASC
");
$comments_query->execute([$ticket_id]);
$comments = $comments_query->fetchAll(PDO::FETCH_ASSOC);

$success_message = '';
$error_message = '';

// Handle ADD COMMENT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_comment') {
    $comment = trim($_POST['comment']);
    
    if (!empty($comment)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO ticket_comments (ticket_id, user_id, comment, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$ticket_id, $user_id, $comment]);
            
            // Log history
            $history_stmt = $pdo->prepare("
                INSERT INTO ticket_history (ticket_id, action_type, new_value, performed_by, created_at) 
                VALUES (?, 'comment_added', ?, ?, NOW())
            ");
            $history_stmt->execute([$ticket_id, "Comment added by " . $manager_name, $user_id]);
            
            $success_message = "Comment added successfully!";
            
            // Refresh comments
            $comments_query->execute([$ticket_id]);
            $comments = $comments_query->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $error_message = "Error adding comment: " . $e->getMessage();
        }
    } else {
        $error_message = "Comment cannot be empty.";
    }
}

// Format dates
function formatDate($date) {
    return date('M d, Y g:i A', strtotime($date));
}

// Status badge helper
function getStatusBadge($status) {
    $badges = [
        'open' => '<span class="badge badge-info"><i class="fas fa-folder-open"></i> Open</span>',
        'in_progress' => '<span class="badge badge-warning"><i class="fas fa-spinner"></i> In Progress</span>',
        'resolved' => '<span class="badge badge-success"><i class="fas fa-check-circle"></i> Resolved</span>',
        'closed' => '<span class="badge badge-secondary"><i class="fas fa-times-circle"></i> Closed</span>'
    ];
    return $badges[$status] ?? '<span class="badge badge-secondary">' . ucfirst($status) . '</span>';
}

function getApprovalBadge($status) {
    $badges = [
        'pending' => '<span class="badge badge-pending"><i class="fas fa-clock"></i> Pending Approval</span>',
        'approved' => '<span class="badge badge-approved"><i class="fas fa-check"></i> Approved</span>',
        'rejected' => '<span class="badge badge-rejected"><i class="fas fa-times"></i> Rejected</span>'
    ];
    return $badges[$status] ?? '<span class="badge badge-secondary">' . ucfirst($status) . '</span>';
}

function getPriorityBadge($priority) {
    $badges = [
        'low' => '<span class="badge badge-low"><i class="fas fa-arrow-down"></i> Low</span>',
        'medium' => '<span class="badge badge-medium"><i class="fas fa-minus"></i> Medium</span>',
        'high' => '<span class="badge badge-high"><i class="fas fa-arrow-up"></i> High</span>',
        'urgent' => '<span class="badge badge-urgent"><i class="fas fa-exclamation-triangle"></i> Urgent</span>'
    ];
    return $badges[$priority] ?? '<span class="badge badge-secondary">' . ucfirst($priority) . '</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Details - <?php echo htmlspecialchars($ticket['ticket_number']); ?></title>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            color: #2d3748;
        }

        .dashboard-content {
            padding: 2rem;
            min-height: 100vh;
        }

        .page-header {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header-left h1 {
            font-size: 1.75rem;
            color: #2c3e50;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .header-left p {
            color: #6c757d;
            font-size: 0.9rem;
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

        .btn-outline {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .btn-outline:hover {
            background: #667eea;
            color: white;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 24px;
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
        }

        .card-header h2 {
            font-size: 1.25rem;
            color: #2c3e50;
            margin: 0;
        }

        .card-header i {
            color: #667eea;
            font-size: 1.5rem;
        }

        .ticket-number {
            font-size: 2rem;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 1rem;
            font-family: 'Courier New', monospace;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #4a5568;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-value {
            color: #2d3748;
            text-align: right;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .badge-info {
            background: #cfe2ff;
            color: #084298;
        }

        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }

        .badge-success {
            background: #d1e7dd;
            color: #0f5132;
        }

        .badge-secondary {
            background: #e2e3e5;
            color: #41464b;
        }

        .badge-pending {
            background: #fff3cd;
            color: #856404;
        }

        .badge-approved {
            background: #d1e7dd;
            color: #0f5132;
        }

        .badge-rejected {
            background: #f8d7da;
            color: #842029;
        }

        .badge-low {
            background: #d1e7dd;
            color: #0f5132;
        }

        .badge-medium {
            background: #cfe2ff;
            color: #084298;
        }

        .badge-high {
            background: #fff3cd;
            color: #856404;
        }

        .badge-urgent {
            background: #f8d7da;
            color: #842029;
        }

        .description-box {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
            margin: 16px 0;
        }

        .description-box p {
            color: #4a5568;
            line-height: 1.6;
            margin: 0;
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 30px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 8px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e9ecef;
        }

        .timeline-item {
            position: relative;
            margin-bottom: 24px;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -26px;
            top: 5px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #667eea;
            border: 3px solid white;
            box-shadow: 0 0 0 2px #e9ecef;
        }

        .timeline-content {
            background: #f8f9fa;
            padding: 12px 16px;
            border-radius: 8px;
            border-left: 3px solid #667eea;
        }

        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .timeline-user {
            font-weight: 600;
            color: #2c3e50;
        }

        .timeline-date {
            font-size: 0.85rem;
            color: #6c757d;
        }

        .timeline-action {
            color: #4a5568;
            font-size: 0.95rem;
        }

        /* Comments */
        .comment {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            border-left: 3px solid #667eea;
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .comment-author {
            font-weight: 600;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .comment-role {
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 12px;
            background: #667eea;
            color: white;
        }

        .comment-date {
            font-size: 0.85rem;
            color: #6c757d;
        }

        .comment-text {
            color: #4a5568;
            line-height: 1.6;
        }

        .comment-form {
            margin-top: 20px;
        }

        .comment-form textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.95rem;
            resize: vertical;
            min-height: 100px;
        }

        .comment-form textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .comment-form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 12px;
        }

        /* Attachments */
        .attachment-list {
            display: grid;
            gap: 12px;
        }

        .attachment-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }

        .attachment-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .attachment-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #667eea;
            color: white;
            border-radius: 8px;
            font-size: 1.2rem;
        }

        .attachment-details {
            display: flex;
            flex-direction: column;
        }

        .attachment-name {
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.95rem;
        }

        .attachment-meta {
            font-size: 0.8rem;
            color: #6c757d;
        }

        .btn-download {
            padding: 8px 16px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.85rem;
            transition: all 0.3s;
        }

        .btn-download:hover {
            background: #5568d3;
            transform: translateY(-2px);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-content {
                padding: 1rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .info-row {
                flex-direction: column;
                gap: 8px;
            }

            .info-value {
                text-align: left;
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
                    <h1>
                        <i class="fas fa-ticket-alt"></i> 
                        My Ticket Details
                    </h1>
                    <p>Viewing ticket you created</p>
                </div>
                <div class="header-right">
                    <a href="../users/managerTicket.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Tickets
                    </a>
                </div>
            </header>

            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
            </div>
            <?php endif; ?>

            <div class="content-grid">
                <!-- Left Column -->
                <div>
                    <!-- Ticket Details Card -->
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-info-circle"></i>
                            <h2>Ticket Information</h2>
                        </div>

                        <div class="ticket-number">
                            <?php echo htmlspecialchars($ticket['ticket_number']); ?>
                        </div>

                        <div class="info-row">
                            <span class="info-label">
                                <i class="fas fa-tag"></i> Type
                            </span>
                            <span class="info-value">
                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $ticket['ticket_type']))); ?>
                            </span>
                        </div>

                        <div class="info-row">
                            <span class="info-label">
                                <i class="fas fa-flag"></i> Status
                            </span>
                            <span class="info-value">
                                <?php echo getStatusBadge($ticket['status']); ?>
                            </span>
                        </div>

                        <div class="info-row">
                            <span class="info-label">
                                <i class="fas fa-check-circle"></i> Approval
                            </span>
                            <span class="info-value">
                                <?php echo getApprovalBadge($ticket['approval_status']); ?>
                            </span>
                        </div>

                        <div class="info-row">
                            <span class="info-label">
                                <i class="fas fa-exclamation-triangle"></i> Priority
                            </span>
                            <span class="info-value">
                                <?php echo getPriorityBadge($ticket['priority']); ?>
                            </span>
                        </div>

                        <?php if ($ticket['asset_id']): ?>
                        <div class="info-row">
                            <span class="info-label">
                                <i class="fas fa-laptop"></i> Related Asset
                            </span>
                            <span class="info-value">
                                <?php echo htmlspecialchars($ticket['asset_code'] . ' - ' . $ticket['asset_name']); ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <?php if ($ticket['assigned_to']): ?>
                        <div class="info-row">
                            <span class="info-label">
                                <i class="fas fa-user-cog"></i> Assigned To
                            </span>
                            <span class="info-value">
                                <?php echo htmlspecialchars($ticket['tech_first_name'] . ' ' . $ticket['tech_last_name']); ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <div class="info-row">
                            <span class="info-label">
                                <i class="fas fa-calendar-plus"></i> Created
                            </span>
                            <span class="info-value">
                                <?php echo formatDate($ticket['created_at']); ?>
                            </span>
                        </div>

                        <div class="info-row">
                            <span class="info-label">
                                <i class="fas fa-calendar-check"></i> Last Updated
                            </span>
                            <span class="info-value">
                                <?php echo formatDate($ticket['updated_at']); ?>
                            </span>
                        </div>

                        <div style="margin-top: 24px;">
                            <h3 style="margin-bottom: 12px; color: #2c3e50;">
                                <i class="fas fa-align-left"></i> Subject
                            </h3>
                            <p style="font-size: 1.1rem; font-weight: 600; color: #2c3e50;">
                                <?php echo htmlspecialchars($ticket['subject']); ?>
                            </p>
                        </div>

                        <div style="margin-top: 20px;">
                            <h3 style="margin-bottom: 12px; color: #2c3e50;">
                                <i class="fas fa-file-alt"></i> Description
                            </h3>
                            <div class="description-box">
                                <p><?php echo nl2br(htmlspecialchars($ticket['description'])); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Comments Card -->
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-comments"></i>
                            <h2>Comments (<?php echo count($comments); ?>)</h2>
                        </div>

                        <?php if (empty($comments)): ?>
                        <div class="empty-state">
                            <i class="fas fa-comments"></i>
                            <p>No comments yet</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($comments as $comment): ?>
                        <div class="comment">
                            <div class="comment-header">
                                <div class="comment-author">
                                    <i class="fas fa-user-circle"></i>
                                    <?php echo htmlspecialchars($comment['first_name'] . ' ' . $comment['last_name']); ?>
                                    <span class="comment-role"><?php echo strtoupper($comment['role']); ?></span>
                                </div>
                                <span class="comment-date">
                                    <i class="fas fa-clock"></i>
                                    <?php echo formatDate($comment['created_at']); ?>
                                </span>
                            </div>
                            <div class="comment-text">
                                <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Add Comment Form -->
                        <div class="comment-form">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="add_comment">
                                <textarea 
                                    name="comment" 
                                    placeholder="Add a comment to this ticket..."
                                    required></textarea>
                                <div class="comment-form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-paper-plane"></i> Post Comment
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div>
                    <!-- Attachments Card -->
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-paperclip"></i>
                            <h2>Attachments (<?php echo count($attachments); ?>)</h2>
                        </div>

                        <?php if (empty($attachments)): ?>
                        <div class="empty-state">
                            <i class="fas fa-paperclip"></i>
                            <p>No attachments</p>
                        </div>
                        <?php else: ?>
                        <div class="attachment-list">
                            <?php foreach ($attachments as $attachment): ?>
                            <div class="attachment-item">
                                <div class="attachment-info">
                                    <div class="attachment-icon">
                                        <i class="fas fa-file"></i>
                                    </div>
                                    <div class="attachment-details">
                                        <div class="attachment-name">
                                            <?php echo htmlspecialchars($attachment['file_name']); ?>
                                        </div>
                                        <div class="attachment-meta">
                                            <?php echo number_format($attachment['file_size'] / 1024, 2); ?> KB • 
                                            Uploaded by <?php echo htmlspecialchars($attachment['first_name'] . ' ' . $attachment['last_name']); ?>
                                        </div>
                                    </div>
                                </div>
                                <a href="../uploads/tickets/<?php echo htmlspecialchars($attachment['file_path']); ?>" 
                                   class="btn-download" 
                                   download>
                                    <i class="fas fa-download"></i> Download
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- History Card -->
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-history"></i>
                            <h2>Ticket History</h2>
                        </div>

                        <?php if (empty($history)): ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <p>No history yet</p>
                        </div>
                        <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($history as $item): ?>
                            <div class="timeline-item">
                                <div class="timeline-content">
                                    <div class="timeline-header">
                                        <span class="timeline-user">
                                            <i class="fas fa-user"></i>
                                            <?php 
                                            if ($item['first_name']) {
                                                echo htmlspecialchars($item['first_name'] . ' ' . $item['last_name']);
                                            } else {
                                                echo 'System';
                                            }
                                            ?>
                                        </span>
                                        <span class="timeline-date">
                                            <?php echo formatDate($item['created_at']); ?>
                                        </span>
                                    </div>
                                    <div class="timeline-action">
                                        <?php echo htmlspecialchars($item['new_value']); ?>
                                    </div>
                                </div>
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
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Confirm before leaving page if comment is being written
        const commentTextarea = document.querySelector('textarea[name="comment"]');
        if (commentTextarea) {
            let commentStarted = false;
            
            commentTextarea.addEventListener('input', function() {
                if (this.value.trim().length > 0) {
                    commentStarted = true;
                } else {
                    commentStarted = false;
                }
            });

            window.addEventListener('beforeunload', function(e) {
                if (commentStarted) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

            // Reset on form submit
            commentTextarea.closest('form').addEventListener('submit', function() {
                commentStarted = false;
            });
        }

        // Scroll to comments if comment was just added
        <?php if ($success_message === "Comment added successfully!"): ?>
        setTimeout(() => {
            const commentsCard = document.querySelector('.card:has(.comment-form)');
            if (commentsCard) {
                commentsCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }, 100);
        <?php endif; ?>
    </script>
</body>
</html>