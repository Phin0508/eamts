<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Include database connection
include("../auth/config/database.php");

// Verify PDO connection exists
if (!isset($pdo)) {
    die("Database connection failed. Please check your database configuration.");
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];

// Handle Mark as Complete for recurring maintenance
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_maintenance'])) {
    $recurring_id = intval($_POST['recurring_id']);
    $notes = trim($_POST['completion_notes']);
    
    try {
        // Get recurring maintenance details
        $recurring_stmt = $pdo->prepare("SELECT r.*, a.id as asset_id FROM recurring_maintenance r 
                                         INNER JOIN assets a ON r.asset_id = a.id 
                                         WHERE r.id = ? AND a.assigned_to = ?");
        $recurring_stmt->execute([$recurring_id, $user_id]);
        $recurring = $recurring_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($recurring) {
            // Add maintenance record
            $maint_stmt = $pdo->prepare("INSERT INTO asset_maintenance (asset_id, maintenance_type, maintenance_date, performed_by, notes, created_by, created_at) VALUES (?, ?, NOW(), ?, ?, ?, NOW())");
            $maint_stmt->execute([
                $recurring['asset_id'], 
                $recurring['maintenance_type'], 
                $user_name,
                $notes,
                $user_id
            ]);
            
            // Update next due date and last completed
            $next_due = date('Y-m-d', strtotime($recurring['next_due_date'] . ' + ' . $recurring['frequency_days'] . ' days'));
            $update_stmt = $pdo->prepare("UPDATE recurring_maintenance SET next_due_date = ?, last_completed_date = NOW() WHERE id = ?");
            $update_stmt->execute([$next_due, $recurring_id]);
            
            $success_message = "Maintenance completed successfully! Next due date updated.";
        }
    } catch (PDOException $e) {
        $error_message = "Error completing maintenance: " . $e->getMessage();
    }
}

// Fetch assets assigned to this user with maintenance info
$assets = [];
$total_value = 0;
try {
    $query = "SELECT a.*, 
              u.username as created_by_name,
              CONCAT(u.first_name, ' ', u.last_name) as created_by_fullname,
              (SELECT COUNT(*) FROM asset_maintenance am WHERE am.asset_id = a.id) as maintenance_count,
              (SELECT MAX(am.maintenance_date) FROM asset_maintenance am WHERE am.asset_id = a.id) as last_maintenance_date
              FROM assets a 
              LEFT JOIN users u ON a.created_by = u.user_id 
              WHERE a.assigned_to = ?
              ORDER BY a.created_at DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$user_id]);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total value
    foreach ($assets as $asset) {
        if ($asset['purchase_cost']) {
            $total_value += $asset['purchase_cost'];
        }
    }
} catch (PDOException $e) {
    $error_message = "Error fetching assets: " . $e->getMessage();
}

// Fetch maintenance history for user's assets
$maintenance_history = [];
try {
    $maint_query = "SELECT m.*, a.asset_name, a.asset_code, a.id as asset_id
                    FROM asset_maintenance m
                    INNER JOIN assets a ON m.asset_id = a.id
                    WHERE a.assigned_to = ?
                    ORDER BY m.maintenance_date DESC
                    LIMIT 20";
    $maint_stmt = $pdo->prepare($maint_query);
    $maint_stmt->execute([$user_id]);
    $maintenance_history = $maint_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching maintenance history: " . $e->getMessage();
}

// Fetch upcoming maintenance reminders for user's assets
$upcoming_maintenance = [];
try {
    $reminder_query = "SELECT r.*, a.asset_name, a.asset_code, a.id as asset_id
                       FROM recurring_maintenance r
                       INNER JOIN assets a ON r.asset_id = a.id
                       WHERE a.assigned_to = ? AND r.is_active = 1
                       ORDER BY r.next_due_date ASC";
    $reminder_stmt = $pdo->prepare($reminder_query);
    $reminder_stmt->execute([$user_id]);
    $all_schedules = $reminder_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Filter for upcoming/overdue maintenance
    $today = new DateTime();
    foreach ($all_schedules as $schedule) {
        $due_date = new DateTime($schedule['next_due_date']);
        $interval = $today->diff($due_date);
        $days_until = $interval->days * ($interval->invert ? -1 : 1);
        
        // Show if overdue or due within notification period
        if ($days_until < 0 || $days_until <= $schedule['notify_days_before']) {
            $schedule['days_until'] = $days_until;
            $schedule['is_overdue'] = $days_until < 0;
            $upcoming_maintenance[] = $schedule;
        }
    }
} catch (PDOException $e) {
    $error_message = "Error fetching maintenance reminders: " . $e->getMessage();
}

