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
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }

        .page-header h1 {
            margin: 0 0 0.5rem 0;
            font-size: 2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-header p {
            margin: 0;
            opacity: 0.95;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .stat-card .stat-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .stat-card .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 0.25rem;
        }

        .stat-card .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .stat-card .stat-value {
            color: #059669;
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }

        .filters-section {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .filters-form {
            display: flex;
            gap: 1rem;
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
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }

        .search-box input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 0.95rem;
        }

        .filters-form select {
            padding: 0.75rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 0.95rem;
            background: white;
            cursor: pointer;
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

        .btn-primary {
            background: #2563eb;
            color: white;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .btn-outline {
            background: white;
            color: #374151;
            border: 1px solid #d1d5db;
        }

        .btn-outline:hover {
            background: #f9fafb;
        }

        .assets-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .asset-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
            position: relative;
        }

        .asset-card:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
            transform: translateY(-2px);
        }

        .asset-card-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f0f0f0;
        }

        .asset-title {
            flex: 1;
        }

        .asset-title h3 {
            margin: 0 0 0.5rem 0;
            color: #2c3e50;
            font-size: 1.1rem;
        }

        .asset-code {
            background: #667eea;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-available { background: #d1fae5; color: #065f46; }
        .status-in-use { background: #dbeafe; color: #1e40af; }
        .status-maintenance { background: #fed7aa; color: #92400e; }
        .status-retired { background: #e5e7eb; color: #374151; }
        .status-damaged { background: #fecaca; color: #991b1b; }

        .asset-details {
            display: grid;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .asset-detail {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: #4b5563;
        }

        .asset-detail i {
            width: 20px;
            color: #6b7280;
        }

        .asset-detail strong {
            color: #1f2937;
        }

        .asset-actions {
            display: flex;
            gap: 0.5rem;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            border-radius: 6px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            flex: 1;
            justify-content: center;
        }

        .btn-view {
            background: #dbeafe;
            color: #1e40af;
        }

        .btn-view:hover {
            background: #bfdbfe;
        }

        .no-assets {
            text-align: center;
            padding: 4rem 2rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .no-assets i {
            font-size: 4rem;
            color: #d1d5db;
            margin-bottom: 1rem;
        }

        .no-assets h3 {
            color: #374151;
            margin-bottom: 0.5rem;
        }

        .no-assets p {
            color: #6b7280;
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
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

        .maintenance-info {
            display: flex;
            gap: 1rem;
            padding: 0.75rem;
            background: #f9fafb;
            border-radius: 6px;
            margin-top: 0.75rem;
            font-size: 0.8rem;
        }

        .maintenance-stat {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            color: #4b5563;
        }

        .info-banner {
            background: #eff6ff;
            border-left: 4px solid #2563eb;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .info-banner i {
            color: #2563eb;
            font-size: 1.25rem;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .assets-grid {
                grid-template-columns: 1fr;
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

            .page-header {
                background: white !important;
                color: black !important;
                border: 1px solid #ddd;
            }

            .asset-card {
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
        <div class="dashboard-content">
            <div class="page-header">
                <div>
                    <h1><i class="fas fa-user-circle"></i> My Assets</h1>
                    <p>Assets assigned to <?php echo htmlspecialchars($user_name); ?> (<?php echo htmlspecialchars($department); ?> Department)</p>
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
            <div class="stats-grid">
                <div class="stat-card" onclick="window.location.href='?status=all'">
                    <div class="stat-icon">📦</div>
                    <div class="stat-number"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">My Total Assets</div>
                    <div class="stat-value">$<?php echo number_format($total_value, 2); ?></div>
                </div>

                <div class="stat-card" onclick="window.location.href='?status=Available'">
                    <div class="stat-icon">✅</div>
                    <div class="stat-number"><?php echo $stats['available']; ?></div>
                    <div class="stat-label">Available</div>
                </div>

                <div class="stat-card" onclick="window.location.href='?status=In Use'">
                    <div class="stat-icon">💼</div>
                    <div class="stat-number"><?php echo $stats['in_use']; ?></div>
                    <div class="stat-label">In Use</div>
                </div>

                <div class="stat-card" onclick="window.location.href='?status=Maintenance'">
                    <div class="stat-icon">🔧</div>
                    <div class="stat-number"><?php echo $stats['maintenance']; ?></div>
                    <div class="stat-label">Maintenance</div>
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

            <!-- Assets Grid -->
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

                                <?php if ($asset['department']): ?>
                                    <div class="asset-detail">
                                        <i class="fas fa-building"></i>
                                        <span><strong>Department:</strong> <?php echo htmlspecialchars($asset['department']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

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
                                <a href="assetDetails.php?id=<?php echo $asset['id']; ?>" class="btn-sm btn-view">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-assets">
                    <i class="fas fa-inbox"></i>
                    <h3>No Assets Assigned</h3>
                    <p>You currently don't have any assets assigned to you<?php echo !empty($search) || $filter_status !== 'all' || $filter_category !== 'all' ? ' that match your filters' : ''; ?>.</p>
                    <?php if (!empty($search) || $filter_status !== 'all' || $filter_category !== 'all'): ?>
                        <a href="managerAsset.php" class="btn btn-primary" style="margin-top: 1rem;">
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
                    select.style.borderColor = '#2563eb';
                    select.style.backgroundColor = '#eff6ff';
                }
            });

            const searchValue = urlParams.get('search');
            if (searchValue && searchInput) {
                searchInput.style.borderColor = '#2563eb';
                searchInput.style.backgroundColor = '#eff6ff';
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

        document.querySelectorAll('.asset-card').forEach(card => {
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
    </script>
</body>
</html>