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
    // First, let's check if any records exist for this asset
    $check_query = "SELECT COUNT(*) as count FROM assets_history WHERE asset_id = ?";
    $check_stmt = $pdo->prepare($check_query);
    $check_stmt->execute([$asset_id]);
    $count_result = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Now fetch the actual history
    $history_query = "SELECT ah.*, 
                      performer.username as performer_name,
                      CONCAT(performer.first_name, ' ', performer.last_name) as performer_full_name,
                      prev_user.username as prev_username,
                      CONCAT(prev_user.first_name, ' ', prev_user.last_name) as prev_user_name,
                      new_user.username as new_username,
                      CONCAT(new_user.first_name, ' ', new_user.last_name) as new_user_name
                      FROM assets_history ah
                      LEFT JOIN users performer ON ah.performed_by = performer.user_id
                      LEFT JOIN users prev_user ON ah.assigned_from = prev_user.user_id
                      LEFT JOIN users new_user ON ah.assigned_to = new_user.user_id
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
    <title>Asset History - <?php echo htmlspecialchars($asset['asset_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8f9fc;
            color: #2d3748;
            min-height: 100vh;
        }

        /* Main Container */
        .container {
            margin-left: 260px;
            padding: 30px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }

        .container.sidebar-collapsed {
            margin-left: 80px;
        }

        /* Page Header */
        .page-header {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .asset-code-badge {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }

        .back-btn {
            background: white;
            color: #718096;
            border: 2px solid #e2e8f0;
            padding: 10px 18px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s;
        }

        .back-btn:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 24px;
        }

        /* Asset Summary Card */
        .asset-summary {
            background: white;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            height: fit-content;
            position: sticky;
            top: 30px;
        }

        .asset-summary h3 {
            font-size: 20px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .summary-item {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e2e8f0;
        }

        .summary-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .summary-label {
            font-weight: 700;
            color: #6b7280;
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .summary-value {
            color: #1a202c;
            font-size: 15px;
            font-weight: 600;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-badge.status-available {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }

        .status-badge.status-in-use {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        .status-badge.status-maintenance {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }

        .status-badge.status-retired {
            background: linear-gradient(135deg, #e5e7eb 0%, #d1d5db 100%);
            color: #4b5563;
        }

        /* Action Buttons */
        .action-icons {
            display: flex;
            gap: 10px;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 2px solid #e2e8f0;
        }

        .action-btn {
            flex: 1;
            padding: 12px;
            text-align: center;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .action-btn.edit {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }

        .action-btn.edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }

        .action-btn.inventory {
            background: white;
            color: #718096;
            border: 2px solid #e2e8f0;
        }

        .action-btn.inventory:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
        }

        /* History Timeline */
        .history-timeline {
            background: white;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .history-timeline h2 {
            font-size: 20px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 50px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 16px;
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(180deg, #e2e8f0 0%, #cbd5e0 100%);
            border-radius: 2px;
        }

        .timeline-item {
            position: relative;
            padding: 24px;
            background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
            border-radius: 12px;
            margin-bottom: 20px;
            border: 2px solid #e2e8f0;
            transition: all 0.3s;
        }

        .timeline-item:hover {
            border-color: #7c3aed;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.1);
            transform: translateX(4px);
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -38px;
            top: 30px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: white;
            border: 4px solid #7c3aed;
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
            z-index: 1;
        }

        .timeline-item.assigned::before {
            border-color: #10b981;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
        }

        .timeline-item.unassigned::before {
            border-color: #ef4444;
            box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.1);
        }

        .timeline-item.reassigned::before {
            border-color: #f59e0b;
            box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.1);
        }

        .timeline-item.assigned {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border-color: #bbf7d0;
        }

        .timeline-item.unassigned {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border-color: #fecaca;
        }

        .timeline-item.reassigned {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border-color: #fde68a;
        }

        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 16px;
        }

        .timeline-action {
            font-weight: 700;
            color: #1a202c;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .action-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .timeline-item.assigned .action-icon {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }

        .timeline-item.unassigned .action-icon {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }

        .timeline-item.reassigned .action-icon {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }

        .timeline-time {
            font-size: 13px;
            color: #718096;
            text-align: right;
            font-weight: 600;
        }

        .timeline-date {
            font-weight: 700;
            color: #1a202c;
        }

        .timeline-details {
            color: #4b5563;
            line-height: 1.8;
            font-size: 14px;
        }

        .timeline-user {
            display: inline-block;
            background: white;
            padding: 4px 12px;
            border-radius: 6px;
            font-weight: 700;
            color: #1a202c;
            margin: 0 4px;
            border: 2px solid #e2e8f0;
        }

        .timeline-performer {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 2px solid rgba(255, 255, 255, 0.8);
            font-size: 13px;
            color: #718096;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .performer-icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
        }

        /* Empty State */
        .no-history {
            text-align: center;
            padding: 80px 20px;
            color: #718096;
        }

        .no-history-icon {
            font-size: 64px;
            margin-bottom: 24px;
            opacity: 0.5;
        }

        .no-history h3 {
            font-size: 20px;
            color: #1a202c;
            margin-bottom: 12px;
            font-weight: 700;
        }

        .no-history p {
            font-size: 15px;
            line-height: 1.6;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .container {
                margin-left: 80px;
            }

            .container.sidebar-collapsed {
                margin-left: 80px;
            }

            .content-grid {
                grid-template-columns: 300px 1fr;
            }
        }

        @media (max-width: 968px) {
            .content-grid {
                grid-template-columns: 1fr;
            }

            .asset-summary {
                position: static;
            }
        }

        @media (max-width: 768px) {
            .container {
                margin-left: 0;
                padding: 20px;
            }

            .container.sidebar-collapsed {
                margin-left: 0;
            }

            .page-header {
                flex-direction: column;
                gap: 16px;
                align-items: flex-start;
                padding: 20px;
            }

            .page-header h1 {
                font-size: 22px;
                flex-wrap: wrap;
            }

            .back-btn {
                width: 100%;
                justify-content: center;
            }

            .asset-summary {
                padding: 20px;
            }

            .history-timeline {
                padding: 20px;
            }

            .timeline {
                padding-left: 40px;
            }

            .timeline::before {
                left: 12px;
            }

            .timeline-item::before {
                left: -32px;
                width: 16px;
                height: 16px;
                border-width: 3px;
            }

            .timeline-header {
                flex-direction: column;
                gap: 8px;
            }

            .timeline-time {
                text-align: left;
            }

            .action-icons {
                flex-direction: column;
            }
        }
    </style>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="container" id="mainContainer">
        <div class="page-header">
            <h1>
                <i class="fas fa-history"></i> Asset History
                <span class="asset-code-badge"><?php echo htmlspecialchars($asset['asset_code']); ?></span>
            </h1>
            <a href="asset.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Inventory
            </a>
        </div>

        <div class="content-grid">
            <!-- Asset Summary Sidebar -->
            <div class="asset-summary">
                <h3><i class="fas fa-info-circle"></i> Asset Details</h3>
                
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
                            <?php echo htmlspecialchars($asset['assigned_user_name']); ?><br>
                            <small style="color: #718096; font-weight: 400;">@<?php echo htmlspecialchars($asset['assigned_username']); ?></small>
                        <?php else: ?>
                            <span style="color: #9ca3af;">Unassigned</span>
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
                        <a href="assetEdit.php?id=<?php echo $asset['id']; ?>" class="action-btn edit">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                    <?php endif; ?>
                    <a href="asset.php" class="action-btn inventory">
                        <i class="fas fa-box"></i> Inventory
                    </a>
                </div>
            </div>

            <!-- History Timeline -->
            <div class="history-timeline">
                <h2><i class="fas fa-clock"></i> Complete History Timeline</h2>
                
                <?php if (count($history) > 0): ?>
                    <div class="timeline">
                        <?php foreach ($history as $record): ?>
                            <div class="timeline-item <?php echo $record['action_type']; ?>">
                                <div class="timeline-header">
                                    <div class="timeline-action">
                                        <div class="action-icon">
                                            <?php 
                                            $action_icons = [
                                                'assigned' => '<i class="fas fa-check"></i>',
                                                'unassigned' => '<i class="fas fa-times"></i>',
                                                'reassigned' => '<i class="fas fa-exchange-alt"></i>'
                                            ];
                                            echo $action_icons[$record['action_type']] ?? '<i class="fas fa-circle"></i>';
                                            ?>
                                        </div>
                                        <span><?php echo ucfirst($record['action_type']); ?></span>
                                    </div>
                                    <div class="timeline-time">
                                        <div class="timeline-date">
                                            <?php 
                                            $date = new DateTime($record['created_at']);
                                            echo $date->format('M d, Y');
                                            ?>
                                        </div>
                                        <div><?php echo $date->format('h:i A'); ?></div>
                                    </div>
                                </div>
                                
                                <div class="timeline-details">
                                    <?php if ($record['action_type'] === 'assigned'): ?>
                                        Asset was assigned to 
                                        <span class="timeline-user"><?php echo htmlspecialchars($record['new_user_name']); ?></span>
                                        <small style="color: #9ca3af;">(@<?php echo htmlspecialchars($record['new_username']); ?>)</small>
                                        
                                    <?php elseif ($record['action_type'] === 'unassigned'): ?>
                                        Asset was unassigned from 
                                        <span class="timeline-user"><?php echo htmlspecialchars($record['prev_user_name']); ?></span>
                                        <small style="color: #9ca3af;">(@<?php echo htmlspecialchars($record['prev_username']); ?>)</small>
                                        
                                    <?php elseif ($record['action_type'] === 'reassigned'): ?>
                                        Asset was reassigned from 
                                        <span class="timeline-user"><?php echo htmlspecialchars($record['prev_user_name']); ?></span>
                                        to 
                                        <span class="timeline-user"><?php echo htmlspecialchars($record['new_user_name']); ?></span>
                                    <?php endif; ?>
                                    
                                    <div class="timeline-performer">
                                        <div class="performer-icon">
                                            <?php echo strtoupper(substr($record['performer_full_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            Action performed by: <strong><?php echo htmlspecialchars($record['performer_full_name']); ?></strong>
                                            <small style="color: #9ca3af;">(@<?php echo htmlspecialchars($record['performer_name']); ?>)</small>
                                        </div>
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

    <script>
        // Handle sidebar toggle
        function updateMainContainer() {
            const mainContainer = document.getElementById('mainContainer');
            const sidebar = document.querySelector('.sidebar');
            
            if (sidebar && sidebar.classList.contains('collapsed')) {
                mainContainer.classList.add('sidebar-collapsed');
            } else {
                mainContainer.classList.remove('sidebar-collapsed');
            }
        }

        // Check on load
        document.addEventListener('DOMContentLoaded', updateMainContainer);

        // Listen for sidebar changes
        document.addEventListener('click', function(e) {
            if (e.target.closest('.toggle-sidebar')) {
                setTimeout(updateMainContainer, 50);
            }
        });

        // Observe sidebar changes
        const observer = new MutationObserver(updateMainContainer);
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) {
            observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
        }
    </script>
</body>
</html> 