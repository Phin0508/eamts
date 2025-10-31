<?php
session_start();
require_once '../auth/config/database.php';

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header("Location: login.php");
    exit();
}

$manager_id = $_SESSION['user_id'];
$employee_user_id = $_GET['user'] ?? 0;

// Get manager's department
$dept_query = "SELECT department FROM users WHERE user_id = ?";
$dept_stmt = $pdo->prepare($dept_query);
$dept_stmt->execute([$manager_id]);
$manager_dept = $dept_stmt->fetchColumn();

// Get employee details and verify they're in the same department
$employee_query = "SELECT user_id, first_name, last_name, email, department 
                   FROM users 
                   WHERE user_id = ? AND department = ?";
$employee_stmt = $pdo->prepare($employee_query);
$employee_stmt->execute([$employee_user_id, $manager_dept]);
$employee = $employee_stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    $_SESSION['error_message'] = "Employee not found or you don't have permission to view their tickets.";
    header("Location: Mdashboard.php");
    exit();
}

// Fetch all tickets for this employee
$tickets_query = "
    SELECT 
        t.*,
        a.asset_name,
        a.asset_code,
        CONCAT(assigned.first_name, ' ', assigned.last_name) as assigned_to_name
    FROM tickets t
    LEFT JOIN assets a ON t.asset_id = a.id
    LEFT JOIN users assigned ON t.assigned_to = assigned.user_id
    WHERE t.requester_id = ? AND t.requester_department = ?
    ORDER BY t.created_at DESC
";

$tickets_stmt = $pdo->prepare($tickets_query);
$tickets_stmt->execute([$employee_user_id, $manager_dept]);
$tickets = $tickets_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tickets - <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></title>
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

        .employee-info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }

        .employee-info-card h2 {
            margin: 0 0 0.5rem 0;
            font-size: 1.5rem;
        }

        .employee-info-card p {
            margin: 0.25rem 0;
            opacity: 0.95;
        }

        .tickets-grid {
            display: grid;
            gap: 1.5rem;
        }

        .ticket-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }

        .ticket-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
        }

        .ticket-number {
            font-size: 1.1rem;
            font-weight: 600;
            color: #667eea;
        }

        .ticket-subject {
            font-size: 1rem;
            color: #2c3e50;
            margin: 0.5rem 0;
            font-weight: 500;
        }

        .ticket-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .badge-open { background: #e3f2fd; color: #1976d2; }
        .badge-in_progress { background: #fff3e0; color: #f57c00; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-resolved { background: #e8f5e9; color: #388e3c; }
        .badge-closed { background: #f5f5f5; color: #616161; }
        .badge-low { background: #e8f5e9; color: #388e3c; }
        .badge-medium { background: #fff3e0; color: #f57c00; }
        .badge-high { background: #ffebee; color: #d32f2f; }
        .badge-urgent { background: #f3e5f5; color: #7b1fa2; }
        .badge-approved { background: #d4edda; color: #155724; }
        .badge-rejected { background: #f8d7da; color: #721c24; }

        .ticket-details {
            color: #6c757d;
            font-size: 0.9rem;
            margin-top: 0.75rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 12px;
        }

        .empty-state i {
            font-size: 3rem;
            color: #cbd5e0;
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: #6c757d;
        }

        @media (max-width: 768px) {
            .dashboard-content {
                padding: 1rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
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
                    <h1>Employee Tickets</h1>
                    <p>View all tickets submitted by this employee</p>
                </div>
                <div class="header-right">
                    <a href="managerDashboard.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </header>

            <div class="employee-info-card">
                <h2><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></h2>
                <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($employee['email']); ?></p>
                <p><i class="fas fa-building"></i> <?php echo htmlspecialchars($employee['department']); ?></p>
                <p><i class="fas fa-ticket-alt"></i> Total Tickets: <?php echo count($tickets); ?></p>
            </div>

            <?php if (empty($tickets)): ?>
                <div class="empty-state">
                    <i class="fas fa-ticket-alt"></i>
                    <h3>No Tickets Found</h3>
                    <p>This employee hasn't submitted any tickets yet.</p>
                </div>
            <?php else: ?>
                <div class="tickets-grid">
                    <?php foreach ($tickets as $ticket): ?>
                        <div class="ticket-card" onclick="window.location.href='departmentTicketDetails.php?id=<?php echo $ticket['ticket_id']; ?>'">
                            <div class="ticket-header">
                                <div>
                                    <div class="ticket-number">
                                        <?php echo htmlspecialchars($ticket['ticket_number']); ?>
                                    </div>
                                    <div class="ticket-subject">
                                        <?php echo htmlspecialchars($ticket['subject']); ?>
                                    </div>
                                </div>
                            </div>

                            <div class="ticket-meta">
                                <span class="badge badge-<?php echo $ticket['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                </span>
                                <span class="badge badge-<?php echo $ticket['priority']; ?>">
                                    <?php echo ucfirst($ticket['priority']); ?>
                                </span>
                                <span class="badge badge-<?php echo $ticket['approval_status']; ?>">
                                    <?php echo ucfirst($ticket['approval_status']); ?>
                                </span>
                            </div>

                            <div class="ticket-details">
                                <p>
                                    <strong>Created:</strong> <?php echo date('M d, Y h:i A', strtotime($ticket['created_at'])); ?>
                                </p>
                                <?php if ($ticket['asset_name']): ?>
                                    <p>
                                        <strong>Asset:</strong> <?php echo htmlspecialchars($ticket['asset_name']); ?> 
                                        (<?php echo htmlspecialchars($ticket['asset_code']); ?>)
                                    </p>
                                <?php endif; ?>
                                <?php if ($ticket['assigned_to_name']): ?>
                                    <p>
                                        <strong>Assigned to:</strong> <?php echo htmlspecialchars($ticket['assigned_to_name']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>