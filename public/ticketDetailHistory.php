<?php
session_start();
require_once '../auth/config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'employee';

// Get ticket ID
$ticket_id = $_GET['id'] ?? 0;

if (!$ticket_id) {
    header("Location: ticketHistory.php");
    exit();
}

// Fetch ticket details with all related information
$query = "
    SELECT 
        t.*,
        CONCAT(requester.first_name, ' ', requester.last_name) as requester_name,
        requester.email as requester_email,
        requester.department as requester_department,
        requester.phone as requester_phone,
        CONCAT(assigned.first_name, ' ', assigned.last_name) as assigned_to_name,
        assigned.email as assigned_email,
        CONCAT(resolver.first_name, ' ', resolver.last_name) as resolved_by_name,
        CONCAT(closer.first_name, ' ', closer.last_name) as closed_by_name,
        a.asset_name,
        a.asset_code,
        a.category as asset_category,
        a.serial_number,
        a.brand,
        a.model
    FROM tickets t
    JOIN users requester ON t.requester_id = requester.user_id
    LEFT JOIN users assigned ON t.assigned_to = assigned.user_id
    LEFT JOIN users resolver ON t.resolved_by = resolver.user_id
    LEFT JOIN users closer ON t.closed_by = closer.user_id
    LEFT JOIN assets a ON t.asset_id = a.id
    WHERE t.ticket_id = ?
";

$stmt = $pdo->prepare($query);
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    header("Location: ticketHistory.php");
    exit();
}

// Check permissions
if ($user_role === 'employee' && $ticket['requester_id'] != $user_id) {
    header("Location: ticketHistory.php");
    exit();
}

