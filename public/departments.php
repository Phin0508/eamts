<?php
session_start();

// Check if user is logged in and has admin/manager role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager'])) {
    header("Location: login.php");
    exit();
}

// Include database configuration
include("../auth/config/database.php");

$success_message = '';
$error_message = '';

// Handle department operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            
            // Add new department
            if ($_POST['action'] === 'add_department' && $_SESSION['role'] === 'admin') {
                $dept_name = trim($_POST['dept_name']);
                $dept_code = trim($_POST['dept_code']);
                $description = trim($_POST['description']);
                $manager_id = !empty($_POST['manager_id']) ? $_POST['manager_id'] : null;
                $location = trim($_POST['location']);
                $budget = !empty($_POST['budget']) ? $_POST['budget'] : null;
                
                if (empty($dept_name) || empty($dept_code)) {
                    $error_message = "Department name and code are required";
                } else {
                    // Check if department already exists
                    $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE dept_name = ? OR dept_code = ?");
                    $check_stmt->execute([$dept_name, $dept_code]);
                    
                    if ($check_stmt->fetchColumn() > 0) {
                        $error_message = "Department name or code already exists";
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO departments (dept_name, dept_code, description, manager_id, location, budget, is_active, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
                        ");
                        
                        if ($stmt->execute([$dept_name, $dept_code, $description, $manager_id, $location, $budget])) {
                            $success_message = "Department added successfully";
                        } else {
                            $error_message = "Failed to add department";
                        }
                    }
                }
            }
            
            // Update department
            if ($_POST['action'] === 'update_department' && $_SESSION['role'] === 'admin') {
                $dept_id = $_POST['dept_id'];
                $dept_name = trim($_POST['dept_name']);
                $dept_code = trim($_POST['dept_code']);
                $description = trim($_POST['description']);
                $manager_id = !empty($_POST['manager_id']) ? $_POST['manager_id'] : null;
                $location = trim($_POST['location']);
                $budget = !empty($_POST['budget']) ? $_POST['budget'] : null;
                
                $stmt = $pdo->prepare("
                    UPDATE departments 
                    SET dept_name = ?, dept_code = ?, description = ?, manager_id = ?, location = ?, budget = ?, updated_at = NOW()
                    WHERE dept_id = ?
                ");
                
                if ($stmt->execute([$dept_name, $dept_code, $description, $manager_id, $location, $budget, $dept_id])) {
                    $success_message = "Department updated successfully";
                } else {
                    $error_message = "Failed to update department";
                }
            }
            
            // Toggle department status
            if ($_POST['action'] === 'toggle_status' && $_SESSION['role'] === 'admin') {
                $dept_id = $_POST['dept_id'];
                $new_status = $_POST['new_status'];
                
                $stmt = $pdo->prepare("UPDATE departments SET is_active = ?, updated_at = NOW() WHERE dept_id = ?");
                if ($stmt->execute([$new_status, $dept_id])) {
                    $success_message = "Department status updated successfully";
                }
            }
            
            // Delete department
            if ($_POST['action'] === 'delete_department' && $_SESSION['role'] === 'admin') {
                $dept_id = $_POST['dept_id'];
                
                // Check if department has users
                $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE department = (SELECT dept_name FROM departments WHERE dept_id = ?)");
                $check_stmt->execute([$dept_id]);
                
                if ($check_stmt->fetchColumn() > 0) {
                    $error_message = "Cannot delete department with existing users. Please reassign users first.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM departments WHERE dept_id = ?");
                    if ($stmt->execute([$dept_id])) {
                        $success_message = "Department deleted successfully";
                    }
                }
            }
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Get all departments with statistics
$departments_query = "
    SELECT 
        d.*,
        CONCAT(u.first_name, ' ', u.last_name) as manager_name,
        u.email as manager_email,
        COUNT(DISTINCT users.user_id) as employee_count
    FROM departments d
    LEFT JOIN users u ON d.manager_id = u.user_id
    LEFT JOIN users ON users.department = d.dept_name
    GROUP BY d.dept_id
    ORDER BY d.dept_name ASC
";

$departments = $pdo->query($departments_query)->fetchAll(PDO::FETCH_ASSOC);

// Get managers list for dropdown
$managers = $pdo->query("
    SELECT user_id, first_name, last_name, email 
    FROM users 
    WHERE role IN ('admin', 'manager') AND is_active = 1
    ORDER BY first_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_departments,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_departments,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_departments,
        SUM(budget) as total_budget
    FROM departments
";
$stats = $pdo->query($stats_query)->fetch(PDO::FETCH_ASSOC);

// Get total employees across all departments
$total_employees = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Management - E-Asset Management System</title>
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
            margin-bottom: 20px;
        }

        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
        }

        .header-actions .user-welcome {
            color: #718096;
            font-size: 14px;
        }

        .header-actions .user-welcome strong {
            color: #1a202c;
            font-weight: 600;
        }

        .header-buttons {
            display: flex;
            gap: 10px;
        }

        /* Messages */
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(124, 58, 237, 0.15);
        }

        .stat-card .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 16px;
            background: linear-gradient(135deg, #f7f4fe 0%, #ede9fe 100%);
        }

        .stat-card .stat-number {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 8px;
            color: #1a202c;
        }

        .stat-card .stat-number.total { color: #7c3aed; }
        .stat-card .stat-number.active { color: #10b981; }
        .stat-card .stat-number.employees { color: #3b82f6; }
        .stat-card .stat-number.budget { color: #f59e0b; }

        .stat-card .stat-label {
            color: #718096;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Buttons */
        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
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

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
        }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
        }

        .btn-info {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }

        .btn-cancel {
            background: white;
            color: #718096;
            border: 2px solid #e2e8f0;
        }

        .btn-cancel:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
        }

        .btn-sm {
            padding: 8px 14px;
            font-size: 12px;
        }

        /* Section */
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
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-header p {
            color: #718096;
            font-size: 14px;
        }

        /* Departments Grid */
        .departments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
        }

        .department-card {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 25px;
            transition: all 0.3s;
            position: relative;
        }

        .department-card:hover {
            border-color: #7c3aed;
            box-shadow: 0 8px 20px rgba(124, 58, 237, 0.15);
            transform: translateY(-2px);
        }

        .department-card.inactive {
            opacity: 0.6;
            background: #f9fafb;
        }

        .dept-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 16px;
        }

        .dept-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .dept-title h3 {
            font-size: 20px;
            color: #1a202c;
            margin-bottom: 8px;
            font-weight: 700;
        }

        .dept-code {
            font-size: 12px;
            color: #6b7280;
            font-weight: 600;
            background: #f3f4f6;
            padding: 4px 10px;
            border-radius: 6px;
            display: inline-block;
        }

        .dept-description {
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
            margin: 15px 0;
            min-height: 40px;
        }

        .dept-stats {
            display: flex;
            justify-content: space-around;
            padding: 15px 0;
            border-top: 1px solid #e5e7eb;
            border-bottom: 1px solid #e5e7eb;
            margin: 15px 0;
        }

        .dept-stat {
            text-align: center;
        }

        .dept-stat-number {
            font-size: 24px;
            font-weight: 700;
            color: #7c3aed;
        }

        .dept-stat-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .dept-info {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin: 20px 0;
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
        }

        .dept-info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }

        .dept-info-item i {
            color: #7c3aed;
            width: 20px;
            text-align: center;
        }

        .dept-info-item strong {
            color: #374151;
            min-width: 80px;
            font-weight: 600;
        }

        .dept-info-item span {
            color: #6b7280;
        }

        .dept-actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
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

        /* Modal */
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
            align-items: center;
            justify-content: center;
            overflow-y: auto;
            animation: fadeIn 0.3s ease;
        }

        .modal.active {
            display: flex;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 32px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            margin: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            margin-bottom: 24px;
        }

        .modal-header h3 {
            font-size: 24px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header h3 i {
            color: #7c3aed;
        }

        .modal-header p {
            color: #718096;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #2d3748;
            font-weight: 600;
            font-size: 14px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .modal-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 25px;
        }

        .delete-warning {
            background: linear-gradient(135deg, #ffe6e6 0%, #ffd4d4 100%);
            color: #721c24;
            padding: 12px 16px;
            border-radius: 8px;
            margin: 16px 0;
            font-size: 14px;
            border-left: 4px solid #dc3545;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
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

            .header h1 {
                font-size: 22px;
            }

            .header-actions {
                flex-direction: column;
                gap: 10px;
                align-items: stretch;
            }

            .header-buttons {
                flex-direction: column;
                width: 100%;
            }

            .header-buttons .btn {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .departments-grid {
                grid-template-columns: 1fr;
            }

            .dept-actions {
                flex-direction: column;
            }

            .dept-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .dept-actions form {
                width: 100%;
            }

            .modal-content {
                padding: 24px;
                width: 95%;
            }

            .modal-buttons {
                flex-direction: column;
            }

            .modal-buttons .btn {
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
            <h1><i class="fas fa-building"></i> Department Management</h1>
            <p>Manage organizational departments and structure</p>
            <div class="header-actions">
                <div class="user-welcome">
                    Welcome, <strong><?php echo htmlspecialchars($_SESSION['first_name']); ?></strong>
                </div>
                <div class="header-buttons">
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                    <button onclick="openAddModal()" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add New Department
                    </button>
                    <?php endif; ?>
                    <a href="dashboard.php" class="btn btn-cancel">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
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

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">🏢</div>
                <div class="stat-number total"><?php echo $stats['total_departments']; ?></div>
                <div class="stat-label">Total Departments</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">✓</div>
                <div class="stat-number active"><?php echo $stats['active_departments']; ?></div>
                <div class="stat-label">Active Departments</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-number employees"><?php echo $total_employees; ?></div>
                <div class="stat-label">Total Employees</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">💰</div>
                <div class="stat-number budget">$<?php echo number_format($stats['total_budget'] ?? 0, 0); ?></div>
                <div class="stat-label">Combined Budget</div>
            </div>
        </div>

        <!-- Departments Section -->
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-th-large"></i> All Departments</h2>
                <p>View and manage all organizational departments</p>
            </div>

            <?php if (count($departments) > 0): ?>
            <div class="departments-grid">
                <?php foreach ($departments as $dept): ?>
                <div class="department-card <?php echo $dept['is_active'] ? '' : 'inactive'; ?>">
                    <div class="dept-icon">🏢</div>
                    
                    <div class="dept-header">
                        <div class="dept-title">
                            <h3><?php echo htmlspecialchars($dept['dept_name']); ?></h3>
                            <span class="dept-code"><?php echo htmlspecialchars($dept['dept_code']); ?></span>
                        </div>
                        <span class="badge badge-<?php echo $dept['is_active'] ? 'active' : 'inactive'; ?>">
                            <?php echo $dept['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>

                    <div class="dept-description">
                        <?php echo htmlspecialchars($dept['description'] ?: 'No description available'); ?>
                    </div>

                    <div class="dept-stats">
                        <div class="dept-stat">
                            <div class="dept-stat-number"><?php echo $dept['employee_count']; ?></div>
                            <div class="dept-stat-label">Employees</div>
                        </div>
                        <div class="dept-stat">
                            <div class="dept-stat-number">$<?php echo number_format($dept['budget'] ?? 0, 0); ?></div>
                            <div class="dept-stat-label">Budget</div>
                        </div>
                    </div>

                    <div class="dept-info">
                        <div class="dept-info-item">
                            <i class="fas fa-user-tie"></i>
                            <strong>Manager:</strong>
                            <span><?php echo $dept['manager_name'] ? htmlspecialchars($dept['manager_name']) : 'Not assigned'; ?></span>
                        </div>
                        <div class="dept-info-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <strong>Location:</strong>
                            <span><?php echo htmlspecialchars($dept['location'] ?: 'Not specified'); ?></span>
                        </div>
                        <div class="dept-info-item">
                            <i class="fas fa-calendar-alt"></i>
                            <strong>Created:</strong>
                            <span><?php echo date('M d, Y', strtotime($dept['created_at'])); ?></span>
                        </div>
                    </div>

                    <?php if ($_SESSION['role'] === 'admin'): ?>
                    <div class="dept-actions">
                        <button onclick='openEditModal(<?php echo json_encode($dept); ?>)' class="btn btn-sm btn-info">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <form method="POST" style="display: inline; width: 100%;">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="dept_id" value="<?php echo $dept['dept_id']; ?>">
                            <input type="hidden" name="new_status" value="<?php echo $dept['is_active'] ? 0 : 1; ?>">
                            <button type="submit" class="btn btn-sm <?php echo $dept['is_active'] ? 'btn-warning' : 'btn-success'; ?>">
                                <i class="fas fa-<?php echo $dept['is_active'] ? 'toggle-off' : 'toggle-on'; ?>"></i>
                                <?php echo $dept['is_active'] ? 'Deactivate' : 'Activate'; ?>
                            </button>
                        </form>
                        <button onclick="confirmDelete(<?php echo $dept['dept_id']; ?>, '<?php echo htmlspecialchars($dept['dept_name']); ?>', <?php echo $dept['employee_count']; ?>)" 
                                class="btn btn-sm btn-danger">
                            <i class="fas fa-trash-alt"></i> Delete
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">🏢</div>
                <h3>No departments found</h3>
                <p>Start by creating your first department</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add/Edit Department Modal -->
    <div id="departmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus-circle"></i> Add New Department</h3>
            </div>
            <form method="POST" id="departmentForm">
                <input type="hidden" name="action" id="formAction" value="add_department">
                <input type="hidden" name="dept_id" id="deptId">

                <div class="form-group">
                    <label for="dept_name"><i class="fas fa-building"></i> Department Name *</label>
                    <input type="text" id="dept_name" name="dept_name" placeholder="e.g., Information Technology" required>
                </div>

                <div class="form-group">
                    <label for="dept_code"><i class="fas fa-tag"></i> Department Code *</label>
                    <input type="text" id="dept_code" name="dept_code" placeholder="e.g., IT, HR, FIN" required>
                </div>

                <div class="form-group">
                    <label for="description"><i class="fas fa-align-left"></i> Description</label>
                    <textarea id="description" name="description" placeholder="Brief description of the department"></textarea>
                </div>

                <div class="form-group">
                    <label for="manager_id"><i class="fas fa-user-tie"></i> Department Manager</label>
                    <select id="manager_id" name="manager_id">
                        <option value="">No manager assigned</option>
                        <?php foreach ($managers as $manager): ?>
                        <option value="<?php echo $manager['user_id']; ?>">
                            <?php echo htmlspecialchars($manager['first_name'] . ' ' . $manager['last_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="location"><i class="fas fa-map-marker-alt"></i> Location</label>
                    <input type="text" id="location" name="location" placeholder="e.g., Building A, Floor 3">
                </div>

                <div class="form-group">
                    <label for="budget"><i class="fas fa-dollar-sign"></i> Annual Budget ($)</label>
                    <input type="number" id="budget" name="budget" min="0" step="1000" placeholder="0">
                </div>

                <div class="modal-buttons">
                    <button type="button" class="btn btn-cancel" onclick="closeModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success" id="submitBtn">
                        <i class="fas fa-check"></i> Add Department
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i> Confirm Delete</h3>
                <p>Are you sure you want to delete <strong id="deleteDeptName"></strong>?</p>
            </div>
            <div class="delete-warning" id="deleteWarning"></div>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="action" value="delete_department">
                <input type="hidden" name="dept_id" id="deleteDeptId">
                <div class="modal-buttons">
                    <button type="button" class="btn btn-cancel" onclick="closeDeleteModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-danger" id="deleteSubmitBtn">
                        <i class="fas fa-trash-alt"></i> Delete Department
                    </button>
                </div>
            </form>
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

        function openAddModal() {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add New Department';
            document.getElementById('formAction').value = 'add_department';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-check"></i> Add Department';
            document.getElementById('departmentForm').reset();
            document.getElementById('departmentModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function openEditModal(dept) {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Department';
            document.getElementById('formAction').value = 'update_department';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> Update Department';
            document.getElementById('deptId').value = dept.dept_id;
            document.getElementById('dept_name').value = dept.dept_name;
            document.getElementById('dept_code').value = dept.dept_code;
            document.getElementById('description').value = dept.description || '';
            document.getElementById('manager_id').value = dept.manager_id || '';
            document.getElementById('location').value = dept.location || '';
            document.getElementById('budget').value = dept.budget || '';
            document.getElementById('departmentModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            document.getElementById('departmentModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        function confirmDelete(deptId, deptName, employeeCount) {
            document.getElementById('deleteDeptId').value = deptId;
            document.getElementById('deleteDeptName').textContent = deptName;
            
            const warning = document.getElementById('deleteWarning');
            const submitBtn = document.getElementById('deleteSubmitBtn');
            
            if (employeeCount > 0) {
                warning.innerHTML = '<i class="fas fa-exclamation-triangle"></i> This department has ' + employeeCount + ' employee(s). Please reassign them before deleting.';
                warning.style.display = 'block';
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                submitBtn.style.cursor = 'not-allowed';
            } else {
                warning.innerHTML = '<i class="fas fa-info-circle"></i> This action cannot be undone.';
                warning.style.display = 'block';
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.style.cursor = 'pointer';
            }
            
            document.getElementById('deleteModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Close modals when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                    document.body.style.overflow = 'auto';
                }
            });
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeDeleteModal();
            }
        });

        // Auto-hide success/error messages
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