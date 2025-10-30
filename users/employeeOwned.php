<?php
session_start();
require_once '../auth/config/database.php';

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$employee_user_id = $_GET['user_id'] ?? 0;

// Get manager's department
$dept_query = "SELECT department FROM users WHERE user_id = ?";
$dept_stmt = $pdo->prepare($dept_query);
$dept_stmt->execute([$user_id]);
$manager_dept = $dept_stmt->fetchColumn();

// Verify employee is in manager's department
$employee_query = "SELECT user_id, first_name, last_name, email, phone, employee_id, department, role, is_active 
                   FROM users 
                   WHERE user_id = ? AND department = ?";
$employee_stmt = $pdo->prepare($employee_query);
$employee_stmt->execute([$employee_user_id, $manager_dept]);
$employee = $employee_stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    $_SESSION['team_error'] = "Employee not found or not in your department.";
    header("Location: teamMembers.php");
    exit();
}

// Get filters
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build query for employee's assets
$where_conditions = ["a.assigned_to = ?"];
$params = [$employee_user_id];

if (!empty($search)) {
    $where_conditions[] = "(a.asset_name LIKE ? OR a.asset_code LIKE ? OR a.serial_number LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
}

if (!empty($category_filter)) {
    $where_conditions[] = "a.category = ?";
    $params[] = $category_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = "a.status = ?";
    $params[] = $status_filter;
}

$where_clause = implode(" AND ", $where_conditions);

// Fetch employee's assets
$assets_query = "
    SELECT a.*,
           CONCAT(assigned.first_name, ' ', assigned.last_name) as assigned_user_name
    FROM assets a
    LEFT JOIN users assigned ON a.assigned_to = assigned.user_id
    WHERE $where_clause
    ORDER BY a.created_at DESC
";

$stmt = $pdo->prepare($assets_query);
$stmt->execute($params);
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get asset statistics for this employee
$stats_query = "
    SELECT 
        COUNT(*) as total_assets,
        COUNT(CASE WHEN status = 'available' THEN 1 END) as available_count,
        COUNT(CASE WHEN status = 'in_use' THEN 1 END) as in_use_count,
        COUNT(CASE WHEN status = 'maintenance' THEN 1 END) as maintenance_count,
        COUNT(CASE WHEN status = 'retired' THEN 1 END) as retired_count,
        SUM(CASE WHEN purchase_cost IS NOT NULL THEN purchase_cost ELSE 0 END) as total_value
    FROM assets
    WHERE assigned_to = ?
";

