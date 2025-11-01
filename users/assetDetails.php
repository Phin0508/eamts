<?php
session_start();

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['manager', 'admin'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Include database connection
include("../auth/config/database.php");

// Get user information
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];

// Get asset ID from URL
$asset_id = $_GET['id'] ?? null;

if (!$asset_id) {
    header("Location: managerAsset.php");
    exit();
}

// Fetch asset details
try {
    $query = "SELECT a.*, 
              CONCAT(assigned_user.first_name, ' ', assigned_user.last_name) as assigned_to_name,
              assigned_user.email as assigned_to_email,
              assigned_user.department as assigned_to_department,
              CONCAT(creator.first_name, ' ', creator.last_name) as created_by_name
              FROM assets a
              LEFT JOIN users assigned_user ON a.assigned_to = assigned_user.user_id
              LEFT JOIN users creator ON a.created_by = creator.user_id
              WHERE a.id = ?";
    
    // SECURITY: Managers can only view their own assets
    if ($user_role === 'manager') {
        $query .= " AND a.assigned_to = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$asset_id, $user_id]);
    } else {
        // Admins can view any asset
        $stmt = $pdo->prepare($query);
        $stmt->execute([$asset_id]);
    }
    
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$asset) {
        $_SESSION['error_message'] = "Asset not found or you don't have permission to view it.";
        header("Location: managerAsset.php");
        exit();
    }
    
    // Set default value for updated_by_name if not available
    $asset['updated_by_name'] = $asset['created_by_name'] ?? 'System';
    
} catch (PDOException $e) {
    die("Error fetching asset: " . $e->getMessage());
}

// Fetch maintenance history
$maintenance_history = [];
try {
    $maint_query = "SELECT am.*, 
                    CONCAT(u.first_name, ' ', u.last_name) as performed_by_name
                    FROM asset_maintenance am
                    LEFT JOIN users u ON am.performed_by = u.user_id
                    WHERE am.asset_id = ?
                    ORDER BY am.maintenance_date DESC";
    $stmt = $pdo->prepare($maint_query);
    $stmt->execute([$asset_id]);
    $maintenance_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Maintenance history error: " . $e->getMessage());
}

// Fetch related tickets
$related_tickets = [];
try {
    $ticket_query = "SELECT t.*, 
                     CONCAT(u.first_name, ' ', u.last_name) as requester_name,
                     CONCAT(tech.first_name, ' ', tech.last_name) as assigned_to_name
                     FROM tickets t
                     LEFT JOIN users u ON t.requester_id = u.user_id
                     LEFT JOIN users tech ON t.assigned_to = tech.user_id
                     WHERE t.asset_id = ?
                     ORDER BY t.created_at DESC
                     LIMIT 10";
    $stmt = $pdo->prepare($ticket_query);
    $stmt->execute([$asset_id]);
    $related_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Related tickets error: " . $e->getMessage());
}

// Calculate asset age
$purchase_date = $asset['purchase_date'] ? new DateTime($asset['purchase_date']) : null;
$current_date = new DateTime();
$asset_age = $purchase_date ? $purchase_date->diff($current_date) : null;

