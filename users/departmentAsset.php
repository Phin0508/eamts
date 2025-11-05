<?php
// Start session
session_start();

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager', 'employee'])) {
    header("Location: login.php");
    exit();
}

// Include database connection
include("../auth/config/database.php");

// Get user information from session
$user_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
$username = $_SESSION['username'];
$department = $_SESSION['department'];
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, '/', '', true, true);
    }
    header("Location: login.php?message=logged_out");
    exit();
}

// Initialize messages
$success_message = '';
$error_message = '';

// Handle filters
$filter_status = $_GET['status'] ?? 'all';
$filter_category = $_GET['category'] ?? 'all';
$search = $_GET['search'] ?? '';
$assigned_user = $_GET['user'] ?? 'all';

// Build the query based on user role
if ($user_role === 'admin') {
    // Admin can see all departments or filter by specific department
    $dept_filter = $_GET['department'] ?? 'all';
    
    if ($dept_filter === 'all') {
        $where_clauses = [];
        $params = [];
    } else {
        $where_clauses = ["a.department = ?"];
        $params = [$dept_filter];
    }
} else {
    // Manager and employee can only see their own department
    $where_clauses = ["a.department = ?"];
    $params = [$department];
    $dept_filter = $department;
}

if ($filter_status !== 'all') {
    $where_clauses[] = "a.status = ?";
    $params[] = $filter_status;
}

if ($filter_category !== 'all') {
    $where_clauses[] = "a.category = ?";
    $params[] = $filter_category;
}

if ($assigned_user !== 'all') {
    if ($assigned_user === 'unassigned') {
        $where_clauses[] = "a.assigned_to IS NULL";
    } else {
        $where_clauses[] = "a.assigned_to = ?";
        $params[] = $assigned_user;
    }
}