// Fetch ticket comments/updates history
$history = [];
try {
    // Check if ticket_comments table exists
    $check_table = $pdo->query("SHOW TABLES LIKE 'ticket_comments'");
    if ($check_table->rowCount() > 0) {
        $history_query = "
            SELECT 
                tc.*,
                CONCAT(u.first_name, ' ', u.last_name) as user_name,
                u.role as user_role
            FROM ticket_comments tc
            JOIN users u ON tc.user_id = u.user_id
            WHERE tc.ticket_id = ?
            ORDER BY tc.created_at ASC
        ";

        $history_stmt = $pdo->prepare($history_query);
        $history_stmt->execute([$ticket_id]);
        $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Table doesn't exist or query failed, continue without history
    $history = [];
}

// Fix for ticketDetailHistory.php - Replace the attachments fetch section (around line 97-115)

$attachments = [];
try {
    $check_table = $pdo->query("SHOW TABLES LIKE 'ticket_attachments'");
    if ($check_table->rowCount() > 0) {
        $attachments_query = "
            SELECT 
                ta.attachment_id,
                ta.ticket_id,
                ta.file_name,
                ta.file_path,
                ta.file_size,
                ta.file_type,
                ta.file_name as original_name,
                ta.created_at as uploaded_at,
                ta.uploaded_by,
                CONCAT(u.first_name, ' ', u.last_name) as uploaded_by_name
            FROM ticket_attachments ta
            LEFT JOIN users u ON ta.uploaded_by = u.user_id
            WHERE ta.ticket_id = ?
            ORDER BY ta.attachment_id DESC
        ";

        $attachments_stmt = $pdo->prepare($attachments_query);
        $attachments_stmt->execute([$ticket_id]);
        $attachments = $attachments_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $attachments = [];
    error_log("Attachments query error: " . $e->getMessage());
}

// Create timeline events
$timeline = [];

// Initial creation
$timeline[] = [
    'type' => 'created',
    'title' => 'Ticket Created',
    'description' => 'Ticket was created by ' . $ticket['requester_name'],
    'user' => $ticket['requester_name'],
    'role' => 'Requester',
    'timestamp' => $ticket['created_at'],
    'icon' => 'fa-plus-circle',
    'color' => 'blue'
];

// Approval events
if ($ticket['approval_status'] === 'approved' && !empty($ticket['approved_at'])) {
    $approver_name = 'Manager';
    // Try to get approver name from ticket data if column exists
    if (isset($ticket['approved_by'])) {
        $approver_query = "SELECT CONCAT(first_name, ' ', last_name) as name FROM users WHERE user_id = ?";
        $approver_stmt = $pdo->prepare($approver_query);
        $approver_stmt->execute([$ticket['approved_by']]);
        $approver_result = $approver_stmt->fetch(PDO::FETCH_ASSOC);
        if ($approver_result) {
            $approver_name = $approver_result['name'];
        }
    }

    $timeline[] = [
        'type' => 'approved',
        'title' => 'Ticket Approved',
        'description' => 'Ticket was approved by ' . $approver_name,
        'user' => $approver_name,
        'role' => 'Manager',
        'timestamp' => $ticket['approved_at'],
        'icon' => 'fa-check-circle',
        'color' => 'green'
    ];
}

if ($ticket['approval_status'] === 'rejected' && !empty($ticket['rejected_at'])) {
    $rejecter_name = 'Manager';
    // Try to get rejecter name from ticket data if column exists
    if (isset($ticket['rejected_by'])) {
        $rejecter_query = "SELECT CONCAT(first_name, ' ', last_name) as name FROM users WHERE user_id = ?";
        $rejecter_stmt = $pdo->prepare($rejecter_query);
        $rejecter_stmt->execute([$ticket['rejected_by']]);
        $rejecter_result = $rejecter_stmt->fetch(PDO::FETCH_ASSOC);
        if ($rejecter_result) {
            $rejecter_name = $rejecter_result['name'];
        }
    }

    $timeline[] = [
        'type' => 'rejected',
        'title' => 'Ticket Rejected',
        'description' => 'Ticket was rejected by ' . $rejecter_name,
        'user' => $rejecter_name,
        'role' => 'Manager',
        'timestamp' => $ticket['rejected_at'],
        'reason' => $ticket['rejection_reason'] ?? 'No reason provided',
        'icon' => 'fa-times-circle',
        'color' => 'red'
    ];
}

// Assignment event
if ($ticket['assigned_to'] && $ticket['assigned_at']) {
    $timeline[] = [
        'type' => 'assigned',
        'title' => 'Ticket Assigned',
        'description' => 'Ticket was assigned to ' . $ticket['assigned_to_name'],
        'user' => $ticket['assigned_to_name'],
        'role' => 'Admin',
        'timestamp' => $ticket['assigned_at'],
        'icon' => 'fa-user-check',
        'color' => 'purple'
    ];
}

// Status changes from history
foreach ($history as $item) {
    if (isset($item['is_status_change']) && $item['is_status_change']) {
        $timeline[] = [
            'type' => 'status_change',
            'title' => 'Status Changed',
            'description' => $item['comment'],
            'user' => $item['user_name'],
            'role' => ucfirst($item['user_role']),
            'timestamp' => $item['created_at'],
            'icon' => 'fa-exchange-alt',
            'color' => 'orange'
        ];
    } else {
        $timeline[] = [
            'type' => 'comment',
            'title' => 'Comment Added',
            'description' => $item['comment'] ?? '',
            'user' => $item['user_name'] ?? 'Unknown',
            'role' => isset($item['user_role']) ? ucfirst($item['user_role']) : 'User',
            'timestamp' => $item['created_at'],
            'icon' => 'fa-comment',
            'color' => 'gray'
        ];
    }
}

// Resolution event
if ($ticket['status'] === 'resolved' && $ticket['resolved_at']) {
    $timeline[] = [
        'type' => 'resolved',
        'title' => 'Ticket Resolved',
        'description' => 'Ticket was marked as resolved',
        'user' => $ticket['resolved_by_name'] ?? 'System',
        'role' => 'Admin',
        'timestamp' => $ticket['resolved_at'],
        'resolution' => $ticket['resolution_notes'] ?? '',
        'icon' => 'fa-check-double',
        'color' => 'green'
    ];
}

// Closure event
if ($ticket['status'] === 'closed' && $ticket['closed_at']) {
    $timeline[] = [
        'type' => 'closed',
        'title' => 'Ticket Closed',
        'description' => 'Ticket was closed',
        'user' => $ticket['closed_by_name'] ?? 'System',
        'role' => 'Admin',
        'timestamp' => $ticket['closed_at'],
        'icon' => 'fa-lock',
        'color' => 'gray'
    ];
}

// Sort timeline by timestamp
usort($timeline, function ($a, $b) {
    return strtotime($a['timestamp']) - strtotime($b['timestamp']);
});

// Calculate resolution time
$resolution_time = null;
if ($ticket['resolved_at']) {
    $created = new DateTime($ticket['created_at']);
    $resolved = new DateTime($ticket['resolved_at']);
    $interval = $created->diff($resolved);

    $resolution_time = '';
    if ($interval->d > 0) $resolution_time .= $interval->d . ' days ';
    if ($interval->h > 0) $resolution_time .= $interval->h . ' hours ';
    if ($interval->i > 0) $resolution_time .= $interval->i . ' minutes';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket History - <?php echo htmlspecialchars($ticket['ticket_number']); ?></title>

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
            font-size: 1.75rem;
            font-weight: 700;
        }

        .ticket-number {
            color: #667eea;
            font-size: 1.5rem;
        }

        .page-header p {
            color: #6c757d;
            margin: 0.25rem 0 0 0;
            font-size: 0.95rem;
        }

        .header-actions {
            display: flex;
            gap: 0.75rem;
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

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .main-column {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        .content-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f0f0f0;
        }

        .card-header h2 {
            margin: 0;
            font-size: 1.25rem;
            color: #2d3748;
            font-weight: 600;
        }

        .card-header i {
            color: #667eea;
            font-size: 1.25rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.25rem;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .info-label {
            font-size: 0.75rem;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .info-value {
            font-size: 0.95rem;
            color: #2d3748;
            font-weight: 500;
        }

        .badge {
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
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
            background: #fff9c4;
            color: #f57f17;
        }

        .badge-resolved {
            background: #e8f5e9;
            color: #388e3c;
        }

        .badge-closed {
            background: #f5f5f5;
            color: #616161;
        }

        .badge-approved {
            background: #e8f5e9;
            color: #388e3c;
        }

        .badge-rejected {
            background: #ffebee;
            color: #d32f2f;
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

        .description-box {
            background: #f7fafc;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #667eea;
            margin: 1rem 0;
        }

        .description-box p {
            margin: 0;
            color: #2d3748;
            line-height: 1.6;
        }

        /* Timeline Styles */
        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 8px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 2rem;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-marker {
            position: absolute;
            left: -1.6rem;
            top: 0;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 0 0 2px currentColor;
        }

        .timeline-marker.blue {
            color: #4299e1;
        }

        .timeline-marker.green {
            color: #48bb78;
        }

        .timeline-marker.red {
            color: #f56565;
        }

        .timeline-marker.purple {
            color: #9f7aea;
        }

        .timeline-marker.orange {
            color: #ed8936;
        }

        .timeline-marker.gray {
            color: #a0aec0;
        }

        .timeline-content {
            background: #f7fafc;
            padding: 1rem;
            border-radius: 8px;
            border-left: 3px solid currentColor;
        }

        .timeline-content.blue {
            border-color: #4299e1;
        }

        .timeline-content.green {
            border-color: #48bb78;
        }

        .timeline-content.red {
            border-color: #f56565;
        }

        .timeline-content.purple {
            border-color: #9f7aea;
        }

        .timeline-content.orange {
            border-color: #ed8936;
        }

        .timeline-content.gray {
            border-color: #a0aec0;
        }

        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .timeline-title {
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .timeline-timestamp {
            font-size: 0.75rem;
            color: #718096;
        }

        .timeline-user {
            font-size: 0.875rem;
            color: #4a5568;
            margin-bottom: 0.25rem;
        }

        .timeline-description {
            font-size: 0.875rem;
            color: #2d3748;
            line-height: 1.5;
        }

        .rejection-reason {
            background: #fff5f5;
            padding: 0.75rem;
            border-radius: 6px;
            margin-top: 0.5rem;
            border-left: 3px solid #f56565;
        }

        .rejection-reason strong {
            color: #c53030;
            display: block;
            margin-bottom: 0.25rem;
        }

        /* Attachments */
        .attachments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
        }

        .attachment-card {
            background: #f7fafc;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .attachment-card:hover {
            background: #edf2f7;
            transform: translateY(-2px);
        }

        .attachment-icon {
            font-size: 2rem;
            color: #667eea;
            margin-bottom: 0.5rem;
        }

        .attachment-name {
            font-size: 0.875rem;
            color: #2d3748;
            margin-bottom: 0.25rem;
            word-break: break-all;
        }

        .attachment-meta {
            font-size: 0.75rem;
            color: #718096;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-top: 1rem;
        }

        .stat-box {
            background: #f7fafc;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #667eea;
        }

        .stat-label {
            font-size: 0.75rem;
            color: #718096;
            text-transform: uppercase;
            margin-top: 0.25rem;
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

            .info-grid {
                grid-template-columns: 1fr;
            }

            .stats-row {
                grid-template-columns: 1fr;
            }
        }

        @media print {

            .header-actions,
            .btn {
                display: none;
            }
        }
    </style>
</head>

<body>

    <?php include("../auth/inc/sidebar.php"); ?>

    <main class="main-content">
        <div class="dashboard-content">
            <header class="page-header">
                <div class="header-left">
                    <h1>
                        <i class="fas fa-history"></i>
                        Ticket History
                    </h1>
                    <p class="ticket-number"><?php echo htmlspecialchars($ticket['ticket_number']); ?></p>
                </div>
                <div class="header-actions">
                    <a href="ticketHistory.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                    <a href="ticketDetails.php?id=<?php echo $ticket_id; ?>" class="btn btn-outline">
                        <i class="fas fa-eye"></i> View Ticket
                    </a>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </header>

            <div class="content-grid">
                <!-- Main Column -->
                <div class="main-column">
                    <!-- Ticket Overview -->
                    <div class="content-card">
                        <div class="card-header">
                            <i class="fas fa-info-circle"></i>
                            <h2>Ticket Overview</h2>
                        </div>

                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Subject</span>
                                <span class="info-value"><?php echo htmlspecialchars($ticket['subject']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Type</span>
                                <span class="info-value">
                                    <?php echo ucfirst(str_replace('_', ' ', $ticket['ticket_type'])); ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Priority</span>
                                <span class="badge badge-<?php echo $ticket['priority']; ?>">
                                    <?php echo ucfirst($ticket['priority']); ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Status</span>
                                <span class="badge badge-<?php echo $ticket['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Approval Status</span>
                                <span class="badge badge-<?php echo $ticket['approval_status']; ?>">
                                    <?php echo ucfirst($ticket['approval_status']); ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Created</span>
                                <span class="info-value">
                                    <?php echo date('M d, Y h:i A', strtotime($ticket['created_at'])); ?>
                                </span>
                            </div>
                        </div>

                        <div class="description-box">
                            <strong style="display: block; margin-bottom: 0.5rem;">Description:</strong>
                            <p><?php echo nl2br(htmlspecialchars($ticket['description'])); ?></p>
                        </div>

                        <?php if ($resolution_time): ?>
                            <div class="stats-row">
                                <div class="stat-box">
                                    <div class="stat-value"><?php echo $resolution_time; ?></div>
                                    <div class="stat-label">Resolution Time</div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-value"><?php echo count($history); ?></div>
                                    <div class="stat-label">Total Updates</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Timeline -->
                    <div class="content-card">
                        <div class="card-header">
                            <i class="fas fa-stream"></i>
                            <h2>Activity Timeline</h2>
                        </div>

                        <div class="timeline">
                            <?php foreach ($timeline as $event): ?>
                                <div class="timeline-item">
                                    <div class="timeline-marker <?php echo $event['color']; ?>"></div>
                                    <div class="timeline-content <?php echo $event['color']; ?>">
                                        <div class="timeline-header">
                                            <div class="timeline-title">
                                                <i class="fas <?php echo $event['icon']; ?>"></i>
                                                <?php echo $event['title']; ?>
                                            </div>
                                            <div class="timeline-timestamp">
                                                <?php echo date('M d, Y h:i A', strtotime($event['timestamp'])); ?>
                                            </div>
                                        </div>
                                        <div class="timeline-user">
                                            <strong><?php echo htmlspecialchars($event['user']); ?></strong>
                                            <span style="color: #718096;">• <?php echo $event['role']; ?></span>
                                        </div>
                                        <div class="timeline-description">
                                            <?php echo nl2br(htmlspecialchars($event['description'])); ?>
                                        </div>
                                        <?php if (isset($event['reason'])): ?>
                                            <div class="rejection-reason">
                                                <strong>Reason:</strong>
                                                <?php echo nl2br(htmlspecialchars($event['reason'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (isset($event['resolution']) && $event['resolution']): ?>
                                            <div style="background: #e6fffa; padding: 0.75rem; border-radius: 6px; margin-top: 0.5rem;">
                                                <strong style="color: #2c7a7b; display: block; margin-bottom: 0.25rem;">Resolution Notes:</strong>
                                                <?php echo nl2br(htmlspecialchars($event['resolution'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Attachments -->
                    <?php if (!empty($attachments)): ?>
                        <div class="content-card">
                            <div class="card-header">
                                <i class="fas fa-paperclip"></i>
                                <h2>Attachments (<?php echo count($attachments); ?>)</h2>
                            </div>

                            <div class="attachments-grid">
                                <?php foreach ($attachments as $attachment): ?>
                                    <?php
                                    // Clean up file_path - remove full server path if present
                                    $file_to_use = $attachment['file_path'];

                                    // If file_path contains full Windows path, extract just the filename
                                    if (strpos($file_to_use, ':\\') !== false || strpos($file_to_use, 'uploads') !== false) {
                                        $file_to_use = basename($file_to_use);
                                    }

                                    // Get original name for display
                                    $display_name = $attachment['file_name'];
                                    if (strpos($display_name, ':\\') !== false) {
                                        $display_name = basename($display_name);
                                    }

                                    // Determine file icon based on extension
                                    $ext = strtolower(pathinfo($file_to_use, PATHINFO_EXTENSION));
                                    $icon = 'fa-file';
                                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) $icon = 'fa-file-image';
                                    elseif (in_array($ext, ['pdf'])) $icon = 'fa-file-pdf';
                                    elseif (in_array($ext, ['doc', 'docx'])) $icon = 'fa-file-word';
                                    elseif (in_array($ext, ['xls', 'xlsx'])) $icon = 'fa-file-excel';
                                    ?>
                                    <a href="../uploads/tickets/<?php echo htmlspecialchars($file_to_use); ?>"
                                        target="_blank"
                                        class="attachment-card"
                                        style="text-decoration: none;">
                                        <div class="attachment-icon">
                                            <i class="fas <?php echo $icon; ?>"></i>
                                        </div>
                                        <div class="attachment-name">
                                            <?php echo htmlspecialchars($display_name); ?>
                                        </div>
                                        <div class="attachment-meta">
                                            Uploaded by <?php echo htmlspecialchars($attachment['uploaded_by_name']); ?><br>
                                            <?php echo date('M d, Y', strtotime($attachment['uploaded_at'])); ?>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar Column -->
                <div class="sidebar-column">
                    <!-- Requester Info -->
                    <div class="content-card">
                        <div class="card-header">
                            <i class="fas fa-user"></i>
                            <h2>Requester</h2>
                        </div>

                        <div class="info-item" style="margin-bottom: 1rem;">
                            <span class="info-label">Name</span>
                            <span class="info-value"><?php echo htmlspecialchars($ticket['requester_name']); ?></span>
                        </div>
                        <div class="info-item" style="margin-bottom: 1rem;">
                            <span class="info-label">Email</span>
                            <span class="info-value"><?php echo htmlspecialchars($ticket['requester_email']); ?></span>
                        </div>
                        <div class="info-item" style="margin-bottom: 1rem;">
                            <span class="info-label">Department</span>
                            <span class="info-value"><?php echo htmlspecialchars($ticket['requester_department']); ?></span>
                        </div>
                        <?php if ($ticket['requester_phone']): ?>
                            <div class="info-item">
                                <span class="info-label">Phone</span>
                                <span class="info-value"><?php echo htmlspecialchars($ticket['requester_phone']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Assignment Info -->
                    <?php if ($ticket['assigned_to']): ?>
                        <div class="content-card">
                            <div class="card-header">
                                <i class="fas fa-user-check"></i>
                                <h2>Assigned To</h2>
                            </div>

                            <div class="info-item" style="margin-bottom: 1rem;">
                                <span class="info-label">Name</span>
                                <span class="info-value"><?php echo htmlspecialchars($ticket['assigned_to_name']); ?></span>
                            </div>
                            <div class="info-item" style="margin-bottom: 1rem;">
                                <span class="info-label">Email</span>
                                <span class="info-value"><?php echo htmlspecialchars($ticket['assigned_email']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Assigned On</span>
                                <span class="info-value">
                                    <?php echo date('M d, Y', strtotime($ticket['assigned_at'])); ?>
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Asset Info -->
                    <?php if ($ticket['asset_id']): ?>
                        <div class="content-card">
                            <div class="card-header">
                                <i class="fas fa-laptop"></i>
                                <h2>Related Asset</h2>
                            </div>

                            <div class="info-item" style="margin-bottom: 1rem;">
                                <span class="info-label">Asset Name</span>
                                <span class="info-value"><?php echo htmlspecialchars($ticket['asset_name']); ?></span>
                            </div>
                            <div class="info-item" style="margin-bottom: 1rem;">
                                <span class="info-label">Asset Code</span>
                                <span class="info-value">
                                    <span class="badge" style="background: #e3f2fd; color: #1976d2;">
                                        <?php echo htmlspecialchars($ticket['asset_code']); ?>
                                    </span>
                                </span>
                            </div>
                            <div class="info-item" style="margin-bottom: 1rem;">
                                <span class="info-label">Category</span>
                                <span class="info-value"><?php echo htmlspecialchars($ticket['asset_category']); ?></span>
                            </div>
                            <?php if ($ticket['brand']): ?>
                                <div class="info-item" style="margin-bottom: 1rem;">
                                    <span class="info-label">Brand</span>
                                    <span class="info-value"><?php echo htmlspecialchars($ticket['brand']); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($ticket['model']): ?>
                                <div class="info-item" style="margin-bottom: 1rem;">
                                    <span class="info-label">Model</span>
                                    <span class="info-value"><?php echo htmlspecialchars($ticket['model']); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($ticket['serial_number']): ?>
                                <div class="info-item">
                                    <span class="info-label">Serial Number</span>
                                    <span class="info-value"><?php echo htmlspecialchars($ticket['serial_number']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Quick Stats -->
                    <div class="content-card">
                        <div class="card-header">
                            <i class="fas fa-chart-line"></i>
                            <h2>Quick Stats</h2>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <div class="stat-box">
                                <div class="stat-value"><?php echo count($history); ?></div>
                                <div class="stat-label">Comments</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-value"><?php echo count($attachments); ?></div>
                                <div class="stat-label">Attachments</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-value"><?php echo count($timeline); ?></div>
                                <div class="stat-label">Timeline Events</div>
                            </div>
                            <?php if ($ticket['updated_at']): ?>
                                <div style="padding: 0.75rem; background: #f7fafc; border-radius: 6px; text-align: center;">
                                    <div style="font-size: 0.75rem; color: #718096; margin-bottom: 0.25rem;">
                                        LAST UPDATED
                                    </div>
                                    <div style="font-size: 0.875rem; color: #2d3748; font-weight: 500;">
                                        <?php echo date('M d, Y h:i A', strtotime($ticket['updated_at'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Resolution Info -->
                    <?php if ($ticket['status'] === 'resolved' || $ticket['status'] === 'closed'): ?>
                        <div class="content-card">
                            <div class="card-header">
                                <i class="fas fa-check-double"></i>
                                <h2>Resolution Details</h2>
                            </div>

                            <?php if ($ticket['resolved_by_name']): ?>
                                <div class="info-item" style="margin-bottom: 1rem;">
                                    <span class="info-label">Resolved By</span>
                                    <span class="info-value"><?php echo htmlspecialchars($ticket['resolved_by_name']); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($ticket['resolved_at']): ?>
                                <div class="info-item" style="margin-bottom: 1rem;">
                                    <span class="info-label">Resolved On</span>
                                    <span class="info-value">
                                        <?php echo date('M d, Y h:i A', strtotime($ticket['resolved_at'])); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if ($resolution_time): ?>
                                <div class="info-item" style="margin-bottom: 1rem;">
                                    <span class="info-label">Time to Resolve</span>
                                    <span class="info-value" style="color: #48bb78;">
                                        <?php echo $resolution_time; ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if ($ticket['resolution_notes']): ?>
                                <div style="background: #e6fffa; padding: 0.75rem; border-radius: 6px; border-left: 3px solid #38b2ac;">
                                    <div style="font-size: 0.75rem; color: #2c7a7b; font-weight: 600; margin-bottom: 0.5rem;">
                                        RESOLUTION NOTES
                                    </div>
                                    <div style="font-size: 0.875rem; color: #234e52; line-height: 1.5;">
                                        <?php echo nl2br(htmlspecialchars($ticket['resolution_notes'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($ticket['status'] === 'closed' && $ticket['closed_by_name']): ?>
                                <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e2e8f0;">
                                    <div class="info-item" style="margin-bottom: 0.5rem;">
                                        <span class="info-label">Closed By</span>
                                        <span class="info-value"><?php echo htmlspecialchars($ticket['closed_by_name']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Closed On</span>
                                        <span class="info-value">
                                            <?php echo date('M d, Y h:i A', strtotime($ticket['closed_at'])); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Rejection Info -->
                    <?php if ($ticket['approval_status'] === 'rejected'): ?>
                        <div class="content-card" style="border: 2px solid #fc8181;">
                            <div class="card-header">
                                <i class="fas fa-times-circle" style="color: #f56565;"></i>
                                <h2 style="color: #c53030;">Rejection Details</h2>
                            </div>

                            <?php
                            // Try to get rejected_by name if the column exists
                            $rejected_by_name = null;
                            if (isset($ticket['rejected_by']) && $ticket['rejected_by']) {
                                $rejecter_query = "SELECT CONCAT(first_name, ' ', last_name) as name FROM users WHERE user_id = ?";
                                $rejecter_stmt = $pdo->prepare($rejecter_query);
                                $rejecter_stmt->execute([$ticket['rejected_by']]);
                                $rejecter_result = $rejecter_stmt->fetch(PDO::FETCH_ASSOC);
                                if ($rejecter_result) {
                                    $rejected_by_name = $rejecter_result['name'];
                                }
                            }

                            if ($rejected_by_name):
                            ?>
                                <div class="info-item" style="margin-bottom: 1rem;">
                                    <span class="info-label">Rejected By</span>
                                    <span class="info-value"><?php echo htmlspecialchars($rejected_by_name); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($ticket['rejected_at'])): ?>
                                <div class="info-item" style="margin-bottom: 1rem;">
                                    <span class="info-label">Rejected On</span>
                                    <span class="info-value">
                                        <?php echo date('M d, Y h:i A', strtotime($ticket['rejected_at'])); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($ticket['rejection_reason'])): ?>
                                <div style="background: #fff5f5; padding: 0.75rem; border-radius: 6px; border-left: 3px solid #f56565;">
                                    <div style="font-size: 0.75rem; color: #c53030; font-weight: 600; margin-bottom: 0.5rem;">
                                        REJECTION REASON
                                    </div>
                                    <div style="font-size: 0.875rem; color: #742a2a; line-height: 1.5;">
                                        <?php echo nl2br(htmlspecialchars($ticket['rejection_reason'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    </div>
    </div>
    </div>
    </div>
    </main>

    <script>
        // Print functionality
        window.addEventListener('beforeprint', function() {
            document.querySelector('.main-content').style.marginLeft = '0';
        });

        window.addEventListener('afterprint', function() {
            document.querySelector('.main-content').style.marginLeft = '';
        });
    </script>
</body>

</html>