$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute([$employee_user_id]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get unique categories for filter
$categories_query = "SELECT DISTINCT category FROM assets WHERE assigned_to = ? AND category IS NOT NULL ORDER BY category";
$categories_stmt = $pdo->prepare($categories_query);
$categories_stmt->execute([$employee_user_id]);
$categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>'s Assets</title>
    
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
        }

        .breadcrumb {
            margin-bottom: 1.5rem;
            font-size: 14px;
            color: #6b7280;
        }

        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
            transition: color 0.3s;
        }

        .breadcrumb a:hover {
            color: #764ba2;
            text-decoration: underline;
        }

        .page-header {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .employee-info {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }

        .employee-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: bold;
            flex-shrink: 0;
        }

        .employee-details h1 {
            font-size: 28px;
            color: #1a202c;
            margin-bottom: 8px;
        }

        .employee-meta {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
            font-size: 14px;
            color: #6b7280;
        }

        .employee-meta i {
            color: #667eea;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
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
        }

        .stat-card.primary::before { background: #667eea; }
        .stat-card.success::before { background: #10b981; }
        .stat-card.info::before { background: #3b82f6; }
        .stat-card.warning::before { background: #f59e0b; }
        .stat-card.danger::before { background: #ef4444; }
        .stat-card.purple::before { background: #8b5cf6; }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 15px;
        }

        .stat-card.primary .stat-icon { background: #e0e7ff; color: #667eea; }
        .stat-card.success .stat-icon { background: #d1fae5; color: #10b981; }
        .stat-card.info .stat-icon { background: #dbeafe; color: #3b82f6; }
        .stat-card.warning .stat-icon { background: #fef3c7; color: #f59e0b; }
        .stat-card.danger .stat-icon { background: #fee2e2; color: #ef4444; }
        .stat-card.purple .stat-icon { background: #ede9fe; color: #8b5cf6; }

        .stat-label {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #1a202c;
        }

        .filters-section {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }

        .filters-form {
            display: grid;
            grid-template-columns: 1fr auto auto auto auto;
            gap: 15px;
            align-items: end;
        }

        .search-box {
            position: relative;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }

        .search-box input {
            width: 100%;
            padding: 12px 12px 12px 40px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
        }

        .search-box input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        select {
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            background: white;
        }

        select:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-outline {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .btn-outline:hover {
            background: #667eea;
            color: white;
        }

        .assets-table {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f9fafb;
        }

        th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 16px;
            border-top: 1px solid #e5e7eb;
            font-size: 14px;
            color: #4b5563;
        }

        tbody tr {
            transition: background-color 0.3s;
        }

        tbody tr:hover {
            background: #f9fafb;
        }

        .asset-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
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
            background: #e5e7eb;
            color: #4b5563;
        }

        .badge-damaged {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #6b7280;
            background: white;
            border-radius: 12px;
        }

        .empty-state i {
            font-size: 80px;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .empty-state h3 {
            font-size: 24px;
            margin-bottom: 10px;
            color: #1a202c;
        }

        .action-btn {
            padding: 6px 12px;
            font-size: 12px;
            text-decoration: none;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s;
        }

        .action-btn.view {
            background: #e0e7ff;
            color: #667eea;
        }

        .action-btn.view:hover {
            background: #667eea;
            color: white;
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

            .filters-form {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .assets-table {
                overflow-x: auto;
            }

            table {
                min-width: 800px;
            }
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/Msidebar.php"); ?>

    <main class="main-content">
        <div class="container">
            <!-- Breadcrumb -->
            <div class="breadcrumb">
                <a href="teamMembers.php"><i class="fas fa-users"></i> My Team</a> 
                <span> / </span>
                <span><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>'s Assets</span>
            </div>

            <!-- Employee Info Header -->
            <div class="page-header">
                <div class="employee-info">
                    <div class="employee-avatar">
                        <?php echo strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)); ?>
                    </div>
                    <div class="employee-details">
                        <h1><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></h1>
                        <div class="employee-meta">
                            <span><i class="fas fa-id-badge"></i> <?php echo ucfirst($employee['role']); ?></span>
                            <?php if ($employee['employee_id']): ?>
                                <span><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($employee['employee_id']); ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-building"></i> <?php echo htmlspecialchars($employee['department']); ?></span>
                            <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($employee['email']); ?></span>
                            <?php if ($employee['phone']): ?>
                                <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($employee['phone']); ?></span>
                            <?php endif; ?>
                            <span class="status-badge status-<?php echo $employee['is_active'] ? 'active' : 'inactive'; ?>">
                                <?php echo $employee['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Asset Statistics -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-icon">
                        <i class="fas fa-laptop"></i>
                    </div>
                    <div class="stat-label">Total Assets</div>
                    <div class="stat-value"><?php echo $stats['total_assets']; ?></div>
                </div>

                <div class="stat-card success">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-label">In Use</div>
                    <div class="stat-value"><?php echo $stats['in_use_count']; ?></div>
                </div>

                <div class="stat-card info">
                    <div class="stat-icon">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="stat-label">Available</div>
                    <div class="stat-value"><?php echo $stats['available_count']; ?></div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-icon">
                        <i class="fas fa-tools"></i>
                    </div>
                    <div class="stat-label">Maintenance</div>
                    <div class="stat-value"><?php echo $stats['maintenance_count']; ?></div>
                </div>

                <div class="stat-card danger">
                    <div class="stat-icon">
                        <i class="fas fa-archive"></i>
                    </div>
                    <div class="stat-label">Retired</div>
                    <div class="stat-value"><?php echo $stats['retired_count']; ?></div>
                </div>

                <div class="stat-card purple">
                    <div class="stat-icon">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                    <div class="stat-label">Total Value</div>
                    <div class="stat-value">$<?php echo number_format($stats['total_value'], 0); ?></div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" action="" class="filters-form">
                    <input type="hidden" name="user_id" value="<?php echo $employee_user_id; ?>">
                    
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search assets..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <select name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" 
                                    <?php echo $category_filter === $cat ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="status">
                        <option value="">All Status</option>
                        <option value="available" <?php echo $status_filter === 'available' ? 'selected' : ''; ?>>Available</option>
                        <option value="in_use" <?php echo $status_filter === 'in_use' ? 'selected' : ''; ?>>In Use</option>
                        <option value="maintenance" <?php echo $status_filter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                        <option value="retired" <?php echo $status_filter === 'retired' ? 'selected' : ''; ?>>Retired</option>
                        <option value="damaged" <?php echo $status_filter === 'damaged' ? 'selected' : ''; ?>>Damaged</option>
                    </select>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    
                    <a href="employeeOwned.php?user_id=<?php echo $employee_user_id; ?>" class="btn btn-outline">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </form>
            </div>

            <!-- Assets Table -->
            <?php if (count($assets) > 0): ?>
            <div class="assets-table">
                <table>
                    <thead>
                        <tr>
                            <th>Asset Code</th>
                            <th>Asset Name</th>
                            <th>Category</th>
                            <th>Brand/Model</th>
                            <th>Serial Number</th>
                            <th>Status</th>
                            <th>Purchase Date</th>
                            <th>Value</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assets as $asset): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($asset['asset_code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($asset['asset_name']); ?></td>
                            <td><?php echo htmlspecialchars($asset['category'] ?: 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars(trim($asset['brand'] . ' ' . $asset['model']) ?: 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($asset['serial_number'] ?: 'N/A'); ?></td>
                            <td>
                                <span class="asset-badge badge-<?php echo str_replace(' ', '-', strtolower($asset['status'])); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $asset['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo $asset['purchase_date'] ? date('M d, Y', strtotime($asset['purchase_date'])) : 'N/A'; ?>
                            </td>
                            <td>
                                <?php echo $asset['purchase_cost'] ? '$' . number_format($asset['purchase_cost'], 2) : 'N/A'; ?>
                            </td>
                            <td>
                                <a href="departmentAssetDetails.php?id=<?php echo $asset['id']; ?>" 
                                   class="action-btn view" title="View Details">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No Assets Found</h3>
                <p>This employee currently has no assets assigned matching the current filters.</p>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Add smooth scroll behavior
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    </script>
</body>
</html>