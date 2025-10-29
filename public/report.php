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

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Initialize report data arrays
$stats = [
    'total_assets' => 0,
    'available_assets' => 0,
    'in_use_assets' => 0,
    'maintenance_assets' => 0,
    'retired_assets' => 0,
    'total_value' => 0,
    'avg_asset_age' => 0,
    'warranty_expiring' => 0,
    'overdue_maintenance' => 0,
    'total_maintenance_cost' => 0,
    'completed_on_time' => 0,
    'completed_late' => 0,
    'compliance_rate' => 0
];

$category_breakdown = [];
$department_breakdown = [];
$age_distribution = [];
$maintenance_by_month = [];
$top_maintenance_assets = [];
$warranty_status = [];
$asset_utilization = [];
$cost_by_category = [];
$maintenance_compliance = [];
$user_compliance = [];

try {
    // Total Assets Statistics
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM assets");
    $stats['total_assets'] = $stmt->fetchColumn();

    // Status breakdown - FIXED: Handle all status value formats
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM assets GROUP BY status");
    $status_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Map all possible status value formats to stat keys
    foreach ($status_results as $row) {
        $status = strtolower(str_replace(' ', '_', trim($row['status'])));
        $stat_key = $status . '_assets';

        // Map to the correct key
        if (
            $stat_key === 'available_assets' || $stat_key === 'in_use_assets' ||
            $stat_key === 'maintenance_assets' || $stat_key === 'retired_assets'
        ) {
            $stats[$stat_key] = $row['count'];
        }
    }

    // Total Asset Value
    $stmt = $pdo->query("SELECT SUM(purchase_cost) as total_value FROM assets WHERE purchase_cost IS NOT NULL");
    $stats['total_value'] = $stmt->fetchColumn() ?: 0;

    // Average Asset Age
    $stmt = $pdo->query("SELECT AVG(DATEDIFF(NOW(), purchase_date)) as avg_age FROM assets WHERE purchase_date IS NOT NULL");
    $avg_days = $stmt->fetchColumn();
    $stats['avg_asset_age'] = $avg_days ? round($avg_days / 365, 1) : 0;

    // Warranty Expiring Soon (within 30 days)
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM assets WHERE warranty_expiry IS NOT NULL AND warranty_expiry BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)");
    $stats['warranty_expiring'] = $stmt->fetchColumn();

    // Overdue Maintenance
    $stmt = $pdo->query("SELECT COUNT(DISTINCT r.asset_id) as count FROM recurring_maintenance r WHERE r.is_active = 1 AND r.next_due_date < NOW()");
    $stats['overdue_maintenance'] = $stmt->fetchColumn();

    // Total Maintenance Cost (last 12 months)
    $stmt = $pdo->query("SELECT SUM(cost) as total FROM asset_maintenance WHERE cost IS NOT NULL AND maintenance_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)");
    $stats['total_maintenance_cost'] = $stmt->fetchColumn() ?: 0;

    // Category Breakdown
    $stmt = $pdo->query("SELECT category, COUNT(*) as count, SUM(purchase_cost) as total_cost FROM assets GROUP BY category ORDER BY count DESC");
    $category_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Department Breakdown
    $stmt = $pdo->query("SELECT department, COUNT(*) as count FROM assets WHERE department IS NOT NULL AND department != '' GROUP BY department ORDER BY count DESC");
    $department_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Age Distribution
    $stmt = $pdo->query("
        SELECT 
            CASE 
                WHEN DATEDIFF(NOW(), purchase_date) <= 365 THEN '0-1 years'
                WHEN DATEDIFF(NOW(), purchase_date) <= 1095 THEN '1-3 years'
                WHEN DATEDIFF(NOW(), purchase_date) <= 1825 THEN '3-5 years'
                ELSE '5+ years'
            END as age_group,
            COUNT(*) as count
        FROM assets 
        WHERE purchase_date IS NOT NULL
        GROUP BY age_group
        ORDER BY age_group
    ");
    $age_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Maintenance by Month (last 12 months)
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(maintenance_date, '%Y-%m') as month,
            COUNT(*) as count,
            SUM(cost) as total_cost
        FROM asset_maintenance
        WHERE maintenance_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY month
        ORDER BY month ASC
    ");
    $maintenance_by_month = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top Maintenance Assets
    $stmt = $pdo->query("
        SELECT 
            a.asset_code,
            a.asset_name,
            a.category,
            COUNT(m.id) as maintenance_count,
            SUM(m.cost) as total_cost
        FROM assets a
        INNER JOIN asset_maintenance m ON a.id = m.asset_id
        WHERE m.maintenance_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY a.id
        ORDER BY maintenance_count DESC
        LIMIT 10
    ");
    $top_maintenance_assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Warranty Status Distribution
    $stmt = $pdo->query("
        SELECT 
            CASE 
                WHEN warranty_expiry IS NULL THEN 'No Warranty Info'
                WHEN warranty_expiry < NOW() THEN 'Expired'
                WHEN warranty_expiry BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY) THEN 'Expiring Soon'
                ELSE 'Active'
            END as warranty_status,
            COUNT(*) as count
        FROM assets
        GROUP BY warranty_status
    ");
    $warranty_status = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Asset Utilization
    $stmt = $pdo->query("
        SELECT 
            CASE 
                WHEN assigned_to IS NOT NULL THEN 'Assigned'
                ELSE 'Unassigned'
            END as utilization,
            COUNT(*) as count
        FROM assets
        WHERE status NOT IN ('Retired', 'Maintenance')
        GROUP BY utilization
    ");
    $asset_utilization = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cost by Category
    $stmt = $pdo->query("
        SELECT 
            category,
            SUM(purchase_cost) as total_cost,
            AVG(purchase_cost) as avg_cost,
            COUNT(*) as count
        FROM assets
        WHERE purchase_cost IS NOT NULL
        GROUP BY category
        ORDER BY total_cost DESC
    ");
    $cost_by_category = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Reports error: " . $e->getMessage());
}

// Prepare data for charts - FIXED: Better empty data handling
$category_labels = !empty($category_breakdown) ? json_encode(array_column($category_breakdown, 'category')) : '[]';
$category_values = !empty($category_breakdown) ? json_encode(array_column($category_breakdown, 'count')) : '[]';

$department_labels = !empty($department_breakdown) ? json_encode(array_column($department_breakdown, 'department')) : '[]';
$department_values = !empty($department_breakdown) ? json_encode(array_column($department_breakdown, 'count')) : '[]';

$age_labels = !empty($age_distribution) ? json_encode(array_column($age_distribution, 'age_group')) : '[]';
$age_values = !empty($age_distribution) ? json_encode(array_column($age_distribution, 'count')) : '[]';

$maintenance_months = !empty($maintenance_by_month) ? json_encode(array_map(function ($item) {
    return date('M Y', strtotime($item['month'] . '-01'));
}, $maintenance_by_month)) : '[]';
$maintenance_counts = !empty($maintenance_by_month) ? json_encode(array_column($maintenance_by_month, 'count')) : '[]';
$maintenance_costs = !empty($maintenance_by_month) ? json_encode(array_column($maintenance_by_month, 'total_cost')) : '[]';

$warranty_labels = !empty($warranty_status) ? json_encode(array_column($warranty_status, 'warranty_status')) : '[]';
$warranty_values = !empty($warranty_status) ? json_encode(array_column($warranty_status, 'count')) : '[]';

$utilization_labels = !empty($asset_utilization) ? json_encode(array_column($asset_utilization, 'utilization')) : '[]';
$utilization_values = !empty($asset_utilization) ? json_encode(array_column($asset_utilization, 'count')) : '[]';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Reports & Analytics - E-Asset Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
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

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header h1 i {
            color: #7c3aed;
        }

        .header p {
            color: #718096;
            font-size: 15px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.3s;
            border-left: 4px solid #7c3aed;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(124, 58, 237, 0.15);
        }

        .stat-card.green {
            border-left-color: #10b981;
        }

        .stat-card.blue {
            border-left-color: #3b82f6;
        }

        .stat-card.yellow {
            border-left-color: #f59e0b;
        }

        .stat-card.red {
            border-left-color: #ef4444;
        }

        .stat-icon {
            font-size: 32px;
            color: #7c3aed;
            margin-bottom: 12px;
            display: block;
        }

        .stat-card.green .stat-icon {
            color: #10b981;
        }

        .stat-card.blue .stat-icon {
            color: #3b82f6;
        }

        .stat-card.yellow .stat-icon {
            color: #f59e0b;
        }

        .stat-card.red .stat-icon {
            color: #ef4444;
        }

        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
        }

        .stat-label {
            color: #718096;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .section {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .section-header {
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header h2 {
            font-size: 22px;
            font-weight: 700;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-header h2 i {
            color: #7c3aed;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: white;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .chart-header {
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #e2e8f0;
        }

        .chart-header h3 {
            font-size: 18px;
            font-weight: 700;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chart-header h3 i {
            color: #7c3aed;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        .table-container {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table thead {
            background: linear-gradient(135deg, #f7f4fe 0%, #ede9fe 100%);
        }

        .table thead th {
            padding: 16px;
            text-align: left;
            font-weight: 700;
            font-size: 13px;
            color: #6d28d9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
        }

        .table tbody tr {
            border-bottom: 1px solid #e2e8f0;
            transition: all 0.2s;
        }

        .table tbody tr:hover {
            background: #fafbfc;
        }

        .table tbody td {
            padding: 16px;
            font-size: 14px;
            color: #2d3748;
        }

        .cost-value {
            color: #10b981;
            font-weight: 600;
        }

        .count-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        .btn {
            padding: 12px 24px;
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

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }

        .export-buttons {
            display: flex;
            gap: 12px;
        }

        .alert-box {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }

        .alert-box.warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
            border-left: 4px solid #f59e0b;
        }

        .alert-box.danger {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state-icon {
            font-size: 48px;
            color: #cbd5e0;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 20px;
            color: #1a202c;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #718096;
            font-size: 15px;
        }

        @media (max-width: 1024px) {
            .container {
                margin-left: 80px;
            }

            .charts-grid {
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

            .header h1 {
                font-size: 22px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .section {
                padding: 20px;
            }

            .export-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
</head>

<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="container" id="mainContainer">
        <div class="header">
            <h1><i class="fas fa-chart-bar"></i> Asset Reports & Analytics</h1>
            <p>Comprehensive reports and statistics for asset management and maintenance tracking</p>
        </div>

        <!-- Alerts -->
        <?php if ($stats['overdue_maintenance'] > 0): ?>
            <div class="alert-box danger">
                <i class="fas fa-exclamation-circle" style="font-size: 24px;"></i>
                <div>
                    <strong>Overdue Maintenance Alert!</strong><br>
                    <?php echo $stats['overdue_maintenance']; ?> asset(s) have overdue maintenance schedules that need immediate attention.
                </div>
            </div>
        <?php endif; ?>

        <?php if ($stats['warranty_expiring'] > 0): ?>
            <div class="alert-box warning">
                <i class="fas fa-exclamation-triangle" style="font-size: 24px;"></i>
                <div>
                    <strong>Warranty Expiration Warning!</strong><br>
                    <?php echo $stats['warranty_expiring']; ?> asset(s) have warranties expiring within the next 30 days.
                </div>
            </div>
        <?php endif; ?>

        <!-- Key Statistics -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <i class="fas fa-boxes stat-icon"></i>
                <div class="stat-number"><?php echo number_format($stats['total_assets']); ?></div>
                <div class="stat-label">Total Assets</div>
            </div>

            <div class="stat-card green">
                <i class="fas fa-dollar-sign stat-icon"></i>
                <div class="stat-number">$<?php echo number_format($stats['total_value'], 0); ?></div>
                <div class="stat-label">Total Asset Value</div>
            </div>

            <div class="stat-card yellow">
                <i class="fas fa-tools stat-icon"></i>
                <div class="stat-number">$<?php echo number_format($stats['total_maintenance_cost'], 0); ?></div>
                <div class="stat-label">Maintenance Cost (12mo)</div>
            </div>

            <div class="stat-card">
                <i class="fas fa-clock stat-icon"></i>
                <div class="stat-number"><?php echo $stats['avg_asset_age']; ?> yrs</div>
                <div class="stat-label">Average Asset Age</div>
            </div>

            <div class="stat-card green">
                <i class="fas fa-check-circle stat-icon"></i>
                <div class="stat-number"><?php echo $stats['available_assets']; ?></div>
                <div class="stat-label">Available Assets</div>
            </div>

            <div class="stat-card blue">
                <i class="fas fa-user-check stat-icon"></i>
                <div class="stat-number"><?php echo $stats['in_use_assets']; ?></div>
                <div class="stat-label">In Use</div>
            </div>

            <div class="stat-card yellow">
                <i class="fas fa-wrench stat-icon"></i>
                <div class="stat-number"><?php echo $stats['maintenance_assets']; ?></div>
                <div class="stat-label">In Maintenance</div>
            </div>

            <div class="stat-card red">
                <i class="fas fa-exclamation-triangle stat-icon"></i>
                <div class="stat-number"><?php echo $stats['overdue_maintenance']; ?></div>
                <div class="stat-label">Overdue Maintenance</div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="charts-grid">
            <!-- Category Distribution -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-pie"></i> Assets by Category</h3>
                </div>
                <div class="chart-container">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>

            <!-- Department Distribution -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fas fa-building"></i> Assets by Department</h3>
                </div>
                <div class="chart-container">
                    <canvas id="departmentChart"></canvas>
                </div>
            </div>

            <!-- Age Distribution -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fas fa-calendar-alt"></i> Asset Age Distribution</h3>
                </div>
                <div class="chart-container">
                    <canvas id="ageChart"></canvas>
                </div>
            </div>

            <!-- Warranty Status -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fas fa-shield-alt"></i> Warranty Status</h3>
                </div>
                <div class="chart-container">
                    <canvas id="warrantyChart"></canvas>
                </div>
            </div>

            <!-- Maintenance Trend -->
            <?php if (!empty($maintenance_by_month)): ?>
                <div class="chart-card" style="grid-column: 1 / -1;">
                    <div class="chart-header">
                        <h3><i class="fas fa-chart-line"></i> Maintenance Trend (Last 12 Months)</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="maintenanceTrendChart"></canvas>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Asset Utilization -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-pie"></i> Asset Utilization</h3>
                </div>
                <div class="chart-container">
                    <canvas id="utilizationChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Top Maintenance Assets Table -->
        <?php if (count($top_maintenance_assets) > 0): ?>
            <div class="section">
                <div class="section-header">
                    <h2><i class="fas fa-list-ol"></i> Top 10 Assets by Maintenance Frequency</h2>
                    <div class="export-buttons">
                        <button class="btn btn-success" onclick="exportTableToCSV('maintenance_table', 'top_maintenance_assets.csv')">
                            <i class="fas fa-file-csv"></i> Export CSV
                        </button>
                    </div>
                </div>
                <div class="table-container">
                    <table class="table" id="maintenance_table">
                        <thead>
                            <tr>
                                <th>Asset Code</th>
                                <th>Asset Name</th>
                                <th>Category</th>
                                <th>Maintenance Count</th>
                                <th>Total Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_maintenance_assets as $asset): ?>
                                <tr>
                                    <td>
                                        <a href="assetDetails.php?id=<?php echo $asset['asset_code']; ?>" style="color: #7c3aed; text-decoration: none; font-weight: 600;">
                                            <?php echo htmlspecialchars($asset['asset_code']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($asset['asset_name']); ?></td>
                                    <td><?php echo htmlspecialchars($asset['category']); ?></td>
                                    <td>
                                        <span class="count-badge"><?php echo $asset['maintenance_count']; ?> times</span>
                                    </td>
                                    <td class="cost-value">
                                        $<?php echo number_format($asset['total_cost'] ?: 0, 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Cost by Category Table -->
        <?php if (count($cost_by_category) > 0): ?>
            <div class="section">
                <div class="section-header">
                    <h2><i class="fas fa-dollar-sign"></i> Cost Analysis by Category</h2>
                    <div class="export-buttons">
                        <button class="btn btn-success" onclick="exportTableToCSV('cost_table', 'cost_by_category.csv')">
                            <i class="fas fa-file-csv"></i> Export CSV
                        </button>
                    </div>
                </div>
                <div class="table-container">
                    <table class="table" id="cost_table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Asset Count</th>
                                <th>Total Cost</th>
                                <th>Average Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cost_by_category as $category): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($category['category']); ?></td>
                                    <td>
                                        <span class="count-badge"><?php echo $category['count']; ?> assets</span>
                                    </td>
                                    <td class="cost-value">
                                        $<?php echo number_format($category['total_cost'], 2); ?>
                                    </td>
                                    <td class="cost-value">
                                        $<?php echo number_format($category['avg_cost'], 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Export All Reports Button -->
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-download"></i> Export Options</h2>
            </div>
            <div class="export-buttons">
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Report
                </button>
                <button class="btn btn-success" onclick="exportAllData()">
                    <i class="fas fa-file-excel"></i> Export All Data
                </button>
                <a href="asset.php" class="btn btn-primary">
                    <i class="fas fa-boxes"></i> Back to Inventory
                </a>
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

        document.addEventListener('DOMContentLoaded', updateMainContainer);

        document.addEventListener('click', function(e) {
            if (e.target.closest('.toggle-sidebar')) {
                setTimeout(updateMainContainer, 50);
            }
        });

        const observer = new MutationObserver(updateMainContainer);
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) {
            observer.observe(sidebar, {
                attributes: true,
                attributeFilter: ['class']
            });
        }

        // Chart.js configuration
        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.color = '#718096';

        const chartColors = {
            primary: ['#7c3aed', '#6d28d9', '#5b21b6', '#4c1d95'],
            success: ['#10b981', '#059669', '#047857', '#065f46'],
            blue: ['#3b82f6', '#2563eb', '#1d4ed8', '#1e40af'],
            yellow: ['#f59e0b', '#d97706', '#b45309', '#92400e'],
            red: ['#ef4444', '#dc2626', '#b91c1c', '#991b1b'],
            mixed: ['#7c3aed', '#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#6366f1', '#8b5cf6', '#ec4899']
        };

        // Helper function to check if data is valid
        function hasValidData(labels, values) {
            return labels.length > 0 && values.length > 0 && values.some(v => v > 0);
        }

        // Helper function to show empty state
        function showEmptyState(container, icon, title, message) {
            const emptyState = document.createElement('div');
            emptyState.className = 'empty-state';
            emptyState.innerHTML = '<div class="empty-state-icon"><i class="' + icon + '"></i></div>' +
                '<h3>' + title + '</h3>' +
                '<p>' + message + '</p>';
            container.innerHTML = '';
            container.appendChild(emptyState);
        }

        // Category Distribution Chart
        const categoryCtx = document.getElementById('categoryChart');
        if (categoryCtx) {
            const categoryLabels = <?php echo $category_labels; ?>;
            const categoryValues = <?php echo $category_values; ?>;

            if (hasValidData(categoryLabels, categoryValues)) {
                new Chart(categoryCtx, {
                    type: 'doughnut',
                    data: {
                        labels: categoryLabels,
                        datasets: [{
                            data: categoryValues,
                            backgroundColor: chartColors.mixed,
                            borderWidth: 3,
                            borderColor: '#fff',
                            hoverOffset: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 15,
                                    font: {
                                        size: 12,
                                        weight: 500
                                    },
                                    usePointStyle: true,
                                    pointStyle: 'circle'
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                padding: 12,
                                titleFont: {
                                    size: 14,
                                    weight: 600
                                },
                                bodyFont: {
                                    size: 13
                                },
                                cornerRadius: 8,
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.parsed || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = ((value / total) * 100).toFixed(1);
                                        return label + ': ' + value + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            } else {
                showEmptyState(categoryCtx.parentElement, 'fas fa-chart-pie', 'No Data Available', 'Add assets with categories to see distribution.');
            }
        }

        // Department Distribution Chart
        const departmentCtx = document.getElementById('departmentChart');
        if (departmentCtx) {
            const departmentLabels = <?php echo $department_labels; ?>;
            const departmentValues = <?php echo $department_values; ?>;

            if (hasValidData(departmentLabels, departmentValues)) {
                new Chart(departmentCtx, {
                    type: 'bar',
                    data: {
                        labels: departmentLabels,
                        datasets: [{
                            label: 'Number of Assets',
                            data: departmentValues,
                            backgroundColor: 'rgba(124, 58, 237, 0.8)',
                            borderColor: 'rgba(124, 58, 237, 1)',
                            borderWidth: 0,
                            borderRadius: 8,
                            hoverBackgroundColor: 'rgba(109, 40, 217, 0.9)'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                    font: {
                                        size: 12
                                    }
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                }
                            },
                            x: {
                                ticks: {
                                    font: {
                                        size: 12
                                    }
                                },
                                grid: {
                                    display: false
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                padding: 12,
                                titleFont: {
                                    size: 14,
                                    weight: 600
                                },
                                bodyFont: {
                                    size: 13
                                },
                                cornerRadius: 8
                            }
                        }
                    }
                });
            } else {
                showEmptyState(departmentCtx.parentElement, 'fas fa-building', 'No Data Available', 'Assign assets to departments to see distribution.');
            }
        }

        // Age Distribution Chart
        const ageCtx = document.getElementById('ageChart');
        if (ageCtx) {
            const ageLabels = <?php echo $age_labels; ?>;
            const ageValues = <?php echo $age_values; ?>;

            if (hasValidData(ageLabels, ageValues)) {
                new Chart(ageCtx, {
                    type: 'bar',
                    data: {
                        labels: ageLabels,
                        datasets: [{
                            label: 'Number of Assets',
                            data: ageValues,
                            backgroundColor: chartColors.blue,
                            borderWidth: 0,
                            borderRadius: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                    font: {
                                        size: 12
                                    }
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                }
                            },
                            x: {
                                ticks: {
                                    font: {
                                        size: 12
                                    }
                                },
                                grid: {
                                    display: false
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                padding: 12,
                                titleFont: {
                                    size: 14,
                                    weight: 600
                                },
                                bodyFont: {
                                    size: 13
                                },
                                cornerRadius: 8
                            }
                        }
                    }
                });
            } else {
                showEmptyState(ageCtx.parentElement, 'fas fa-calendar-alt', 'No Data Available', 'Add purchase dates to see age distribution.');
            }
        }

        // Warranty Status Chart
        const warrantyCtx = document.getElementById('warrantyChart');
        if (warrantyCtx) {
            const warrantyLabels = <?php echo $warranty_labels; ?>;
            const warrantyValues = <?php echo $warranty_values; ?>;

            const warrantyColors = {
                'Active': '#10b981',
                'Expiring Soon': '#f59e0b',
                'Expired': '#ef4444',
                'No Warranty Info': '#6b7280'
            };

            const warrantyBgColors = warrantyLabels.map(label => warrantyColors[label] || '#6b7280');

            if (hasValidData(warrantyLabels, warrantyValues)) {
                new Chart(warrantyCtx, {
                    type: 'doughnut',
                    data: {
                        labels: warrantyLabels,
                        datasets: [{
                            data: warrantyValues,
                            backgroundColor: warrantyBgColors,
                            borderWidth: 3,
                            borderColor: '#fff',
                            hoverOffset: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 15,
                                    font: {
                                        size: 12,
                                        weight: 500
                                    },
                                    usePointStyle: true,
                                    pointStyle: 'circle'
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                padding: 12,
                                titleFont: {
                                    size: 14,
                                    weight: 600
                                },
                                bodyFont: {
                                    size: 13
                                },
                                cornerRadius: 8,
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.parsed || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = ((value / total) * 100).toFixed(1);
                                        return label + ': ' + value + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            } else {
                showEmptyState(warrantyCtx.parentElement, 'fas fa-shield-alt', 'No Data Available', 'Add warranty information to see status.');
            }
        }

        // Maintenance Trend Chart
        const maintenanceTrendCtx = document.getElementById('maintenanceTrendChart');
        if (maintenanceTrendCtx) {
            const maintenanceMonths = <?php echo $maintenance_months; ?>;
            const maintenanceCounts = <?php echo $maintenance_counts; ?>;
            const maintenanceCosts = <?php echo $maintenance_costs; ?>;

            if (hasValidData(maintenanceMonths, maintenanceCounts)) {
                new Chart(maintenanceTrendCtx, {
                    type: 'line',
                    data: {
                        labels: maintenanceMonths,
                        datasets: [{
                                label: 'Maintenance Count',
                                data: maintenanceCounts,
                                borderColor: '#7c3aed',
                                backgroundColor: 'rgba(124, 58, 237, 0.1)',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                yAxisID: 'y'
                            },
                            {
                                label: 'Maintenance Cost ($)',
                                data: maintenanceCosts,
                                borderColor: '#10b981',
                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                yAxisID: 'y1'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false
                        },
                        scales: {
                            y: {
                                type: 'linear',
                                display: true,
                                position: 'left',
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                    font: {
                                        size: 12
                                    }
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                },
                                title: {
                                    display: true,
                                    text: 'Count',
                                    font: {
                                        size: 12,
                                        weight: 600
                                    }
                                }
                            },
                            y1: {
                                type: 'linear',
                                display: true,
                                position: 'right',
                                beginAtZero: true,
                                ticks: {
                                    font: {
                                        size: 12
                                    },
                                    callback: function(value) {
                                        return '$' + value;
                                    }
                                },
                                grid: {
                                    drawOnChartArea: false
                                },
                                title: {
                                    display: true,
                                    text: 'Cost ($)',
                                    font: {
                                        size: 12,
                                        weight: 600
                                    }
                                }
                            },
                            x: {
                                ticks: {
                                    font: {
                                        size: 11
                                    }
                                },
                                grid: {
                                    display: false
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    padding: 15,
                                    font: {
                                        size: 12,
                                        weight: 500
                                    },
                                    usePointStyle: true
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                padding: 12,
                                titleFont: {
                                    size: 14,
                                    weight: 600
                                },
                                bodyFont: {
                                    size: 13
                                },
                                cornerRadius: 8,
                                callbacks: {
                                    label: function(context) {
                                        let label = context.dataset.label || '';
                                        if (label) {
                                            label += ': ';
                                        }
                                        if (context.parsed.y !== null) {
                                            if (context.datasetIndex === 1) {
                                                label += '$' + context.parsed.y.toFixed(2);
                                            } else {
                                                label += context.parsed.y;
                                            }
                                        }
                                        return label;
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }

        // Asset Utilization Chart
        const utilizationCtx = document.getElementById('utilizationChart');
        if (utilizationCtx) {
            const utilizationLabels = <?php echo $utilization_labels; ?>;
            const utilizationValues = <?php echo $utilization_values; ?>;

            if (hasValidData(utilizationLabels, utilizationValues)) {
                new Chart(utilizationCtx, {
                    type: 'doughnut',
                    data: {
                        labels: utilizationLabels,
                        datasets: [{
                            data: utilizationValues,
                            backgroundColor: ['#10b981', '#6b7280'],
                            borderWidth: 3,
                            borderColor: '#fff',
                            hoverOffset: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 15,
                                    font: {
                                        size: 12,
                                        weight: 500
                                    },
                                    usePointStyle: true,
                                    pointStyle: 'circle'
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                padding: 12,
                                titleFont: {
                                    size: 14,
                                    weight: 600
                                },
                                bodyFont: {
                                    size: 13
                                },
                                cornerRadius: 8,
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.parsed || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = ((value / total) * 100).toFixed(1);
                                        return label + ': ' + value + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            } else {
                showEmptyState(utilizationCtx.parentElement, 'fas fa-chart-pie', 'No Data Available', 'Assets will appear here as they are used.');
            }
        }

        // Export table to CSV function
        function exportTableToCSV(tableId, filename) {
            const table = document.getElementById(tableId);
            if (!table) {
                alert('Table not found!');
                return;
            }

            let csv = [];
            const rows = table.querySelectorAll('tr');

            for (let i = 0; i < rows.length; i++) {
                const row = [];
                const cols = rows[i].querySelectorAll('td, th');

                for (let j = 0; j < cols.length; j++) {
                    let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, '').replace(/(\s\s+)/gm, ' ');
                    data = data.replace(/"/g, '""');
                    row.push('"' + data + '"');
                }

                csv.push(row.join(','));
            }

            downloadCSV(csv.join('\n'), filename);
        }

        // Export all data function
        function exportAllData() {
            const reportData = {
                'Generated': new Date().toLocaleString(),
                'Total Assets': <?php echo $stats['total_assets']; ?>,
                'Total Value': '$<?php echo number_format($stats['total_value'], 2); ?>',
                'Available': <?php echo $stats['available_assets']; ?>,
                'In Use': <?php echo $stats['in_use_assets']; ?>,
                'Maintenance': <?php echo $stats['maintenance_assets']; ?>,
                'Average Age (years)': <?php echo $stats['avg_asset_age']; ?>,
                'Maintenance Cost (12mo)': '$<?php echo number_format($stats['total_maintenance_cost'], 2); ?>',
                'Overdue Maintenance': <?php echo $stats['overdue_maintenance']; ?>,
                'Warranty Expiring': <?php echo $stats['warranty_expiring']; ?>
            };

            let csv = 'Asset Management Report\n\n';
            csv += 'Key Statistics\n';

            for (const [key, value] of Object.entries(reportData)) {
                csv += '"' + key + '","' + value + '"\n';
            }

            csv += '\n\nCategory Breakdown\n';
            csv += '"Category","Count"\n';
            <?php foreach ($category_breakdown as $cat): ?>
                csv += '"<?php echo addslashes($cat['category']); ?>","<?php echo $cat['count']; ?>"\n';
            <?php endforeach; ?>

            const filename = 'asset_report_' + new Date().toISOString().split('T')[0] + '.csv';
            downloadCSV(csv, filename);
        }

        // Download CSV helper
        function downloadCSV(csv, filename) {
            const csvFile = new Blob([csv], {
                type: 'text/csv'
            });
            const downloadLink = document.createElement('a');
            downloadLink.download = filename;
            downloadLink.href = window.URL.createObjectURL(csvFile);
            downloadLink.style.display = 'none';
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
        }

        // Print styles
        const style = document.createElement('style');
        style.textContent = `
            @media print {
                .sidebar, .export-buttons, .btn { display: none !important; }
                .container { margin-left: 0 !important; padding: 20px !important; }
                .section, .chart-card { page-break-inside: avoid; }
                body { background: white; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>

</html>