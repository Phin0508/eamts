<?php
session_start();

// Check if user is logged in and has admin/manager role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Include database configuration
include("../auth/config/database.php");

// Verify PDO connection exists
if (!isset($pdo)) {
    die("Database connection failed. Please check your database configuration.");
}

// Get the department for the manager
$manager_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Get manager's department
$dept_query = "SELECT department FROM users WHERE user_id = ?";
$dept_stmt = $pdo->prepare($dept_query);
$dept_stmt->execute([$manager_id]);
$manager_dept = $dept_stmt->fetchColumn();

if (!$manager_dept) {
    die("You are not assigned to any department.");
}

// Initialize variables
$employees = [];
$category_breakdown = [];
$assignments = [];
$top_valuable_assets = [];
$employee_asset_distribution = [];

$emp_stats = [
    'total_employees' => 0,
    'active_employees' => 0,
    'inactive_employees' => 0,
    'admins' => 0,
    'managers' => 0,
    'employees' => 0
];

$asset_stats = [
    'total_assets' => 0,
    'available_assets' => 0,
    'in_use_assets' => 0,
    'maintenance_assets' => 0,
    'retired_assets' => 0,
    'total_value' => 0,
    'avg_value' => 0
];

// Get employee statistics
try {
    $emp_stats_query = $pdo->prepare("
        SELECT 
            COUNT(*) as total_employees,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_employees,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_employees,
            SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins,
            SUM(CASE WHEN role = 'manager' THEN 1 ELSE 0 END) as managers,
            SUM(CASE WHEN role = 'employee' THEN 1 ELSE 0 END) as employees
        FROM users
        WHERE department = ?
    ");
    $emp_stats_query->execute([$manager_dept]);
    $emp_stats = $emp_stats_query->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Employee stats error: " . $e->getMessage());
}

// Get all employees in the department
try {
    $employees_query = $pdo->prepare("
        SELECT user_id, first_name, last_name, email, role, is_active, created_at, last_login
        FROM users
        WHERE department = ?
        ORDER BY first_name, last_name
    ");
    $employees_query->execute([$manager_dept]);
    $employees = $employees_query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Employees query error: " . $e->getMessage());
}

// Get asset statistics for the department
try {
    $asset_stats_query = $pdo->prepare("
        SELECT 
            COUNT(*) as total_assets,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_assets,
            SUM(CASE WHEN status = 'in_use' THEN 1 ELSE 0 END) as in_use_assets,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_assets,
            SUM(CASE WHEN status = 'retired' THEN 1 ELSE 0 END) as retired_assets,
            COALESCE(SUM(purchase_cost), 0) as total_value,
            COALESCE(AVG(purchase_cost), 0) as avg_value
        FROM assets
        WHERE department = ?
    ");
    $asset_stats_query->execute([$manager_dept]);
    $asset_stats = $asset_stats_query->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Asset stats error: " . $e->getMessage());
}

// Get assets by category
try {
    $assets_by_category = $pdo->prepare("
        SELECT category, COUNT(*) as count, COALESCE(SUM(purchase_cost), 0) as total_value
        FROM assets
        WHERE department = ? AND category IS NOT NULL
        GROUP BY category
        ORDER BY count DESC
    ");
    $assets_by_category->execute([$manager_dept]);
    $category_breakdown = $assets_by_category->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Category breakdown error: " . $e->getMessage());
}

// Get recent asset assignments
try {
    $recent_assignments = $pdo->prepare("
        SELECT a.id, a.asset_name, a.asset_code, a.category, a.status,
               CONCAT(u.first_name, ' ', u.last_name) as assigned_to,
               a.created_at as assigned_date
        FROM assets a
        LEFT JOIN users u ON a.assigned_to = u.user_id
        WHERE a.department = ? AND a.assigned_to IS NOT NULL
        ORDER BY a.created_at DESC
        LIMIT 10
    ");
    $recent_assignments->execute([$manager_dept]);
    $assignments = $recent_assignments->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Recent assignments error: " . $e->getMessage());
}

// Get asset value statistics
try {
    $asset_value_stats = $pdo->prepare("
        SELECT 
            COALESCE(MIN(purchase_cost), 0) as min_value,
            COALESCE(MAX(purchase_cost), 0) as max_value
        FROM assets
        WHERE department = ? AND purchase_cost > 0
    ");
    $asset_value_stats->execute([$manager_dept]);
    $value_stats = $asset_value_stats->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Asset value stats error: " . $e->getMessage());
    $value_stats = ['min_value' => 0, 'max_value' => 0];
}

// Calculate utilization rate
$utilization_rate = $asset_stats['total_assets'] > 0 
    ? round(($asset_stats['in_use_assets'] / $asset_stats['total_assets']) * 100, 1) 
    : 0;

// Get most valuable assets in department
try {
    $top_assets = $pdo->prepare("
        SELECT asset_name, asset_code, purchase_cost, status
        FROM assets
        WHERE department = ? AND purchase_cost > 0
        ORDER BY purchase_cost DESC
        LIMIT 5
    ");
    $top_assets->execute([$manager_dept]);
    $top_valuable_assets = $top_assets->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Top assets error: " . $e->getMessage());
}

// Get employee asset distribution
try {
    // Get the dept_id for the manager's department first
    $dept_id_query = "SELECT dept_id FROM departments WHERE dept_name = ? LIMIT 1";
    $dept_id_stmt = $pdo->prepare($dept_id_query);
    $dept_id_stmt->execute([$manager_dept]);
    $dept_id = $dept_id_stmt->fetchColumn();
    
    if ($dept_id) {
        // Now query using dept_id if assets table uses dept_id
        $employee_assets = $pdo->prepare("
            SELECT 
                u.user_id,
                CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                COUNT(DISTINCT a.id) as asset_count,
                COALESCE(SUM(a.purchase_cost), 0) as total_value
            FROM users u
            INNER JOIN assets a ON u.user_id = a.assigned_to
            WHERE u.department = ? 
                AND a.status != 'retired'
            GROUP BY u.user_id, u.first_name, u.last_name
            HAVING asset_count > 0
            ORDER BY asset_count DESC, total_value DESC
            LIMIT 10
        ");
        $employee_assets->execute([$manager_dept]);
        $employee_asset_distribution = $employee_assets->fetchAll(PDO::FETCH_ASSOC);
        
        // If no results, try with dept_id in assets table
        if (empty($employee_asset_distribution)) {
            $employee_assets = $pdo->prepare("
                SELECT 
                    u.user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                    COUNT(DISTINCT a.id) as asset_count,
                    COALESCE(SUM(a.purchase_cost), 0) as total_value
                FROM users u
                INNER JOIN assets a ON u.user_id = a.assigned_to
                INNER JOIN departments d ON u.department = d.dept_name
                WHERE d.dept_id = ? 
                    AND a.status != 'retired'
                GROUP BY u.user_id, u.first_name, u.last_name
                HAVING asset_count > 0
                ORDER BY asset_count DESC, total_value DESC
                LIMIT 10
            ");
            $employee_assets->execute([$dept_id]);
            $employee_asset_distribution = $employee_assets->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        // Fallback: just match by department name without filter on assets.department
        $employee_assets = $pdo->prepare("
            SELECT 
                u.user_id,
                CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                COUNT(DISTINCT a.id) as asset_count,
                COALESCE(SUM(a.purchase_cost), 0) as total_value
            FROM users u
            INNER JOIN assets a ON u.user_id = a.assigned_to
            WHERE u.department = ? 
                AND a.status != 'retired'
            GROUP BY u.user_id, u.first_name, u.last_name
            HAVING asset_count > 0
            ORDER BY asset_count DESC, total_value DESC
            LIMIT 10
        ");
        $employee_assets->execute([$manager_dept]);
        $employee_asset_distribution = $employee_assets->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Employee assets error: " . $e->getMessage());
}

// Default budget (since departments table doesn't exist in your schema)
$department_budget = 100000; // Default budget
$budget_utilization = 0;
if ($department_budget > 0) {
    $budget_utilization = round(($asset_stats['total_value'] / $department_budget) * 100, 1);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Report - <?php echo htmlspecialchars($manager_dept); ?></title>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f8f9fa;
        }

        .main-content {
            margin-left: 260px;
            padding: 2rem;
            min-height: 100vh;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
        }

        .header h1 {
            font-size: 28px;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-subtitle {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 20px;
        }

        .header-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
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

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .dept-info-section {
            padding: 30px;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
        }

        .dept-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .dept-detail-item {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }

        .dept-detail-item h4 {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .dept-detail-item p {
            font-size: 16px;
            color: #111827;
            font-weight: 600;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            padding: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }

        .stat-icon {
            font-size: 32px;
            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 36px;
            font-weight: bold;
            color: #667eea;
            margin: 10px 0;
        }

        .stat-label {
            font-size: 14px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-sublabel {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 5px;
        }

        .content-section {
            padding: 30px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e5e7eb;
        }

        .section-title {
            font-size: 20px;
            font-weight: bold;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        thead {
            background: #f9fafb;
        }

        th {
            padding: 15px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e5e7eb;
        }

        td {
            padding: 15px;
            font-size: 14px;
            color: #374151;
            border-bottom: 1px solid #f3f4f6;
        }

        tr:hover {
            background: #f9fafb;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-active {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-available {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-in-use {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-maintenance {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-retired {
            background: #e5e7eb;
            color: #4b5563;
        }

        .chart-container {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 18px;
            font-weight: bold;
            color: #111827;
            margin-bottom: 20px;
        }

        .category-bar {
            margin-bottom: 15px;
        }

        .category-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .category-name {
            font-weight: 600;
            color: #374151;
        }

        .category-value {
            color: #6b7280;
        }

        .progress-bar {
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            border-radius: 4px;
            transition: width 0.3s;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: linear-gradient(135deg, #f9fafb 0%, #ffffff 100%);
            padding: 20px;
            border-radius: 10px;
            border: 2px solid #e5e7eb;
        }

        .summary-card h3 {
            font-size: 14px;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 12px;
        }

        .summary-card .value {
            font-size: 28px;
            font-weight: bold;
            color: #667eea;
        }

        .summary-card .description {
            font-size: 13px;
            color: #9ca3af;
            margin-top: 6px;
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .header-actions, .btn {
                display: none !important;
            }
            
            .container {
                box-shadow: none;
            }

            .main-content {
                margin-left: 0;
            }
        }

        @media (max-width: 1200px) {
            .main-content {
                margin-left: 0;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .header-actions {
                flex-direction: column;
                align-items: stretch;
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 10px 8px;
            }
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/Msidebar.php"); ?>
    
    <main class="main-content">
        <div class="container">
            <div class="header">
                <h1><i class="fas fa-chart-bar"></i> Department Report</h1>
                <div class="header-subtitle">
                    Comprehensive overview of <?php echo htmlspecialchars($manager_dept); ?> Department
                </div>
                <div class="header-actions">
                    <button onclick="exportToCSV()" class="btn btn-success">
                        <i class="fas fa-file-download"></i> Export CSV
                    </button>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <a href="dashboard.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>

            <!-- Department Information -->
            <div class="dept-info-section">
                <div class="dept-details">
                    <div class="dept-detail-item">
                        <h4>Department Name</h4>
                        <p><?php echo htmlspecialchars($manager_dept); ?></p>
                    </div>
                    <div class="dept-detail-item">
                        <h4>Total Employees</h4>
                        <p><?php echo $emp_stats['total_employees']; ?></p>
                    </div>
                    <div class="dept-detail-item">
                        <h4>Total Assets</h4>
                        <p><?php echo $asset_stats['total_assets']; ?></p>
                    </div>
                    <div class="dept-detail-item">
                        <h4>Total Asset Value</h4>
                        <p>$<?php echo number_format($asset_stats['total_value'], 2); ?></p>
                    </div>
                    <div class="dept-detail-item">
                        <h4>Report Generated</h4>
                        <p><?php echo date('M d, Y'); ?></p>
                    </div>
                    <div class="dept-detail-item">
                        <h4>Generated By</h4>
                        <p><?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Key Metrics Summary -->
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-chart-line"></i> Key Performance Indicators</h2>
                </div>
                <div class="summary-grid">
                    <div class="summary-card">
                        <h3>Asset Utilization Rate</h3>
                        <div class="value"><?php echo $utilization_rate; ?>%</div>
                        <div class="description">
                            <?php echo $asset_stats['in_use_assets']; ?> of <?php echo $asset_stats['total_assets']; ?> assets in active use
                        </div>
                    </div>
                    <div class="summary-card">
                        <h3>Budget Utilization</h3>
                        <div class="value"><?php echo $budget_utilization; ?>%</div>
                        <div class="description">
                            $<?php echo number_format($asset_stats['total_value'], 0); ?> of $<?php echo number_format($department_budget, 0); ?> budget
                        </div>
                    </div>
                    <div class="summary-card">
                        <h3>Average Asset Value</h3>
                        <div class="value">$<?php echo number_format($asset_stats['avg_value'], 0); ?></div>
                        <div class="description">
                            Range: $<?php echo number_format($value_stats['min_value'], 0); ?> - $<?php echo number_format($value_stats['max_value'], 0); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Employee Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">👥</div>
                    <div class="stat-number"><?php echo $emp_stats['total_employees']; ?></div>
                    <div class="stat-label">Total Employees</div>
                    <div class="stat-sublabel"><?php echo $emp_stats['active_employees']; ?> Active</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📦</div>
                    <div class="stat-number"><?php echo $asset_stats['total_assets']; ?></div>
                    <div class="stat-label">Total Assets</div>
                    <div class="stat-sublabel"><?php echo $asset_stats['available_assets']; ?> Available</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">💰</div>
                    <div class="stat-number">$<?php echo number_format($asset_stats['total_value'], 0); ?></div>
                    <div class="stat-label">Asset Value</div>
                    <div class="stat-sublabel">Total Investment</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⚙️</div>
                    <div class="stat-number"><?php echo $asset_stats['maintenance_assets']; ?></div>
                    <div class="stat-label">In Maintenance</div>
                    <div class="stat-sublabel">Requires Attention</div>
                </div>
            </div>

            <!-- Top Valuable Assets -->
            <?php if (count($top_valuable_assets) > 0): ?>
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-gem"></i> Top Valuable Assets</h2>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Asset Code</th>
                                <th>Asset Name</th>
                                <th>Purchase Cost</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_valuable_assets as $asset): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($asset['asset_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($asset['asset_name']); ?></td>
                                <td><strong>$<?php echo number_format($asset['purchase_cost'], 2); ?></strong></td>
                                <td>
                                    <span class="badge badge-<?php echo strtolower(str_replace(' ', '-', $asset['status'])); ?>">
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $asset['status']))); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Assets by Category Chart -->
            <?php if (count($category_breakdown) > 0): ?>
            <div class="content-section">
                <div class="chart-container">
                    <div class="chart-title">Assets by Category</div>
                    <?php 
                    $max_count = max(array_column($category_breakdown, 'count'));
                    foreach ($category_breakdown as $category): 
                        $percentage = ($category['count'] / $max_count) * 100;
                    ?>
                    <div class="category-bar">
                        <div class="category-header">
                            <span class="category-name"><?php echo htmlspecialchars($category['category']); ?></span>
                            <span class="category-value">
                                <?php echo $category['count']; ?> assets ($<?php echo number_format($category['total_value'], 0); ?>)
                            </span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Employee Asset Distribution -->
            <?php if (count($employee_asset_distribution) > 0): ?>
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-user-check"></i> Employee Asset Distribution</h2>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Employee Name</th>
                                <th>Assets Assigned</th>
                                <th>Total Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employee_asset_distribution as $emp): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($emp['employee_name']); ?></strong></td>
                                <td><?php echo $emp['asset_count']; ?> assets</td>
                                <td>$<?php echo number_format($emp['total_value'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Asset Status Breakdown -->
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-chart-pie"></i> Asset Status Overview</h2>
                </div>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number" style="color: #3b82f6;"><?php echo $asset_stats['available_assets']; ?></div>
                        <div class="stat-label">Available</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" style="color: #f59e0b;"><?php echo $asset_stats['in_use_assets']; ?></div>
                        <div class="stat-label">In Use</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" style="color: #ef4444;"><?php echo $asset_stats['maintenance_assets']; ?></div>
                        <div class="stat-label">Maintenance</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" style="color: #6b7280;"><?php echo $asset_stats['retired_assets']; ?></div>
                        <div class="stat-label">Retired</div>
                    </div>
                </div>
            </div>

            <!-- Recent Asset Assignments -->
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-history"></i> Recent Asset Assignments</h2>
                </div>
                <?php if (count($assignments) > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Asset Code</th>
                                <th>Asset Name</th>
                                <th>Category</th>
                                <th>Assigned To</th>
                                <th>Status</th>
                                <th>Date Added</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $assignment): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($assignment['asset_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($assignment['asset_name']); ?></td>
                                <td><?php echo htmlspecialchars($assignment['category']); ?></td>
                                <td><?php echo htmlspecialchars($assignment['assigned_to']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo strtolower(str_replace(' ', '-', $assignment['status'])); ?>">
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $assignment['status']))); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($assignment['assigned_date'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-inbox"></i></div>
                    <h3>No Recent Assignments</h3>
                    <p>No assets have been assigned recently</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Department Employees -->
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-users"></i> Department Employees</h2>
                    <div>
                        <span class="badge badge-active"><?php echo $emp_stats['active_employees']; ?> Active</span>
                        <?php if ($emp_stats['inactive_employees'] > 0): ?>
                        <span class="badge badge-inactive"><?php echo $emp_stats['inactive_employees']; ?> Inactive</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (count($employees) > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Joined Date</th>
                                <th>Last Login</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $employee): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($employee['email']); ?></td>
                                <td><?php echo ucfirst(htmlspecialchars($employee['role'])); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $employee['is_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $employee['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($employee['created_at'])); ?></td>
                                <td><?php echo $employee['last_login'] ? date('M d, Y H:i', strtotime($employee['last_login'])) : 'Never'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-users-slash"></i></div>
                    <h3>No Employees</h3>
                    <p>No employees are currently assigned to this department</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Report Summary Footer -->
            <div class="content-section" style="background: #f9fafb; border-top: 2px solid #e5e7eb;">
                <div style="text-align: center; color: #6b7280;">
                    <p><strong>Report Generated:</strong> <?php echo date('F j, Y \a\t g:i A'); ?></p>
                    <p style="margin-top: 5px; font-size: 14px;">
                        Generated by: <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                        (<?php echo ucfirst($_SESSION['role']); ?>)
                    </p>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Department data
        const deptData = <?php 
            $js_data = [
                'name' => $manager_dept,
                'stats' => [
                    'totalEmployees' => $emp_stats['total_employees'],
                    'activeEmployees' => $emp_stats['active_employees'],
                    'totalAssets' => $asset_stats['total_assets'],
                    'availableAssets' => $asset_stats['available_assets'],
                    'inUseAssets' => $asset_stats['in_use_assets'],
                    'maintenanceAssets' => $asset_stats['maintenance_assets'],
                    'totalValue' => number_format($asset_stats['total_value'], 2),
                    'avgValue' => number_format($asset_stats['avg_value'], 2),
                    'utilizationRate' => $utilization_rate,
                    'budgetUtilization' => $budget_utilization
                ],
                'employees' => array_map(function($emp) {
                    return [
                        'name' => ($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''),
                        'email' => $emp['email'] ?? '',
                        'role' => ucfirst($emp['role'] ?? 'employee'),
                        'status' => ($emp['is_active'] ?? 0) ? 'Active' : 'Inactive',
                        'joined' => isset($emp['created_at']) ? date('Y-m-d', strtotime($emp['created_at'])) : 'N/A',
                        'last_login' => isset($emp['last_login']) && $emp['last_login'] ? date('Y-m-d H:i', strtotime($emp['last_login'])) : 'Never'
                    ];
                }, $employees),
                'categories' => array_map(function($cat) {
                    return [
                        'category' => $cat['category'] ?? 'Unknown',
                        'count' => $cat['count'] ?? 0,
                        'value' => number_format($cat['total_value'] ?? 0, 2)
                    ];
                }, $category_breakdown)
            ];
            echo json_encode($js_data);
        ?>;

        // Add smooth animations on load
        document.addEventListener('DOMContentLoaded', function() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(20px)';
                    card.style.transition = 'all 0.5s';
                    setTimeout(() => {
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0)';
                    }, 50);
                }, index * 100);
            });
        });

        // Department data - properly encoded from PHP
    const deptData = <?php 
        $js_data = [
            'name' => $manager_dept,
            'stats' => [
                'totalEmployees' => (int)$emp_stats['total_employees'],
                'activeEmployees' => (int)$emp_stats['active_employees'],
                'totalAssets' => (int)$asset_stats['total_assets'],
                'availableAssets' => (int)$asset_stats['available_assets'],
                'inUseAssets' => (int)$asset_stats['in_use_assets'],
                'maintenanceAssets' => (int)$asset_stats['maintenance_assets'],
                'totalValue' => number_format($asset_stats['total_value'], 2),
                'avgValue' => number_format($asset_stats['avg_value'], 2),
                'utilizationRate' => (string)$utilization_rate,
                'budgetUtilization' => (string)$budget_utilization
            ],
            'employees' => array_map(function($emp) {
                return [
                    'name' => trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')),
                    'email' => $emp['email'] ?? '',
                    'role' => ucfirst($emp['role'] ?? 'employee'),
                    'status' => ($emp['is_active'] ?? 0) ? 'Active' : 'Inactive',
                    'joined' => isset($emp['created_at']) ? date('Y-m-d', strtotime($emp['created_at'])) : 'N/A',
                    'last_login' => isset($emp['last_login']) && $emp['last_login'] ? date('Y-m-d H:i', strtotime($emp['last_login'])) : 'Never'
                ];
            }, $employees),
            'categories' => array_map(function($cat) {
                return [
                    'category' => $cat['category'] ?? 'Unknown',
                    'count' => (int)($cat['count'] ?? 0),
                    'value' => number_format($cat['total_value'] ?? 0, 2)
                ];
            }, $category_breakdown)
        ];
        echo json_encode($js_data, JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;

    // Add smooth animations on load
    document.addEventListener('DOMContentLoaded', function() {
        const statCards = document.querySelectorAll('.stat-card');
        statCards.forEach((card, index) => {
            setTimeout(() => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'all 0.5s';
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, 50);
            }, index * 100);
        });
    });

    // Export to CSV function
    function exportToCSV() {
        const csvData = [];
        
        // Header
        csvData.push(['Department Report - ' + deptData.name]);
        csvData.push(['Generated: ' + new Date().toLocaleString()]);
        csvData.push([]);
        
        // Department Info
        csvData.push(['DEPARTMENT INFORMATION']);
        csvData.push(['Department Name', deptData.name]);
        csvData.push([]);
        
        // Statistics
        csvData.push(['STATISTICS']);
        csvData.push(['Total Employees', deptData.stats.totalEmployees]);
        csvData.push(['Active Employees', deptData.stats.activeEmployees]);
        csvData.push(['Total Assets', deptData.stats.totalAssets]);
        csvData.push(['Available Assets', deptData.stats.availableAssets]);
        csvData.push(['In Use Assets', deptData.stats.inUseAssets]);
        csvData.push(['Maintenance Assets', deptData.stats.maintenanceAssets]);
        csvData.push(['Total Asset Value', '$' + deptData.stats.totalValue]);
        csvData.push(['Average Asset Value', '$' + deptData.stats.avgValue]);
        csvData.push(['Asset Utilization Rate', deptData.stats.utilizationRate + '%']);
        csvData.push(['Budget Utilization', deptData.stats.budgetUtilization + '%']);
        csvData.push([]);
        
        // Employees
        csvData.push(['EMPLOYEES']);
        csvData.push(['Name', 'Email', 'Role', 'Status', 'Joined Date', 'Last Login']);
        
        if (deptData.employees && deptData.employees.length > 0) {
            deptData.employees.forEach(emp => {
                csvData.push([emp.name, emp.email, emp.role, emp.status, emp.joined, emp.last_login]);
            });
        } else {
            csvData.push(['No employees found']);
        }
        
        csvData.push([]);
        
        // Assets by Category
        csvData.push(['ASSETS BY CATEGORY']);
        csvData.push(['Category', 'Count', 'Total Value']);
        
        if (deptData.categories && deptData.categories.length > 0) {
            deptData.categories.forEach(cat => {
                csvData.push([cat.category, cat.count, '$' + cat.value]);
            });
        } else {
            csvData.push(['No categories found']);
        }
        
        // Convert to CSV string
        let csvContent = '';
        csvData.forEach(row => {
            const escapedRow = row.map(cell => {
                const cellStr = String(cell);
                if (cellStr.includes(',') || cellStr.includes('"') || cellStr.includes('\n')) {
                    return '"' + cellStr.replace(/"/g, '""') + '"';
                }
                return cellStr;
            });
            csvContent += escapedRow.join(',') + '\n';
        });
        
        // Create download
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        const deptName = deptData.name.replace(/[^a-zA-Z0-9]/g, '_');
        const timestamp = new Date().toISOString().slice(0, 10);
        
        link.setAttribute('href', url);
        link.setAttribute('download', 'Department_Report_' + deptName + '_' + timestamp + '.csv');
        link.style.visibility = 'hidden';
        
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        alert('Report exported successfully!');
    }
    </script>
</body>
</html>