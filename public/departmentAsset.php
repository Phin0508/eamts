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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../auth/inc/navigation.css">
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
            margin-bottom: 12px;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header h1 i {
            color: #7c3aed;
        }

        .header-subtitle {
            color: #718096;
            font-size: 15px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.3s;
            border-left: 4px solid #7c3aed;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, rgba(124, 58, 237, 0.1) 0%, rgba(109, 40, 217, 0.05) 100%);
            border-radius: 0 0 0 100%;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(124, 58, 237, 0.15);
        }

        .stat-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .stat-card h3 {
            font-size: 13px;
            color: #718096;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .stat-card .number {
            font-size: 36px;
            font-weight: 700;
            color: #1a202c;
            position: relative;
            z-index: 1;
        }

        .stat-card.total { border-left-color: #7c3aed; }
        .stat-card.total .stat-icon { background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%); color: #7c3aed; }

        .stat-card.available { border-left-color: #10b981; }
        .stat-card.available .number { color: #10b981; }
        .stat-card.available .stat-icon { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #059669; }

        .stat-card.in-use { border-left-color: #3b82f6; }
        .stat-card.in-use .number { color: #3b82f6; }
        .stat-card.in-use .stat-icon { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #2563eb; }

        .stat-card.maintenance { border-left-color: #f59e0b; }
        .stat-card.maintenance .number { color: #f59e0b; }
        .stat-card.maintenance .stat-icon { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #d97706; }

        .stat-card.value { border-left-color: #8b5cf6; }
        .stat-card.value .number { color: #8b5cf6; }
        .stat-card.value .stat-icon { background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%); color: #7c3aed; }

        /* Filters Section */
        .filters-section {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .filters-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
        }

        .filters-header h2 {
            font-size: 18px;
            font-weight: 700;
            color: #1a202c;
        }

        .filters-header i {
            color: #7c3aed;
            font-size: 20px;
        }

        .filters-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filter-group label i {
            font-size: 12px;
            color: #718096;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s;
            background: white;
        }

        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

        .btn-filter {
            padding: 12px 24px;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4);
        }

        /* Assets Grid */
        .assets-section {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .assets-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
        }

        .assets-header h2 {
            font-size: 20px;
            font-weight: 700;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .assets-header i {
            color: #7c3aed;
        }

        .asset-count {
            background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%);
            color: #7c3aed;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 700;
        }

        .assets-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 24px;
        }

        .asset-card {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            padding: 24px;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .asset-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, rgba(124, 58, 237, 0.05) 0%, transparent 100%);
            border-radius: 0 0 0 100%;
        }

        .asset-card:hover {
            border-color: #7c3aed;
            box-shadow: 0 8px 24px rgba(124, 58, 237, 0.15);
            transform: translateY(-4px);
        }

        .asset-card-header {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }

        .asset-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        }

        .asset-title {
            flex: 1;
        }

        .asset-title h3 {
            font-size: 18px;
            color: #1a202c;
            margin-bottom: 6px;
            font-weight: 700;
        }

        .asset-code {
            font-size: 12px;
            color: #7c3aed;
            font-weight: 700;
            background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%);
            padding: 4px 12px;
            border-radius: 6px;
            display: inline-block;
        }

        .badge {
            position: absolute;
            top: 24px;
            right: 24px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-available {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }

        .badge-in_use {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        .badge-maintenance {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }

        .badge-retired {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }

        .asset-details {
            background: #f9fafb;
            border-radius: 12px;
            padding: 16px;
            margin: 16px 0;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
            border-bottom: 1px solid #e2e8f0;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-row strong {
            color: #374151;
            font-weight: 600;
        }

        .detail-row span {
            color: #6b7280;
            text-align: right;
        }

        .assigned-user {
            background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%);
            padding: 16px;
            border-radius: 12px;
            margin-top: 16px;
        }

        .assigned-user.not-assigned {
            background: #f3f4f6;
        }

        .assigned-user h4 {
            font-size: 11px;
            color: #6b7280;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.3);
        }

        .user-details {
            flex: 1;
        }

        .user-name {
            font-weight: 700;
            color: #1a202c;
            font-size: 14px;
            margin-bottom: 2px;
        }

        .user-email {
            font-size: 12px;
            color: #6b7280;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            grid-column: 1 / -1;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 20px;
            color: #1a202c;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .empty-state p {
            color: #718096;
            font-size: 15px;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .container {
                margin-left: 80px;
            }

            .container.sidebar-collapsed {
                margin-left: 80px;
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

            .header {
                padding: 20px;
            }

            .header-top {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }

            .header h1 {
                font-size: 22px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filters-row {
                grid-template-columns: 1fr;
            }

            .assets-grid {
                grid-template-columns: 1fr;
            }

            .filters-section,
            .assets-section {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="container" id="mainContainer">
        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div>
                    <h1><i class="fas fa-boxes"></i> Department Assets</h1>
                    <p class="header-subtitle">
                        <?php if ($user_role === 'admin' && $dept_filter === 'all'): ?>
                            All Departments - Complete Asset Overview
                        <?php else: ?>
                            <?php echo htmlspecialchars($dept_filter); ?> Department
                        <?php endif; ?>
                    </p>
                </div>
                <a href="dashboard.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-card-header">
                    <h3>Total Assets</h3>
                    <div class="stat-icon">
                        <i class="fas fa-cubes"></i>
                    </div>
                </div>
                <div class="number"><?php echo $stats['total_assets']; ?></div>
            </div>
            <div class="stat-card available">
                <div class="stat-card-header">
                    <h3>Available</h3>
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="number"><?php echo $stats['available_assets']; ?></div>
            </div>
            <div class="stat-card in-use">
                <div class="stat-card-header">
                    <h3>In Use</h3>
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                </div>
                <div class="number"><?php echo $stats['in_use_assets']; ?></div>
            </div>
            <div class="stat-card maintenance">
                <div class="stat-card-header">
                    <h3>Maintenance</h3>
                    <div class="stat-icon">
                        <i class="fas fa-tools"></i>
                    </div>
                </div>
                <div class="number"><?php echo $stats['maintenance_assets']; ?></div>
            </div>
            <div class="stat-card value">
                <div class="stat-card-header">
                    <h3>Total Value</h3>
                    <div class="stat-icon">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                </div>
                <div class="number">$<?php echo number_format($stats['total_value'] ?? 0, 0); ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-section">
            <div class="filters-header">
                <i class="fas fa-filter"></i>
                <h2>Filter Assets</h2>
            </div>
            <form method="GET" action="">
                <div class="filters-row">
                    <?php if ($user_role === 'admin'): ?>
                    <div class="filter-group">
                        <label><i class="fas fa-building"></i> Department</label>
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
                        <label><i class="fas fa-info-circle"></i> Status</label>
                        <select name="status" onchange="this.form.submit()">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="available" <?php echo $status_filter === 'available' ? 'selected' : ''; ?>>Available</option>
                            <option value="in_use" <?php echo $status_filter === 'in_use' ? 'selected' : ''; ?>>In Use</option>
                            <option value="maintenance" <?php echo $status_filter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                            <option value="retired" <?php echo $status_filter === 'retired' ? 'selected' : ''; ?>>Retired</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label><i class="fas fa-tags"></i> Category</label>
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
                        <label><i class="fas fa-search"></i> Search</label>
                        <input type="text" name="search" placeholder="Search assets..." 
                               value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>

                    <div class="filter-group">
                        <button type="submit" class="btn-filter">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Assets Grid -->
        <div class="assets-section">
            <div class="assets-header">
                <h2><i class="fas fa-box-open"></i> Assets Overview</h2>
                <span class="asset-count"><?php echo count($assets); ?> Assets</span>
            </div>

            <div class="assets-grid">
                <?php if (count($assets) > 0): ?>
                    <?php foreach ($assets as $asset): ?>
                    <div class="asset-card">
                        <span class="badge badge-<?php echo $asset['status']; ?>">
                            <?php echo ucwords(str_replace('_', ' ', $asset['status'])); ?>
                        </span>

                        <div class="asset-card-header">
                            <div class="asset-icon">📦</div>
                            <div class="asset-title">
                                <h3><?php echo htmlspecialchars($asset['asset_name']); ?></h3>
                                <span class="asset-code"><?php echo htmlspecialchars($asset['asset_code']); ?></span>
                            </div>
                        </div>

                        <div class="asset-details">
                            <?php if ($asset['brand']): ?>
                            <div class="detail-row">
                                <strong><i class="fas fa-copyright"></i> Brand:</strong>
                                <span><?php echo htmlspecialchars($asset['brand']); ?></span>
                            </div>
                            <?php endif; ?>

                            <?php if ($asset['model']): ?>
                            <div class="detail-row">
                                <strong><i class="fas fa-tag"></i> Model:</strong>
                                <span><?php echo htmlspecialchars($asset['model']); ?></span>
                            </div>
                            <?php endif; ?>

                            <?php if ($asset['category']): ?>
                            <div class="detail-row">
                                <strong><i class="fas fa-layer-group"></i> Category:</strong>
                                <span><?php echo htmlspecialchars($asset['category']); ?></span>
                            </div>
                            <?php endif; ?>

                            <?php if ($asset['location']): ?>
                            <div class="detail-row">
                                <strong><i class="fas fa-map-marker-alt"></i> Location:</strong>
                                <span><?php echo htmlspecialchars($asset['location']); ?></span>
                            </div>
                            <?php endif; ?>

                            <?php if ($asset['purchase_cost']): ?>
                            <div class="detail-row">
                                <strong><i class="fas fa-dollar-sign"></i> Value:</strong>
                                <span>$<?php echo number_format($asset['purchase_cost'], 2); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($asset['assigned_to']): ?>
                        <div class="assigned-user">
                            <h4><i class="fas fa-user"></i> Assigned To</h4>
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
                        <div class="assigned-user not-assigned">
                            <h4><i class="fas fa-user-slash"></i> Not Assigned</h4>
                            <div style="color: #6b7280; font-size: 14px;">This asset is not currently assigned to anyone</div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📦</div>
                    <h3>No assets found</h3>
                    <p>No assets match your current filters. Try adjusting your search criteria.</p>
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