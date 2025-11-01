<?php
session_start();

// Check if user is logged in and has admin/manager role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("Location: login.php");
    exit();
}

// Include database configuration
include("../auth/config/database.php");

$error_message = '';
$user_data = null;

// Get user ID from URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: userList.php");
    exit();
}

$user_id = $_GET['id'];

// Fetch user details
try {
    $stmt = $pdo->prepare("
        SELECT user_id, first_name, last_name, email, username, phone, 
               department, role, employee_id, is_active, is_verified, 
               created_at, updated_at, last_login
        FROM users 
        WHERE user_id = ? AND (is_deleted IS NULL OR is_deleted = 0)
    ");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_data) {
        header("Location: userList.php");
        exit();
    }
    
    // Get user's asset count
    $asset_count = 0;
    try {
        $asset_stmt = $pdo->prepare("SELECT COUNT(*) as asset_count FROM assets WHERE assigned_to = ?");
        $asset_stmt->execute([$user_id]);
        $asset_count = $asset_stmt->fetch(PDO::FETCH_ASSOC)['asset_count'];
    } catch (PDOException $e) {
        $asset_count = 0;
    }
    
    // Get user's recent activity (from assets_history)
    $activities = [];
    try {
        $activity_stmt = $pdo->prepare("
            SELECT 
                ah.action_type as action,
                CONCAT('Asset ', a.asset_name, ' (', a.asset_code, ')') as description,
                ah.created_at,
                'System' as ip_address
            FROM assets_history ah
            LEFT JOIN assets a ON ah.asset_id = a.id
            WHERE ah.performed_by = ? OR ah.assigned_to = ? OR ah.assigned_from = ?
            ORDER BY ah.created_at DESC 
            LIMIT 10
        ");
        $activity_stmt->execute([$user_id, $user_id, $user_id]);
        $activities = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // If assets_history doesn't exist, just use empty array
        $activities = [];
    }
    
    // Get user's assigned assets
    $assigned_assets = [];
    try {
        $assets_stmt = $pdo->prepare("
            SELECT id as asset_id, asset_code as asset_tag, asset_name, category, status, created_at as assigned_date
            FROM assets 
            WHERE assigned_to = ? 
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $assets_stmt->execute([$user_id]);
        $assigned_assets = $assets_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $assigned_assets = [];
    }
    
} catch (PDOException $e) {
    $error_message = "Error fetching user details: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Details - <?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?></title>
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

        .container {
            margin-left: 260px;
            padding: 30px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }

        .container.sidebar-collapsed {
            margin-left: 80px;
        }

        /* Header */
        .header {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .back-btn {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #f7f4fe;
            color: #7c3aed;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.3s;
            font-size: 18px;
        }

        .back-btn:hover {
            background: #7c3aed;
            color: white;
            transform: translateX(-3px);
        }

        .header-title h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
        }

        /* Profile Card */
        .profile-card {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            border-radius: 16px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 8px 24px rgba(124, 58, 237, 0.3);
            color: white;
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 30px;
            margin-bottom: 30px;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: white;
            color: #7c3aed;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            font-weight: 700;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
        }

        .profile-info h2 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .profile-info p {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 15px;
        }

        .profile-badges {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .profile-badge {
            padding: 8px 16px;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            padding-top: 30px;
            border-top: 2px solid rgba(255, 255, 255, 0.2);
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        /* Info Card */
        .info-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .info-card h3 {
            font-size: 20px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }

        .info-card h3 i {
            color: #7c3aed;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 16px 0;
            border-bottom: 1px solid #f1f3f5;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: #718096;
            font-weight: 600;
            font-size: 14px;
        }

        .info-value {
            color: #1a202c;
            font-weight: 500;
            font-size: 14px;
            text-align: right;
        }

        /* Badge */
        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .badge-active {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }

        .badge-inactive {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }

        .badge-verified {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }

        .badge-unverified {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #6b7280;
        }

        .badge-admin {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        .badge-manager {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }

        .badge-employee {
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
            color: #3730a3;
        }

        /* Activity Timeline */
        .activity-timeline {
            position: relative;
        }

        .activity-item {
            position: relative;
            padding-left: 30px;
            padding-bottom: 24px;
            border-left: 2px solid #e2e8f0;
        }

        .activity-item:last-child {
            border-left: 2px solid transparent;
            padding-bottom: 0;
        }

        .activity-icon {
            position: absolute;
            left: -9px;
            top: 0;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #7c3aed;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 10px;
        }

        .activity-content {
            background: #f9fafb;
            padding: 12px 16px;
            border-radius: 8px;
        }

        .activity-action {
            font-weight: 600;
            color: #1a202c;
            font-size: 14px;
            margin-bottom: 4px;
        }

        .activity-desc {
            color: #718096;
            font-size: 13px;
            margin-bottom: 6px;
        }

        .activity-meta {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #9ca3af;
        }

        /* Assets List */
        .asset-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px;
            background: #f9fafb;
            border-radius: 10px;
            margin-bottom: 12px;
            transition: all 0.3s;
        }

        .asset-item:hover {
            background: #f3f4f6;
            transform: translateX(5px);
        }

        .asset-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .asset-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .asset-details h4 {
            font-size: 14px;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 4px;
        }

        .asset-details p {
            font-size: 12px;
            color: #718096;
        }

        .badge-available {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }

        .badge-in-use {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        .badge-maintenance {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        .empty-state p {
            color: #718096;
            font-size: 14px;
        }

        /* Buttons */
        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .container {
                margin-left: 80px;
            }

            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .container {
                margin-left: 0;
                padding: 20px;
            }

            .header {
                padding: 20px;
            }

            .header-top {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .profile-card {
                padding: 30px 20px;
            }

            .profile-header {
                flex-direction: column;
                text-align: center;
            }

            .profile-info h2 {
                font-size: 24px;
            }

            .profile-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .content-grid {
                grid-template-columns: 1fr;
            }

            .info-card {
                padding: 20px;
            }
        }
    </style>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="container" id="mainContainer">
        <?php if ($error_message): ?>
        <div class="error-message">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error_message); ?></span>
        </div>
        <?php endif; ?>

        <?php if ($user_data): ?>
        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="header-title">
                    <a href="userList.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <h1>User Details</h1>
                </div>
                <a href="userList.php" class="btn btn-primary">
                    <i class="fas fa-list"></i> Back to User List
                </a>
            </div>
        </div>

        <!-- Profile Card -->
        <div class="profile-card">
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php echo strtoupper(substr($user_data['first_name'], 0, 1) . substr($user_data['last_name'], 0, 1)); ?>
                </div>
                <div class="profile-info">
                    <h2><?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?></h2>
                    <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user_data['email']); ?></p>
                    <div class="profile-badges">
                        <span class="profile-badge">
                            <i class="fas fa-user-tag"></i>
                            <?php echo ucfirst($user_data['role']); ?>
                        </span>
                        <span class="profile-badge">
                            <i class="fas fa-building"></i>
                            <?php echo htmlspecialchars($user_data['department']); ?>
                        </span>
                        <span class="profile-badge">
                            <i class="fas fa-<?php echo $user_data['is_active'] ? 'check-circle' : 'times-circle'; ?>"></i>
                            <?php echo $user_data['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                        <?php if ($user_data['is_verified']): ?>
                        <span class="profile-badge">
                            <i class="fas fa-shield-check"></i>
                            Verified
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="profile-stats">
                <div class="stat-item">
                    <div class="stat-value"><?php echo $asset_count; ?></div>
                    <div class="stat-label">Assigned Assets</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo count($activities); ?></div>
                    <div class="stat-label">Recent Activities</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">
                        <?php echo $user_data['last_login'] ? date('M d', strtotime($user_data['last_login'])) : 'Never'; ?>
                    </div>
                    <div class="stat-label">Last Login</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo date('M d, Y', strtotime($user_data['created_at'])); ?></div>
                    <div class="stat-label">Member Since</div>
                </div>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Personal Information -->
            <div class="info-card">
                <h3><i class="fas fa-user"></i> Personal Information</h3>
                <div class="info-row">
                    <span class="info-label">Full Name</span>
                    <span class="info-value"><?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Username</span>
                    <span class="info-value">@<?php echo htmlspecialchars($user_data['username']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email Address</span>
                    <span class="info-value"><?php echo htmlspecialchars($user_data['email']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Phone Number</span>
                    <span class="info-value">
                        <?php echo $user_data['phone'] ? htmlspecialchars($user_data['phone']) : 'Not provided'; ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Employee ID</span>
                    <span class="info-value">
                        <?php echo $user_data['employee_id'] ? htmlspecialchars($user_data['employee_id']) : 'Not assigned'; ?>
                    </span>
                </div>
            </div>

            <!-- Account Information -->
            <div class="info-card">
                <h3><i class="fas fa-cog"></i> Account Information</h3>
                <div class="info-row">
                    <span class="info-label">Department</span>
                    <span class="info-value"><?php echo htmlspecialchars($user_data['department']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Role</span>
                    <span class="info-value">
                        <span class="badge badge-<?php echo $user_data['role']; ?>">
                            <?php echo ucfirst($user_data['role']); ?>
                        </span>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Account Status</span>
                    <span class="info-value">
                        <span class="badge badge-<?php echo $user_data['is_active'] ? 'active' : 'inactive'; ?>">
                            <?php echo $user_data['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Verification Status</span>
                    <span class="info-value">
                        <span class="badge badge-<?php echo $user_data['is_verified'] ? 'verified' : 'unverified'; ?>">
                            <?php echo $user_data['is_verified'] ? 'Verified' : 'Not Verified'; ?>
                        </span>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Last Login</span>
                    <span class="info-value">
                        <?php echo $user_data['last_login'] ? date('M d, Y H:i', strtotime($user_data['last_login'])) : 'Never logged in'; ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Account Created</span>
                    <span class="info-value"><?php echo date('M d, Y H:i', strtotime($user_data['created_at'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Last Updated</span>
                    <span class="info-value">
                        <?php echo $user_data['updated_at'] ? date('M d, Y H:i', strtotime($user_data['updated_at'])) : 'Never updated'; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Assigned Assets -->
        <div class="info-card" style="margin-bottom: 30px;">
            <h3><i class="fas fa-laptop"></i> Assigned Assets (<?php echo count($assigned_assets); ?>)</h3>
            <?php if (count($assigned_assets) > 0): ?>
                <?php foreach ($assigned_assets as $asset): ?>
                    <div class="asset-item">
                        <div class="asset-info">
                            <div class="asset-icon">
                                <i class="fas fa-laptop"></i>
                            </div>
                            <div class="asset-details">
                                <h4>
                                    <a href="assetDetails.php?id=<?php echo $asset['asset_id']; ?>" style="color: #1a202c; text-decoration: none;">
                                        <?php echo htmlspecialchars($asset['asset_name']); ?>
                                    </a>
                                </h4>
                                <p>
                                    <?php echo htmlspecialchars($asset['asset_tag']); ?> • 
                                    <?php echo htmlspecialchars($asset['category']); ?> •
                                    Added: <?php echo date('M d, Y', strtotime($asset['assigned_date'])); ?>
                                </p>
                            </div>
                        </div>
                        <span class="badge badge-<?php echo strtolower(str_replace(' ', '-', $asset['status'])); ?>">
                            <?php echo htmlspecialchars($asset['status']); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
                <?php if ($asset_count > 5): ?>
                <div style="text-align: center; margin-top: 15px;">
                    <a href="../public/asset.php?assigned_to=<?php echo $user_id; ?>" class="btn btn-primary">
                        <i class="fas fa-list"></i> View All Assets (<?php echo $asset_count; ?>)
                    </a>
                </div>
                <?php endif; ?>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">📦</div>
                <p>No assets assigned to this user</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Recent Activity -->
        <div class="info-card">
            <h3><i class="fas fa-history"></i> Recent Activity</h3>
            <?php if (count($activities) > 0): ?>
            <div class="activity-timeline">
                <?php foreach ($activities as $activity): ?>
                <div class="activity-item">
                    <div class="activity-icon">
                        <i class="fas fa-circle"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-action"><?php echo htmlspecialchars(ucfirst($activity['action'])); ?></div>
                        <div class="activity-desc"><?php echo htmlspecialchars($activity['description']); ?></div>
                        <div class="activity-meta">
                            <span><i class="fas fa-clock"></i> <?php echo date('M d, Y H:i', strtotime($activity['created_at'])); ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">📊</div>
                <p>No recent activity recorded</p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
        function updateMainContainer() {
            const mainContainer = document.getElementById('mainContainer');
            const sidebar = document.querySelector('.sidebar');
            
            if (sidebar && sidebar.classList.contains('collapsed')) {
                mainContainer.classList.add('sidebar-collapsed');
            } else {
                mainContainer.classList.remove('sidebar-collapsed');
            }
        }

        document.addEventListener('DOMContentLoaded', updateMainContainer);

        document.addEventListener('click', function(e) {
            if (e.target.closest('.toggle-sidebar')) {
                setTimeout(updateMainContainer, 50);
            }
        });

        const observer = new MutationObserver(updateMainContainer);
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) {
            observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
        }
    </script>
</body>
</html>