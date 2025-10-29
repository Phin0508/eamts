<?php
session_start();

// Check if user is logged in and has admin role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Include database configuration
include("../auth/config/database.php");

$success_message = '';
$error_message = '';

// Handle user restoration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'restore_user' && isset($_POST['user_id'])) {
        try {
            $user_id = $_POST['user_id'];
            
            // Get original email and username
            $check_stmt = $pdo->prepare("SELECT email, username FROM users WHERE user_id = ?");
            $check_stmt->execute([$user_id]);
            $user = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Remove _DELETED_ suffix from email and username
                $original_email = preg_replace('/_DELETED_.*$/', '', $user['email']);
                $original_username = preg_replace('/_DELETED_.*$/', '', $user['username']);
                
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET is_active = 1, 
                        is_deleted = 0,
                        email = ?,
                        username = ?,
                        updated_at = NOW() 
                    WHERE user_id = ?
                ");
                if ($stmt->execute([$original_email, $original_username, $user_id])) {
                    $success_message = "User restored successfully";
                }
            }
        } catch (PDOException $e) {
            $error_message = "Error restoring user: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$department_filter = $_GET['department'] ?? '';
$role_filter = $_GET['role'] ?? '';
$status_filter = $_GET['status'] ?? ''; // all, active, inactive, deleted
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Build query
$where_conditions = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR username LIKE ? OR employee_id LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
}

if (!empty($department_filter)) {
    $where_conditions[] = "department = ?";
    $params[] = $department_filter;
}

if (!empty($role_filter)) {
    $where_conditions[] = "role = ?";
    $params[] = $role_filter;
}

if ($status_filter === 'active') {
    $where_conditions[] = "is_active = 1 AND (is_deleted IS NULL OR is_deleted = 0)";
} elseif ($status_filter === 'inactive') {
    $where_conditions[] = "is_active = 0 AND (is_deleted IS NULL OR is_deleted = 0)";
} elseif ($status_filter === 'deleted') {
    $where_conditions[] = "is_deleted = 1";
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $where_clause");
$count_stmt->execute($params);
$total_users = $count_stmt->fetchColumn();
$total_pages = ceil($total_users / $per_page);

// Get users with pagination
$sql = "SELECT user_id, first_name, last_name, email, username, phone, department, role, 
        employee_id, is_active, is_deleted, is_verified, created_at, updated_at, last_login 
        FROM users 
        WHERE $where_clause 
        ORDER BY 
            CASE WHEN is_deleted = 1 THEN 1 ELSE 0 END,
            CASE WHEN is_active = 0 THEN 1 ELSE 0 END,
            created_at DESC 
        LIMIT $per_page OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get comprehensive statistics
$stats_stmt = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 AND (is_deleted IS NULL OR is_deleted = 0) THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN is_active = 0 AND (is_deleted IS NULL OR is_deleted = 0) THEN 1 ELSE 0 END) as inactive,
        SUM(CASE WHEN is_deleted = 1 THEN 1 ELSE 0 END) as deleted,
        SUM(CASE WHEN role = 'admin' AND (is_deleted IS NULL OR is_deleted = 0) THEN 1 ELSE 0 END) as admins,
        SUM(CASE WHEN role = 'manager' AND (is_deleted IS NULL OR is_deleted = 0) THEN 1 ELSE 0 END) as managers,
        SUM(CASE WHEN role = 'employee' AND (is_deleted IS NULL OR is_deleted = 0) THEN 1 ELSE 0 END) as employees
    FROM users
