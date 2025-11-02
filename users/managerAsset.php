<?php
// Start session
session_start();

// Check if user is logged in and is a manager
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
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

// Build query - ONLY for assets assigned to THIS manager
$where_clauses = ["a.assigned_to = ?"];
$params = [$user_id];

if ($filter_status !== 'all') {
    $where_clauses[] = "a.status = ?";
    $params[] = $filter_status;
}

if ($filter_category !== 'all') {
    $where_clauses[] = "a.category = ?";
    $params[] = $filter_category;
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

// Fetch manager's personal assets
$assets = [];
$total_value = 0;
try {
    $query = "SELECT a.*, 
              CONCAT(creator.first_name, ' ', creator.last_name) as created_by_name,
              (SELECT COUNT(*) FROM asset_maintenance am WHERE am.asset_id = a.id) as maintenance_count,
              (SELECT MAX(am.maintenance_date) FROM asset_maintenance am WHERE am.asset_id = a.id) as last_maintenance_date
              FROM assets a 
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

// Get statistics for manager's personal assets
$stats = [
    'total' => 0,
    'available' => 0,
    'in_use' => 0,
    'maintenance' => 0,
    'retired' => 0,
    'damaged' => 0
];

try {
    $stats_query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'Available' THEN 1 ELSE 0 END) as available,
                    SUM(CASE WHEN status = 'In Use' THEN 1 ELSE 0 END) as in_use,
                    SUM(CASE WHEN status = 'Maintenance' THEN 1 ELSE 0 END) as maintenance,
                    SUM(CASE WHEN status = 'Retired' THEN 1 ELSE 0 END) as retired,
                    SUM(CASE WHEN status = 'Damaged' THEN 1 ELSE 0 END) as damaged
                    FROM assets WHERE assigned_to = ?";
    $stmt = $pdo->prepare($stats_query);
    $stmt->execute([$user_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Stats error: " . $e->getMessage());
}

// Get categories for filter
$categories = [];
try {
    $cat_query = "SELECT DISTINCT category FROM assets WHERE assigned_to = ? AND category IS NOT NULL ORDER BY category";
    $stmt = $pdo->prepare($cat_query);
    $stmt->execute([$user_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Categories error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assets - E-Asset Management System</title>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link rel="stylesheet" href="../style/asset.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* Clean Stats Container */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        /* Clean White Stat Cards */
        .stat-card {
            background: white;
            padding: 28px;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.3s;
            border-left: 4px solid #667eea;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.15);
        }

        .stat-card h3 {
            font-size: 14px;
            margin: 0 0 12px 0;
            color: #718096;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .stat-number {
            font-size: 36px;
            font-weight: 700;
            margin: 0;
            color: #1a202c;
            margin-bottom: 8px;
        }

        .stat-card .stat-label {
            font-size: 13px;
            color: #a0aec0;
            margin-top: 8px;
            font-weight: 500;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            color: #065f46;
        }

        .alert-error {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            color: #991b1b;
        }

        .inventory-header {
            margin-bottom: 30px;
        }

        .inventory-header h1 {
            font-size: 32px;
            margin: 0 0 10px 0;
            color: #1a202c;
            font-weight: 700;
        }

        .info-banner {
            background: #eff6ff;
            border-left: 4px solid #2563eb;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            color: #1e40af;
        }

        .info-banner i {
            font-size: 18px;
        }

        /* Filters Section */
        .filters-section {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .filters-form {
            display: flex;
            gap: 15px;
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
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #718096;
        }

        .search-box input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .search-box input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .filters-form select {
            padding: 12px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
        }

        .filters-form select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
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
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .btn-outline {
            background: white;
            color: #4a5568;
            border: 1px solid #e2e8f0;
        }

        .btn-outline:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
        }

        /* Asset Cards */
        .asset-details-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.3s;
        }

        .asset-details-card:hover {
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
        }

        .asset-details-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f7fafc;
        }

        .asset-details-title {
            flex: 1;
        }

        .asset-details-title h3 {
            margin: 0 0 8px 0;
            color: #1a202c;
            font-size: 20px;
            font-weight: 600;
        }

        .asset-code-badge {
            background: #667eea;
            color: white;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-available { background: #d1fae5; color: #065f46; }
        .status-in-use { background: #dbeafe; color: #1e40af; }
        .status-maintenance { background: #fed7aa; color: #92400e; }
        .status-retired { background: #e5e7eb; color: #374151; }
        .status-damaged { background: #fecaca; color: #991b1b; }

        .asset-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .detail-item {
            padding: 12px;
            background: #f7fafc;
            border-radius: 8px;
        }

        .detail-label {
            font-size: 11px;
            color: #718096;
            margin-bottom: 6px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-size: 14px;
            color: #1a202c;
            font-weight: 500;
        }

        .asset-maintenance-info {
            display: flex;
            gap: 20px;
            align-items: center;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #f0f0f0;
        }

        .maintenance-stat {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #718096;
        }

        .maintenance-stat .icon {
            font-size: 16px;
        }

        .asset-actions {
            display: flex;
            gap: 10px;
            padding-top: 20px;
            border-top: 1px solid #f0f0f0;
        }

        .btn-sm {
            padding: 10px 20px;
            font-size: 13px;
            border-radius: 8px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            justify-content: center;
            font-weight: 600;
        }

        .btn-view {
            background: #dbeafe;
            color: #1e40af;
        }

        .btn-view:hover {
            background: #bfdbfe;
            transform: translateY(-1px);
        }

        .no-assets-message {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .no-assets-message .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .no-assets-message h2 {
            color: #1a202c;
            margin-bottom: 10px;
            font-size: 24px;
        }

        .no-assets-message p {
            color: #718096;
            font-size: 16px;
            line-height: 1.6;
        }

        @media (max-width: 768px) {
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }

            .filters-form {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box {
                width: 100%;
            }

            .filters-form select {
                width: 100%;
            }

            .asset-details-header {
                flex-direction: column;
                gap: 15px;
            }

            .asset-details-grid {
                grid-template-columns: 1fr;
            }
        }

        @media print {
            .main-content {
                margin: 0;
                padding: 20px;
            }
            
            .filters-section,
            .asset-actions,
            nav,
            .btn {
                display: none !important;
            }

            .asset-details-card {
                break-inside: avoid;
                page-break-inside: avoid;
                margin-bottom: 20px;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/Msidebar.php"); ?>

    <main class="main-content">
        <div class="inventory-container">
            <div class="inventory-header">
                <h1>💼 My Assets</h1>
                <div style="color: #718096; font-size: 14px;">
                    <strong><?php echo htmlspecialchars($user_name); ?></strong> - <?php echo htmlspecialchars($department); ?> Department
                </div>
            </div>

            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <div class="info-banner">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Personal Assets View:</strong> This page displays assets that are currently assigned to you. 
                    For department-wide asset management, please visit the Department Assets page.
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card" onclick="window.location.href='?status=all'">
                    <h3>Total Assets</h3>
                    <p class="stat-number"><?php echo $stats['total']; ?></p>
                    <p class="stat-label">$<?php echo number_format($total_value, 2); ?></p>
                </div>

                <div class="stat-card" onclick="window.location.href='?status=Available'">
                    <h3>Available</h3>
                    <p class="stat-number"><?php echo $stats['available']; ?></p>
                </div>

                <div class="stat-card" onclick="window.location.href='?status=In Use'">
                    <h3>In Use</h3>
                    <p class="stat-number"><?php echo $stats['in_use']; ?></p>
                </div>

                <div class="stat-card" onclick="window.location.href='?status=Maintenance'">
                    <h3>Maintenance</h3>
                    <p class="stat-number"><?php echo $stats['maintenance']; ?></p>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" action="" class="filters-form">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search my assets..." value="<?php echo htmlspecialchars($search); ?>">
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

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    
                    <a href="managerAsset.php" class="btn btn-outline">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </form>
            </div>

            <!-- Assets List -->
            <?php if (count($assets) > 0): ?>
                <?php foreach ($assets as $asset): ?>
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

                        <?php if ($asset['maintenance_count'] > 0 || $asset['last_maintenance_date']): ?>
                            <div class="asset-maintenance-info">
                                <?php if ($asset['maintenance_count'] > 0): ?>
                                    <div class="maintenance-stat">
                                        <span class="icon">🔧</span>
                                        <span><?php echo $asset['maintenance_count']; ?> maintenance record(s)</span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($asset['last_maintenance_date']): ?>
                                    <div class="maintenance-stat">
                                        <span class="icon">📅</span>
                                        <span>Last: <?php echo date('M d, Y', strtotime($asset['last_maintenance_date'])); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

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

                            <?php if ($asset['description']): ?>
                                <div class="detail-item" style="grid-column: 1 / -1;">
                                    <div class="detail-label">Description</div>
                                    <div class="detail-value"><?php echo nl2br(htmlspecialchars($asset['description'])); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="asset-actions">
                            <a href="assetDetails.php?id=<?php echo $asset['id']; ?>" class="btn-sm btn-view">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-assets-message">
                    <div class="icon">📦</div>
                    <h2>No Assets Assigned</h2>
                    <p>You currently don't have any assets assigned to you<?php echo !empty($search) || $filter_status !== 'all' || $filter_category !== 'all' ? ' that match your filters' : ''; ?>.</p>
                    <?php if (!empty($search) || $filter_status !== 'all' || $filter_category !== 'all'): ?>
                        <a href="managerAsset.php" class="btn btn-primary" style="margin-top: 20px;">
                            <i class="fas fa-redo"></i> Reset Filters
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Add keyboard shortcut for search (Ctrl+K or Cmd+K)
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                document.querySelector('.search-box input').focus();
            }
        });

        // Real-time search with debounce
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

        // Add loading state to filter buttons
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

        // Highlight selected filters
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            
            document.querySelectorAll('select').forEach(select => {
                const paramValue = urlParams.get(select.name);
                if (paramValue && paramValue !== 'all') {
                    select.style.borderColor = '#667eea';
                    select.style.backgroundColor = '#f7fafc';
                }
            });

            const searchValue = urlParams.get('search');
            if (searchValue && searchInput) {
                searchInput.style.borderColor = '#667eea';
                searchInput.style.backgroundColor = '#f7fafc';
            }
        });

        // Smooth scroll to top when clicking stat cards
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('click', function() {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });

        // Add animation to cards on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        document.querySelectorAll('.asset-details-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            observer.observe(card);
        });

        // Print functionality
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });

        // Quick stats summary in console
        console.log('%cMy Assets Summary:', 'font-weight: bold; font-size: 16px; color: #667eea;');
        console.log('Total Assets: <?php echo $stats['total']; ?>');
        console.log('Total Value: $<?php echo number_format($total_value, 2); ?>');
        console.log('Available: <?php echo $stats['available']; ?>');
        console.log('In Use: <?php echo $stats['in_use']; ?>');
        console.log('Maintenance: <?php echo $stats['maintenance']; ?>'); 