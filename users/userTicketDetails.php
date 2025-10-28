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

// SECURITY: Only employees should use this page
if ($user_role !== 'employee') {
    header("Location: ../tickets/view_ticket.php");
    exit();
}

// Get ticket ID
$ticket_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$ticket_id) {
    header("Location: userTicket.php");
    exit();
}

// Fetch ticket details - SECURITY: Only show tickets created by this user
$ticket_query = $pdo->prepare("
    SELECT t.*, 
           u.first_name as requester_first_name, 
           u.last_name as requester_last_name,
           u.email as requester_email,
           a.asset_name, a.asset_code, a.category as asset_category,
           assignee.first_name as assignee_first_name,
           assignee.last_name as assignee_last_name,
           assignee.email as assignee_email
    FROM tickets t
    LEFT JOIN users u ON t.requester_id = u.user_id
    LEFT JOIN assets a ON t.asset_id = a.id
    LEFT JOIN users assignee ON t.assigned_to = assignee.user_id
    WHERE t.ticket_id = ? AND t.requester_id = ?
");
$ticket_query->execute([$ticket_id, $user_id]);
$ticket = $ticket_query->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    $_SESSION['error'] = "Ticket not found or you don't have permission to view it.";
    header("Location: userTicket.php");
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

// Handle comment submission
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    try {
        $comment = trim($_POST['comment']);

        if (empty($comment)) {
            throw new Exception("Comment cannot be empty");
        }

        if (strlen($comment) < 5) {
            throw new Exception("Comment must be at least 5 characters long");
        }

        // Insert comment
        $insert_comment = $pdo->prepare("
            INSERT INTO ticket_comments (ticket_id, user_id, comment, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $insert_comment->execute([$ticket_id, $user_id, $comment]);

        // Update ticket's updated_at timestamp
        $update_ticket = $pdo->prepare("UPDATE tickets SET updated_at = NOW() WHERE ticket_id = ?");
        $update_ticket->execute([$ticket_id]);

        // Log history
        $history_insert = $pdo->prepare("
            INSERT INTO ticket_history (ticket_id, action_type, new_value, performed_by, created_at)
            VALUES (?, 'comment_added', ?, ?, NOW())
        ");
        $history_insert->execute([$ticket_id, "Comment added by requester", $user_id]);

        $_SESSION['success'] = "Comment added successfully";
        header("Location: userTicketDetails.php?id=$ticket_id");
        exit();
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Check for session messages
if (isset($_SESSION['success'])) {
    $success_message = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error_message = $_SESSION['error'];
    unset($_SESSION['error']);
}
if (isset($_SESSION['ticket_created'])) {
    $success_message = "Your ticket has been created successfully! We'll review it shortly.";
    unset($_SESSION['ticket_created']);
}

// Helper functions
function getStatusBadgeClass($status)
{
    $classes = [
        'open' => 'badge-info',
        'in_progress' => 'badge-warning',
        'resolved' => 'badge-success',
        'closed' => 'badge-secondary',
        'cancelled' => 'badge-danger'
    ];
    return $classes[$status] ?? 'badge-secondary';
}

function getApprovalBadgeClass($status)
{
    $classes = [
        'pending' => 'badge-warning',
        'approved' => 'badge-success',
        'rejected' => 'badge-danger'
    ];
    return $classes[$status] ?? 'badge-secondary';
}

function getPriorityBadgeClass($priority)
{
    $classes = [
        'low' => 'priority-low',
        'medium' => 'priority-medium',
        'high' => 'priority-high',
        'urgent' => 'priority-urgent'
    ];
    return $classes[$priority] ?? 'priority-medium';
}

function formatTicketType($type)
{
    $types = [
        'repair' => '🔧 Repair',
        'maintenance' => '⚙️ Maintenance',
        'request_item' => '📦 Request New Item',
        'request_replacement' => '🔄 Request Replacement',
        'inquiry' => '❓ Inquiry',
        'other' => '📝 Other'
    ];
    return $types[$type] ?? ucfirst($type);
}

function timeAgo($datetime)
{
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;

    if ($diff < 60) return "just now";
    if ($diff < 3600) return floor($diff / 60) . " minutes ago";
    if ($diff < 86400) return floor($diff / 3600) . " hours ago";
    if ($diff < 604800) return floor($diff / 86400) . " days ago";

    return date('M d, Y', $timestamp);
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

        .header-left h1 {
            color: #2c3e50;
            margin: 0;
            font-size: 1.75rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .header-left p {
            color: #6c757d;
            margin: 0.5rem 0 0 0;
            font-size: 0.9rem;
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

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        /* Main Layout */
        .ticket-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .ticket-grid {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 1.5rem;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f3f4f6;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Ticket Header Card */
        .ticket-header-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
        }

        .ticket-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .ticket-subject {
            font-size: 1.25rem;
            margin-bottom: 1rem;
            opacity: 0.95;
        }

        .ticket-meta {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .ticket-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Badges */
        .badge {
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-secondary {
            background: #e5e7eb;
            color: #374151;
        }

        .priority-low {
            background: #d1fae5;
            color: #065f46;
        }

        .priority-medium {
            background: #fef3c7;
            color: #92400e;
        }

        .priority-high {
            background: #fed7aa;
            color: #9a3412;
        }

        .priority-urgent {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Ticket Details */
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            color: #6b7280;
            font-size: 0.875rem;
        }

        .detail-value {
            color: #1f2937;
            font-size: 0.875rem;
            text-align: right;
        }

        /* Description */
        .description-content {
            background: #f9fafb;
            padding: 1.5rem;
            border-radius: 8px;
            color: #374151;
            line-height: 1.6;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e5e7eb;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 1.5rem;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-icon {
            position: absolute;
            left: -1.5rem;
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            background: white;
            border: 3px solid #667eea;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            color: #667eea;
        }

        .timeline-content {
            background: #f9fafb;
            padding: 1rem;
            border-radius: 8px;
            border-left: 3px solid #667eea;
        }

        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .timeline-user {
            font-weight: 600;
            color: #1f2937;
        }

        .timeline-time {
            font-size: 0.75rem;
            color: #6b7280;
        }

        .timeline-action {
            color: #4b5563;
            font-size: 0.875rem;
        }

        /* Comments */
        .comment {
            background: #f9fafb;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border-left: 3px solid #667eea;
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .comment-user {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .comment-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .comment-name {
            font-weight: 600;
            color: #1f2937;
        }

        .comment-role {
            font-size: 0.75rem;
            color: #6b7280;
            text-transform: capitalize;
        }

        .comment-time {
            font-size: 0.75rem;
            color: #6b7280;
        }

        .comment-text {
            color: #374151;
            line-height: 1.6;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .no-comments {
            text-align: center;
            padding: 2rem;
            color: #6b7280;
        }

        /* Comment Form */
        .comment-form {
            margin-top: 1.5rem;
        }

        .comment-form textarea {
            width: 100%;
            min-height: 120px;
            padding: 0.75rem;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.875rem;
            resize: vertical;
            transition: all 0.3s ease;
        }

        .comment-form textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .comment-form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 0.75rem;
        }

        /* Attachments */
        .attachment-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .attachment-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem;
            background: #f9fafb;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            transition: all 0.2s;
        }

        .attachment-item:hover {
            background: #f3f4f6;
            border-color: #667eea;
        }

        .attachment-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.25rem;
        }

        .attachment-info {
            flex: 1;
        }

        .attachment-name {
            font-weight: 600;
            color: #1f2937;
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .attachment-meta {
            font-size: 0.75rem;
            color: #6b7280;
        }

        .attachment-download {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .attachment-download:hover {
            background: #f0f3ff;
        }

        .no-attachments {
            text-align: center;
            padding: 2rem;
            color: #6b7280;
        }

        /* Info Box */
        .info-box {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            display: flex;
            gap: 0.75rem;
        }

        .info-box i {
            color: #0284c7;
            margin-top: 0.25rem;
        }

        .info-box-content {
            color: #075985;
            font-size: 0.875rem;
            line-height: 1.5;
        }

        /* Status Progress */
        .status-progress {
            margin-top: 1.5rem;
        }

        .progress-steps {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin-bottom: 0.5rem;
        }

        .progress-steps::before {
            content: '';
            position: absolute;
            top: 1rem;
            left: 0;
            right: 0;
            height: 2px;
            background: #e5e7eb;
            z-index: 0;
        }

        .progress-step {
            position: relative;
            text-align: center;
            flex: 1;
            z-index: 1;
        }

        .progress-step-icon {
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            background: white;
            border: 2px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-size: 0.875rem;
            color: #9ca3af;
        }

        .progress-step.active .progress-step-icon {
            background: #667eea;
            border-color: #667eea;
            color: white;
        }

        .progress-step.completed .progress-step-icon {
            background: #10b981;
            border-color: #10b981;
            color: white;
        }

        .progress-step-label {
            font-size: 0.75rem;
            color: #6b7280;
            font-weight: 500;
        }

        .progress-step.active .progress-step-label {
            color: #667eea;
            font-weight: 600;
        }

        .progress-step.completed .progress-step-label {
            color: #10b981;
        }

        @media (max-width: 1024px) {
            .ticket-grid {
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

            .ticket-header-card {
                padding: 1.5rem;
            }

            .ticket-number {
                font-size: 1.5rem;
            }

            .ticket-meta {
                flex-direction: column;
                gap: 0.75rem;
            }

            .card {
                padding: 1rem;
            }
        }
    </style>
</head>

<body>
    <?php include("../auth/inc/Usidebar.php"); ?>

    <main class="main-content">
        <div class="dashboard-content">
            <header class="page-header">
                <div class="header-left">
                    <h1>
                        <i class="fas fa-ticket-alt"></i>
                        Ticket Details
                    </h1>
                    <p>View and manage your support ticket</p>
                </div>
                <div>
                    <a href="userTicket.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Tickets
                    </a>
                </div>
            </header>

            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo htmlspecialchars($success_message); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo htmlspecialchars($error_message); ?></div>
                </div>
            <?php endif; ?>

            <div class="ticket-container">
                <!-- Ticket Header -->
                <div class="card ticket-header-card">
                    <div class="ticket-number">#<?php echo htmlspecialchars($ticket['ticket_number']); ?></div>
                    <div class="ticket-subject"><?php echo htmlspecialchars($ticket['subject']); ?></div>
                    <div class="ticket-meta">
                        <div class="ticket-meta-item">
                            <i class="fas fa-calendar"></i>
                            Created <?php echo timeAgo($ticket['created_at']); ?>
                        </div>
                        <div class="ticket-meta-item">
                            <i class="fas fa-clock"></i>
                            Updated <?php echo timeAgo($ticket['updated_at']); ?>
                        </div>
                        <div class="ticket-meta-item">
                            <i class="fas fa-tag"></i>
                            <?php echo formatTicketType($ticket['ticket_type']); ?>
                        </div>
                    </div>
                </div>

                <div class="ticket-grid">
                    <!-- Main Content -->
                    <div>
                        <!-- Description -->
                        <div class="card">
                            <div class="card-header">
                                <h2 class="card-title">
                                    <i class="fas fa-align-left"></i>
                                    Description
                                </h2>
                            </div>
                            <div class="description-content">
                                <?php echo nl2br(htmlspecialchars($ticket['description'])); ?>
                            </div>
                        </div>

                        <!-- Attachments -->
                        <?php if (count($attachments) > 0): ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="fas fa-paperclip"></i>
            Attachments (<?php echo count($attachments); ?>)
        </h2>
    </div>
    <div class="attachment-list">
        <?php foreach ($attachments as $attachment): ?>
        <?php
        // Determine which field to use for the actual file
        $file_to_use = '';
        
        if (!empty($attachment['file_path'])) {
            $file_to_use = $attachment['file_path'];
        } elseif (!empty($attachment['file_name'])) {
            $file_to_use = $attachment['file_name'];
        } else {
            continue; // Skip if no file reference exists
        }
        
        // Clean up - remove full server path if present
        if (strpos($file_to_use, ':\\') !== false || strpos($file_to_use, '/uploads/') !== false) {
            $file_to_use = basename($file_to_use);
        }
        
        // Get display name
        $display_name = !empty($attachment['original_name']) ? $attachment['original_name'] : $attachment['file_name'];
        if (strpos($display_name, ':\\') !== false) {
            $display_name = basename($display_name);
        }
        
        // Get file size
        $file_size_kb = 0;
        if (isset($attachment['file_size']) && $attachment['file_size'] > 0) {
            $file_size_kb = $attachment['file_size'] / 1024;
        } else {
            $full_path = "../uploads/tickets/" . $file_to_use;
            if (file_exists($full_path)) {
                $file_size_kb = filesize($full_path) / 1024;
            }
        }
        
        // Determine icon
        $ext = strtolower(pathinfo($file_to_use, PATHINFO_EXTENSION));
        $icon = 'fa-file';
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) $icon = 'fa-file-image';
        elseif (in_array($ext, ['pdf'])) $icon = 'fa-file-pdf';
        elseif (in_array($ext, ['doc', 'docx'])) $icon = 'fa-file-word';
        elseif (in_array($ext, ['xls', 'xlsx'])) $icon = 'fa-file-excel';
        
        // Get uploader name
        $uploader = !empty($attachment['uploaded_by_name']) ? $attachment['uploaded_by_name'] : 'Unknown';
        
        // Get upload date
        $upload_date = !empty($attachment['uploaded_at']) ? date('M d, Y', strtotime($attachment['uploaded_at'])) : 'Unknown date';
        ?>
        <div class="attachment-item">
            <div class="attachment-icon">
                <i class="fas <?php echo $icon; ?>"></i>
            </div>
            <div class="attachment-info">
                <div class="attachment-name">
                    <?php echo htmlspecialchars($display_name); ?>
                </div>
                <div class="attachment-meta">
                    <?php if ($file_size_kb > 0): ?>
                        <?php echo number_format($file_size_kb, 2); ?> KB • 
                    <?php endif; ?>
                    Uploaded by <?php echo htmlspecialchars($uploader); ?> • 
                    <?php echo $upload_date; ?>
                </div>
            </div>
            <a href="../uploads/tickets/<?php echo htmlspecialchars($file_to_use); ?>" 
               class="attachment-download" 
               download 
               target="_blank">
                <i class="fas fa-download"></i> Download
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

                        <!-- Comments -->
                        <div class="card">
                            <div class="card-header">
                                <h2 class="card-title">
                                    <i class="fas fa-comments"></i>
                                    Comments (<?php echo count($comments); ?>)
                                </h2>
                            </div>

                            <?php if (count($comments) > 0): ?>
                                <?php foreach ($comments as $comment): ?>
                                    <div class="comment">
                                        <div class="comment-header">
                                            <div class="comment-user">
                                                <div class="comment-avatar">
                                                    <?php echo strtoupper(substr($comment['first_name'], 0, 1) . substr($comment['last_name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div class="comment-name">
                                                        <?php echo htmlspecialchars($comment['first_name'] . ' ' . $comment['last_name']); ?>
                                                    </div>
                                                    <div class="comment-role"><?php echo htmlspecialchars($comment['role']); ?></div>
                                                </div>
                                            </div>
                                            <div class="comment-time">
                                                <?php echo timeAgo($comment['created_at']); ?>
                                            </div>
                                        </div>
                                        <div class="comment-text">
                                            <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="no-comments">
                                    <i class="fas fa-comments" style="font-size: 3rem; color: #d1d5db; margin-bottom: 1rem;"></i>
                                    <p>No comments yet. Be the first to add one!</p>
                                </div>
                            <?php endif; ?>

                            <!-- Add Comment Form -->
                            <div class="comment-form">
                                <form method="POST" action="">
                                    <textarea
                                        name="comment"
                                        placeholder="Add a comment or ask a question about this ticket..."
                                        required
                                        minlength="5"></textarea>
                                    <div class="comment-form-actions">
                                        <button type="submit" name="add_comment" class="btn btn-primary">
                                            <i class="fas fa-paper-plane"></i> Add Comment
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Activity History -->
                        <div class="card">
                            <div class="card-header">
                                <h2 class="card-title">
                                    <i class="fas fa-history"></i>
                                    Activity History
                                </h2>
                            </div>
                            <div class="timeline">
                                <?php foreach ($history as $index => $item): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-icon">
                                            <?php
                                            $icon_map = [
                                                'created' => 'fa-plus',
                                                'status_changed' => 'fa-exchange-alt',
                                                'assigned' => 'fa-user-check',
                                                'comment_added' => 'fa-comment',
                                                'priority_changed' => 'fa-flag',
                                                'approval_changed' => 'fa-check-circle'
                                            ];
                                            $icon = $icon_map[$item['action_type']] ?? 'fa-circle';
                                            ?>
                                            <i class="fas <?php echo $icon; ?>"></i>
                                        </div>
                                        <div class="timeline-content">
                                            <div class="timeline-header">
                                                <span class="timeline-user">
                                                    <?php echo htmlspecialchars($item['first_name'] . ' ' . $item['last_name']); ?>
                                                </span>
                                                <span class="timeline-time">
                                                    <?php echo timeAgo($item['created_at']); ?>
                                                </span>
                                            </div>
                                            <div class="timeline-action">
                                                <?php echo htmlspecialchars($item['new_value']); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div>
                        <!-- Ticket Status -->
                        <div class="card">
                            <div class="card-header">
                                <h2 class="card-title">
                                    <i class="fas fa-info-circle"></i>
                                    Ticket Status
                                </h2>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Status</span>
                                <span class="detail-value">
                                    <span class="badge <?php echo getStatusBadgeClass($ticket['status']); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                    </span>
                                </span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Approval</span>
                                <span class="detail-value">
                                    <span class="badge <?php echo getApprovalBadgeClass($ticket['approval_status']); ?>">
                                        <?php echo ucfirst($ticket['approval_status']); ?>
                                    </span>
                                </span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Priority</span>
                                <span class="detail-value">
                                    <span class="badge <?php echo getPriorityBadgeClass($ticket['priority']); ?>">
                                        <?php echo ucfirst($ticket['priority']); ?>
                                    </span>
                                </span>
                            </div>

                            <div class="status-progress">
                                <div class="progress-steps">
                                    <div class="progress-step <?php echo in_array($ticket['status'], ['open', 'in_progress', 'resolved', 'closed']) ? 'completed' : ''; ?>">
                                        <div class="progress-step-icon">
                                            <i class="fas fa-file-alt"></i>
                                        </div>
                                        <div class="progress-step-label">Open</div>
                                    </div>
                                    <div class="progress-step <?php echo in_array($ticket['status'], ['in_progress', 'resolved', 'closed']) ? 'active' : ''; ?> <?php echo in_array($ticket['status'], ['resolved', 'closed']) ? 'completed' : ''; ?>">
                                        <div class="progress-step-icon">
                                            <i class="fas fa-tasks"></i>
                                        </div>
                                        <div class="progress-step-label">In Progress</div>
                                    </div>
                                    <div class="progress-step <?php echo $ticket['status'] === 'resolved' ? 'active' : ''; ?> <?php echo $ticket['status'] === 'closed' ? 'completed' : ''; ?>">
                                        <div class="progress-step-icon">
                                            <i class="fas fa-check"></i>
                                        </div>
                                        <div class="progress-step-label">Resolved</div>
                                    </div>
                                    <div class="progress-step <?php echo $ticket['status'] === 'closed' ? 'active completed' : ''; ?>">
                                        <div class="progress-step-icon">
                                            <i class="fas fa-check-double"></i>
                                        </div>
                                        <div class="progress-step-label">Closed</div>
                                    </div>
                                </div>
                            </div>

                            <?php if ($ticket['approval_status'] === 'pending'): ?>
                                <div class="info-box">
                                    <i class="fas fa-clock"></i>
                                    <div class="info-box-content">
                                        Your ticket is awaiting approval from management. You'll be notified once it's reviewed.
                                    </div>
                                </div>
                            <?php elseif ($ticket['approval_status'] === 'rejected'): ?>
                                <div class="info-box" style="background: #fef2f2; border-color: #fecaca;">
                                    <i class="fas fa-times-circle" style="color: #dc2626;"></i>
                                    <div class="info-box-content" style="color: #991b1b;">
                                        This ticket has been rejected. Please check the comments for more information or contact your manager.
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Ticket Details -->
                        <div class="card">
                            <div class="card-header">
                                <h2 class="card-title">
                                    <i class="fas fa-clipboard-list"></i>
                                    Details
                                </h2>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Ticket Number</span>
                                <span class="detail-value"><?php echo htmlspecialchars($ticket['ticket_number']); ?></span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Type</span>
                                <span class="detail-value"><?php echo formatTicketType($ticket['ticket_type']); ?></span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Department</span>
                                <span class="detail-value"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $ticket['requester_department']))); ?></span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Created</span>
                                <span class="detail-value"><?php echo date('M d, Y', strtotime($ticket['created_at'])); ?></span>
                            </div>

                            <div class="detail-row">
                                <span class="detail-label">Last Updated</span>
                                <span class="detail-value"><?php echo date('M d, Y', strtotime($ticket['updated_at'])); ?></span>
                            </div>

                            <?php if ($ticket['asset_id']): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Related Asset</span>
                                    <span class="detail-value">
                                        <?php echo htmlspecialchars($ticket['asset_code']); ?>
                                        <br>
                                        <small style="color: #6b7280;">
                                            <?php echo htmlspecialchars($ticket['asset_name']); ?>
                                        </small>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if ($ticket['assigned_to']): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Assigned To</span>
                                    <span class="detail-value">
                                        <?php echo htmlspecialchars($ticket['assignee_first_name'] . ' ' . $ticket['assignee_last_name']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($ticket['resolved_at'])): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Resolved On</span>
                                    <span class="detail-value"><?php echo date('M d, Y', strtotime($ticket['resolved_at'])); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($ticket['resolution_notes'])): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Resolution Notes</span>
                                    <span class="detail-value">
                                        <small><?php echo htmlspecialchars($ticket['resolution_notes']); ?></small>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Quick Actions -->
                        <div class="card">
                            <div class="card-header">
                                <h2 class="card-title">
                                    <i class="fas fa-bolt"></i>
                                    Quick Actions
                                </h2>
                            </div>

                            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                                <a href="userTicket.php" class="btn btn-outline" style="width: 100%; justify-content: center;">
                                    <i class="fas fa-list"></i> View All Tickets
                                </a>
                                <a href="createUserTicket.php" class="btn btn-primary" style="width: 100%; justify-content: center;">
                                    <i class="fas fa-plus"></i> Create New Ticket
                                </a>
                            </div>

                            <div class="info-box" style="margin-top: 1rem; margin-bottom: 0;">
                                <i class="fas fa-question-circle"></i>
                                <div class="info-box-content">
                                    Need help? Add a comment above or contact IT support directly.
                                </div>
                            </div>
                        </div>
                    </div>