// Calculate depreciation (simple straight-line, assuming 5-year useful life)
$depreciation_value = 0;
$current_value = $asset['purchase_cost'];
if ($asset['purchase_cost'] && $asset_age) {
    $years_old = $asset_age->y + ($asset_age->m / 12);
    $useful_life = 5; // years
    $annual_depreciation = $asset['purchase_cost'] / $useful_life;
    $depreciation_value = min($annual_depreciation * $years_old, $asset['purchase_cost']);
    $current_value = max($asset['purchase_cost'] - $depreciation_value, 0);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Details - <?php echo htmlspecialchars($asset['asset_name']); ?></title>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        .dashboard-content {
            padding: 2rem;
            background: #f8f9fa;
            min-height: calc(100vh - 60px);
        }

        .page-header {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header-left h1 {
            margin: 0 0 0.5rem 0;
            color: #2c3e50;
            font-size: 1.75rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header-left p {
            margin: 0;
            color: #6c757d;
        }

        .asset-code-large {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-outline {
            background: white;
            color: #374151;
            border: 1px solid #d1d5db;
        }

        .btn-outline:hover {
            background: #f9fafb;
            border-color: #9ca3af;
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

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .info-card {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .info-card h2 {
            margin: 0 0 1.5rem 0;
            color: #2c3e50;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f0f0f0;
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-available { background: #d1fae5; color: #065f46; }
        .status-in-use { background: #dbeafe; color: #1e40af; }
        .status-maintenance { background: #fed7aa; color: #92400e; }
        .status-retired { background: #e5e7eb; color: #374151; }
        .status-damaged { background: #fecaca; color: #991b1b; }

        .detail-group {
            display: grid;
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .detail-item {
            display: flex;
            align-items: start;
            gap: 1rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .detail-item:hover {
            background: #e9ecef;
        }

        .detail-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .detail-content {
            flex: 1;
        }

        .detail-label {
            font-size: 0.8rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.25rem;
            font-weight: 600;
        }

        .detail-value {
            color: #2c3e50;
            font-size: 1rem;
            font-weight: 500;
        }

        .detail-value.large {
            font-size: 1.5rem;
            color: #059669;
        }

        .info-banner {
            background: #eff6ff;
            border-left: 4px solid #2563eb;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .info-banner.warning {
            background: #fef3c7;
            border-left-color: #f59e0b;
        }

        .info-banner.success {
            background: #d1fae5;
            border-left-color: #10b981;
        }

        .info-banner i {
            font-size: 1.25rem;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-box {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
        }

        .stat-box .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 0.25rem;
        }

        .stat-box .stat-label {
            font-size: 0.8rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

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
            padding-bottom: 2rem;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-marker {
            position: absolute;
            left: -1.5rem;
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            background: #667eea;
            border: 3px solid white;
            box-shadow: 0 0 0 2px #667eea;
        }

        .timeline-content {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
        }

        .timeline-date {
            font-size: 0.8rem;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }

        .timeline-title {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        .timeline-description {
            font-size: 0.9rem;
            color: #4b5563;
        }

        .timeline-meta {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
            font-size: 0.8rem;
            color: #6c757d;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .tickets-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .tickets-table th {
            background: #f8f9fa;
            padding: 0.75rem;
            text-align: left;
            font-size: 0.8rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            border-bottom: 2px solid #e5e7eb;
        }

        .tickets-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
            font-size: 0.9rem;
        }

        .tickets-table tr:hover {
            background: #f8f9fa;
        }

        .ticket-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .ticket-link:hover {
            text-decoration: underline;
        }

        .priority-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .priority-low { background: #d1fae5; color: #065f46; }
        .priority-medium { background: #dbeafe; color: #1e40af; }
        .priority-high { background: #fed7aa; color: #92400e; }
        .priority-urgent { background: #fecaca; color: #991b1b; }

        .no-data {
            text-align: center;
            padding: 2rem;
            color: #9ca3af;
            font-style: italic;
        }

        .no-data i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            display: block;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px solid #f0f0f0;
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }

            .stats-row {
                grid-template-columns: repeat(2, 1fr);
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

            .stats-row {
                grid-template-columns: 1fr;
            }
        }

        @media print {
            .main-content {
                margin: 0;
                padding: 20px;
            }
            
            .btn,
            nav,
            .action-buttons {
                display: none !important;
            }

            .info-card {
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/Msidebar.php"); ?>

    <main class="main-content">
        <div class="dashboard-content">
            <!-- Page Header -->
            <div class="page-header">
                <div class="header-left">
                    <h1>
                        <i class="fas fa-box"></i>
                        <?php echo htmlspecialchars($asset['asset_name']); ?>
                    </h1>
                    <p>Complete asset information and history</p>
                </div>
                <div class="header-right">
                    <span class="asset-code-large">
                        <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($asset['asset_code']); ?>
                    </span>
                </div>
            </div>

            <!-- View Only Banner -->
            <div class="info-banner">
                <i class="fas fa-eye"></i>
                <div>
                    <strong>View Only Mode:</strong> You are viewing this asset in read-only mode. 
                    Contact your administrator if changes are needed.
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="content-grid">
                <!-- Left Column: Main Information -->
                <div>
                    <!-- Basic Information Card -->
                    <div class="info-card">
                        <h2><i class="fas fa-info-circle"></i> Basic Information</h2>
                        
                        <div class="detail-group">
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-tag"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Asset Name</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($asset['asset_name']); ?></div>
                                </div>
                            </div>

                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-layer-group"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Category</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($asset['category']); ?></div>
                                </div>
                            </div>

                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-signal"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Status</div>
                                    <div class="detail-value">
                                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $asset['status'])); ?>">
                                            <?php echo htmlspecialchars($asset['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <?php if ($asset['brand']): ?>
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-trademark"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Brand</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($asset['brand']); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($asset['model']): ?>
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-cube"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Model</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($asset['model']); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($asset['serial_number']): ?>
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-barcode"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Serial Number</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($asset['serial_number']); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Location & Assignment Card -->
                    <div class="info-card" style="margin-top: 2rem;">
                        <h2><i class="fas fa-map-marker-alt"></i> Location & Assignment</h2>
                        
                        <div class="detail-group">
                            <?php if ($asset['location']): ?>
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-building"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Location</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($asset['location']); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($asset['department']): ?>
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-sitemap"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Department</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($asset['department']); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($asset['assigned_to_name']): ?>
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Assigned To</div>
                                    <div class="detail-value">
                                        <?php echo htmlspecialchars($asset['assigned_to_name']); ?>
                                        <?php if ($asset['assigned_to_email']): ?>
                                            <br><small style="color: #6c757d;"><?php echo htmlspecialchars($asset['assigned_to_email']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-user-slash"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Assignment Status</div>
                                    <div class="detail-value" style="color: #9ca3af;">Not assigned</div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Additional Details Card -->
                    <?php if (!empty($asset['description']) || !empty($asset['specifications'] ?? '') || !empty($asset['notes'] ?? '')): ?>
                    <div class="info-card" style="margin-top: 2rem;">
                        <h2><i class="fas fa-file-alt"></i> Additional Details</h2>
                        
                        <?php if (!empty($asset['description'])): ?>
                        <div style="margin-bottom: 1.5rem;">
                            <div class="detail-label" style="margin-bottom: 0.5rem;">Description</div>
                            <div style="background: #f8f9fa; padding: 1rem; border-radius: 8px; line-height: 1.6;">
                                <?php echo nl2br(htmlspecialchars($asset['description'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($asset['specifications'] ?? '')): ?>
                        <div style="margin-bottom: 1.5rem;">
                            <div class="detail-label" style="margin-bottom: 0.5rem;">Specifications</div>
                            <div style="background: #f8f9fa; padding: 1rem; border-radius: 8px; line-height: 1.6;">
                                <?php echo nl2br(htmlspecialchars($asset['specifications'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($asset['notes'] ?? '')): ?>
                        <div>
                            <div class="detail-label" style="margin-bottom: 0.5rem;">Notes</div>
                            <div style="background: #f8f9fa; padding: 1rem; border-radius: 8px; line-height: 1.6;">
                                <?php echo nl2br(htmlspecialchars($asset['notes'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Right Column: Financial & Timeline -->
                <div>
                    <!-- Financial Information Card -->
                    <div class="info-card">
                        <h2><i class="fas fa-dollar-sign"></i> Financial Information</h2>
                        
                        <div class="stats-row">
                            <div class="stat-box">
                                <div class="stat-number" style="color: #059669;">
                                    $<?php echo number_format($asset['purchase_cost'], 2); ?>
                                </div>
                                <div class="stat-label">Purchase Cost</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number" style="color: #dc2626;">
                                    $<?php echo number_format($depreciation_value, 2); ?>
                                </div>
                                <div class="stat-label">Depreciation</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number" style="color: #2563eb;">
                                    $<?php echo number_format($current_value, 2); ?>
                                </div>
                                <div class="stat-label">Current Value</div>
                            </div>
                        </div>

                        <div class="detail-group">
                            <?php if ($asset['purchase_date']): ?>
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Purchase Date</div>
                                    <div class="detail-value">
                                        <?php echo date('F j, Y', strtotime($asset['purchase_date'])); ?>
                                        <?php if ($asset_age): ?>
                                            <br><small style="color: #6c757d;">
                                                <?php 
                                                if ($asset_age->y > 0) echo $asset_age->y . ' year' . ($asset_age->y > 1 ? 's' : '');
                                                if ($asset_age->m > 0) echo ' ' . $asset_age->m . ' month' . ($asset_age->m > 1 ? 's' : '');
                                                echo ' old';
                                                ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($asset['warranty_expiry']): ?>
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Warranty Expiry</div>
                                    <div class="detail-value">
                                        <?php 
                                        $warranty_date = new DateTime($asset['warranty_expiry']);
                                        $is_expired = $warranty_date < $current_date;
                                        echo date('F j, Y', strtotime($asset['warranty_expiry']));
                                        ?>
                                        <br><small style="color: <?php echo $is_expired ? '#dc2626' : '#059669'; ?>;">
                                            <?php echo $is_expired ? '⚠️ Expired' : '✓ Active'; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($asset['supplier']): ?>
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-truck"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Supplier</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($asset['supplier']); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- System Information Card -->
                    <div class="info-card" style="margin-top: 2rem;">
                        <h2><i class="fas fa-clock"></i> System Information</h2>
                        
                        <div class="detail-group">
                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-plus-circle"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Created</div>
                                    <div class="detail-value">
                                        <?php echo date('F j, Y g:i A', strtotime($asset['created_at'])); ?>
                                        <?php if ($asset['created_by_name']): ?>
                                            <br><small style="color: #6c757d;">by <?php echo htmlspecialchars($asset['created_by_name']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="detail-item">
                                <div class="detail-icon">
                                    <i class="fas fa-edit"></i>
                                </div>
                                <div class="detail-content">
                                    <div class="detail-label">Last Updated</div>
                                    <div class="detail-value">
                                        <?php echo date('F j, Y g:i A', strtotime($asset['updated_at'])); ?>
                                        <?php if ($asset['updated_by_name']): ?>
                                            <br><small style="color: #6c757d;">by <?php echo htmlspecialchars($asset['updated_by_name']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Maintenance History Section -->
            <?php if (count($maintenance_history) > 0): ?>
            <div class="info-card">
                <h2><i class="fas fa-tools"></i> Maintenance History (<?php echo count($maintenance_history); ?>)</h2>
                
                <div class="timeline">
                    <?php foreach ($maintenance_history as $index => $maintenance): ?>
                    <div class="timeline-item">
                        <div class="timeline-marker"></div>
                        <div class="timeline-content">
                            <div class="timeline-date">
                                <i class="fas fa-calendar"></i>
                                <?php echo date('F j, Y', strtotime($maintenance['maintenance_date'])); ?>
                            </div>
                            <div class="timeline-title">
                                <?php echo htmlspecialchars($maintenance['maintenance_type']); ?>
                            </div>
                            <?php if ($maintenance['description']): ?>
                            <div class="timeline-description">
                                <?php echo nl2br(htmlspecialchars($maintenance['description'])); ?>
                            </div>
                            <?php endif; ?>
                            <div class="timeline-meta">
                                <?php if ($maintenance['cost']): ?>
                                <span><i class="fas fa-dollar-sign"></i> $<?php echo number_format($maintenance['cost'], 2); ?></span>
                                <?php endif; ?>
                                <?php if ($maintenance['performed_by_name']): ?>
                                <span><i class="fas fa-user-check"></i> <?php echo htmlspecialchars($maintenance['performed_by_name']); ?></span>
                                <?php endif; ?>
                                <?php if ($maintenance['vendor']): ?>
                                <span><i class="fas fa-building"></i> <?php echo htmlspecialchars($maintenance['vendor']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="info-card">
                <h2><i class="fas fa-tools"></i> Maintenance History</h2>
                <div class="no-data">
                    <i class="fas fa-wrench"></i>
                    No maintenance records found for this asset.
                </div>
            </div>
            <?php endif; ?>

            <!-- Related Tickets Section -->
            <?php if (count($related_tickets) > 0): ?>
            <div class="info-card" style="margin-top: 2rem;">
                <h2><i class="fas fa-ticket-alt"></i> Related Support Tickets (<?php echo count($related_tickets); ?>)</h2>
                
                <div class="table-responsive">
                    <table class="tickets-table">
                        <thead>
                            <tr>
                                <th>Ticket #</th>
                                <th>Subject</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Requester</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($related_tickets as $ticket): ?>
                            <tr>
                                <td>
                                    <a href="managerticketDetails.php?id=<?php echo $ticket['ticket_id']; ?>" class="ticket-link">
                                        <?php echo htmlspecialchars($ticket['ticket_number']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($ticket['subject']); ?></td>
                                <td>
                                    <span class="priority-badge priority-<?php echo $ticket['priority']; ?>">
                                        <?php echo strtoupper($ticket['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower(str_replace('_', '-', $ticket['status'])); ?>">
                                        <?php echo strtoupper(str_replace('_', ' ', $ticket['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($ticket['requester_name']); ?></td>
                                <td><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="info-card" style="margin-top: 2rem;">
                <h2><i class="fas fa-ticket-alt"></i> Related Support Tickets</h2>
                <div class="no-data">
                    <i class="fas fa-inbox"></i>
                    No support tickets related to this asset.
                </div>
            </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div class="info-card" style="margin-top: 2rem;">
                <div class="action-buttons">
                    <a href="managerAsset.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to My Assets
                    </a>
                    <a href="managerCreateTicket.php?asset_id=<?php echo $asset_id; ?>" class="btn btn-primary">
                        <i class="fas fa-ticket-alt"></i> Create Ticket for This Asset
                    </a>
                    <button onclick="window.print()" class="btn btn-outline">
                        <i class="fas fa-print"></i> Print Details
                    </button>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Smooth scroll to sections
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });

        // Add animation on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -100px 0px'
        };

        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        document.querySelectorAll('.info-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            observer.observe(card);
        });

        // Add copy functionality for asset code
        document.querySelector('.asset-code-large').addEventListener('click', function() {
            const assetCode = this.textContent.trim().replace('#', '').trim();
            navigator.clipboard.writeText(assetCode).then(() => {
                const originalText = this.innerHTML;
                this.innerHTML = '<i class="fas fa-check"></i> Copied!';
                this.style.background = 'linear-gradient(135deg, #10b981, #059669)';
                
                setTimeout(() => {
                    this.innerHTML = originalText;
                    this.style.background = 'linear-gradient(135deg, #667eea, #764ba2)';
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy: ', err);
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // ESC to go back
            if (e.key === 'Escape') {
                window.location.href = 'managerAsset.php';
            }
            
            // Ctrl/Cmd + P for print
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.print();
            }

            // Ctrl/Cmd + T to create ticket
            if ((e.ctrlKey || e.metaKey) && e.key === 't') {
                e.preventDefault();
                window.location.href = 'managerCreateTicket.php?asset_id=<?php echo $asset_id; ?>';
            }
        });

        // Add tooltip for shortcuts
        const shortcutInfo = document.createElement('div');
        shortcutInfo.style.cssText = 'position: fixed; bottom: 20px; right: 20px; background: rgba(0,0,0,0.8); color: white; padding: 10px 15px; border-radius: 8px; font-size: 12px; opacity: 0; transition: opacity 0.3s; pointer-events: none; z-index: 1000;';
        shortcutInfo.innerHTML = '<strong>Keyboard Shortcuts:</strong><br>ESC - Back | Ctrl+P - Print | Ctrl+T - Create Ticket';
        document.body.appendChild(shortcutInfo);

        // Show shortcuts on ? key
        document.addEventListener('keydown', function(e) {
            if (e.key === '?') {
                shortcutInfo.style.opacity = '1';
                setTimeout(() => {
                    shortcutInfo.style.opacity = '0';
                }, 3000);
            }
        });

        // Highlight urgent/warning items
        document.addEventListener('DOMContentLoaded', function() {
            // Check warranty expiry
            const warrantyItem = document.querySelector('.detail-content:has(.detail-label:contains("Warranty Expiry"))');
            
            // Highlight maintenance count if high
            const maintenanceCount = <?php echo count($maintenance_history); ?>;
            if (maintenanceCount > 5) {
                const maintenanceTitle = document.querySelector('h2:has(.fa-tools)');
                if (maintenanceTitle) {
                    const badge = document.createElement('span');
                    badge.style.cssText = 'background: #fed7aa; color: #92400e; padding: 0.25rem 0.5rem; border-radius: 12px; font-size: 0.75rem; margin-left: 0.5rem;';
                    badge.textContent = 'Frequent Maintenance';
                    maintenanceTitle.appendChild(badge);
                }
            }

            // Highlight if asset is damaged or in maintenance
            const status = '<?php echo strtolower($asset['status']); ?>';
            if (status === 'damaged' || status === 'maintenance') {
                const statusBanner = document.createElement('div');
                statusBanner.className = 'info-banner warning';
                statusBanner.innerHTML = '<i class="fas fa-exclamation-triangle"></i><div><strong>Attention:</strong> This asset requires attention and may not be available for regular use.</div>';
                document.querySelector('.dashboard-content').insertBefore(statusBanner, document.querySelector('.content-grid'));
            }
        });

        // Add interactive timeline markers
        document.querySelectorAll('.timeline-item').forEach((item, index) => {
            item.addEventListener('mouseenter', function() {
                this.style.transform = 'translateX(5px)';
                this.style.transition = 'transform 0.3s ease';
            });
            
            item.addEventListener('mouseleave', function() {
                this.style.transform = 'translateX(0)';
            });

            // Add animation delay
            setTimeout(() => {
                item.style.opacity = '1';
                item.style.transform = 'translateX(0)';
            }, index * 100);

            item.style.opacity = '0';
            item.style.transform = 'translateX(-20px)';
            item.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        });

        // Add hover effect to detail items
        document.querySelectorAll('.detail-item').forEach(item => {
            item.addEventListener('mouseenter', function() {
                this.style.transform = 'translateX(5px)';
            });
            
            item.addEventListener('mouseleave', function() {
                this.style.transform = 'translateX(0)';
            });
        });

        // Add click-to-expand for long descriptions
        document.querySelectorAll('.timeline-description').forEach(desc => {
            if (desc.scrollHeight > 100) {
                desc.style.maxHeight = '100px';
                desc.style.overflow = 'hidden';
                desc.style.position = 'relative';
                
                const expandBtn = document.createElement('button');
                expandBtn.textContent = 'Read more...';
                expandBtn.style.cssText = 'background: none; border: none; color: #667eea; cursor: pointer; font-size: 0.875rem; margin-top: 0.5rem; padding: 0;';
                
                expandBtn.addEventListener('click', function() {
                    if (desc.style.maxHeight === '100px') {
                        desc.style.maxHeight = 'none';
                        this.textContent = 'Read less';
                    } else {
                        desc.style.maxHeight = '100px';
                        this.textContent = 'Read more...';
                    }
                });
                
                desc.after(expandBtn);
            }
        });


        // Add success message if coming from ticket creation
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('ticket_created') === 'true') {
            const successBanner = document.createElement('div');
            successBanner.className = 'info-banner success';
            successBanner.innerHTML = '<i class="fas fa-check-circle"></i><div><strong>Success!</strong> Your ticket has been created for this asset.</div>';
            successBanner.style.animation = 'slideIn 0.5s ease';
            document.querySelector('.dashboard-content').insertBefore(successBanner, document.querySelector('.page-header').nextSibling);
            
            setTimeout(() => {
                successBanner.style.animation = 'slideOut 0.5s ease';
                setTimeout(() => successBanner.remove(), 500);
            }, 5000);
        }

        // Add CSS animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateY(-20px); opacity: 0; }
                to { transform: translateY(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateY(0); opacity: 1; }
                to { transform: translateY(-20px); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>