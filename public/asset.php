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

// Include database connection and email helper
include("../auth/config/database.php");
require_once '../auth/helpers/EmailHelper.php';

// Verify PDO connection exists
if (!isset($pdo)) {
    die("Database connection failed. Please check your database configuration.");
}

// Initialize EmailHelper
$emailHelper = new EmailHelper();

// Handle form submission
$success_message = '';
$error_message = '';

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// handle form submission
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
                $stmt = $pdo->prepare("INSERT INTO assets (asset_name, asset_code, 
                category, brand, model, serial_number, purchase_date, purchase_cost, supplier, warranty_expiry, 
                location, department, status, description, assigned_to, created_by, created_at) VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

                $created_by = $_SESSION['user_id'];

                if ($stmt->execute([$asset_name, $asset_code, $category, $brand, $model, $serial_number, $purchase_date, $purchase_cost, $supplier, $warranty_expiry, $location, $department, $status, $description, $assigned_to, $created_by])) {
                    $asset_id = $pdo->lastInsertId();
                    $success_message = "Asset added successfully!";

                    // Send email notification if asset is assigned to a user
                    if ($assigned_to) {
                        try {
                            // Get user details
                            $user_stmt = $pdo->prepare("SELECT user_id, first_name, last_name, email FROM users WHERE user_id = ?");
                            $user_stmt->execute([$assigned_to]);
                            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);

                            // Get assigned by user details
                            $assigned_by_stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
                            $assigned_by_stmt->execute([$_SESSION['user_id']]);
                            $assigned_by = $assigned_by_stmt->fetch(PDO::FETCH_ASSOC);
                            $assigned_by_name = $assigned_by['first_name'] . ' ' . $assigned_by['last_name'];

                            if ($user) {
                                $assignment_data = [
                                    'asset_id' => $asset_id,
                                    'user_name' => $user['first_name'] . ' ' . $user['last_name'],
                                    'user_email' => $user['email'],
                                    'asset_name' => $asset_name,
                                    'asset_code' => $asset_code,
                                    'asset_category' => $category,
                                    'brand_model' => trim($brand . ' ' . $model),
                                    'serial_number' => $serial_number ?: 'N/A',
                                    'location' => $location ?: 'Not specified',
                                    'assigned_by' => $assigned_by_name
                                ];

                                if ($emailHelper->sendAssetAssignmentEmail($assignment_data)) {
                                    $success_message .= " Assignment notification email sent to " . $user['email'];
                                }
                            }

                            // Log the assignment
                            $log_stmt = $pdo->prepare("INSERT INTO assets_history (asset_id, action_type, assigned_from,
                             assigned_to, performed_by, created_at) VALUES (?, 'assigned', NULL, ?, ?, NOW())");
                            $log_stmt->execute([$asset_id, $assigned_to, $_SESSION['user_id']]);
                        } catch (Exception $e) {
                            // Don't fail the whole operation if email fails
                            error_log("Failed to send assignment email: " . $e->getMessage());
                        }
                    }
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
        // Get current asset and assignment info
        $current_stmt = $pdo->prepare("
            SELECT a.*, 
                   u.user_id as current_user_id, 
                   u.first_name as current_first_name, 
                   u.last_name as current_last_name,
                   u.email as current_email
            FROM assets a
            LEFT JOIN users u ON a.assigned_to = u.user_id
            WHERE a.id = ?
        ");
        $current_stmt->execute([$asset_id]);
        $asset_data = $current_stmt->fetch(PDO::FETCH_ASSOC);
        $current_user_id = $asset_data['current_user_id'];

        // Update assignment with explicit status - ensure no empty status
        $new_status = $assigned_to ? 'in_use' : 'available';

        // Debug: Log what we're about to update
        error_log("=== ASSIGNMENT UPDATE DEBUG ===");
        error_log("Asset ID: $asset_id");
        error_log("Assigned to: " . ($assigned_to ?: 'NULL'));
        error_log("New status: $new_status");
        error_log("Current status in DB: " . $asset_data['status']);

        $update_stmt = $pdo->prepare("UPDATE assets SET assigned_to = ?, status = ?, updated_at = NOW() WHERE id = ?");
        $update_result = $update_stmt->execute([$assigned_to, $new_status, $asset_id]);

        // Check how many rows were affected
        $rows_affected = $update_stmt->rowCount();
        error_log("Update result: " . ($update_result ? 'SUCCESS' : 'FAILED'));
        error_log("Rows affected: $rows_affected");

        if ($update_result) {
            // Verify the update worked
            $verify_stmt = $pdo->prepare("SELECT status, assigned_to FROM assets WHERE id = ?");
            $verify_stmt->execute([$asset_id]);
            $verify = $verify_stmt->fetch(PDO::FETCH_ASSOC);
            error_log("Status after update: " . $verify['status']);
            error_log("Assigned_to after update: " . ($verify['assigned_to'] ?: 'NULL'));
            error_log("=== END DEBUG ===");

            // Get assigned by user details
            $assigned_by_stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
            $assigned_by_stmt->execute([$_SESSION['user_id']]);
            $assigned_by = $assigned_by_stmt->fetch(PDO::FETCH_ASSOC);
            $assigned_by_name = $assigned_by['first_name'] . ' ' . $assigned_by['last_name'];

            // Send unassignment email to previous user
            if ($current_user_id && $current_user_id != $assigned_to) {
                try {
                    $unassignment_data = [
                        'user_name' => $asset_data['current_first_name'] . ' ' . $asset_data['current_last_name'],
                        'user_email' => $asset_data['current_email'],
                        'asset_name' => $asset_data['asset_name'],
                        'asset_code' => $asset_data['asset_code'],
                        'unassigned_by' => $assigned_by_name
                    ];

                    $emailHelper->sendAssetUnassignmentEmail($unassignment_data);
                } catch (Exception $e) {
                    error_log("Failed to send unassignment email: " . $e->getMessage());
                }
            }

            // Send assignment email to new user
            if ($assigned_to) {
                try {
                    // Get new user details
                    $new_user_stmt = $pdo->prepare("SELECT user_id, first_name, last_name, email FROM users WHERE user_id = ?");
                    $new_user_stmt->execute([$assigned_to]);
                    $new_user = $new_user_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($new_user) {
                        $assignment_data = [
                            'asset_id' => $asset_id,
                            'user_name' => $new_user['first_name'] . ' ' . $new_user['last_name'],
                            'user_email' => $new_user['email'],
                            'asset_name' => $asset_data['asset_name'],
                            'asset_code' => $asset_data['asset_code'],
                            'asset_category' => $asset_data['category'],
                            'brand_model' => trim($asset_data['brand'] . ' ' . $asset_data['model']),
                            'serial_number' => $asset_data['serial_number'] ?: 'N/A',
                            'location' => $asset_data['location'] ?: 'Not specified',
                            'assigned_by' => $assigned_by_name
                        ];

                        if ($emailHelper->sendAssetAssignmentEmail($assignment_data)) {
                            $_SESSION['success_message'] = "Asset assignment updated successfully! Notification email sent to " . $new_user['email'];
                        } else {
                            $_SESSION['success_message'] = "Asset assignment updated successfully! (Email notification failed to send)";
                        }
                    }
                } catch (Exception $e) {
                    error_log("Failed to send assignment email: " . $e->getMessage());
                    $_SESSION['success_message'] = "Asset assignment updated successfully! (Email notification failed to send)";
                }
            } else {
                $_SESSION['success_message'] = "Asset unassigned successfully!";
            }

            // Log the assignment change
            $action = $assigned_to ? 'assigned' : 'unassigned';
            $log_stmt = $pdo->prepare("INSERT INTO assets_history (asset_id, action_type, assigned_from, assigned_to, performed_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $log_stmt->execute([$asset_id, $action, $current_user_id, $assigned_to, $_SESSION['user_id']]);

            // Redirect to refresh the page with updated data
            header("Location: asset.php");
            exit();
        } else {
            $_SESSION['error_message'] = "Error updating asset assignment.";
            header("Location: asset.php");
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
        error_log("Database error in assignment: " . $e->getMessage());
        header("Location: asset.php");
        exit();
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
    $status = strtolower(str_replace(' ', '_', trim($asset['status'])));

    if ($status === 'available') {
        $available_assets++;
    } elseif ($status === 'in_use' || $status === 'in use') {
        $in_use_assets++;
    } elseif ($status === 'maintenance') {
        $maintenance_assets++;
    }
}
// Calculate expiring warranties (within next 30 days)
$expiring_assets = [];
$expired_assets = [];
$today = new DateTime();

foreach ($assets as $asset) {
    if ($asset['warranty_expiry']) {
        $warranty_date = new DateTime($asset['warranty_expiry']);
        $interval = $today->diff($warranty_date);
        $days_left = $interval->days * ($interval->invert ? -1 : 1);

        // Check if expiring within 30 days
        if ($days_left >= 0 && $days_left <= 30) {
            $asset['days_until_expiry'] = $days_left;
            $expiring_assets[] = $asset;
        }

        // Check if already expired
        if ($days_left < 0) {
            $asset['days_since_expiry'] = abs($days_left);
            $expired_assets[] = $asset;
        }
    }
}

// Sort by days remaining
usort($expiring_assets, function ($a, $b) {
    return $a['days_until_expiry'] - $b['days_until_expiry'];
});

usort($expired_assets, function ($a, $b) {
    return $b['days_since_expiry'] - $a['days_since_expiry'];
});

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
        .success-message,
        .error-message {
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

        .stat-card.available {
            border-left-color: #10b981;
        }

        .stat-card.in-use {
            border-left-color: #3b82f6;
        }

        .stat-card.maintenance {
            border-left-color: #f59e0b;
        }

        .stat-number {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 8px;
            color: #7c3aed;
        }

        .stat-card.available .stat-number {
            color: #10b981;
        }

        .stat-card.in-use .stat-number {
            color: #3b82f6;
        }

        .stat-card.maintenance .stat-number {
            color: #f59e0b;
        }

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
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
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

        /* Search and Filter Bar */
        .search-filter-bar {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .search-filter-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 15px;
            align-items: end;
        }

        .search-group {
            position: relative;
        }

        .search-group input {
            width: 100%;
            padding: 12px 16px 12px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .search-group input:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #718096;
            font-size: 16px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 8px;
            color: #2d3748;
            font-weight: 600;
            font-size: 13px;
        }

        .filter-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
        }

        .filter-group select:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

        .reset-filters-btn {
            padding: 12px 20px;
            background: #f7fafc;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            color: #718096;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .reset-filters-btn:hover {
            background: white;
            border-color: #cbd5e0;
            color: #2d3748;
        }

        .results-info {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
            color: #718096;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .results-count {
            font-weight: 600;
            color: #7c3aed;
        }

        /* Sortable Table Headers */
        .table thead th.sortable {
            cursor: pointer;
            user-select: none;
            position: relative;
            padding-right: 30px;
        }

        .table thead th.sortable:hover {
            background: #ede9fe;
        }

        .sort-icon {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 12px;
            color: #cbd5e0;
            transition: color 0.2s;
        }

        .table thead th.sortable.asc .sort-icon,
        .table thead th.sortable.desc .sort-icon {
            color: #7c3aed;
        }

        .no-results {
            text-align: center;
            padding: 60px 20px;
        }

        .no-results-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .no-results h3 {
            font-size: 20px;
            color: #1a202c;
            margin-bottom: 10px;
        }

        .no-results p {
            color: #718096;
            font-size: 15px;
        }

        /* Responsive Search Bar */
        @media (max-width: 1200px) {
            .search-filter-grid {
                grid-template-columns: 1fr 1fr;
            }

            .search-group {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 768px) {
            .search-filter-grid {
                grid-template-columns: 1fr;
            }

            .reset-filters-btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Warranty Alerts Section */
        .warranty-alerts-section {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .alert-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            border-left: 5px solid;
        }

        .alert-card.expired {
            border-left-color: #ef4444;
        }

        .alert-card.expiring {
            border-left-color: #f59e0b;
        }

        .alert-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 30px;
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            cursor: pointer;
            transition: background 0.3s;
        }

        .alert-card.expiring .alert-header {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
        }

        .alert-header:hover {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
        }

        .alert-card.expiring .alert-header:hover {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        }

        .alert-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-title i {
            font-size: 24px;
        }

        .alert-card.expired .alert-title i {
            color: #ef4444;
        }

        .alert-card.expiring .alert-title i {
            color: #f59e0b;
        }

        .alert-title h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: #1a202c;
        }

        .toggle-alert-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 20px;
            color: #718096;
            transition: transform 0.3s, color 0.3s;
            padding: 8px;
        }

        .toggle-alert-btn:hover {
            color: #2d3748;
        }

        .toggle-alert-btn.rotated {
            transform: rotate(180deg);
        }

        .alert-content {
            padding: 0 30px 20px 30px;
            max-height: 600px;
            overflow-y: auto;
        }

        .alert-content.collapsed {
            display: none;
        }

        .alert-items {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .alert-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 20px;
            background: #fafbfc;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            transition: all 0.3s;
        }

        .alert-item:hover {
            border-color: #cbd5e0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
        }

        .alert-item-icon {
            font-size: 28px;
            line-height: 1;
        }

        .alert-item-content {
            flex: 1;
            min-width: 0;
        }

        .alert-item-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .alert-asset-name {
            font-size: 16px;
            font-weight: 700;
            color: #7c3aed;
            text-decoration: none;
            transition: color 0.2s;
        }

        .alert-asset-name:hover {
            color: #6d28d9;
            text-decoration: underline;
        }

        .alert-code {
            font-size: 14px;
            color: #718096;
            font-weight: 600;
            padding: 4px 10px;
            background: white;
            border-radius: 6px;
        }

        .alert-item-details {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 8px;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #4b5563;
        }

        .detail-item i {
            color: #9ca3af;
            font-size: 12px;
        }

        .alert-item-actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .alert-header {
                padding: 15px 20px;
            }

            .alert-content {
                padding: 0 20px 15px 20px;
            }

            .alert-title h3 {
                font-size: 16px;
            }

            .alert-item {
                flex-direction: column;
                padding: 15px;
            }

            .alert-item-actions {
                width: 100%;
            }

            .alert-item-actions .btn {
                width: 100%;
            }

            .alert-item-details {
                flex-direction: column;
                gap: 8px;
            }
        }

        /* Scrollbar styling for alert content */
        .alert-content::-webkit-scrollbar {
            width: 8px;
        }

        .alert-content::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }

        .alert-content::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 4px;
        }

        .alert-content::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
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
        <?php if (!empty($expiring_assets) || !empty($expired_assets)): ?>
            <div class="warranty-alerts-section" style="margin-bottom: 30px;">
                <?php if (!empty($expired_assets)): ?>
                    <div class="alert-card expired">
                        <div class="alert-header">
                            <div class="alert-title">
                                <i class="fas fa-exclamation-triangle"></i>
                                <h3>Expired Warranties (<?php echo count($expired_assets); ?>)</h3>
                            </div>
                            <button class="toggle-alert-btn" onclick="toggleAlert('expired')">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <div class="alert-content" id="expired-alerts">
                            <div class="alert-items">
                                <?php foreach ($expired_assets as $asset): ?>
                                    <div class="alert-item">
                                        <div class="alert-item-icon">🚨</div>
                                        <div class="alert-item-content">
                                            <div class="alert-item-header">
                                                <a href="assetDetails.php?id=<?php echo $asset['id']; ?>" class="alert-asset-name">
                                                    <?php echo htmlspecialchars($asset['asset_name']); ?>
                                                </a>
                                                <span class="alert-code"><?php echo htmlspecialchars($asset['asset_code']); ?></span>
                                            </div>
                                            <div class="alert-item-details">
                                                <span class="detail-item">
                                                    <i class="fas fa-tag"></i>
                                                    <?php echo htmlspecialchars($asset['category']); ?>
                                                </span>
                                                <span class="detail-item">
                                                    <i class="fas fa-calendar-times"></i>
                                                    Expired <?php echo $asset['days_since_expiry']; ?> days ago
                                                </span>
                                                <?php if ($asset['department']): ?>
                                                    <span class="detail-item">
                                                        <i class="fas fa-building"></i>
                                                        <?php echo htmlspecialchars($asset['department']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="alert-item-actions">
                                            <a href="assetEdit.php?id=<?php echo $asset['id']; ?>" class="btn btn-small btn-secondary">
                                                <i class="fas fa-edit"></i> Update
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($expiring_assets)): ?>
                    <div class="alert-card expiring">
                        <div class="alert-header">
                            <div class="alert-title">
                                <i class="fas fa-exclamation-circle"></i>
                                <h3>Warranties Expiring Soon (<?php echo count($expiring_assets); ?>)</h3>
                            </div>
                            <button class="toggle-alert-btn" onclick="toggleAlert('expiring')">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <div class="alert-content" id="expiring-alerts">
                            <div class="alert-items">
                                <?php foreach ($expiring_assets as $asset): ?>
                                    <div class="alert-item">
                                        <div class="alert-item-icon">⚠️</div>
                                        <div class="alert-item-content">
                                            <div class="alert-item-header">
                                                <a href="assetDetails.php?id=<?php echo $asset['id']; ?>" class="alert-asset-name">
                                                    <?php echo htmlspecialchars($asset['asset_name']); ?>
                                                </a>
                                                <span class="alert-code"><?php echo htmlspecialchars($asset['asset_code']); ?></span>
                                            </div>
                                            <div class="alert-item-details">
                                                <span class="detail-item">
                                                    <i class="fas fa-tag"></i>
                                                    <?php echo htmlspecialchars($asset['category']); ?>
                                                </span>
                                                <span class="detail-item">
                                                    <i class="fas fa-calendar-check"></i>
                                                    Expires in <?php echo $asset['days_until_expiry']; ?> day<?php echo $asset['days_until_expiry'] != 1 ? 's' : ''; ?>
                                                </span>
                                                <span class="detail-item">
                                                    <i class="fas fa-clock"></i>
                                                    <?php echo date('M d, Y', strtotime($asset['warranty_expiry'])); ?>
                                                </span>
                                                <?php if ($asset['department']): ?>
                                                    <span class="detail-item">
                                                        <i class="fas fa-building"></i>
                                                        <?php echo htmlspecialchars($asset['department']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="alert-item-actions">
                                            <a href="assetEdit.php?id=<?php echo $asset['id']; ?>" class="btn btn-small btn-secondary">
                                                <i class="fas fa-edit"></i> Update
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
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
                            <option value="available">Available</option>
                            <option value="in_use">In Use</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="retired">Retired</option>
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
                        <span class="help-text">User will receive an email notification if assigned.</span>
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

        <!-- Search and Filter Bar -->
        <div class="search-filter-bar">
            <div class="search-filter-grid">
                <div class="search-group">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="searchInput" placeholder="Search by asset name, code, brand, model, or serial number...">
                </div>

                <div class="filter-group">
                    <label for="categoryFilter">Category</label>
                    <select id="categoryFilter">
                        <option value="">All Categories</option>
                        <option value="Computer">Computer</option>
                        <option value="Laptop">Laptop</option>
                        <option value="Monitor">Monitor</option>
                        <option value="Printer">Printer</option>
                        <option value="Mobile Device">Mobile Device</option>
                        <option value="Network Equipment">Network Equipment</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="statusFilter">Status</label>
                    <select id="statusFilter">
                        <option value="">All Status</option>
                        <option value="available">Available</option>
                        <option value="in_use">In Use</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="retired">Retired</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="departmentFilter">Department</label>
                    <select id="departmentFilter">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>">
                                <?php echo htmlspecialchars($dept); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button class="reset-filters-btn" id="resetFilters">
                    <i class="fas fa-redo"></i> Reset
                </button>
            </div>

            <div class="results-info">
                <span>Showing <span class="results-count" id="resultsCount"><?php echo count($assets); ?></span> of <?php echo count($assets); ?> assets</span>
                <span id="activeFilters"></span>
            </div>
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
                    <table class="table" id="assetsTable">
                        <thead>
                            <tr>
                                <th class="sortable" data-column="asset_code">
                                    Asset Code
                                    <i class="fas fa-sort sort-icon"></i>
                                </th>
                                <th class="sortable" data-column="asset_name">
                                    Asset Name
                                    <i class="fas fa-sort sort-icon"></i>
                                </th>
                                <th class="sortable" data-column="category">
                                    Category
                                    <i class="fas fa-sort sort-icon"></i>
                                </th>
                                <th class="sortable" data-column="brand_model">
                                    Brand/Model
                                    <i class="fas fa-sort sort-icon"></i>
                                </th>
                                <th class="sortable" data-column="assigned_user_name">
                                    Assigned To
                                    <i class="fas fa-sort sort-icon"></i>
                                </th>
                                <th class="sortable" data-column="department">
                                    Department
                                    <i class="fas fa-sort sort-icon"></i>
                                </th>
                                <th class="sortable" data-column="status">
                                    Status
                                    <i class="fas fa-sort sort-icon"></i>
                                </th>
                                <th class="sortable" data-column="purchase_date">
                                    Purchase Date
                                    <i class="fas fa-sort sort-icon"></i>
                                </th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="assetsTableBody">
                            <?php foreach ($assets as $asset):
                                // Normalize status to prevent empty values
                                $display_status = trim($asset['status']) ?: 'Available';
                                $status_class = strtolower(str_replace(' ', '-', $display_status));
                                $status_data_attr = strtolower(str_replace(' ', '_', $display_status));
                            ?>
                                <tr data-category="<?php echo htmlspecialchars($asset['category']); ?>"
                                    data-status="<?php echo htmlspecialchars($status_data_attr); ?>"
                                    data-department="<?php echo htmlspecialchars($asset['department'] ?: ''); ?>"
                                    data-search="<?php echo htmlspecialchars(strtolower($asset['asset_code'] . ' ' . $asset['asset_name'] . ' ' . $asset['brand'] . ' ' . $asset['model'] . ' ' . $asset['serial_number'] . ' ' . $asset['assigned_user_name'])); ?>">
                                    <td data-label="Asset Code">
                                        <a href="assetDetails.php?id=<?php echo $asset['id']; ?>" class="asset-code-link">
                                            <?php echo htmlspecialchars($asset['asset_code']); ?>
                                        </a>
                                    </td>
                                    <td data-label="Asset Name"><?php echo htmlspecialchars($asset['asset_name']); ?></td>
                                    <td data-label="Category"><?php echo htmlspecialchars($asset['category']); ?></td>
                                    <td data-label="Brand/Model">
                                        <?php
                                        $brandModel = trim($asset['brand'] . ' ' . $asset['model']);
                                        echo htmlspecialchars($brandModel ?: '-');
                                        ?>
                                    </td>
                                    <td data-label="Assigned To">
                                        <?php if ($asset['assigned_user_id']): ?>
                                            <div class="user-info">
                                                <span class="user-name"><?php echo htmlspecialchars($asset['assigned_user_name']); ?></span>
                                                <span class="user-email">@<?php echo htmlspecialchars($asset['assigned_username']); ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span class="unassigned">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Department"><?php echo htmlspecialchars($asset['department'] ?: '-'); ?></td>
                                    <td data-label="Status">
                                        <span class="status-badge status-<?php echo $status_class; ?>">
                                            <?php echo htmlspecialchars($display_status); ?>
                                        </span>
                                    </td>
                                    <td data-label="Purchase Date"><?php echo $asset['purchase_date'] ? date('M j, Y', strtotime($asset['purchase_date'])) : '-'; ?></td>
                                    <td data-label="Actions">
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

                <!-- No Results Message (hidden by default) -->
                <div class="no-results" id="noResults" style="display: none;">
                    <div class="no-results-icon">🔍</div>
                    <h3>No Assets Found</h3>
                    <p>No assets match your search criteria. Try adjusting your filters.</p>
                </div>
            <?php endif; ?>
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
                        <span class="help-text">User will receive an email notification if assigned.</span>
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
            observer.observe(sidebar, {
                attributes: true,
                attributeFilter: ['class']
            });
        }

        // Toggle form visibility
        const toggleFormBtn = document.getElementById('toggleFormBtn');
        const assetForm = document.getElementById('assetForm');
        const cancelBtn = document.getElementById('cancelBtn');

        toggleFormBtn.addEventListener('click', function() {
            assetForm.classList.toggle('active');
            if (assetForm.classList.contains('active')) {
                this.innerHTML = '<i class="fas fa-times"></i> Close Form';
                assetForm.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
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

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (assignModal.classList.contains('active')) {
                    closeModal();
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

        // Search and Filter Functionality
        (function() {
            const searchInput = document.getElementById('searchInput');
            const categoryFilter = document.getElementById('categoryFilter');
            const statusFilter = document.getElementById('statusFilter');
            const departmentFilter = document.getElementById('departmentFilter');
            const resetFiltersBtn = document.getElementById('resetFilters');
            const tableBody = document.getElementById('assetsTableBody');
            const resultsCount = document.getElementById('resultsCount');
            const activeFilters = document.getElementById('activeFilters');
            const noResults = document.getElementById('noResults');
            const table = document.getElementById('assetsTable');

            const rows = Array.from(tableBody.querySelectorAll('tr'));
            const totalAssets = rows.length;

            function filterTable() {
                const searchTerm = searchInput.value.toLowerCase().trim();
                const categoryValue = categoryFilter.value;
                const statusValue = statusFilter.value;
                const departmentValue = departmentFilter.value;

                let visibleCount = 0;
                const activeFiltersList = [];

                rows.forEach(row => {
                    const searchData = row.getAttribute('data-search');
                    const category = row.getAttribute('data-category');
                    const status = row.getAttribute('data-status') ? row.getAttribute('data-status').trim() : '';
                    const department = row.getAttribute('data-department');

                    const matchesSearch = searchData.includes(searchTerm);
                    const matchesCategory = !categoryValue || category === categoryValue;
                    const matchesStatus = !statusValue || status === statusValue;
                    const matchesDepartment = !departmentValue || department === departmentValue;

                    if (matchesSearch && matchesCategory && matchesStatus && matchesDepartment) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                resultsCount.textContent = visibleCount;

                if (visibleCount === 0) {
                    noResults.style.display = 'block';
                    table.style.display = 'none';
                } else {
                    noResults.style.display = 'none';
                    table.style.display = 'table';
                }

                if (searchTerm) activeFiltersList.push(`Search: "${searchTerm}"`);
                if (categoryValue) activeFiltersList.push(`Category: ${categoryValue}`);
                if (statusValue) activeFiltersList.push(`Status: ${statusValue}`);
                if (departmentValue) activeFiltersList.push(`Department: ${departmentValue}`);

                if (activeFiltersList.length > 0) {
                    activeFilters.innerHTML = '<i class="fas fa-filter"></i> Active filters: ' + activeFiltersList.join(', ');
                } else {
                    activeFilters.textContent = '';
                }
            }

            searchInput.addEventListener('input', filterTable);
            categoryFilter.addEventListener('change', filterTable);
            statusFilter.addEventListener('change', filterTable);
            departmentFilter.addEventListener('change', filterTable);

            resetFiltersBtn.addEventListener('click', function() {
                searchInput.value = '';
                categoryFilter.value = '';
                statusFilter.value = '';
                departmentFilter.value = '';
                filterTable();
            });

            // Table Sorting
            let currentSort = {
                column: null,
                direction: 'asc'
            };

            const sortableHeaders = document.querySelectorAll('.sortable');

            sortableHeaders.forEach(header => {
                header.addEventListener('click', function() {
                    const column = this.getAttribute('data-column');

                    if (currentSort.column === column) {
                        currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
                    } else {
                        currentSort.column = column;
                        currentSort.direction = 'asc';
                    }

                    sortableHeaders.forEach(h => {
                        h.classList.remove('asc', 'desc');
                        h.querySelector('.sort-icon').className = 'fas fa-sort sort-icon';
                    });

                    this.classList.add(currentSort.direction);
                    const icon = this.querySelector('.sort-icon');
                    icon.className = currentSort.direction === 'asc' ?
                        'fas fa-sort-up sort-icon' :
                        'fas fa-sort-down sort-icon';

                    sortTable(column, currentSort.direction);
                });
            });

            function sortTable(column, direction) {
                const visibleRows = rows.filter(row => row.style.display !== 'none');

                visibleRows.sort((a, b) => {
                    let aValue, bValue;

                    switch (column) {
                        case 'asset_code':
                            aValue = a.querySelector('td:nth-child(1)').textContent.trim();
                            bValue = b.querySelector('td:nth-child(1)').textContent.trim();
                            break;
                        case 'asset_name':
                            aValue = a.querySelector('td:nth-child(2)').textContent.trim();
                            bValue = b.querySelector('td:nth-child(2)').textContent.trim();
                            break;
                        case 'category':
                            aValue = a.getAttribute('data-category');
                            bValue = b.getAttribute('data-category');
                            break;
                        case 'brand_model':
                            aValue = a.querySelector('td:nth-child(4)').textContent.trim();
                            bValue = b.querySelector('td:nth-child(4)').textContent.trim();
                            break;
                        case 'assigned_user_name':
                            aValue = a.querySelector('td:nth-child(5)').textContent.trim().toLowerCase();
                            bValue = b.querySelector('td:nth-child(5)').textContent.trim().toLowerCase();
                            break;
                        case 'department':
                            aValue = a.getAttribute('data-department') || '';
                            bValue = b.getAttribute('data-department') || '';
                            break;
                        case 'status':
                            aValue = a.getAttribute('data-status');
                            bValue = b.getAttribute('data-status');
                            break;
                        case 'purchase_date':
                            aValue = a.querySelector('td:nth-child(8)').textContent.trim();
                            bValue = b.querySelector('td:nth-child(8)').textContent.trim();
                            aValue = aValue === '-' ? new Date(0) : new Date(aValue);
                            bValue = bValue === '-' ? new Date(0) : new Date(bValue);
                            break;
                        default:
                            return 0;
                    }

                    if (!aValue || aValue === '-') aValue = '';
                    if (!bValue || bValue === '-') bValue = '';

                    if (column === 'purchase_date') {
                        return direction === 'asc' ? aValue - bValue : bValue - aValue;
                    } else {
                        if (aValue < bValue) return direction === 'asc' ? -1 : 1;
                        if (aValue > bValue) return direction === 'asc' ? 1 : -1;
                        return 0;
                    }
                });

                visibleRows.forEach(row => tableBody.appendChild(row));

                rows.filter(row => row.style.display === 'none').forEach(row => {
                    tableBody.appendChild(row);
                });
            }
        })();
        // Toggle alert card collapse/expand
        function toggleAlert(type) {
            const content = document.getElementById(type + '-alerts');
            const button = event.currentTarget;

            content.classList.toggle('collapsed');
            button.classList.toggle('rotated');
        }

        // Auto-collapse alerts if there are more than 5 items
        document.addEventListener('DOMContentLoaded', function() {
            const alertContents = document.querySelectorAll('.alert-content');

            alertContents.forEach(content => {
                const itemCount = content.querySelectorAll('.alert-item').length;

                // If there are more than 5 items, start collapsed
                if (itemCount > 5) {
                    content.classList.add('collapsed');
                    const cardHeader = content.previousElementSibling;
                    const toggleBtn = cardHeader.querySelector('.toggle-alert-btn');
                    if (toggleBtn) {
                        toggleBtn.classList.add('rotated');
                    }
                }
            });
        });
    </script>
</body>

</html>