if (!empty($search)) {
    $where_clauses[] = "(a.asset_name LIKE ? OR a.asset_code LIKE ? OR a.serial_number LIKE ? OR a.brand LIKE ? OR a.model LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Fetch department assets
$assets = [];
$total_value = 0;
try {
    $query = "SELECT a.*, 
              CONCAT(u.first_name, ' ', u.last_name) as assigned_to_name,
              u.email as assigned_to_email,
              u.employee_id as assigned_user_emp_id,
              CONCAT(creator.first_name, ' ', creator.last_name) as created_by_name,
              (SELECT COUNT(*) FROM asset_maintenance am WHERE am.asset_id = a.id) as maintenance_count,
              (SELECT MAX(am.maintenance_date) FROM asset_maintenance am WHERE am.asset_id = a.id) as last_maintenance_date
              FROM assets a 
              LEFT JOIN users u ON a.assigned_to = u.user_id
              LEFT JOIN users creator ON a.created_by = creator.user_id
              $where_sql
              ORDER BY a.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
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

// Get statistics
$stats = [
    'total' => 0,
    'available' => 0,
    'in_use' => 0,
    'maintenance' => 0,
    'retired' => 0,
    'damaged' => 0,
    'unassigned' => 0
];

try {
    if ($user_role === 'admin' && $dept_filter === 'all') {
        $stats_query = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN LOWER(REPLACE(TRIM(status), ' ', '_')) = 'available' THEN 1 ELSE 0 END) as available,
                        SUM(CASE WHEN LOWER(REPLACE(TRIM(status), ' ', '_')) = 'in_use' THEN 1 ELSE 0 END) as in_use,
                        SUM(CASE WHEN LOWER(REPLACE(TRIM(status), ' ', '_')) = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
                        SUM(CASE WHEN LOWER(REPLACE(TRIM(status), ' ', '_')) = 'retired' THEN 1 ELSE 0 END) as retired,
                        SUM(CASE WHEN LOWER(REPLACE(TRIM(status), ' ', '_')) = 'damaged' THEN 1 ELSE 0 END) as damaged,
                        SUM(CASE WHEN assigned_to IS NULL THEN 1 ELSE 0 END) as unassigned
                        FROM assets";
        $stats = $pdo->query($stats_query)->fetch(PDO::FETCH_ASSOC);
    } else {
        $stats_query = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN LOWER(REPLACE(TRIM(status), ' ', '_')) = 'available' THEN 1 ELSE 0 END) as available,
                        SUM(CASE WHEN LOWER(REPLACE(TRIM(status), ' ', '_')) = 'in_use' THEN 1 ELSE 0 END) as in_use,
                        SUM(CASE WHEN LOWER(REPLACE(TRIM(status), ' ', '_')) = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
                        SUM(CASE WHEN LOWER(REPLACE(TRIM(status), ' ', '_')) = 'retired' THEN 1 ELSE 0 END) as retired,
                        SUM(CASE WHEN LOWER(REPLACE(TRIM(status), ' ', '_')) = 'damaged' THEN 1 ELSE 0 END) as damaged,
                        SUM(CASE WHEN assigned_to IS NULL THEN 1 ELSE 0 END) as unassigned
                        FROM assets WHERE department = ?";
        $stmt = $pdo->prepare($stats_query);
        $stmt->execute([$dept_filter]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Stats error: " . $e->getMessage());
}

// Get department employees for filter
$dept_employees = [];
try {
    if ($user_role === 'admin' && $dept_filter !== 'all') {
        $emp_query = "SELECT user_id, first_name, last_name, email 
                      FROM users 
                      WHERE department = ? AND is_active = 1 
                      ORDER BY first_name, last_name";
        $stmt = $pdo->prepare($emp_query);
        $stmt->execute([$dept_filter]);
        $dept_employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($user_role !== 'admin') {
        $emp_query = "SELECT user_id, first_name, last_name, email 
                      FROM users 
                      WHERE department = ? AND is_active = 1 
                      ORDER BY first_name, last_name";
        $stmt = $pdo->prepare($emp_query);
        $stmt->execute([$department]);
        $dept_employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Employees error: " . $e->getMessage());
}

// Get categories for filter
$categories = [];
try {
    $cat_query = "SELECT DISTINCT category FROM assets WHERE category IS NOT NULL ORDER BY category";
    $categories = $pdo->query($cat_query)->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Categories error: " . $e->getMessage());
}

// Get all departments for admin dropdown
$departments_list = [];
if ($user_role === 'admin') {
    try {
        $departments_list = $pdo->query("SELECT dept_name FROM departments WHERE is_active = 1 ORDER BY dept_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Departments error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Assets - E-Asset Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../auth/inc/navigation.css">
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

        .header {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-left h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-left h1 i {
            color: #7c3aed;
        }

        .header-left p {
            color: #718096;
            font-size: 15px;
        }

        .header-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .success-message, .error-message {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
            font-weight: 500;
        }

        .success-message {
            background: linear-gradient(135deg, #d4f4dd 0%, #c3e6cb 100%);
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .error-message {
            background: linear-gradient(135deg, #ffe6e6 0%, #ffd4d4 100%);
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(124, 58, 237, 0.15);
        }

        .stat-icon {
            font-size: 28px;
            margin-bottom: 12px;
            color: #7c3aed;
        }

        .stat-number {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 8px;
            color: #1a202c;
        }

        .stat-label {
            color: #718096;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            color: #059669;
            font-size: 14px;
            font-weight: 600;
            margin-top: 8px;
        }

        .filters-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .filters-form {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }

        .search-box i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #718096;
        }

        .search-box input {
            width: 100%;
            padding: 12px 16px 12px 44px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            font-family: inherit;
        }

        .search-box input:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

        .filters-form select {
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
        }

        .filters-form select:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

        .btn {
            padding: 12px 20px;
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

        .btn-outline {
            background: white;
            color: #718096;
            border: 2px solid #e2e8f0;
        }

        .btn-outline:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
        }

        .assets-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .asset-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.3s;
            position: relative;
        }

        .asset-card:hover {
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
            transform: translateY(-4px);
        }

        .asset-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 2px solid #f0f0f0;
        }

        .asset-title h3 {
            font-size: 18px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
        }

        .asset-code {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-available { 
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }
        .status-in-use { 
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }
        .status-maintenance { 
            background: linear-gradient(135deg, #fed7aa 0%, #fdba74 100%);
            color: #92400e;
        }
        .status-retired { 
            background: linear-gradient(135deg, #e5e7eb 0%, #d1d5db 100%);
            color: #374151;
        }
        .status-damaged { 
            background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);
            color: #991b1b;
        }

        .asset-details {
            display: grid;
            gap: 12px;
            margin-bottom: 16px;
        }

        .asset-detail {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: #4b5563;
        }

        .asset-detail i {
            width: 20px;
            color: #7c3aed;
            font-size: 14px;
        }

        .asset-detail strong {
            color: #1f2937;
            font-weight: 600;
        }

        .asset-assigned {
            background: linear-gradient(135deg, #f7f4fe 0%, #ede9fe 100%);
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 16px;
            border-left: 3px solid #7c3aed;
        }

        .assigned-label {
            font-size: 11px;
            color: #6d28d9;
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .assigned-name {
            font-weight: 600;
            color: #1f2937;
            font-size: 15px;
            margin-bottom: 4px;
        }

        .assigned-email {
            font-size: 13px;
            color: #6b7280;
        }

        .unassigned-badge {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
            padding: 12px;
            border-radius: 12px;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .maintenance-info {
            display: flex;
            gap: 16px;
            padding: 12px;
            background: #f9fafb;
            border-radius: 10px;
            margin-top: 12px;
            font-size: 13px;
        }

        .maintenance-stat {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #4b5563;
        }

        .maintenance-stat i {
            color: #7c3aed;
        }

        .asset-actions {
            display: flex;
            gap: 10px;
            padding-top: 16px;
            border-top: 1px solid #e5e7eb;
        }

        .btn-sm {
            padding: 10px 18px;
            font-size: 13px;
            border-radius: 8px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex: 1;
            justify-content: center;
            font-weight: 600;
        }

        .btn-view {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-edit {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }

        .btn-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        .no-assets {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .no-assets-icon {
            font-size: 64px;
            color: #d1d5db;
            margin-bottom: 20px;
        }

        .no-assets h3 {
            font-size: 22px;
            color: #1a202c;
            margin-bottom: 12px;
            font-weight: 700;
        }

        .no-assets p {
            color: #718096;
            font-size: 15px;
            margin-bottom: 24px;
        }

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

            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-left h1 {
                font-size: 22px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filters-form {
                flex-direction: column;
            }

            .search-box {
                width: 100%;
            }

            .filters-form select {
                width: 100%;
            }

            .assets-grid {
                grid-template-columns: 1fr;
            }
        }

        @media print {
            .container {
                margin: 0;
                padding: 20px;
            }
            
            .filters-section,
            .header-actions,
            .asset-actions,
            nav,
            .btn {
                display: none !important;
            }

            .header {
                background: white !important;
                border: 1px solid #ddd;
            }

            .asset-card {
                break-inside: avoid;
                page-break-inside: avoid;
                margin-bottom: 20px;
                border: 1px solid #ddd;
            }

            .stats-grid {
                display: none;
            }
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/Msidebar.php"); ?>

    <div class="container" id="mainContainer">
        <div class="header">
            <div class="header-content">
                <div class="header-left">
                    <h1><i class="fas fa-box"></i> Department Assets</h1>
                    <p>
                        <?php if ($user_role === 'admin' && $dept_filter === 'all'): ?>
                            All Departments - Complete Asset Overview
                        <?php else: ?>
                            Manage assets for <?php echo htmlspecialchars($dept_filter); ?> Department
                        <?php endif; ?>
                    </p>
                </div>
                <div class="header-actions">
                    <button onclick="window.print()" class="btn btn-outline">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <button onclick="exportToCSV()" class="btn btn-outline">
                        <i class="fas fa-file-csv"></i> Export
                    </button>
                </div>
            </div>
        </div>

        <?php if (!empty($success_message)): ?>
        <div class="success-message">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($success_message); ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
        <div class="error-message">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error_message); ?></span>
        </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card" onclick="window.location.href='?status=all<?php echo $user_role === 'admin' ? '&department=' . urlencode($dept_filter) : ''; ?>'">
                <div class="stat-icon"><i class="fas fa-chart-bar"></i></div>
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total Assets</div>
                <div class="stat-value">$<?php echo number_format($total_value, 2); ?></div>
            </div>

            <div class="stat-card" onclick="window.location.href='?status=Available<?php echo $user_role === 'admin' ? '&department=' . urlencode($dept_filter) : ''; ?>'">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?php echo $stats['available']; ?></div>
                <div class="stat-label">Available</div>
            </div>

            <div class="stat-card" onclick="window.location.href='?status=In Use<?php echo $user_role === 'admin' ? '&department=' . urlencode($dept_filter) : ''; ?>'">
                <div class="stat-icon"><i class="fas fa-briefcase"></i></div>
                <div class="stat-number"><?php echo $stats['in_use']; ?></div>
                <div class="stat-label">In Use</div>
            </div>

            <div class="stat-card" onclick="window.location.href='?status=Maintenance<?php echo $user_role === 'admin' ? '&department=' . urlencode($dept_filter) : ''; ?>'">
                <div class="stat-icon"><i class="fas fa-wrench"></i></div>
                <div class="stat-number"><?php echo $stats['maintenance']; ?></div>
                <div class="stat-label">Maintenance</div>
            </div>

            <div class="stat-card" onclick="window.location.href='?user=unassigned<?php echo $user_role === 'admin' ? '&department=' . urlencode($dept_filter) : ''; ?>'">
                <div class="stat-icon"><i class="fas fa-clipboard-list"></i></div>
                <div class="stat-number"><?php echo $stats['unassigned']; ?></div>
                <div class="stat-label">Unassigned</div>
            </div>
        </div>

        <div class="filters-section">
            <form method="GET" action="" class="filters-form">
                <?php if ($user_role === 'admin'): ?>
                <select name="department" onchange="this.form.submit()">
                    <option value="all" <?php echo $dept_filter === 'all' ? 'selected' : ''; ?>>All Departments</option>
                    <?php foreach ($departments_list as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept['dept_name']); ?>" 
                                <?php echo $dept_filter === $dept['dept_name'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['dept_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>

                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Search assets..." value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <select name="status" onchange="this.form.submit()">
                    <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="Available" <?php echo $filter_status === 'Available' ? 'selected' : ''; ?>>Available</option>
                    <option value="In Use" <?php echo $filter_status === 'In Use' ? 'selected' : ''; ?>>In Use</option>
                    <option value="Maintenance" <?php echo $filter_status === 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                    <option value="Retired" <?php echo $filter_status === 'Retired' ? 'selected' : ''; ?>>Retired</option>
                    <option value="Damaged" <?php echo $filter_status === 'Damaged' ? 'selected' : ''; ?>>Damaged</option>
                </select>

                <select name="category" onchange="this.form.submit()">
                    <option value="all" <?php echo $filter_category === 'all' ? 'selected' : ''; ?>>All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $filter_category === $category ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if (count($dept_employees) > 0): ?>
                <select name="user" onchange="this.form.submit()">
                    <option value="all" <?php echo $assigned_user === 'all' ? 'selected' : ''; ?>>All Users</option>
                    <option value="unassigned" <?php echo $assigned_user === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                    <?php foreach ($dept_employees as $employee): ?>
                        <option value="<?php echo $employee['user_id']; ?>" <?php echo $assigned_user == $employee['user_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> Filter
                </button>
                
                <a href="managerAsset.php" class="btn btn-outline">
                    <i class="fas fa-redo"></i> Reset
                </a>
            </form>
        </div>

        <?php if (count($assets) > 0): ?>
            <div class="assets-grid">
                <?php foreach ($assets as $asset): ?>
                    <div class="asset-card">
                        <div class="asset-card-header">
                            <div class="asset-title">
                                <h3><?php echo htmlspecialchars($asset['asset_name']); ?></h3>
                                <span class="asset-code"><?php echo htmlspecialchars($asset['asset_code']); ?></span>
                            </div>
                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $asset['status'])); ?>">
                                <?php echo htmlspecialchars($asset['status']); ?>
                            </span>
                        </div>

                        <div class="asset-details">
                            <div class="asset-detail">
                                <i class="fas fa-tag"></i>
                                <span><strong>Category:</strong> <?php echo htmlspecialchars($asset['category']); ?></span>
                            </div>

                            <?php if ($asset['brand']): ?>
                                <div class="asset-detail">
                                    <i class="fas fa-trademark"></i>
                                    <span><strong>Brand:</strong> <?php echo htmlspecialchars($asset['brand']); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($asset['model']): ?>
                                <div class="asset-detail">
                                    <i class="fas fa-cube"></i>
                                    <span><strong>Model:</strong> <?php echo htmlspecialchars($asset['model']); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($asset['serial_number']): ?>
                                <div class="asset-detail">
                                    <i class="fas fa-barcode"></i>
                                    <span><strong>S/N:</strong> <?php echo htmlspecialchars($asset['serial_number']); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($asset['purchase_cost']): ?>
                                <div class="asset-detail">
                                    <i class="fas fa-dollar-sign"></i>
                                    <span><strong>Value:</strong> $<?php echo number_format($asset['purchase_cost'], 2); ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($asset['location']): ?>
                                <div class="asset-detail">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span><?php echo htmlspecialchars($asset['location']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($asset['assigned_to']): ?>
                            <div class="asset-assigned">
                                <div class="assigned-label">Assigned To</div>
                                <div class="assigned-name">
                                    <i class="fas fa-user"></i>
                                    <?php echo htmlspecialchars($asset['assigned_to_name']); ?>
                                </div>
                                <?php if ($asset['assigned_to_email']): ?>
                                    <div class="assigned-email"><?php echo htmlspecialchars($asset['assigned_to_email']); ?></div>
                                <?php endif; ?>
                                <?php if ($asset['assigned_user_emp_id']): ?>
                                    <div class="assigned-email">ID: <?php echo htmlspecialchars($asset['assigned_user_emp_id']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="unassigned-badge">
                                <i class="fas fa-info-circle"></i> Not Assigned
                            </div>
                        <?php endif; ?>

                        <?php if ($asset['maintenance_count'] > 0 || $asset['last_maintenance_date']): ?>
                            <div class="maintenance-info">
                                <?php if ($asset['maintenance_count'] > 0): ?>
                                    <div class="maintenance-stat">
                                        <i class="fas fa-wrench"></i>
                                        <span><?php echo $asset['maintenance_count']; ?> maintenance(s)</span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($asset['last_maintenance_date']): ?>
                                    <div class="maintenance-stat">
                                        <i class="fas fa-calendar"></i>
                                        <span>Last: <?php echo date('M d, Y', strtotime($asset['last_maintenance_date'])); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="asset-actions">
                            <a href="departmentAssetDetails.php?id=<?php echo $asset['id']; ?>" class="btn-sm btn-view">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-assets">
                <div class="no-assets-icon"><i class="fas fa-box-open"></i></div>
                <h3>No Assets Found</h3>
                <p>No assets match your current filters. Try adjusting your search criteria.</p>
                <a href="managerAsset.php" class="btn btn-primary">
                    <i class="fas fa-redo"></i> Reset Filters
                </a>
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

        setTimeout(() => {
            document.querySelectorAll('.success-message, .error-message').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        document.querySelectorAll('.btn-edit').forEach(btn => {
            btn.addEventListener('click', function(e) {
                if (!confirm('Do you want to edit this asset?')) {
                    e.preventDefault();
                }
            });
        });

        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                document.querySelector('.search-box input').focus();
            }
        });

        let searchTimeout;
        const searchInput = document.querySelector('.search-box input');
        
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    if (this.value.length >= 3 || this.value.length === 0) {
                        this.form.submit();
                    }
                }, 500);
            });
        }

        document.querySelectorAll('select').forEach(select => {
            select.addEventListener('change', function() {
                const form = this.closest('form');
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Filtering...';
                    submitBtn.disabled = true;
                }
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            
            document.querySelectorAll('select').forEach(select => {
                const paramValue = urlParams.get(select.name);
                if (paramValue && paramValue !== 'all') {
                    select.style.borderColor = '#7c3aed';
                    select.style.backgroundColor = '#faf5ff';
                }
            });

            const searchValue = urlParams.get('search');
            if (searchValue && searchInput) {
                searchInput.style.borderColor = '#7c3aed';
                searchInput.style.backgroundColor = '#faf5ff';
            }
        });

        document.querySelectorAll('.asset-detail span').forEach(span => {
            if (span.scrollWidth > span.clientWidth) {
                span.title = span.textContent;
            }
        });

        function exportToCSV() {
            const assets = <?php echo json_encode($assets); ?>;
            
            if (assets.length === 0) {
                alert('No assets to export');
                return;
            }

            let csv = 'Asset Code,Asset Name,Category,Status,Brand,Model,Serial Number,Location,Assigned To,Purchase Cost,Purchase Date\n';
            
            assets.forEach(asset => {
                csv += `"${asset.asset_code}","${asset.asset_name}","${asset.category}","${asset.status}",`;
                csv += `"${asset.brand || ''}","${asset.model || ''}","${asset.serial_number || ''}",`;
                csv += `"${asset.location || ''}","${asset.assigned_to_name || 'Unassigned'}",`;
                csv += `"${asset.purchase_cost || '0'}","${asset.purchase_date || ''}"\n`;
            });

            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'department_assets_' + new Date().toISOString().split('T')[0] + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }

        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });

        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('click', function() {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });

        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const cardObserver = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        document.querySelectorAll('.asset-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            cardObserver.observe(card);
        });

        console.log('%cDepartment Assets Summary:', 'font-weight: bold; font-size: 16px; color: #7c3aed;');
        console.log('Total Assets: <?php echo $stats['total']; ?>');
        console.log('Total Value: $<?php echo number_format($total_value, 2); ?>');
        console.log('Available: <?php echo $stats['available']; ?>');
        console.log('In Use: <?php echo $stats['in_use']; ?>');
        console.log('Maintenance: <?php echo $stats['maintenance']; ?>');
        console.log('Unassigned: <?php echo $stats['unassigned']; ?>');
    </script>
</body>
</html>