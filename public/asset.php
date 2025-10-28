<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is admin/manager
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'manager') {
    header("Location: ../dashboard/index.php");
    exit();
}

// Include database connection
include("../auth/config/database.php");

// Verify PDO connection exists
if (!isset($pdo)) {
    die("Database connection failed. Please check your database configuration.");
}

// Handle form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_asset'])) {
    $asset_name = trim($_POST['asset_name']);
    $asset_code = trim($_POST['asset_code']);
    $category = trim($_POST['category']);
    $brand = trim($_POST['brand']);
    $model = trim($_POST['model']);
    $serial_number = trim($_POST['serial_number']);
    $purchase_date = $_POST['purchase_date'];
    $purchase_cost = $_POST['purchase_cost'];
    $supplier = trim($_POST['supplier']);
    $warranty_expiry = $_POST['warranty_expiry'];
    $location = trim($_POST['location']);
    $department = $_POST['department'];
    $status = $_POST['status'];
    $description = trim($_POST['description']);
    $assigned_to = !empty($_POST['assigned_to']) ? $_POST['assigned_to'] : NULL;

    // Validate required fields
    if (empty($asset_name) || empty($asset_code) || empty($category)) {
        $error_message = "Asset Name, Asset Code, and Category are required!";
    } else {
        try {
            // Check if asset code already exists
            $check_stmt = $pdo->prepare("SELECT id FROM assets WHERE asset_code = ?");
            $check_stmt->execute([$asset_code]);

            if ($check_stmt->rowCount() > 0) {
                $error_message = "Asset Code already exists! Please use a unique code.";
            } else {
                // Insert new asset
                $stmt = $pdo->prepare("INSERT INTO assets (asset_name, asset_code, category, brand, model, serial_number, purchase_date, purchase_cost, supplier, warranty_expiry, location, department, status, description, assigned_to, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

                $created_by = $_SESSION['user_id'];

                if ($stmt->execute([$asset_name, $asset_code, $category, $brand, $model, $serial_number, $purchase_date, $purchase_cost, $supplier, $warranty_expiry, $location, $department, $status, $description, $assigned_to, $created_by])) {
                    $success_message = "Asset added successfully!";
                } else {
                    $error_message = "Error adding asset.";
                }
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}

// Handle asset assignment update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_asset'])) {
    $asset_id = $_POST['asset_id'];
    $assigned_to = !empty($_POST['assign_to_user']) ? $_POST['assign_to_user'] : NULL;

    try {
        // Get current assignment for logging
        $current_stmt = $pdo->prepare("SELECT assigned_to FROM assets WHERE id = ?");
        $current_stmt->execute([$asset_id]);
        $current_data = $current_stmt->fetch(PDO::FETCH_ASSOC);
        $current_user_id = $current_data['assigned_to'];

        // Update assignment
        $new_status = $assigned_to ? 'In Use' : 'Available';
        $update_stmt = $pdo->prepare("UPDATE assets SET assigned_to = ?, status = ? WHERE id = ?");

        if ($update_stmt->execute([$assigned_to, $new_status, $asset_id])) {
            // Log the assignment change
            $action = $assigned_to ? 'assigned' : 'unassigned';
            $log_stmt = $pdo->prepare("INSERT INTO assets_history (asset_id, action_type, assigned_from, assigned_to, performed_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $log_stmt->execute([$asset_id, $action, $current_user_id, $assigned_to, $_SESSION['user_id']]);

            $success_message = "Asset assignment updated successfully!";
        } else {
            $error_message = "Error updating asset assignment.";
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}


// Fetch all assets for the table with assigned user information
$assets = [];
try {
    $query = "SELECT a.*, 
              u.username as created_by_name,
              assigned_user.user_id as assigned_user_id,
              assigned_user.username as assigned_username,
              CONCAT(assigned_user.first_name, ' ', assigned_user.last_name) as assigned_user_name
              FROM assets a 
              LEFT JOIN users u ON a.created_by = u.user_id 
              LEFT JOIN users assigned_user ON a.assigned_to = assigned_user.user_id
              ORDER BY a.created_at DESC";
    $result = $pdo->query($query);
    $assets = $result->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching assets: " . $e->getMessage();
}

// Get all users for assignment dropdown
$users = [];
try {
    $users_query = "SELECT user_id, username, first_name, last_name, email, department FROM users ORDER BY first_name, last_name";
    $users_result = $pdo->query($users_query);
    $users = $users_result->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error silently
}

// Get departments for dropdown
$departments = [];
try {
    $dept_query = "SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != ''";
    $dept_result = $pdo->query($dept_query);
    $departments = $dept_result->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // Default departments if query fails
    $departments = ['IT', 'HR', 'Finance', 'Operations', 'Marketing'];
}



// Calculate statistics - FIXED: Case-insensitive and trim comparison
$total_assets = count($assets);
$available_assets = 0;
$in_use_assets = 0;
$maintenance_assets = 0;

foreach ($assets as $asset) {
    $status = strtolower(trim($asset['status']));

    if ($status === 'available') {
        $available_assets++;
    } elseif ($status === 'in_use') {
        $in_use_assets++;
    } elseif ($status === 'maintenance') {
        $maintenance_assets++;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - E-Asset Management</title>
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-content h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-content h1 i {
            color: #7c3aed;
        }

        .header-content p {
            color: #718096;
            font-size: 15px;
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        .stat-card.available { border-left-color: #10b981; }
        .stat-card.in-use { border-left-color: #3b82f6; }
        .stat-card.maintenance { border-left-color: #f59e0b; }

        .stat-number {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 8px;
            color: #7c3aed;
        }

        .stat-card.available .stat-number { color: #10b981; }
        .stat-card.in-use .stat-number { color: #3b82f6; }
        .stat-card.maintenance .stat-number { color: #f59e0b; }

        .stat-label {
            color: #718096;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
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

        /* Buttons */
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

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
        }

        .btn-secondary {
            background: white;
            color: #718096;
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
        }

        .btn-small {
            padding: 8px 14px;
            font-size: 13px;
        }

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            display: none;
            animation: slideDown 0.3s ease;
        }

        .form-card.active {
            display: block;
        }

        .form-card h2 {
            font-size: 22px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-card h2 i {
            color: #7c3aed;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 8px;
            color: #2d3748;
            font-weight: 600;
            font-size: 14px;
        }

        .form-group label.required::after {
            content: ' *';
            color: #ef4444;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
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
            min-height: 100px;
        }

        .help-text {
            font-size: 12px;
            color: #718096;
            margin-top: 6px;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
        }

        /* Table */
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

        /* Asset Code Link */
        .asset-code-link {
            color: #7c3aed;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }

        .asset-code-link:hover {
            color: #6d28d9;
            text-decoration: underline;
        }

        /* User Info */
        .user-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .user-name {
            font-weight: 600;
            color: #1a202c;
        }

        .user-email {
            font-size: 13px;
            color: #718096;
        }

        .unassigned {
            color: #718096;
            font-style: italic;
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
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
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }

        .status-retired {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #374151;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Empty State */
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
            animation: fadeIn 0.3s ease;
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
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
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 22px;
            font-weight: 700;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-close {
            font-size: 28px;
            color: #718096;
            cursor: pointer;
            transition: color 0.2s;
            line-height: 1;
        }

        .modal-close:hover {
            color: #2d3748;
        }

        /* Delete Modal Specific */
        .delete-modal .modal-content {
            max-width: 450px;
        }

        .delete-warning {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .delete-warning p {
            color: #991b1b;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .delete-warning .asset-details {
            background: white;
            padding: 12px;
            border-radius: 6px;
            margin-top: 12px;
        }

        .delete-warning .asset-details strong {
            color: #1a202c;
        }

        /* Responsive Design */
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

            .header-content h1 {
                font-size: 22px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .section {
                padding: 20px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .table {
                min-width: 1000px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
            }

            .modal-content {
                padding: 24px;
                width: 95%;
            }
        }
    </style>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="container" id="mainContainer">
        <div class="header">
            <div class="header-content">
                <h1><i class="fas fa-boxes"></i> Inventory Management</h1>
                <p>Manage and track all your organization's assets</p>
            </div>
            <button class="btn btn-primary" id="toggleFormBtn">
                <i class="fas fa-plus"></i> Add New Asset
            </button>
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
                <div class="stat-number"><?php echo $total_assets; ?></div>
                <div class="stat-label">Total Assets</div>
            </div>
            <div class="stat-card available">
                <div class="stat-number"><?php echo $available_assets; ?></div>
                <div class="stat-label">Available</div>
            </div>
            <div class="stat-card in-use">
                <div class="stat-number"><?php echo $in_use_assets; ?></div>
                <div class="stat-label">In Use</div>
            </div>
            <div class="stat-card maintenance">
                <div class="stat-number"><?php echo $maintenance_assets; ?></div>
                <div class="stat-label">Maintenance</div>
            </div>
        </div>

        <?php if ($_SESSION['role'] === 'admin'): ?>
        <?php endif; ?>

        <!-- Add Asset Form -->
        <div class="form-card" id="assetForm">
            <h2><i class="fas fa-plus-circle"></i> Add New Asset</h2>
            <form method="POST" action="">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="asset_name" class="required">Asset Name</label>
                        <input type="text" id="asset_name" name="asset_name" required placeholder="Enter asset name">
                    </div>

                    <div class="form-group">
                        <label for="asset_code" class="required">Asset Code</label>
                        <input type="text" id="asset_code" name="asset_code" placeholder="e.g., AST-001" required>
                    </div>

                    <div class="form-group">
                        <label for="category" class="required">Category</label>
                        <select id="category" name="category" required>
                            <option value="">Select Category</option>
                            <option value="Computer">Computer</option>
                            <option value="Laptop">Laptop</option>
                            <option value="Monitor">Monitor</option>
                            <option value="Printer">Printer</option>
                            <option value="Mobile Device">Mobile Device</option>
                            <option value="Network Equipment">Network Equipment</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="brand">Brand</label>
                        <input type="text" id="brand" name="brand" placeholder="e.g., Dell, HP, Lenovo">
                    </div>

                    <div class="form-group">
                        <label for="model">Model</label>
                        <input type="text" id="model" name="model" placeholder="e.g., Latitude 5520">
                    </div>

                    <div class="form-group">
                        <label for="serial_number">Serial Number</label>
                        <input type="text" id="serial_number" name="serial_number" placeholder="Enter serial number">
                    </div>

                    <div class="form-group">
                        <label for="purchase_date">Purchase Date</label>
                        <input type="date" id="purchase_date" name="purchase_date">
                    </div>

                    <div class="form-group">
                        <label for="purchase_cost">Purchase Cost ($)</label>
                        <input type="number" id="purchase_cost" name="purchase_cost" step="0.01" min="0" placeholder="0.00">
                    </div>

                    <div class="form-group">
                        <label for="supplier">Supplier</label>
                        <input type="text" id="supplier" name="supplier" placeholder="Enter supplier name">
                    </div>

                    <div class="form-group">
                        <label for="warranty_expiry">Warranty Expiry</label>
                        <input type="date" id="warranty_expiry" name="warranty_expiry">
                    </div>

                    <div class="form-group">
                        <label for="location">Location</label>
                        <input type="text" id="location" name="location" placeholder="e.g., Building A, Room 101">
                    </div>

                    <div class="form-group">
                        <label for="department">Department</label>
                        <select id="department" name="department">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>">
                                    <?php echo htmlspecialchars($dept); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="Available">Available</option>
                            <option value="In Use">In Use</option>
                            <option value="Maintenance">Maintenance</option>
                            <option value="Retired">Retired</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="assigned_to">Assign To User (Optional)</label>
                        <select id="assigned_to" name="assigned_to">
                            <option value="">-- Leave Unassigned --</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['user_id']; ?>">
                                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                    (<?php echo htmlspecialchars($user['username']); ?>)
                                    <?php if ($user['department']): ?>
                                        - <?php echo htmlspecialchars($user['department']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="help-text">You can assign this asset to a user now or leave it unassigned and assign later.</span>
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" placeholder="Additional notes or specifications..."></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="cancelBtn">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="add_asset" class="btn btn-primary">
                        <i class="fas fa-check"></i> Add Asset
                    </button>
                </div>
            </form>
        </div>

        <!-- Assets Table Section -->
        <div class="section">
            <div class="section-header">
                <h2><i class="fas fa-list"></i> Asset Inventory</h2>
            </div>

            <?php if (empty($assets)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📦</div>
                <h3>No Assets Found</h3>
                <p>No assets found in inventory. Add your first asset to get started!</p>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Asset Code</th>
                            <th>Asset Name</th>
                            <th>Category</th>
                            <th>Brand/Model</th>
                            <th>Assigned To</th>
                            <th>Department</th>
                            <th>Status</th>
                            <th>Purchase Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assets as $asset): ?>
                        <tr>
                            <td>
                                <a href="assetDetails.php?id=<?php echo $asset['id']; ?>" class="asset-code-link">
                                    <?php echo htmlspecialchars($asset['asset_code']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($asset['asset_name']); ?></td>
                            <td><?php echo htmlspecialchars($asset['category']); ?></td>
                            <td>
                                <?php
                                $brandModel = trim($asset['brand'] . ' ' . $asset['model']);
                                echo htmlspecialchars($brandModel ?: '-');
                                ?>
                            </td>
                            <td>
                                <?php if ($asset['assigned_user_id']): ?>
                                    <div class="user-info">
                                        <span class="user-name"><?php echo htmlspecialchars($asset['assigned_user_name']); ?></span>
                                        <span class="user-email">@<?php echo htmlspecialchars($asset['assigned_username']); ?></span>
                                    </div>
                                <?php else: ?>
                                    <span class="unassigned">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($asset['department'] ?: '-'); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $asset['status'])); ?>">
                                    <?php echo htmlspecialchars($asset['status']); ?>
                                </span>
                            </td>
                            <td><?php echo $asset['purchase_date'] ? date('M j, Y', strtotime($asset['purchase_date'])) : '-'; ?></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn btn-success btn-small assign-btn"
                                        data-asset-id="<?php echo $asset['id']; ?>"
                                        data-asset-name="<?php echo htmlspecialchars($asset['asset_name']); ?>"
                                        data-current-user="<?php echo $asset['assigned_user_id'] ?: ''; ?>">
                                        <i class="fas fa-user-check"></i>
                                        <?php echo $asset['assigned_user_id'] ? 'Reassign' : 'Assign'; ?>
                                    </button>
                                    <a href="assetEdit.php?id=<?php echo $asset['id']; ?>" class="btn btn-primary btn-small">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <a href="assetHistory.php?id=<?php echo $asset['id']; ?>" class="btn btn-secondary btn-small">
                                        <i class="fas fa-history"></i> History
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Assignment Modal -->
    <div id="assignModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-check"></i> Assign Asset to User</h3>
                <span class="modal-close">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" id="modal_asset_id" name="asset_id">
                
                <div class="form-group">
                    <label for="modal_asset_name">Asset</label>
                    <input type="text" id="modal_asset_name" readonly style="background-color: #f8f9fa;">
                </div>
                
                <div class="form-group">
                    <label for="assign_to_user">Assign To User</label>
                    <select id="assign_to_user" name="assign_to_user">
                        <option value="">-- Unassign (Make Available) --</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['user_id']; ?>">
                                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                (<?php echo htmlspecialchars($user['email']); ?>)
                                <?php if ($user['department']): ?>
                                    - <?php echo htmlspecialchars($user['department']); ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="help-text">Select a user to assign this asset, or leave empty to unassign.</span>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary modal-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="assign_asset" class="btn btn-success">
                        <i class="fas fa-check"></i> Update Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal delete-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Delete Asset</h3>
                <span class="modal-close delete-modal-close">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" id="delete_asset_id" name="delete_asset_id">
                
                <div class="delete-warning">
                    <p><i class="fas fa-exclamation-triangle"></i> <strong>Warning:</strong> This action cannot be undone!</p>
                    <p>Are you sure you want to delete this asset?</p>
                    <div class="asset-details">
                        <strong>Asset Name:</strong> <span id="delete_asset_name"></span><br>
                        <strong>Asset Code:</strong> <span id="delete_asset_code"></span>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary delete-modal-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="delete_asset" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Asset
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

        // Toggle form visibility
        const toggleFormBtn = document.getElementById('toggleFormBtn');
        const assetForm = document.getElementById('assetForm');
        const cancelBtn = document.getElementById('cancelBtn');

        toggleFormBtn.addEventListener('click', function() {
            assetForm.classList.toggle('active');
            if (assetForm.classList.contains('active')) {
                this.innerHTML = '<i class="fas fa-times"></i> Close Form';
                assetForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                this.innerHTML = '<i class="fas fa-plus"></i> Add New Asset';
            }
        });

        cancelBtn.addEventListener('click', function() {
            assetForm.classList.remove('active');
            toggleFormBtn.innerHTML = '<i class="fas fa-plus"></i> Add New Asset';
            document.querySelector('form').reset();
        });

        // Auto-generate asset code suggestion
        const categorySelect = document.getElementById('category');
        const assetCodeInput = document.getElementById('asset_code');

        categorySelect.addEventListener('change', function() {
            if (assetCodeInput.value === '') {
                const prefix = this.value.substring(0, 3).toUpperCase();
                const random = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
                assetCodeInput.value = prefix + '-' + random;
            }
        });

        // Auto-update status when assigning user
        const assignedToSelect = document.getElementById('assigned_to');
        const statusSelect = document.getElementById('status');

        assignedToSelect.addEventListener('change', function() {
            if (this.value) {
                statusSelect.value = 'In Use';
            } else {
                statusSelect.value = 'Available';
            }
        });

        // Assignment Modal
        const assignModal = document.getElementById('assignModal');
        const assignButtons = document.querySelectorAll('.assign-btn');
        const modalClose = document.querySelector('.modal-close');
        const modalCancel = document.querySelector('.modal-cancel');

        assignButtons.forEach(button => {
            button.addEventListener('click', function() {
                const assetId = this.getAttribute('data-asset-id');
                const assetName = this.getAttribute('data-asset-name');
                const currentUser = this.getAttribute('data-current-user');

                document.getElementById('modal_asset_id').value = assetId;
                document.getElementById('modal_asset_name').value = assetName;
                document.getElementById('assign_to_user').value = currentUser;

                assignModal.classList.add('active');
                document.body.style.overflow = 'hidden';
            });
        });

        function closeModal() {
            assignModal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        modalClose.addEventListener('click', closeModal);
        modalCancel.addEventListener('click', closeModal);

        window.addEventListener('click', function(event) {
            if (event.target === assignModal) {
                closeModal();
            }
        });

        // Delete Modal
        const deleteModal = document.getElementById('deleteModal');
        const deleteButtons = document.querySelectorAll('.delete-btn');
        const deleteModalClose = document.querySelector('.delete-modal-close');
        const deleteModalCancel = document.querySelector('.delete-modal-cancel');

        deleteButtons.forEach(button => {
            button.addEventListener('click', function() {
                const assetId = this.getAttribute('data-asset-id');
                const assetName = this.getAttribute('data-asset-name');
                const assetCode = this.getAttribute('data-asset-code');

                document.getElementById('delete_asset_id').value = assetId;
                document.getElementById('delete_asset_name').textContent = assetName;
                document.getElementById('delete_asset_code').textContent = assetCode;

                deleteModal.classList.add('active');
                document.body.style.overflow = 'hidden';
            });
        });

        function closeDeleteModal() {
            deleteModal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        deleteModalClose.addEventListener('click', closeDeleteModal);
        deleteModalCancel.addEventListener('click', closeDeleteModal);

        window.addEventListener('click', function(event) {
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (assignModal.classList.contains('active')) {
                    closeModal();
                }
                if (deleteModal.classList.contains('active')) {
                    closeDeleteModal();
                }
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