// Count assets by status
$status_counts = [
    'In Use' => 0,
    'Maintenance' => 0,
    'Available' => 0,
    'Retired' => 0
];

foreach ($assets as $asset) {
    if (isset($status_counts[$asset['status']])) {
        $status_counts[$asset['status']]++;
    }
}

// Count maintenance reminders
$overdue_count = 0;
$upcoming_count = 0;
foreach ($upcoming_maintenance as $reminder) {
    if ($reminder['is_overdue']) {
        $overdue_count++;
    } else {
        $upcoming_count++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assets - E-Asset</title>
    <link rel="stylesheet" href="../style/asset.css">
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <style>
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card.total {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .stat-card.value {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        .stat-card.in-use {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }

        .stat-card.maintenance-due {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }

        .stat-card h3 {
            font-size: 14px;
            margin: 0 0 10px 0;
            opacity: 0.9;
            font-weight: 500;
        }

        .stat-card .stat-number {
            font-size: 32px;
            font-weight: bold;
            margin: 0;
        }

        .stat-card .stat-label {
            font-size: 12px;
            opacity: 0.8;
            margin-top: 5px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            color: #065f46;
        }

        .alert-error {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            color: #991b1b;
        }

        /* Tab Navigation */
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e5e7eb;
            background: white;
            padding: 0 20px;
            border-radius: 12px 12px 0 0;
        }

        .tab-button {
            padding: 15px 25px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            color: #6b7280;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            font-weight: 500;
        }

        .tab-button.active {
            color: #2563eb;
            border-bottom-color: #2563eb;
            font-weight: 600;
        }

        .tab-button:hover {
            color: #2563eb;
            background: #f9fafb;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Maintenance Reminders Section */
        .reminders-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .reminders-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .reminders-header h2 {
            margin: 0;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .reminder-card {
            border-left: 4px solid #f59e0b;
            background: #fffbeb;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }

        .reminder-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transform: translateX(5px);
        }

        .reminder-card.overdue {
            border-left-color: #ef4444;
            background: #fef2f2;
        }

        .reminder-card-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .reminder-asset-name {
            font-weight: 600;
            color: #1f2937;
            font-size: 18px;
            margin-bottom: 5px;
        }

        .reminder-schedule-name {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .reminder-badge {
            padding: 6px 14px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .reminder-badge.overdue {
            background: #fee2e2;
            color: #991b1b;
        }

        .reminder-badge.upcoming {
            background: #fef3c7;
            color: #92400e;
        }

        .reminder-details {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 15px;
            padding: 15px;
            background: rgba(255, 255, 255, 0.5);
            border-radius: 6px;
        }

        .reminder-detail {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 13px;
            color: #4b5563;
        }

        .reminder-icon {
            font-size: 16px;
        }

        .reminder-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .no-reminders {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }

        .no-reminders .icon {
            font-size: 48px;
            margin-bottom: 15px;
        }

        /* Maintenance History */
        .maintenance-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
        }

        .maintenance-table th,
        .maintenance-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        .maintenance-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .maintenance-table tbody tr:hover {
            background: #f9fafb;
        }

        .maintenance-table tbody tr:last-child td {
            border-bottom: none;
        }

        .asset-link {
            color: #2563eb;
            text-decoration: none;
            font-weight: 500;
        }

        .asset-link:hover {
            text-decoration: underline;
        }

        .cost-display {
            color: #059669;
            font-weight: 600;
        }

        /* Asset Card Enhancements */
        .asset-details-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
        }

        .asset-details-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .asset-details-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .asset-details-title {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .asset-details-title h3 {
            margin: 0;
            color: #2c3e50;
        }

        .asset-code-badge {
            background: #667eea;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .asset-maintenance-info {
            display: flex;
            gap: 15px;
            align-items: center;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #f0f0f0;
        }

        .maintenance-stat {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 13px;
            color: #6b7280;
        }

        .maintenance-stat .icon {
            font-size: 16px;
        }

        .asset-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .detail-item {
            padding: 10px;
            background: #f8f9fa;
            border-radius: 6px;
        }

        .detail-label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 5px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .detail-value {
            font-size: 14px;
            color: #2c3e50;
            font-weight: 500;
        }

        .warranty-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .warranty-active {
            background: #d4edda;
            color: #155724;
        }

        .warranty-expired {
            background: #f8d7da;
            color: #721c24;
        }

        .warranty-expiring {
            background: #fff3cd;
            color: #856404;
        }

        .no-assets-message,
        .no-data {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .no-assets-message .icon,
        .no-data .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .no-assets-message h2,
        .no-data h2 {
            color: #2c3e50;
            margin-bottom: 10px;
        }

        .no-assets-message p,
        .no-data p {
            color: #6c757d;
            font-size: 16px;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            overflow-y: auto;
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: white;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            margin: 0;
            color: #1f2937;
        }

        .modal-close {
            cursor: pointer;
            font-size: 28px;
            color: #6b7280;
            background: none;
            border: none;
        }

        .modal-close:hover {
            color: #1f2937;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }

        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
        }

        .form-group textarea:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #2563eb;
            color: white;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }

        .btn-secondary:hover {
            background: #d1d5db;
        }

        .btn-small {
            padding: 8px 16px;
            font-size: 13px;
        }

        @media (max-width: 768px) {
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }

            .tabs {
                overflow-x: auto;
                padding: 0 10px;
            }

            .tab-button {
                white-space: nowrap;
                padding: 12px 20px;
            }

            .asset-details-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .asset-details-grid {
                grid-template-columns: 1fr;
            }

            .reminder-card-header {
                flex-direction: column;
                gap: 10px;
            }

            .reminder-details {
                flex-direction: column;
                gap: 10px;
            }

            .maintenance-table {
                font-size: 13px;
            }

            .maintenance-table th,
            .maintenance-table td {
                padding: 10px;
            }
        }
    </style>
</head>

<body>
    <!-- Include Sidebar -->
    <?php include("../auth/inc/Usidebar.php"); ?>

    <div class="main-content">
        <div class="inventory-container">
            <div class="inventory-header">
                <h1>💼 My Assets</h1>
                <div style="color: #6c757d;">
                    <strong><?php echo htmlspecialchars($user_name); ?></strong>
                </div>
            </div>

            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success">
                    ✓ <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-error">
                    ✗ <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card total">
                    <h3>Total Assets</h3>
                    <p class="stat-number"><?php echo count($assets); ?></p>
                </div>
                <div class="stat-card value">
                    <h3>Total Value</h3>
                    <p class="stat-number">$<?php echo number_format($total_value, 2); ?></p>
                </div>
                <div class="stat-card in-use">
                    <h3>In Use</h3>
                    <p class="stat-number"><?php echo $status_counts['In Use']; ?></p>
                </div>
                <div class="stat-card maintenance-due">
                    <h3>Maintenance Due</h3>
                    <p class="stat-number"><?php echo $overdue_count + $upcoming_count; ?></p>
                    <p class="stat-label">
                        <?php if ($overdue_count > 0): ?>
                            <?php echo $overdue_count; ?> Overdue
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="tabs">
                <button class="tab-button active" data-tab="assets">My Assets</button>
                <button class="tab-button" data-tab="reminders">
                    Maintenance Reminders 
                    <?php if (count($upcoming_maintenance) > 0): ?>
                        <span style="background: #ef4444; color: white; padding: 2px 8px; border-radius: 10px; font-size: 11px; margin-left: 5px;">
                            <?php echo count($upcoming_maintenance); ?>
                        </span>
                    <?php endif; ?>
                </button>
                <button class="tab-button" data-tab="history">Maintenance History</button>
            </div>

            <!-- Assets Tab -->
            <div class="tab-content active" id="assets-tab">
                <?php if (count($assets) > 0): ?>
                    <?php foreach ($assets as $asset): 
                        // Calculate warranty status
                        $warranty_status = '';
                        $warranty_class = '';
                        if ($asset['warranty_expiry']) {
                            $warranty_date = strtotime($asset['warranty_expiry']);
                            $current_date = time();
                            $days_until_expiry = ($warranty_date - $current_date) / (60 * 60 * 24);
                            
                            if ($days_until_expiry < 0) {
                                $warranty_status = 'Expired';
                                $warranty_class = 'warranty-expired';
                            } elseif ($days_until_expiry < 90) {
                                $warranty_status = 'Expiring Soon';
                                $warranty_class = 'warranty-expiring';
                            } else {
                                $warranty_status = 'Active';
                                $warranty_class = 'warranty-active';
                            }
                        }
                    ?>
                        <div class="asset-details-card">
                            <div class="asset-details-header">
                                <div class="asset-details-title">
                                    <h3><?php echo htmlspecialchars($asset['asset_name']); ?></h3>
                                    <span class="asset-code-badge"><?php echo htmlspecialchars($asset['asset_code']); ?></span>
                                </div>
                                <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $asset['status'])); ?>">
                                    <?php echo htmlspecialchars($asset['status']); ?>
                                </span>
                            </div>

                            <?php if ($asset['maintenance_count'] > 0 || $asset['last_maintenance_date']): ?>
                                <div class="asset-maintenance-info">
                                    <?php if ($asset['maintenance_count'] > 0): ?>
                                        <div class="maintenance-stat">
                                            <span class="icon">🔧</span>
                                            <span><?php echo $asset['maintenance_count']; ?> maintenance record(s)</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($asset['last_maintenance_date']): ?>
                                        <div class="maintenance-stat">
                                            <span class="icon">📅</span>
                                            <span>Last: <?php echo date('M d, Y', strtotime($asset['last_maintenance_date'])); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="asset-details-grid">
                                <div class="detail-item">
                                    <div class="detail-label">Category</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($asset['category']); ?></div>
                                </div>

                                <?php if ($asset['brand']): ?>
                                    <div class="detail-item">
                                        <div class="detail-label">Brand</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($asset['brand']); ?></div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($asset['model']): ?>
                                    <div class="detail-item">
                                        <div class="detail-label">Model</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($asset['model']); ?></div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($asset['serial_number']): ?>
                                    <div class="detail-item">
                                        <div class="detail-label">Serial Number</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($asset['serial_number']); ?></div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($asset['purchase_date']): ?>
                                    <div class="detail-item">
                                        <div class="detail-label">Purchase Date</div>
                                        <div class="detail-value"><?php echo date('M d, Y', strtotime($asset['purchase_date'])); ?></div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($asset['purchase_cost']): ?>
                                    <div class="detail-item">
                                        <div class="detail-label">Purchase Cost</div>
                                        <div class="detail-value">$<?php echo number_format($asset['purchase_cost'], 2); ?></div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($asset['warranty_expiry']): ?>
                                    <div class="detail-item">
                                        <div class="detail-label">Warranty Expiry</div>
                                        <div class="detail-value">
                                            <?php echo date('M d, Y', strtotime($asset['warranty_expiry'])); ?>
                                            <?php if ($warranty_status): ?>
                                                <br><span class="warranty-status <?php echo $warranty_class; ?>"><?php echo $warranty_status; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($asset['location']): ?>
                                    <div class="detail-item">
                                        <div class="detail-label">Location</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($asset['location']); ?></div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($asset['department']): ?>
                                    <div class="detail-item">
                                        <div class="detail-label">Department</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($asset['department']); ?></div>
                                    </div>
                                <?php endif; ?>

                                <div class="detail-item">
                                    <div class="detail-label">Assigned Date</div>
                                    <div class="detail-value"><?php echo date('M d, Y', strtotime($asset['created_at'])); ?></div>
                                </div>

                                <?php if ($asset['description']): ?>
                                    <div class="detail-item" style="grid-column: 1 / -1;">
                                        <div class="detail-label">Description</div>
                                        <div class="detail-value"><?php echo nl2br(htmlspecialchars($asset['description'])); ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                <?php else: ?>
                    <div class="no-assets-message">
                        <div class="icon">📦</div>
                        <h2>No Assets Assigned</h2>
                        <p>You don't have any assets assigned to you at the moment.</p>
                        <p style="margin-top: 10px; font-size: 14px;">Contact your manager or IT department if you believe this is an error.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Maintenance Reminders Tab -->
            <div class="tab-content" id="reminders-tab">
                <?php if (count($upcoming_maintenance) > 0): ?>
                    <div class="reminders-section">
                        <div class="reminders-header">
                            <h2>
                                <span>🔔</span>
                                <span>Active Maintenance Reminders</span>
                            </h2>
                            <span style="color: #6b7280; font-size: 14px;">
                                <?php echo count($upcoming_maintenance); ?> reminder(s)
                            </span>
                        </div>

                        <?php foreach ($upcoming_maintenance as $reminder): ?>
                            <div class="reminder-card <?php echo $reminder['is_overdue'] ? 'overdue' : ''; ?>">
                                <div class="reminder-card-header">
                                    <div style="flex: 1;">
                                        <div class="reminder-asset-name">
                                            <?php echo htmlspecialchars($reminder['asset_name']); ?>
                                            <span style="color: #9ca3af; font-weight: normal; font-size: 14px;">
                                                (<?php echo htmlspecialchars($reminder['asset_code']); ?>)
                                            </span>
                                        </div>
                                        <div class="reminder-schedule-name">
                                            <?php echo htmlspecialchars($reminder['schedule_name']); ?> - 
                                            <?php echo htmlspecialchars($reminder['maintenance_type']); ?>
                                        </div>
                                    </div>
                                    <span class="reminder-badge <?php echo $reminder['is_overdue'] ? 'overdue' : 'upcoming'; ?>">
                                        <?php 
                                        if ($reminder['is_overdue']) {
                                            echo '🚨 Overdue by ' . abs($reminder['days_until']) . ' day(s)';
                                        } else {
                                            echo '⏰ Due in ' . $reminder['days_until'] . ' day(s)';
                                        }
                                        ?>
                                    </span>
                                </div>

                                <div class="reminder-details">
                                    <div class="reminder-detail">
                                        <span class="reminder-icon">📅</span>
                                        <span><strong>Due:</strong> <?php echo date('M d, Y', strtotime($reminder['next_due_date'])); ?></span>
                                    </div>
                                    <div class="reminder-detail">
                                        <span class="reminder-icon">🔄</span>
                                        <span><strong>Frequency:</strong> Every <?php echo $reminder['frequency_days']; ?> days</span>
                                    </div>
                                    <?php if ($reminder['last_completed_date']): ?>
                                        <div class="reminder-detail">
                                            <span class="reminder-icon">✓</span>
                                            <span><strong>Last Completed:</strong> <?php echo date('M d, Y', strtotime($reminder['last_completed_date'])); ?></span>
                                        </div>
                                    <?php else: ?>
                                        <div class="reminder-detail">
                                            <span class="reminder-icon">📝</span>
                                            <span>Never completed</span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="reminder-actions">
                                    <button class="btn btn-success btn-small complete-btn" 
                                            data-recurring-id="<?php echo $reminder['id']; ?>"
                                            data-asset-name="<?php echo htmlspecialchars($reminder['asset_name']); ?>"
                                            data-schedule-name="<?php echo htmlspecialchars($reminder['schedule_name']); ?>">
                                        ✓ Mark as Complete
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <div class="icon">✅</div>
                        <h2>All Caught Up!</h2>
                        <p>You have no upcoming or overdue maintenance tasks.</p>
                        <p style="margin-top: 10px; font-size: 14px;">Check back later or view your maintenance history.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Maintenance History Tab -->
            <div class="tab-content" id="history-tab">
                <?php if (count($maintenance_history) > 0): ?>
                    <div class="reminders-section">
                        <div class="reminders-header">
                            <h2>
                                <span>📋</span>
                                <span>Maintenance History</span>
                            </h2>
                            <span style="color: #6b7280; font-size: 14px;">
                                Last <?php echo count($maintenance_history); ?> record(s)
                            </span>
                        </div>

                        <table class="maintenance-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Asset</th>
                                    <th>Type</th>
                                    <th>Performed By</th>
                                    <th>Cost</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($maintenance_history as $record): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($record['maintenance_date'])); ?></td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($record['asset_name']); ?></div>
                                            <div style="font-size: 12px; color: #6b7280;"><?php echo htmlspecialchars($record['asset_code']); ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars($record['maintenance_type']); ?></td>
                                        <td><?php echo htmlspecialchars($record['performed_by']); ?></td>
                                        <td class="cost-display">
                                            <?php echo $record['cost'] ? '$' . number_format($record['cost'], 2) : '-'; ?>
                                        </td>
                                        <td><?php echo $record['notes'] ? htmlspecialchars($record['notes']) : '-'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <div class="icon">📋</div>
                        <h2>No Maintenance History</h2>
                        <p>No maintenance has been performed on your assigned assets yet.</p>
                        <p style="margin-top: 10px; font-size: 14px;">When maintenance is completed, it will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Complete Maintenance Modal -->
    <div id="completeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>✓ Complete Maintenance</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" id="modal_recurring_id" name="recurring_id">
                
                <div class="form-group">
                    <label>Asset</label>
                    <input type="text" id="modal_asset_name" readonly style="background: #f3f4f6; border: 1px solid #d1d5db; padding: 10px; border-radius: 6px; width: 100%;">
                </div>

                <div class="form-group">
                    <label>Schedule</label>
                    <input type="text" id="modal_schedule_name" readonly style="background: #f3f4f6; border: 1px solid #d1d5db; padding: 10px; border-radius: 6px; width: 100%;">
                </div>

                <div class="form-group">
                    <label for="completion_notes">Notes (Optional)</label>
                    <textarea id="completion_notes" name="completion_notes" rows="4" placeholder="Add any notes about the maintenance performed..."></textarea>
                </div>

                <div style="background: #dbeafe; padding: 15px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; color: #1e40af;">
                    <strong>ℹ️ What happens when you complete this?</strong>
                    <ul style="margin: 10px 0 0 20px; padding: 0;">
                        <li>A maintenance record will be created</li>
                        <li>The next due date will be automatically calculated</li>
                        <li>Your completion will be logged with timestamp</li>
                    </ul>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" name="complete_maintenance" class="btn btn-success">✓ Complete Maintenance</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tab switching
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabContents = document.querySelectorAll('.tab-content');

        tabButtons.forEach(button => {
            button.addEventListener('click', () => {
                const tabName = button.getAttribute('data-tab');

                // Remove active class from all buttons and contents
                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabContents.forEach(content => content.classList.remove('active'));

                // Add active class to clicked button and corresponding content
                button.classList.add('active');
                document.getElementById(tabName + '-tab').classList.add('active');
            });
        });

        // Complete maintenance modal
        const completeButtons = document.querySelectorAll('.complete-btn');
        const modal = document.getElementById('completeModal');

        completeButtons.forEach(button => {
            button.addEventListener('click', function() {
                const recurringId = this.getAttribute('data-recurring-id');
                const assetName = this.getAttribute('data-asset-name');
                const scheduleName = this.getAttribute('data-schedule-name');

                document.getElementById('modal_recurring_id').value = recurringId;
                document.getElementById('modal_asset_name').value = assetName;
                document.getElementById('modal_schedule_name').value = scheduleName;
                document.getElementById('completion_notes').value = '';

                modal.classList.add('active');
            });
        });

        function closeModal() {
            modal.classList.remove('active');
        }

        // Close modal when clicking outside
        window.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });

        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>

</html>