");
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User History - E-Asset Management System</title>
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
            margin-bottom: 20px;
        }

        .header-title h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-title h1 i {
            color: #7c3aed;
        }

        .header-title p {
            color: #718096;
            font-size: 15px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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

        .stat-number {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .stat-number.total { color: #7c3aed; }
        .stat-number.active { color: #10b981; }
        .stat-number.inactive { color: #f59e0b; }
        .stat-number.deleted { color: #ef4444; }
        .stat-number.admins { color: #3b82f6; }
        .stat-number.managers { color: #f59e0b; }
        .stat-number.employees { color: #8b5cf6; }

        .stat-label {
            color: #718096;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-section {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .filter-header {
            margin-bottom: 20px;
        }

        .filter-header h3 {
            font-size: 18px;
            font-weight: 700;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-header h3 i {
            color: #7c3aed;
        }

        .filter-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 15px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }

        .form-group input,
        .form-group select {
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

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

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }

        .btn-sm {
            padding: 8px 14px;
            font-size: 12px;
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

        .table tbody tr.deleted-row {
            background: #fff5f5;
        }

        .table tbody tr.deleted-row:hover {
            background: #fed7d7;
        }

        .table tbody td {
            padding: 20px 16px;
            font-size: 14px;
            color: #2d3748;
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
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            flex-shrink: 0;
        }

        .user-avatar.deleted {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            opacity: 0.7;
        }

        .user-details h4 {
            font-size: 15px;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 2px;
        }

        .user-details p {
            font-size: 13px;
            color: #718096;
        }

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
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }

        .badge-deleted {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
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

        .badge-verified {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }

        .badge-unverified {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #6b7280;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 30px;
        }

        .pagination a,
        .pagination span {
            padding: 10px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            text-decoration: none;
            color: #374151;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .pagination a:hover {
            background: #f9fafb;
            border-color: #7c3aed;
            color: #7c3aed;
        }

        .pagination .active {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            border-color: #7c3aed;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state-icon {
            font-size: 64px;
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

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 10000;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            border-radius: 16px;
            padding: 32px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translate(-50%, -45%);
            }
            to {
                opacity: 1;
                transform: translate(-50%, -50%);
            }
        }

        .modal-header {
            margin-bottom: 20px;
        }

        .modal-header h3 {
            font-size: 22px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header h3 i {
            color: #10b981;
        }

        .modal-header p {
            color: #718096;
            font-size: 15px;
        }

        .modal-info {
            color: #10b981;
            font-size: 14px;
            margin-top: 15px;
            padding: 12px;
            background: #d1fae5;
            border-radius: 8px;
            border-left: 4px solid #10b981;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }

        .deleted-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: #fee2e2;
            border-radius: 6px;
            font-size: 12px;
            color: #991b1b;
            font-weight: 600;
        }

        @media (max-width: 1024px) {
            .container {
                margin-left: 80px;
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

            .header-actions {
                width: 100%;
                flex-direction: column;
            }

            .header-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filter-section {
                padding: 20px;
            }

            .filter-row {
                grid-template-columns: 1fr;
            }

            .section {
                padding: 20px;
            }

            .table {
                min-width: 1200px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-buttons .btn {
                width: 100%;
                justify-content: center;
            }

            .pagination {
                flex-wrap: wrap;
            }

            .modal-content {
                padding: 24px;
                width: 95%;
            }

            .modal-actions {
                flex-direction: column;
            }

            .modal-actions .btn {
                width: 100%;
            }
        }
    </style>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="container" id="mainContainer">
        <div class="header">
            <div class="header-top">
                <div class="header-title">
                    <h1><i class="fas fa-history"></i> User History</h1>
                    <p>View all users including deleted accounts</p>
                </div>
                <div class="header-actions">
                    <a href="userManagement.php" class="btn btn-primary">
                        <i class="fas fa-users"></i> Active Users
                    </a>
                    <a href="userList.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
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
            <div class="stat-card">
                <div class="stat-number total"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-number active"><?php echo $stats['active']; ?></div>
                <div class="stat-label">Active Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-number inactive"><?php echo $stats['inactive']; ?></div>
                <div class="stat-label">Inactive Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-number deleted"><?php echo $stats['deleted']; ?></div>
                <div class="stat-label">Deleted Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-number admins"><?php echo $stats['admins']; ?></div>
                <div class="stat-label">Administrators</div>
            </div>
            <div class="stat-card">
                <div class="stat-number managers"><?php echo $stats['managers']; ?></div>
                <div class="stat-label">Managers</div>
            </div>
            <div class="stat-card">
                <div class="stat-number employees"><?php echo $stats['employees']; ?></div>
                <div class="stat-label">Employees</div>
            </div>
        </div>

        <div class="filter-section">
            <div class="filter-header">
                <h3><i class="fas fa-filter"></i> Filter Users</h3>
            </div>
            <form method="GET" action="">
                <div class="filter-row">
                    <div class="form-group">
                        <label><i class="fas fa-search"></i> Search</label>
                        <input type="text" name="search" placeholder="Search by name, email, username..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-building"></i> Department</label>
                        <select name="department">
                            <option value="">All Departments</option>
                            <option value="IT" <?php echo $department_filter === 'IT' ? 'selected' : ''; ?>>IT</option>
                            <option value="HR" <?php echo $department_filter === 'HR' ? 'selected' : ''; ?>>HR</option>
                            <option value="Finance" <?php echo $department_filter === 'Finance' ? 'selected' : ''; ?>>Finance</option>
                            <option value="Operations" <?php echo $department_filter === 'Operations' ? 'selected' : ''; ?>>Operations</option>
                            <option value="Sales" <?php echo $department_filter === 'Sales' ? 'selected' : ''; ?>>Sales</option>
                            <option value="Marketing" <?php echo $department_filter === 'Marketing' ? 'selected' : ''; ?>>Marketing</option>
                            <option value="Engineering" <?php echo $department_filter === 'Engineering' ? 'selected' : ''; ?>>Engineering</option>
                            <option value="Support" <?php echo $department_filter === 'Support' ? 'selected' : ''; ?>>Support</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-user-tag"></i> Role</label>
                        <select name="role">
                            <option value="">All Roles</option>
                            <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="manager" <?php echo $role_filter === 'manager' ? 'selected' : ''; ?>>Manager</option>
                            <option value="employee" <?php echo $role_filter === 'employee' ? 'selected' : ''; ?>>Employee</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-toggle-on"></i> Status</label>
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="deleted" <?php echo $status_filter === 'deleted' ? 'selected' : ''; ?>>Deleted</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-table"></i> All Users (<?php echo $total_users; ?>)</h2>
            </div>

            <?php if (count($users) > 0): ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Username</th>
                            <th>Department</th>
                            <th>Role</th>
                            <th>Employee ID</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Verified</th>
                            <th>Created</th>
                            <th>Updated</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr class="<?php echo $user['is_deleted'] ? 'deleted-row' : ''; ?>">
                            <td>
                                <div class="user-info">
                                    <div class="user-avatar <?php echo $user['is_deleted'] ? 'deleted' : ''; ?>">
                                        <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                    </div>
                                    <div class="user-details">
                                        <h4>
                                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                            <?php if ($user['is_deleted']): ?>
                                            <span class="deleted-indicator">
                                                <i class="fas fa-trash"></i> DELETED
                                            </span>
                                            <?php endif; ?>
                                        </h4>
                                        <p><?php echo htmlspecialchars($user['email']); ?></p>
                                    </div>
                                </div>
                            </td>
                            <td>@<?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['department']); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $user['role']; ?>">
                                    <?php echo ucfirst($user['role']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($user['employee_id'] ?? '-'); ?></td>
                            <td>
                                <?php if ($user['phone']): ?>
                                    <i class="fas fa-phone" style="font-size: 11px; color: #718096;"></i>
                                    <?php echo htmlspecialchars($user['phone']); ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['is_deleted']): ?>
                                    <span class="badge badge-deleted">Deleted</span>
                                <?php else: ?>
                                    <span class="badge badge-<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $user['is_verified'] ? 'verified' : 'unverified'; ?>">
                                    <?php echo $user['is_verified'] ? 'Yes' : 'No'; ?>
                                </span>
                            </td>
                            <td>
                                <div style="font-size: 13px;">
                                    <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                    <div style="color: #718096; font-size: 12px;">
                                        <?php echo date('h:i A', strtotime($user['created_at'])); ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div style="font-size: 13px;">
                                    <?php echo date('M d, Y', strtotime($user['updated_at'])); ?>
                                    <div style="color: #718096; font-size: 12px;">
                                        <?php echo date('h:i A', strtotime($user['updated_at'])); ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ($user['last_login']): ?>
                                    <div style="font-size: 13px;">
                                        <?php echo date('M d, Y', strtotime($user['last_login'])); ?>
                                        <div style="color: #718096; font-size: 12px;">
                                            <?php echo date('h:i A', strtotime($user['last_login'])); ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">Never</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <?php if ($user['is_deleted']): ?>
                                    <button type="button" class="btn btn-sm btn-success" 
                                            onclick="confirmRestore(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>')">
                                        <i class="fas fa-undo"></i> Restore
                                    </button>
                                    <?php else: ?>
                                    <a href="userManagement.php" class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">📋</div>
                <h3>No users found</h3>
                <p>Try adjusting your filters or search terms</p>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department_filter); ?>&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>">
                <i class="fas fa-chevron-left"></i> Previous
            </a>
            <?php endif; ?>

            <?php 
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            
            if ($start_page > 1): ?>
                <a href="?page=1&search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department_filter); ?>&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>">1</a>
                <?php if ($start_page > 2): ?>
                    <span>...</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                <?php if ($i == $page): ?>
                <span class="active"><?php echo $i; ?></span>
                <?php else: ?>
                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department_filter); ?>&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>">
                    <?php echo $i; ?>
                </a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($end_page < $total_pages): ?>
                <?php if ($end_page < $total_pages - 1): ?>
                    <span>...</span>
                <?php endif; ?>
                <a href="?page=<?php echo $total_pages; ?>&search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department_filter); ?>&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>"><?php echo $total_pages; ?></a>
            <?php endif; ?>

            <?php if ($page < $total_pages): ?>
            <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department_filter); ?>&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>">
                Next <i class="fas fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Restore Confirmation Modal -->
    <div id="restoreModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-undo"></i> Confirm Restore</h3>
                <p>Are you sure you want to restore <strong id="restoreUserName"></strong>?</p>
            </div>
            <div class="modal-info">
                <i class="fas fa-info-circle"></i> This will reactivate the user account and restore their access to the system.
            </div>
            <form method="POST" id="restoreForm">
                <input type="hidden" name="action" value="restore_user">
                <input type="hidden" name="user_id" id="restoreUserId">
                <div class="modal-actions">
                    <button type="button" class="btn btn-primary" onclick="closeRestoreModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-undo"></i> Restore User
                    </button>
                </div>
            </form>
        </div>
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

        function confirmRestore(userId, userName) {
            document.getElementById('restoreUserId').value = userId;
            document.getElementById('restoreUserName').textContent = userName;
            document.getElementById('restoreModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeRestoreModal() {
            document.getElementById('restoreModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        window.onclick = function(event) {
            const modal = document.getElementById('restoreModal');
            if (event.target === modal) {
                closeRestoreModal();
            }
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeRestoreModal();
            }
        });

        setTimeout(() => {
            const successMsg = document.querySelector('.success-message');
            const errorMsg = document.querySelector('.error-message');
            if (successMsg) {
                successMsg.style.transition = 'opacity 0.5s';
                successMsg.style.opacity = '0';
                setTimeout(() => successMsg.style.display = 'none', 500);
            }
            if (errorMsg) {
                errorMsg.style.transition = 'opacity 0.5s';
                errorMsg.style.opacity = '0';
                setTimeout(() => errorMsg.style.display = 'none', 500);
            }
        }, 5000);
    </script>
</body>
</html>