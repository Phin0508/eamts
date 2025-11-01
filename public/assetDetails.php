<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Get asset ID from URL
$asset_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($asset_id === 0) {
    header("Location: asset.php");
    exit();
}

// Handle form submissions
$success_message = '';
$error_message = '';

// Add Maintenance Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_maintenance'])) {
    $maintenance_type = trim($_POST['maintenance_type']);
    $maintenance_date = $_POST['maintenance_date'];
    $performed_by = trim($_POST['performed_by']);
    $cost = !empty($_POST['cost']) ? $_POST['cost'] : NULL;
    $notes = trim($_POST['notes']);
    $next_maintenance_date = !empty($_POST['next_maintenance_date']) ? $_POST['next_maintenance_date'] : NULL;

    try {
        $stmt = $pdo->prepare("INSERT INTO asset_maintenance (asset_id, maintenance_type, maintenance_date,
         performed_by, cost, notes, next_maintenance_date, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");

        if ($stmt->execute([$asset_id, $maintenance_type, $maintenance_date, $performed_by, $cost, $notes, $next_maintenance_date, $_SESSION['user_id']])) {
            $success_message = "Maintenance record added successfully!";
        } else {
            $error_message = "Error adding maintenance record.";
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Add Recurring Maintenance Schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_recurring'])) {
    $schedule_name = trim($_POST['schedule_name']);
    $maintenance_type = trim($_POST['recurring_type']);
    $frequency_days = intval($_POST['frequency_days']);
    $start_date = $_POST['start_date'];
    $assigned_to = !empty($_POST['assigned_to']) ? $_POST['assigned_to'] : NULL;
    $notify_days_before = intval($_POST['notify_days_before']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    try {
        // Calculate next due date
        $next_due_date = date('Y-m-d', strtotime($start_date . ' + ' . $frequency_days . ' days'));

        $stmt = $pdo->prepare("INSERT INTO recurring_maintenance (asset_id, schedule_name, maintenance_type, frequency_days, start_date, next_due_date, assigned_to, notify_days_before, is_active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

        if ($stmt->execute([$asset_id, $schedule_name, $maintenance_type, $frequency_days, $start_date, $next_due_date, $assigned_to, $notify_days_before, $is_active, $_SESSION['user_id']])) {
            $success_message = "Recurring maintenance schedule created successfully!";
        } else {
            $error_message = "Error creating recurring maintenance schedule.";
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Update Recurring Maintenance Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_recurring'])) {
    $recurring_id = intval($_POST['recurring_id']);
    $is_active = intval($_POST['is_active']);

    try {
        $stmt = $pdo->prepare("UPDATE recurring_maintenance SET is_active = ? WHERE id = ?");
        if ($stmt->execute([$is_active, $recurring_id])) {
            $success_message = "Recurring maintenance schedule updated!";
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Mark Recurring Maintenance as Complete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_recurring'])) {
    $recurring_id = intval($_POST['recurring_id']);
    $performed_by = trim($_POST['performed_by_complete']);
    $cost = !empty($_POST['cost_complete']) ? $_POST['cost_complete'] : NULL;
    $notes = trim($_POST['notes_complete']);

    try {
        // Get recurring maintenance details
        $recurring_stmt = $pdo->prepare("SELECT * FROM recurring_maintenance WHERE id = ?");
        $recurring_stmt->execute([$recurring_id]);
        $recurring = $recurring_stmt->fetch(PDO::FETCH_ASSOC);

        if ($recurring) {
            // Add maintenance record
            $maint_stmt = $pdo->prepare("INSERT INTO asset_maintenance (asset_id, maintenance_type, maintenance_date, performed_by, cost, notes, created_by, created_at) VALUES (?, ?, NOW(), ?, ?, ?, ?, NOW())");
            $maint_stmt->execute([$asset_id, $recurring['maintenance_type'], $performed_by, $cost, $notes, $_SESSION['user_id']]);

            // Update next due date and last completed
            $next_due = date('Y-m-d', strtotime($recurring['next_due_date'] . ' + ' . $recurring['frequency_days'] . ' days'));
            $update_stmt = $pdo->prepare("UPDATE recurring_maintenance SET next_due_date = ?, last_completed_date = NOW() WHERE id = ?");
            $update_stmt->execute([$next_due, $recurring_id]);

            $success_message = "Maintenance completed and schedule updated!";
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Fetch asset details matching your schema
$asset = null;
try {
    $query = "SELECT a.*, 
              u.username as created_by_name,
              assigned_user.username as assigned_username,
              CONCAT(assigned_user.first_name, ' ', assigned_user.last_name) as assigned_user_name
              FROM assets a 
              LEFT JOIN users u ON a.created_by = u.user_id 
              LEFT JOIN users assigned_user ON a.assigned_to = assigned_user.user_id                                                        
              WHERE a.id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$asset_id]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$asset) {
        header("Location: asset.php");
        exit();
    }
} catch (PDOException $e) {
    die("Error fetching asset: " . $e->getMessage());
}

// Calculate warranty status
$warranty_status = 'No Warranty Info';
$warranty_days_left = null;
$warranty_class = 'unknown';

if ($asset['warranty_expiry']) {
    $warranty_date = new DateTime($asset['warranty_expiry']);
    $today = new DateTime();
    $interval = $today->diff($warranty_date);
    $warranty_days_left = $interval->days * ($interval->invert ? -1 : 1);

    if ($warranty_days_left < 0) {
        $warranty_status = 'Expired';
        $warranty_class = 'expired';
    } elseif ($warranty_days_left <= 30) {
        $warranty_status = 'Expiring Soon';
        $warranty_class = 'expiring';
    } else {
        $warranty_status = 'Active';
        $warranty_class = 'active';
    }
}

// Fetch maintenance history
$maintenance_records = [];
try {
    $maint_query = "SELECT m.*, u.username as created_by_name 
                    FROM asset_maintenance m
                    LEFT JOIN users u ON m.created_by = u.user_id
                    WHERE m.asset_id = ?
                    ORDER BY m.maintenance_date DESC";
    $maint_stmt = $pdo->prepare($maint_query);
    $maint_stmt->execute([$asset_id]);
    $maintenance_records = $maint_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching maintenance records: " . $e->getMessage();
}

// Fetch recurring maintenance schedules
$recurring_schedules = [];
try {
    $recurring_query = "SELECT r.*, 
                        u.username as assigned_username,
                        CONCAT(u.first_name, ' ', u.last_name) as assigned_user_name
                        FROM recurring_maintenance r
                        LEFT JOIN users u ON r.assigned_to = u.user_id
                        WHERE r.asset_id = ?
                        ORDER BY r.next_due_date ASC";
    $recurring_stmt = $pdo->prepare($recurring_query);
    $recurring_stmt->execute([$asset_id]);
    $recurring_schedules = $recurring_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching recurring schedules: " . $e->getMessage();
}

// Get all users for assignment dropdown
$users = [];
try {
    $users_query = "SELECT user_id, username, first_name, last_name, email FROM users ORDER BY first_name, last_name";
    $users_result = $pdo->query($users_query);
    $users = $users_result->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error silently
}

// Check for upcoming maintenance (within next 7 days)
$upcoming_maintenance = [];
$today = new DateTime();
foreach ($recurring_schedules as $schedule) {
    if ($schedule['is_active']) {
        $due_date = new DateTime($schedule['next_due_date']);
        $interval = $today->diff($due_date);
        $days_until = $interval->days * ($interval->invert ? -1 : 1);

        if ($days_until <= $schedule['notify_days_before'] && $days_until >= 0) {
            $schedule['days_until'] = $days_until;
            $upcoming_maintenance[] = $schedule;
        } elseif ($days_until < 0) {
            $schedule['days_until'] = $days_until;
            $schedule['overdue'] = true;
            $upcoming_maintenance[] = $schedule;
        }
    }
}

// Status mapping for display
$status_labels = [
    'available' => 'Available',
    'in_use' => 'In Use',
    'maintenance' => 'Maintenance',
    'retired' => 'Retired'
];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Details - <?php echo htmlspecialchars($asset['asset_name']); ?></title>
    <link rel="stylesheet" href="../style/asset.css">
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <style>
        .details-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .breadcrumb {
            margin-bottom: 20px;
            font-size: 14px;
            color: #666;
        }

        .breadcrumb a {
            color: #2563eb;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .asset-header {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .asset-header h1 {
            margin: 0 0 10px 0;
            color: #1f2937;
            font-size: 28px;
        }

        .asset-code {
            color: #6b7280;
            font-size: 16px;
            margin-bottom: 20px;
        }

        .asset-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .info-item {
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
        }

        .info-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 16px;
            color: #1f2937;
            font-weight: 500;
        }

        .warranty-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .warranty-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .warranty-status {
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
        }

        .warranty-status.active {
            background: #d1fae5;
            color: #065f46;
        }

        .warranty-status.expiring {
            background: #fef3c7;
            color: #92400e;
        }

        .warranty-status.expired {
            background: #fee2e2;
            color: #991b1b;
        }

        .warranty-status.unknown {
            background: #e5e7eb;
            color: #4b5563;
        }

        .alerts-section {
            margin-bottom: 30px;
        }

        .alert-box {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .alert-box.warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            color: #92400e;
        }

        .alert-box.danger {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            color: #991b1b;
        }

        .alert-icon {
            font-size: 24px;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e5e7eb;
        }

        .tab-button {
            padding: 12px 24px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            color: #6b7280;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }

        .tab-button.active {
            color: #2563eb;
            border-bottom-color: #2563eb;
            font-weight: 600;
        }

        .tab-button:hover {
            color: #2563eb;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .section-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .section-header h2 {
            margin: 0;
            color: #1f2937;
            font-size: 20px;
        }

        .maintenance-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .maintenance-table th,
        .maintenance-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        .maintenance-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }

        .maintenance-table tr:hover {
            background: #f9fafb;
        }

        .recurring-card {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
        }

        .recurring-card.overdue {
            border-color: #ef4444;
            background: #fef2f2;
        }

        .recurring-card.upcoming {
            border-color: #f59e0b;
            background: #fffbeb;
        }

        .recurring-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .recurring-title {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 5px;
        }

        .recurring-type {
            color: #6b7280;
            font-size: 14px;
        }

        .recurring-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: 0.4s;
            border-radius: 24px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: 0.4s;
            border-radius: 50%;
        }

        input:checked+.toggle-slider {
            background-color: #10b981;
        }

        input:checked+.toggle-slider:before {
            transform: translateX(26px);
        }

        .due-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .due-badge.overdue {
            background: #fee2e2;
            color: #991b1b;
        }

        .due-badge.upcoming {
            background: #fef3c7;
            color: #92400e;
        }

        .due-badge.normal {
            background: #dbeafe;
            color: #1e40af;
        }

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
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-close {
            cursor: pointer;
            font-size: 28px;
            color: #6b7280;
        }

        .modal-close:hover {
            color: #1f2937;
        }

        .cost-display {
            color: #059669;
            font-weight: 600;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-badge.status-available {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.status-in-use {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-badge.status-maintenance {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge.status-retired {
            background: #e5e7eb;
            color: #4b5563;
        }

        @media (max-width: 768px) {
            .asset-info-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                overflow-x: auto;
            }

            .tab-button {
                white-space: nowrap;
            }
        }
    </style>
</head>

<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="main-content">
        <div class="details-container">
            <!-- Breadcrumb -->
            <div class="breadcrumb">
                <a href="asset.php">← Back to Inventory</a>
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

            <!-- Alerts Section -->
            <?php if (!empty($upcoming_maintenance)): ?>
                <div class="alerts-section">
                    <?php foreach ($upcoming_maintenance as $upcoming): ?>
                        <?php
                        $alert_class = isset($upcoming['overdue']) ? 'danger' : 'warning';
                        $alert_icon = isset($upcoming['overdue']) ? '🚨' : '⚠️';
                        $alert_text = isset($upcoming['overdue'])
                            ? 'Maintenance Overdue: ' . abs($upcoming['days_until']) . ' days late'
                            : 'Maintenance Due: ' . $upcoming['days_until'] . ' days remaining';
                        ?>
                        <div class="alert-box <?php echo $alert_class; ?>">
                            <div class="alert-icon"><?php echo $alert_icon; ?></div>
                            <div>
                                <strong><?php echo htmlspecialchars($upcoming['schedule_name']); ?></strong><br>
                                <?php echo $alert_text; ?> - Due: <?php echo date('M d, Y', strtotime($upcoming['next_due_date'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Asset Header -->
            <div class="asset-header">
                <h1>📦 <?php echo htmlspecialchars($asset['asset_name']); ?></h1>
                <div class="asset-code">Code: <?php echo htmlspecialchars($asset['asset_code']); ?></div>

                <div class="asset-info-grid">
                    <div class="info-item">
                        <div class="info-label">Category</div>
                        <div class="info-value"><?php echo htmlspecialchars($asset['category'] ?: 'N/A'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Brand & Model</div>
                        <div class="info-value"><?php echo htmlspecialchars(trim($asset['brand'] . ' ' . $asset['model']) ?: 'N/A'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Serial Number</div>
                        <div class="info-value"><?php echo htmlspecialchars($asset['serial_number'] ?: 'N/A'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Status</div>
                        <div class="info-value">
                            <?php
                            // Convert status to CSS-friendly format with hyphens
                            $status_value = strtolower(str_replace(' ', '-', $asset['status'] ?? 'available'));
                            ?>
                            <span class="status-badge status-<?php echo $status_value; ?>">
                                <?php echo htmlspecialchars($asset['status']); ?>
                            </span>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Assigned To</div>
                        <div class="info-value">
                            <?php echo $asset['assigned_user_name'] ? htmlspecialchars($asset['assigned_user_name']) : 'Unassigned'; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Location</div>
                        <div class="info-value"><?php echo htmlspecialchars($asset['location'] ?: 'N/A'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Department</div>
                        <div class="info-value"><?php echo htmlspecialchars($asset['department'] ?: 'N/A'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Supplier</div>
                        <div class="info-value"><?php echo htmlspecialchars($asset['supplier'] ?: 'N/A'); ?></div>
                    </div>
                </div>

                <?php if ($asset['description']): ?>
                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                        <div class="info-label">Description</div>
                        <div class="info-value" style="margin-top: 10px; line-height: 1.6;">
                            <?php echo nl2br(htmlspecialchars($asset['description'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Warranty Card -->
            <div class="warranty-card">
                <div class="warranty-header">
                    <h2>🛡️ Warranty & Purchase Information</h2>
                    <span class="warranty-status <?php echo $warranty_class; ?>">
                        <?php echo $warranty_status; ?>
                    </span>
                </div>
                <div class="asset-info-grid">
                    <div class="info-item">
                        <div class="info-label">Purchase Date</div>
                        <div class="info-value">
                            <?php echo $asset['purchase_date'] ? date('M d, Y', strtotime($asset['purchase_date'])) : 'Not Set'; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Warranty Expiry</div>
                        <div class="info-value">
                            <?php
                            if ($asset['warranty_expiry']) {
                                echo date('M d, Y', strtotime($asset['warranty_expiry']));
                                if ($warranty_days_left !== null) {
                                    echo '<br><small style="color: #6b7280;">';
                                    if ($warranty_days_left < 0) {
                                        echo 'Expired ' . abs($warranty_days_left) . ' days ago';
                                    } else {
                                        echo $warranty_days_left . ' days remaining';
                                    }
                                    echo '</small>';
                                }
                            } else {
                                echo 'Not Set';
                            }
                            ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Purchase Cost</div>
                        <div class="info-value cost-display">
                            <?php echo $asset['purchase_cost'] ? '$' . number_format($asset['purchase_cost'], 2) : 'Not Set'; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Supplier</div>
                        <div class="info-value"><?php echo htmlspecialchars($asset['supplier'] ?: 'Not Set'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab-button active" data-tab="maintenance">Maintenance History</button>
                <button class="tab-button" data-tab="recurring">Recurring Maintenance</button>
            </div>

            <!-- Maintenance History Tab -->
            <div class="tab-content active" id="maintenance-tab">
                <div class="section-card">
                    <div class="section-header">
                        <h2>🔧 Maintenance History</h2>
                        <button class="btn btn-primary" id="addMaintenanceBtn">+ Add Maintenance Record</button>
                    </div>

                    <?php if (count($maintenance_records) > 0): ?>
                        <table class="maintenance-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Performed By</th>
                                    <th>Cost</th>
                                    <th>Next Maintenance</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($maintenance_records as $record): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($record['maintenance_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($record['maintenance_type']); ?></td>
                                        <td><?php echo htmlspecialchars($record['performed_by']); ?></td>
                                        <td class="cost-display">
                                            <?php echo $record['cost'] ? '$' . number_format($record['cost'], 2) : '-'; ?>
                                        </td>
                                        <td>
                                            <?php echo $record['next_maintenance_date'] ? date('M d, Y', strtotime($record['next_maintenance_date'])) : '-'; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($record['notes'] ?: '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">
                            <p>No maintenance records found. Add the first maintenance record to start tracking.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recurring Maintenance Tab -->
            <div class="tab-content" id="recurring-tab">
                <div class="section-card">
                    <div class="section-header">
                        <h2>🔄 Recurring Maintenance Schedules</h2>
                        <button class="btn btn-primary" id="addRecurringBtn">+ Add Recurring Schedule</button>
                    </div>

                    <?php if (count($recurring_schedules) > 0): ?>
                        <?php foreach ($recurring_schedules as $schedule): ?>
                            <?php
                            $due_date = new DateTime($schedule['next_due_date']);
                            $today = new DateTime();
                            $interval = $today->diff($due_date);
                            $days_until = $interval->days * ($interval->invert ? -1 : 1);

                            $card_class = '';
                            $badge_class = 'normal';
                            $badge_text = 'Due in ' . $days_until . ' days';

                            if ($days_until < 0) {
                                $card_class = 'overdue';
                                $badge_class = 'overdue';
                                $badge_text = 'Overdue by ' . abs($days_until) . ' days';
                            } elseif ($days_until <= $schedule['notify_days_before']) {
                                $card_class = 'upcoming';
                                $badge_class = 'upcoming';
                            }
                            ?>
                            <div class="recurring-card <?php echo $card_class; ?>">
                                <div class="recurring-header">
                                    <div>
                                        <div class="recurring-title"><?php echo htmlspecialchars($schedule['schedule_name']); ?></div>
                                        <div class="recurring-type"><?php echo htmlspecialchars($schedule['maintenance_type']); ?></div>
                                    </div>
                                    <div>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="recurring_id" value="<?php echo $schedule['id']; ?>">
                                            <input type="hidden" name="is_active" value="<?php echo $schedule['is_active'] ? 0 : 1; ?>">
                                            <label class="toggle-switch">
                                                <input type="checkbox" <?php echo $schedule['is_active'] ? 'checked' : ''; ?>
                                                    onchange="this.form.submit()" name="toggle_recurring">
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </form>
                                    </div>
                                </div>

                                <div class="recurring-info">
                                    <div class="info-item">
                                        <div class="info-label">Frequency</div>
                                        <div class="info-value">Every <?php echo $schedule['frequency_days']; ?> days</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Next Due Date</div>
                                        <div class="info-value">
                                            <?php echo date('M d, Y', strtotime($schedule['next_due_date'])); ?>
                                            <br>
                                            <span class="due-badge <?php echo $badge_class; ?>"><?php echo $badge_text; ?></span>
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Assigned To</div>
                                        <div class="info-value">
                                            <?php echo $schedule['assigned_user_name'] ? htmlspecialchars($schedule['assigned_user_name']) : 'Not Assigned'; ?>
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Last Completed</div>
                                        <div class="info-value">
                                            <?php echo $schedule['last_completed_date'] ? date('M d, Y', strtotime($schedule['last_completed_date'])) : 'Never'; ?>
                                        </div>
                                    </div>
                                </div>

                                <div style="margin-top: 15px;">
                                    <?php if ($schedule['is_active']): ?>
                                        <button class="btn btn-success btn-small complete-recurring-btn"
                                            data-recurring-id="<?php echo $schedule['id']; ?>"
                                            data-schedule-name="<?php echo htmlspecialchars($schedule['schedule_name']); ?>">
                                            ✓ Mark as Complete
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-data">
                            <p>No recurring maintenance schedules found. Create a schedule to automate maintenance tracking.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Maintenance Modal -->
    <div id="maintenanceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Maintenance Record</h3>
                <span class="modal-close" data-modal="maintenanceModal">&times;</span>
            </div>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="maintenance_type" class="required">Maintenance Type</label>
                    <select id="maintenance_type" name="maintenance_type" required>
                        <option value="">Select Type</option>
                        <option value="Preventive Maintenance">Preventive Maintenance</option>
                        <option value="Repair">Repair</option>
                        <option value="Cleaning">Cleaning</option>
                        <option value="Inspection">Inspection</option>
                        <option value="Software Update">Software Update</option>
                        <option value="Hardware Upgrade">Hardware Upgrade</option>
                        <option value="Calibration">Calibration</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="maintenance_date" class="required">Maintenance Date</label>
                    <input type="date" id="maintenance_date" name="maintenance_date" required
                        value="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="form-group">
                    <label for="performed_by" class="required">Performed By</label>
                    <input type="text" id="performed_by" name="performed_by"
                        placeholder="Name or Company" required>
                </div>

                <div class="form-group">
                    <label for="cost">Cost ($)</label>
                    <input type="number" id="cost" name="cost" step="0.01" min="0"
                        placeholder="0.00">
                </div>

                <div class="form-group">
                    <label for="next_maintenance_date">Next Maintenance Date (Optional)</label>
                    <input type="date" id="next_maintenance_date" name="next_maintenance_date">
                    <span class="help-text">Set when the next maintenance should be performed</span>
                </div>

                <div class="form-group">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" rows="4"
                        placeholder="Details about the maintenance performed..."></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" data-close="maintenanceModal">Cancel</button>
                    <button type="submit" name="add_maintenance" class="btn btn-primary">Add Record</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Recurring Maintenance Modal -->
    <div id="recurringModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Recurring Maintenance Schedule</h3>
                <span class="modal-close" data-modal="recurringModal">&times;</span>
            </div>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="schedule_name" class="required">Schedule Name</label>
                    <input type="text" id="schedule_name" name="schedule_name"
                        placeholder="e.g., Monthly System Check" required>
                </div>

                <div class="form-group">
                    <label for="recurring_type" class="required">Maintenance Type</label>
                    <select id="recurring_type" name="recurring_type" required>
                        <option value="">Select Type</option>
                        <option value="Preventive Maintenance">Preventive Maintenance</option>
                        <option value="Inspection">Inspection</option>
                        <option value="Cleaning">Cleaning</option>
                        <option value="Calibration">Calibration</option>
                        <option value="Software Update">Software Update</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="frequency_days" class="required">Frequency (Days)</label>
                    <select id="frequency_days" name="frequency_days" required>
                        <option value="7">Weekly (7 days)</option>
                        <option value="14">Bi-weekly (14 days)</option>
                        <option value="30" selected>Monthly (30 days)</option>
                        <option value="60">Bi-monthly (60 days)</option>
                        <option value="90">Quarterly (90 days)</option>
                        <option value="180">Semi-annually (180 days)</option>
                        <option value="365">Annually (365 days)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="start_date" class="required">Start Date</label>
                    <input type="date" id="start_date" name="start_date" required
                        value="<?php echo date('Y-m-d'); ?>">
                    <span class="help-text">The first maintenance date</span>
                </div>

                <div class="form-group">
                    <label for="assigned_to">Assign To</label>
                    <select id="assigned_to" name="assigned_to">
                        <option value="">-- Not Assigned --</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['user_id']; ?>">
                                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                (<?php echo htmlspecialchars($user['email']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="help-text">Optionally assign this maintenance to a specific user</span>
                </div>

                <div class="form-group">
                    <label for="notify_days_before" class="required">Notify Before (Days)</label>
                    <select id="notify_days_before" name="notify_days_before" required>
                        <option value="1">1 day before</option>
                        <option value="3">3 days before</option>
                        <option value="7" selected>7 days before</option>
                        <option value="14">14 days before</option>
                        <option value="30">30 days before</option>
                    </select>
                    <span class="help-text">Show alert when maintenance is due within this period</span>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="is_active" checked style="width: auto;">
                        <span>Active (Start scheduling immediately)</span>
                    </label>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" data-close="recurringModal">Cancel</button>
                    <button type="submit" name="add_recurring" class="btn btn-primary">Create Schedule</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Complete Recurring Maintenance Modal -->
    <div id="completeRecurringModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Complete Maintenance</h3>
                <span class="modal-close" data-modal="completeRecurringModal">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" id="complete_recurring_id" name="recurring_id">

                <div class="form-group">
                    <label>Schedule</label>
                    <input type="text" id="complete_schedule_name" readonly style="background-color: #f9fafb;">
                </div>

                <div class="form-group">
                    <label for="performed_by_complete" class="required">Performed By</label>
                    <input type="text" id="performed_by_complete" name="performed_by_complete"
                        placeholder="Name or Company" required>
                </div>

                <div class="form-group">
                    <label for="cost_complete">Cost ($)</label>
                    <input type="number" id="cost_complete" name="cost_complete"
                        step="0.01" min="0" placeholder="0.00">
                </div>

                <div class="form-group">
                    <label for="notes_complete">Notes</label>
                    <textarea id="notes_complete" name="notes_complete" rows="4"
                        placeholder="Details about the maintenance performed..."></textarea>
                </div>

                <div class="alert-box info" style="margin-top: 15px; background: #dbeafe; border-left: 4px solid #3b82f6; color: #1e40af;">
                    <div class="alert-icon">ℹ️</div>
                    <div>
                        <small>
                            Completing this maintenance will:
                            <ul style="margin: 5px 0 0 20px; padding: 0;">
                                <li>Add a maintenance record</li>
                                <li>Update the next due date automatically</li>
                                <li>Log completion timestamp</li>
                            </ul>
                        </small>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" data-close="completeRecurringModal">Cancel</button>
                    <button type="submit" name="complete_recurring" class="btn btn-success">Complete Maintenance</button>
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

        // Modal handling
        const modals = {
            maintenance: document.getElementById('maintenanceModal'),
            recurring: document.getElementById('recurringModal'),
            completeRecurring: document.getElementById('completeRecurringModal')
        };

        // Open modals
        document.getElementById('addMaintenanceBtn').addEventListener('click', () => {
            modals.maintenance.classList.add('active');
        });

        document.getElementById('addRecurringBtn').addEventListener('click', () => {
            modals.recurring.classList.add('active');
        });

        // Complete recurring maintenance buttons
        document.querySelectorAll('.complete-recurring-btn').forEach(button => {
            button.addEventListener('click', function() {
                const recurringId = this.getAttribute('data-recurring-id');
                const scheduleName = this.getAttribute('data-schedule-name');

                document.getElementById('complete_recurring_id').value = recurringId;
                document.getElementById('complete_schedule_name').value = scheduleName;

                modals.completeRecurring.classList.add('active');
            });
        });

        // Close modals
        document.querySelectorAll('.modal-close').forEach(closeBtn => {
            closeBtn.addEventListener('click', function() {
                const modalName = this.getAttribute('data-modal');
                document.getElementById(modalName).classList.remove('active');
            });
        });

        document.querySelectorAll('[data-close]').forEach(closeBtn => {
            closeBtn.addEventListener('click', function() {
                const modalName = this.getAttribute('data-close');
                document.getElementById(modalName).classList.remove('active');
            });
        });

        // Close modal when clicking outside
        window.addEventListener('click', (event) => {
            Object.values(modals).forEach(modal => {
                if (event.target === modal) {
                    modal.classList.remove('active');
                }
            });
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