<?php
session_start();

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager', 'employee'])) {
    header("Location: login.php");
    exit();
}

// Include database configuration
include("../auth/config/database.php");

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_department = $_SESSION['department'];

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$category_filter = $_GET['category'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Build the query based on user role
if ($user_role === 'admin') {
    // Admin can see all departments or filter by specific department
    $dept_filter = $_GET['department'] ?? 'all';
    
    if ($dept_filter === 'all') {
        $where_clause = "WHERE 1=1";
        $params = [];
    } else {
        $where_clause = "WHERE a.department = ?";
        $params = [$dept_filter];
    }
} else {
    // Manager and employee can only see their own department
    $where_clause = "WHERE a.department = ?";
    $params = [$user_department];
    $dept_filter = $user_department;
}

// Add status filter
if ($status_filter !== 'all') {
    $where_clause .= " AND a.status = ?";
    $params[] = $status_filter;
}

// Add category filter
if ($category_filter !== 'all') {
    $where_clause .= " AND a.category = ?";
    $params[] = $category_filter;
}

// Add search filter
if (!empty($search_query)) {
    $where_clause .= " AND (a.asset_name LIKE ? OR a.asset_code LIKE ? OR a.brand LIKE ? OR a.model LIKE ?)";
    $search_param = "%{$search_query}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

// Get assets with assigned user information
$assets_query = "
    SELECT 
        a.*,
        CONCAT(u.first_name, ' ', u.last_name) as assigned_user_name,
        u.email as assigned_user_email,
        u.employee_id as assigned_user_emp_id
    FROM assets a
    LEFT JOIN users u ON a.assigned_to = u.user_id
    {$where_clause}
    ORDER BY a.created_at DESC
";

$stmt = $pdo->prepare($assets_query);
$stmt->execute($params);
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get department statistics
if ($user_role === 'admin' && $dept_filter === 'all') {
    $stats_query = "
        SELECT 
            COUNT(*) as total_assets,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_assets,
            SUM(CASE WHEN status = 'in_use' THEN 1 ELSE 0 END) as in_use_assets,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_assets,
            SUM(purchase_cost) as total_value
        FROM assets
    ";
    $stats = $pdo->query($stats_query)->fetch(PDO::FETCH_ASSOC);
} else {
    $dept_param = $dept_filter;
    $stats_query = "
        SELECT 
            COUNT(*) as total_assets,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_assets,
            SUM(CASE WHEN status = 'in_use' THEN 1 ELSE 0 END) as in_use_assets,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_assets,
            SUM(purchase_cost) as total_value
        FROM assets
        WHERE department = ?
    ";
    $stats_stmt = $pdo->prepare($stats_query);
    $stats_stmt->execute([$dept_param]);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all departments for admin dropdown
$departments_list = [];
if ($user_role === 'admin') {
    $departments_list = $pdo->query("SELECT dept_name FROM departments WHERE is_active = 1 ORDER BY dept_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Get unique categories for filter
$categories = $pdo->query("SELECT DISTINCT category FROM assets WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Assets - E-Asset Management System</title>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .header h1 {
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-subtitle {
            opacity: 0.9;
            margin-top: 5px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }

        .btn-primary {
            background: white;
            color: #667eea;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            padding: 30px;
            background: #f9fafb;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .stat-card h3 {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .number {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
        }

        .filters {
            padding: 30px;
            border-bottom: 1px solid #e5e7eb;
        }

        .filters-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }

        .assets-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            padding: 30px;
        }

        .asset-card {
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s;
            position: relative;
        }

        .asset-card:hover {
            border-color: #667eea;
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.15);
            transform: translateY(-2px);
        }

        .asset-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .asset-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .asset-title h3 {
            font-size: 18px;
            color: #111827;
            margin-bottom: 5px;
        }

        .asset-code {
            font-size: 12px;
            color: #6b7280;
            font-weight: 600;
            background: #f3f4f6;
            padding: 4px 10px;
            border-radius: 4px;
            display: inline-block;
        }

        .asset-details {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin: 15px 0;
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
        }

        .detail-row strong {
            color: #374151;
        }

        .detail-row span {
            color: #6b7280;
        }

        .assigned-user {
            background: #ede9fe;
            padding: 12px;
            border-radius: 8px;
            margin: 15px 0;
        }

        .assigned-user h4 {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #667eea;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        .user-details {
            flex: 1;
        }

        .user-name {
            font-weight: 600;
            color: #111827;
            font-size: 14px;
        }

        .user-email {
            font-size: 12px;
            color: #6b7280;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-available {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-in-use {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-maintenance {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-retired {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #6b7280;
        }

        .empty-state svg {
            width: 100px;
            height: 100px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            .assets-grid {
                grid-template-columns: 1fr;
            }

            .filters-row {
                flex-direction: column;
            }

            .filter-group {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>
    <div class="container">
        <div class="header">
            <div class="header-top">
                <div>
                    <h1>📦 Department Assets</h1>
                    <p class="header-subtitle">
                        <?php if ($user_role === 'admin' && $dept_filter === 'all'): ?>
                            All Departments - Complete Asset Overview
                        <?php else: ?>
                            <?php echo htmlspecialchars($dept_filter); ?> Department
                        <?php endif; ?>
                    </p>
                </div>
                <a href="dashboard.php" class="btn btn-primary">← Back to Dashboard</a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Assets</h3>
                <div class="number"><?php echo $stats['total_assets']; ?></div>
            </div>
            <div class="stat-card">
                <h3>Available</h3>
                <div class="number" style="color: #10b981;"><?php echo $stats['available_assets']; ?></div>
            </div>
            <div class="stat-card">
                <h3>In Use</h3>
                <div class="number" style="color: #3b82f6;"><?php echo $stats['in_use_assets']; ?></div>
            </div>
            <div class="stat-card">
                <h3>Maintenance</h3>
                <div class="number" style="color: #f59e0b;"><?php echo $stats['maintenance_assets']; ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Value</h3>
                <div class="number" style="color: #8b5cf6;">$<?php echo number_format($stats['total_value'] ?? 0, 2); ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters">
            <form method="GET" action="">
                <div class="filters-row">
                    <?php if ($user_role === 'admin'): ?>
                    <div class="filter-group">
                        <label>Department</label>
                        <select name="department" onchange="this.form.submit()">
                            <option value="all" <?php echo $dept_filter === 'all' ? 'selected' : ''; ?>>All Departments</option>
                            <?php foreach ($departments_list as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept['dept_name']); ?>" 
                                    <?php echo $dept_filter === $dept['dept_name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['dept_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status" onchange="this.form.submit()">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="available" <?php echo $status_filter === 'available' ? 'selected' : ''; ?>>Available</option>
                            <option value="in_use" <?php echo $status_filter === 'in_use' ? 'selected' : ''; ?>>In Use</option>
                            <option value="maintenance" <?php echo $status_filter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                            <option value="retired" <?php echo $status_filter === 'retired' ? 'selected' : ''; ?>>Retired</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Category</label>
                        <select name="category" onchange="this.form.submit()">
                            <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?php echo htmlspecialchars($category); ?>" 
                                    <?php echo $category_filter === $category ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Search</label>
                        <input type="text" name="search" placeholder="Search assets..." 
                               value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>

                    <button type="submit" class="btn btn-primary" style="height: 42px;">Filter</button>
                </div>
            </form>
        </div>

        <!-- Assets Grid -->
        <div class="assets-grid">
            <?php if (count($assets) > 0): ?>
                <?php foreach ($assets as $asset): ?>
                <div class="asset-card">
                    <div class="asset-icon">📦</div>
                    
                    <div class="asset-header">
                        <div class="asset-title">
                            <h3><?php echo htmlspecialchars($asset['asset_name']); ?></h3>
                            <span class="asset-code"><?php echo htmlspecialchars($asset['asset_code']); ?></span>
                        </div>
                        <span class="badge badge-<?php echo $asset['status']; ?>">
                            <?php echo ucwords(str_replace('_', ' ', $asset['status'])); ?>
                        </span>
                    </div>

                    <div class="asset-details">
                        <?php if ($asset['brand']): ?>
                        <div class="detail-row">
                            <strong>Brand:</strong>
                            <span><?php echo htmlspecialchars($asset['brand']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ($asset['model']): ?>
                        <div class="detail-row">
                            <strong>Model:</strong>
                            <span><?php echo htmlspecialchars($asset['model']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ($asset['category']): ?>
                        <div class="detail-row">
                            <strong>Category:</strong>
                            <span><?php echo htmlspecialchars($asset['category']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ($asset['location']): ?>
                        <div class="detail-row">
                            <strong>Location:</strong>
                            <span><?php echo htmlspecialchars($asset['location']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ($asset['purchase_cost']): ?>
                        <div class="detail-row">
                            <strong>Value:</strong>
                            <span>$<?php echo number_format($asset['purchase_cost'], 2); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($asset['assigned_to']): ?>
                    <div class="assigned-user">
                        <h4>Assigned To</h4>
                        <div class="user-info">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($asset['assigned_user_name'], 0, 1)); ?>
                            </div>
                            <div class="user-details">
                                <div class="user-name"><?php echo htmlspecialchars($asset['assigned_user_name']); ?></div>
                                <div class="user-email"><?php echo htmlspecialchars($asset['assigned_user_email']); ?></div>
                                <?php if ($asset['assigned_user_emp_id']): ?>
                                <div class="user-email">ID: <?php echo htmlspecialchars($asset['assigned_user_emp_id']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="assigned-user" style="background: #f3f4f6;">
                        <h4>Not Assigned</h4>
                        <div style="color: #6b7280; font-size: 14px;">This asset is not currently assigned to anyone</div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
            <div class="empty-state" style="grid-column: 1/-1;">
                <svg fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd"/>
                </svg>
                <h3>No assets found</h3>
                <p>No assets match your current filters</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>