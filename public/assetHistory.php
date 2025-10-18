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

// Get asset ID from URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: asset.php");
    exit();
}

$asset_id = $_GET['id'];

// Fetch asset data
$asset = null;
try {
    $query = "SELECT a.*, 
              u.username as created_by_name,
              assigned_user.user_id as assigned_user_id,
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
        header("Location: asset.php?error=notfound");
        exit();
    }
} catch (PDOException $e) {
    die("Error fetching asset: " . $e->getMessage());
}

// Get complete asset history
$history = [];
try {
    $history_query = "SELECT ah.*, 
                      performer.username as performer_name,
                      CONCAT(performer.first_name, ' ', performer.last_name) as performer_full_name,
                      prev_user.username as prev_username,
                      CONCAT(prev_user.first_name, ' ', prev_user.last_name) as prev_user_name,
                      new_user.username as new_username,
                      CONCAT(new_user.first_name, ' ', new_user.last_name) as new_user_name
                      FROM asset_history ah
                      LEFT JOIN users performer ON ah.performed_by = performer.user_id
                      LEFT JOIN users prev_user ON ah.previous_user_id = prev_user.user_id
                      LEFT JOIN users new_user ON ah.new_user_id = new_user.user_id
                      WHERE ah.asset_id = ?
                      ORDER BY ah.created_at DESC";
    $history_stmt = $pdo->prepare($history_query);
    $history_stmt->execute([$asset_id]);
    $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching history: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset History - E-Asset</title>
    <link rel="stylesheet" href="../style/asset.css">
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <style>
        .history-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
        }

        .page-header {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h1 {
            margin: 0;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .asset-code-badge {
            background: #3498db;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: normal;
        }

        .back-btn {
            background: #95a5a6;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            transition: background 0.3s;
        }

        .back-btn:hover {
            background: #7f8c8d;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 20px;
        }

        .asset-summary {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            height: fit-content;
            position: sticky;
            top: 20px;
        }

        .asset-summary h3 {
            margin-top: 0;
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .summary-item {
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #ecf0f1;
        }

        .summary-item:last-child {
            border-bottom: none;
        }

        .summary-label {
            font-weight: 600;
            color: #7f8c8d;
            font-size: 0.85em;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .summary-value {
            color: #2c3e50;
            font-size: 0.95em;
        }

        .history-timeline {
            background: #fff;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .history-timeline h2 {
            margin-top: 0;
            color: #2c3e50;
            border-bottom: 2px solid #f39c12;
            padding-bottom: 10px;
            margin-bottom: 25px;
        }

        .timeline {
            position: relative;
            padding-left: 40px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e0e0e0;
        }

        .timeline-item {
            position: relative;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #3498db;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -44px;
            top: 25px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #3498db;
            border: 3px solid #fff;
            box-shadow: 0 0 0 2px #3498db;
        }

        .timeline-item.assigned::before {
            background: #27ae60;
            box-shadow: 0 0 0 2px #27ae60;
        }

        .timeline-item.unassigned::before {
            background: #e74c3c;
            box-shadow: 0 0 0 2px #e74c3c;
        }

        .timeline-item.reassigned::before {
            background: #f39c12;
            box-shadow: 0 0 0 2px #f39c12;
        }

        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 12px;
        }

        .timeline-action {
            font-weight: 600;
            color: #2c3e50;
            font-size: 1.1em;
        }

        .timeline-time {
            font-size: 0.85em;
            color: #7f8c8d;
            text-align: right;
        }

        .timeline-details {
            color: #555;
            line-height: 1.6;
        }

        .timeline-user {
            display: inline-block;
            background: #fff;
            padding: 3px 10px;
            border-radius: 4px;
            font-weight: 600;
            color: #2c3e50;
            margin: 0 3px;
        }

        .timeline-performer {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #dee2e6;
            font-size: 0.9em;
            color: #7f8c8d;
        }

        .no-history {
            text-align: center;
            padding: 60px 20px;
            color: #95a5a6;
        }

        .no-history-icon {
            font-size: 4em;
            margin-bottom: 20px;
        }

        .action-icons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .action-btn {
            flex: 1;
            padding: 10px;
            text-align: center;
            text-decoration: none;
            border-radius: 5px;
            transition: all 0.3s;
            font-size: 0.9em;
        }

        .action-btn.edit {
            background: #3498db;
            color: white;
        }

        .action-btn.edit:hover {
            background: #2980b9;
        }

        .action-btn.inventory {
            background: #95a5a6;
            color: white;
        }

        .action-btn.inventory:hover {
            background: #7f8c8d;
        }

        @media (max-width: 968px) {
            .content-grid {
                grid-template-columns: 1fr;
            }

            .asset-summary {
                position: static;
            }
        }
    </style>
</head>
<body>
    <!-- Include Navigation Bar -->
    <?php include("../auth/inc/navbar.php"); ?>
    
    <!-- Include Sidebar -->
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="main-content">
        <div class="history-container">
            <div class="page-header">
                <h1>
                    📜 Asset History
                    <span class="asset-code-badge"><?php echo htmlspecialchars($asset['asset_code']); ?></span>
                </h1>
                <a href="asset.php" class="back-btn">← Back to Inventory</a>
            </div>

            <div class="content-grid">
                <!-- Asset Summary Sidebar -->
                <div class="asset-summary">
                    <h3>📋 Asset Details</h3>
                    
                    <div class="summary-item">
                        <div class="summary-label">Asset Name</div>
                        <div class="summary-value"><?php echo htmlspecialchars($asset['asset_name']); ?></div>
                    </div>

                    <div class="summary-item">
                        <div class="summary-label">Category</div>
                        <div class="summary-value"><?php echo htmlspecialchars($asset['category']); ?></div>
                    </div>

                    <div class="summary-item">
                        <div class="summary-label">Brand/Model</div>
                        <div class="summary-value">
                            <?php 
                            $brandModel = trim($asset['brand'] . ' ' . $asset['model']);
                            echo htmlspecialchars($brandModel ?: '-');
                            ?>
                        </div>
                    </div>

                    <div class="summary-item">
                        <div class="summary-label">Current Status</div>
                        <div class="summary-value">
                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $asset['status'])); ?>">
                                <?php echo htmlspecialchars($asset['status']); ?>
                            </span>
                        </div>
                    </div>

                    <div class="summary-item">
                        <div class="summary-label">Currently Assigned To</div>
                        <div class="summary-value">
                            <?php if ($asset['assigned_user_id']): ?>
                                <strong><?php echo htmlspecialchars($asset['assigned_user_name']); ?></strong><br>
                                <small style="color: #7f8c8d;"><?php echo htmlspecialchars($asset['assigned_username']); ?></small>
                            <?php else: ?>
                                <span style="color: #95a5a6;">Unassigned</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="summary-item">
                        <div class="summary-label">Department</div>
                        <div class="summary-value"><?php echo htmlspecialchars($asset['department'] ?: '-'); ?></div>
                    </div>

                    <div class="summary-item">
                        <div class="summary-label">Location</div>
                        <div class="summary-value"><?php echo htmlspecialchars($asset['location'] ?: '-'); ?></div>
                    </div>

                    <div class="action-icons">
                        <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'manager'): ?>
                            <a href="assetEdit.php?id=<?php echo $asset['id']; ?>" class="action-btn edit">✏️ Edit</a>
                        <?php endif; ?>
                        <a href="asset.php" class="action-btn inventory">📦 Inventory</a>
                    </div>
                </div>

                <!-- History Timeline -->
                <div class="history-timeline">
                    <h2>📅 Complete History Timeline</h2>
                    
                    <?php if (count($history) > 0): ?>
                        <div class="timeline">
                            <?php foreach ($history as $record): ?>
                                <div class="timeline-item <?php echo $record['action_type']; ?>">
                                    <div class="timeline-header">
                                        <div class="timeline-action">
                                            <?php 
                                            $action_icons = [
                                                'assigned' => '✓',
                                                'unassigned' => '✗',
                                                'reassigned' => '↔'
                                            ];
                                            echo $action_icons[$record['action_type']] ?? '•';
                                            echo ' ' . ucfirst($record['action_type']); 
                                            ?>
                                        </div>
                                        <div class="timeline-time">
                                            <?php 
                                            $date = new DateTime($record['created_at']);
                                            echo $date->format('M d, Y');
                                            ?><br>
                                            <?php echo $date->format('h:i A'); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="timeline-details">
                                        <?php if ($record['action_type'] === 'assigned'): ?>
                                            Asset was assigned to 
                                            <span class="timeline-user"><?php echo htmlspecialchars($record['new_user_name']); ?></span>
                                            (<?php echo htmlspecialchars($record['new_username']); ?>)
                                            
                                        <?php elseif ($record['action_type'] === 'unassigned'): ?>
                                            Asset was unassigned from 
                                            <span class="timeline-user"><?php echo htmlspecialchars($record['prev_user_name']); ?></span>
                                            (<?php echo htmlspecialchars($record['prev_username']); ?>)
                                            
                                        <?php elseif ($record['action_type'] === 'reassigned'): ?>
                                            Asset was reassigned from 
                                            <span class="timeline-user"><?php echo htmlspecialchars($record['prev_user_name']); ?></span>
                                            to 
                                            <span class="timeline-user"><?php echo htmlspecialchars($record['new_user_name']); ?></span>
                                        <?php endif; ?>
                                        
                                        <div class="timeline-performer">
                                            👤 Action performed by: <strong><?php echo htmlspecialchars($record['performer_full_name']); ?></strong>
                                            (<?php echo htmlspecialchars($record['performer_name']); ?>)
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-history">
                            <div class="no-history-icon">📭</div>
                            <h3>No History Records</h3>
                            <p>This asset has no recorded history yet.<br>
                            History will appear here when assignments or changes are made.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>