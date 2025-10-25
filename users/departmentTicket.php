<?php
session_start();
require_once '../auth/config/database.php';

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get manager's department
$dept_query = "SELECT department FROM users WHERE user_id = ?";
$dept_stmt = $pdo->prepare($dept_query);
$dept_stmt->execute([$user_id]);
$manager_dept = $dept_stmt->fetchColumn();

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $ticket_id = intval($_POST['ticket_id']);
    $action = $_POST['action'];
    $manager_notes = trim($_POST['manager_notes'] ?? '');
    
    // Verify ticket belongs to manager's department and is pending
    $verify_query = "SELECT ticket_id, approval_status FROM tickets 
                     WHERE ticket_id = ? AND requester_department = ? AND approval_status = 'pending'";
    $verify_stmt = $pdo->prepare($verify_query);
    $verify_stmt->execute([$ticket_id, $manager_dept]);
    $verify_ticket = $verify_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($verify_ticket) {
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

// Fetch department tickets
$filter_approval = $_GET['approval'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

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

if (!empty($search)) {
    $where_clauses[] = "(t.ticket_number LIKE ? OR t.subject LIKE ? OR requester.first_name LIKE ? OR requester.last_name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

$query = "
    SELECT 
        t.*,
        CONCAT(requester.first_name, ' ', requester.last_name) as requester_name,
        requester.email as requester_email,
        requester.department as requester_department,
        CONCAT(approver.first_name, ' ', approver.last_name) as approver_name,
        CONCAT(assigned.first_name, ' ', assigned.last_name) as assigned_to_name,
        a.asset_name,
        a.asset_code
    FROM tickets t
    JOIN users requester ON t.requester_id = requester.user_id
    LEFT JOIN users approver ON t.approved_by = approver.user_id
    LEFT JOIN users assigned ON t.assigned_to = assigned.user_id
    LEFT JOIN assets a ON t.asset_id = a.id
    $where_sql
    ORDER BY 
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
        t.created_at DESC
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../style/ticket.css">
    
    <style>
        .approval-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .approval-pending {
            background: #fff3cd;
            color: #856404;
        }
        .approval-approved {
            background: #d4edda;
            color: #155724;
        }
        .approval-rejected {
            background: #f8d7da;
            color: #721c24;
        }
        .approval-actions {
            display: flex;
            gap: 8px;
        }
        .btn-approve {
            background: #28a745;
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.875rem;
        }
        .btn-reject {
            background: #dc3545;
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.875rem;
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
        }
        .modal-content {
            background: white;
            margin: 10% auto;
            padding: 30px;
            width: 90%;
            max-width: 500px;
            border-radius: 8px;
        }
        .modal textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin: 15px 0;
            min-height: 100px;
        }
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/Msidebar.php"); ?>

    <main class="main-content">
        <div class="dashboard-content">
            <header class="page-header">
                <div class="header-left">
                    <h1>Department Tickets</h1>
                    <p>Review and approve tickets from your department</p>
                </div>
            </header>

            <?php if (isset($success_message) && !empty($success_message)): ?>
            <div class="alert">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
            </div>
            <?php endif; ?>
            
            <?php if (isset($error_message) && !empty($error_message)): ?>
            <div class="alert" style="background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
            </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon total">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total']; ?></h3>
                        <p>Total Department Tickets</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fff3cd; color: #856404;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['pending_approval']; ?></h3>
                        <p>Pending Approval</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #d4edda; color: #155724;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stats['approved']; ?></h3>
                        <p>Approved</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon urgent">
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
                        <input type="text" name="search" placeholder="Search tickets..." value="<?php echo htmlspecialchars($search); ?>">
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

                    <button type="submit" class="btn btn-secondary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <a href="departmentTicket.php" class="btn btn-outline">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </form>
            </div>

            <!-- Tickets Table -->
            <div class="content-card">
                <div class="table-responsive">
                    <table class="tickets-table">
                        <thead>
                            <tr>
                                <th>Ticket #</th>
                                <th>Subject</th>
                                <th>Requester</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Approval Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tickets)): ?>
                            <tr>
                                <td colspan="8" class="no-data">No tickets found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($tickets as $ticket): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($ticket['ticket_number']); ?></strong>
                                </td>
                                <td>
                                    <div class="ticket-subject">
                                        <?php echo htmlspecialchars($ticket['subject']); ?>
                                        <?php if ($ticket['asset_code']): ?>
                                        <small class="asset-tag">
                                            <i class="fas fa-laptop"></i> <?php echo htmlspecialchars($ticket['asset_code']); ?>
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($ticket['requester_name']); ?></td>
                                <td>
                                    <span class="badge badge-priority badge-<?php echo $ticket['priority']; ?>">
                                        <?php echo ucfirst($ticket['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-status badge-<?php echo $ticket['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="approval-badge approval-<?php echo $ticket['approval_status']; ?>">
                                        <?php echo ucfirst($ticket['approval_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="date-time"><?php echo date('M d, Y', strtotime($ticket['created_at'])); ?></span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="ticketDetails.php?id=<?php echo $ticket['ticket_id']; ?>" class="btn-icon" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($ticket['approval_status'] === 'pending'): ?>
                                        <button onclick="openApprovalModal(<?php echo $ticket['ticket_id']; ?>, 'approve', '<?php echo htmlspecialchars($ticket['ticket_number']); ?>')" 
                                                class="btn-approve" title="Approve">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button onclick="openApprovalModal(<?php echo $ticket['ticket_id']; ?>, 'reject', '<?php echo htmlspecialchars($ticket['ticket_number']); ?>')" 
                                                class="btn-reject" title="Reject">
                                            <i class="fas fa-times"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
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
                
                <label for="manager_notes">Notes (Optional):</label>
                <textarea name="manager_notes" id="manager_notes" placeholder="Add any notes or comments..."></textarea>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
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
            } else {
                document.getElementById('modalTitle').textContent = 'Reject Ticket';
                document.getElementById('modalSubmitBtn').textContent = 'Reject';
                document.getElementById('modalSubmitBtn').className = 'btn-reject';
                document.getElementById('manager_notes').placeholder = 'Please provide reason for rejection...';
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
    </script>
</body>
</html>