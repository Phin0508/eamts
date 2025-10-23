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

// Fetch assets assigned to this user
$assets = [];
$total_value = 0;
try {
    $query = "SELECT a.*, 
              u.username as created_by_name,
              CONCAT(u.first_name, ' ', u.last_name) as created_by_fullname
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

        .asset-details-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
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

        .no-assets-message {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .no-assets-message .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .no-assets-message h2 {
            color: #2c3e50;
            margin-bottom: 10px;
        }

        .no-assets-message p {
            color: #6c757d;
            font-size: 16px;
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

        @media (max-width: 768px) {
            .asset-details-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .asset-details-grid {
                grid-template-columns: 1fr;
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
                <div class="stat-card">
                    <h3>Maintenance</h3>
                    <p class="stat-number"><?php echo $status_counts['Maintenance']; ?></p>
                </div>
            </div>

            <!-- Assets List -->
            <?php if (count($assets) > 0): ?>
                <h2 style="margin-bottom: 20px; color: #2c3e50;">📋 Asset Details</h2>
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

                            <?php if ($asset['supplier']): ?>
                                <div class="detail-item">
                                    <div class="detail-label">Supplier</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($asset['supplier']); ?></div>
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
    </div>

</body>

</html>