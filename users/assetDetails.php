<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
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
    header("Location: managerAsset.php");
    exit();
}

// Fetch asset details - only assets assigned to this manager
$asset = null;
try {
    $query = "SELECT a.*, 
              u.username as created_by_name,
              assigned_user.username as assigned_username,
              CONCAT(assigned_user.first_name, ' ', assigned_user.last_name) as assigned_user_name
              FROM assets a 
              LEFT JOIN users u ON a.created_by = u.user_id 
              LEFT JOIN users assigned_user ON a.assigned_to = assigned_user.user_id                                                        
              WHERE a.id = ? AND a.assigned_to = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$asset_id, $_SESSION['user_id']]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$asset) {
        // Asset not found or not assigned to this manager
        header("Location: managerAsset.php");
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

        .alert-box.info {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            color: #1e40af;
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

        .view-only-notice {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            color: #1e40af;
        }

        .status-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 8px;
        }

        .status-indicator.active {
            background: #10b981;
        }

        .status-indicator.inactive {
            background: #6b7280;
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
    <?php include("../auth/inc/Msidebar.php"); ?>

    <div class="main-content">
        <div class="details-container">
            <!-- Breadcrumb -->
            <div class="breadcrumb">
                <a href="managerAsset.php">← Back to My Assets</a>
            </div>

            <!-- View Only Notice -->
            <div class="view-only-notice">
                <strong>ℹ️ View Only Mode</strong><br>
                You can view asset details and maintenance history. Contact administrators to add or modify maintenance records.
            </div>

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
                            <p>No maintenance records found for this asset.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recurring Maintenance Tab -->
            <div class="tab-content" id="recurring-tab">
                <div class="section-card">
                    <div class="section-header">
                        <h2>🔄 Recurring Maintenance Schedules</h2>
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
                                        <span class="status-indicator <?php echo $schedule['is_active'] ? 'active' : 'inactive'; ?>"></span>
                                        <span style="color: #6b7280; font-size: 14px;">
                                            <?php echo $schedule['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
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
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-data">
                            <p>No recurring maintenance schedules found for this asset.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
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
    </script>
</body>

</html>