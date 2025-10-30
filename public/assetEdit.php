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

// Get asset ID from URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: asset.php");
    exit();
}

$asset_id = $_GET['id'];

// Handle form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_asset'])) {
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
            // Check if asset code already exists for other assets
            $check_stmt = $pdo->prepare("SELECT id FROM assets WHERE asset_code = ? AND id != ?");
            $check_stmt->execute([$asset_code, $asset_id]);

            if ($check_stmt->rowCount() > 0) {
                $error_message = "Asset Code already exists! Please use a unique code.";
            } else {
                // Get current asset data for history logging
                $current_stmt = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
                $current_stmt->execute([$asset_id]);
                $current_asset = $current_stmt->fetch(PDO::FETCH_ASSOC);

                // Update asset
                $stmt = $pdo->prepare("UPDATE assets SET 
                    asset_name = ?, 
                    asset_code = ?, 
                    category = ?, 
                    brand = ?, 
                    model = ?, 
                    serial_number = ?, 
                    purchase_date = ?, 
                    purchase_cost = ?, 
                    supplier = ?, 
                    warranty_expiry = ?, 
                    location = ?, 
                    department = ?, 
                    status = ?, 
                    description = ?, 
                    assigned_to = ?,
                    updated_at = NOW() 
                    WHERE id = ?");

                if ($stmt->execute([$asset_name, $asset_code, $category, $brand, $model, $serial_number, 
                $purchase_date, $purchase_cost, $supplier, $warranty_expiry, $location, $department, $status, $description, $assigned_to, $asset_id])) {

                    // Log assignment change if it changed
                    if ($current_asset['assigned_to'] != $assigned_to) {
                        $action = $assigned_to ? ($current_asset['assigned_to'] ? 'reassigned' : 'assigned') : 'unassigned';

                        // Check if assets_history table exists, if not use asset_history
                        try {
                            $log_stmt = $pdo->prepare("INSERT INTO assets_history (asset_id, action_type, assigned_from, assigned_to, performed_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                            $log_stmt->execute([$asset_id, $action, $current_asset['assigned_to'], $assigned_to, $_SESSION['user_id']]);
                        } catch (PDOException $e) {
                            // Try alternative table name
                            $log_stmt = $pdo->prepare("INSERT INTO asset_history (asset_id, action_type, previous_user_id, new_user_id, performed_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                            $log_stmt->execute([$asset_id, $action, $current_asset['assigned_to'], $assigned_to, $_SESSION['user_id']]);
                        }
                    }

                    $success_message = "Asset updated successfully!";
                    // Redirect after short delay
                    header("refresh:2;url=asset.php?updated=1");
                } else {
                    $error_message = "Error updating asset.";
                }
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}

// Fetch asset data
$asset = null;
try {
    $query = "SELECT a.*, 
              u.username as created_by_name,
              assigned_user.user_id as assigned_user_id,
              assigned_user.username as assigned_username,
              CONCAT(assigned_user.first_name, ' ', assigned_user.last_name) as assigned_user_name
              FROM assets a 
              LEFT JOIN users u ON a.created_by = u.user_id 
              LEFT JOIN users assigned_user ON a.assigned_to = assigned_user.user_id
              WHERE a.id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$asset_id]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$asset) {
        header("Location: asset.php?error=notfound");
        exit();
    }
} catch (PDOException $e) {
    $error_message = "Error fetching asset: " . $e->getMessage();
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

// Get asset history - try both table names
$history = [];
try {
    $history_query = "SELECT ah.*, 
                      performer.username as performer_name,
                      prev_user.username as prev_username,
                      CONCAT(prev_user.first_name, ' ', prev_user.last_name) as prev_user_name,
                      new_user.username as new_username,
                      CONCAT(new_user.first_name, ' ', new_user.last_name) as new_user_name
                      FROM assets_history ah
                      LEFT JOIN users performer ON ah.performed_by = performer.user_id
                      LEFT JOIN users prev_user ON ah.assigned_from = prev_user.user_id
                      LEFT JOIN users new_user ON ah.assigned_to = new_user.user_id
                      WHERE ah.asset_id = ?
                      ORDER BY ah.created_at DESC
                      LIMIT 10";
    $history_stmt = $pdo->prepare($history_query);
    $history_stmt->execute([$asset_id]);
    $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Try alternative table name
    try {
        $history_query = "SELECT ah.*, 
                          performer.username as performer_name,
                          prev_user.username as prev_username,
                          CONCAT(prev_user.first_name, ' ', prev_user.last_name) as prev_user_name,
                          new_user.username as new_username,
                          CONCAT(new_user.first_name, ' ', new_user.last_name) as new_user_name
                          FROM asset_history ah
                          LEFT JOIN users performer ON ah.performed_by = performer.user_id
                          LEFT JOIN users prev_user ON ah.previous_user_id = prev_user.user_id
                          LEFT JOIN users new_user ON ah.new_user_id = new_user.user_id
                          WHERE ah.asset_id = ?
                          ORDER BY ah.created_at DESC
                          LIMIT 10";
        $history_stmt = $pdo->prepare($history_query);
        $history_stmt->execute([$asset_id]);
        $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Handle error silently
    }
}

// Normalize status value for consistency
$status_value = strtolower(str_replace(' ', '_', $asset['status']));
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Asset - E-Asset</title>
    <link rel="stylesheet" href="../style/asset.css">
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <style>
        .edit-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
        }

        .page-header {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h1 {
            margin: 0;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .asset-code-badge {
            background: #3498db;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: normal;
        }

        .back-btn {
            background: #95a5a6;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            transition: background 0.3s;
        }

        .back-btn:hover {
            background: #7f8c8d;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }

        .edit-form-card {
            background: #fff;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .edit-form-card h2 {
            margin-top: 0;
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .info-sidebar {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .info-card {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .info-card h3 {
            margin-top: 0;
            color: #2c3e50;
            font-size: 1.1em;
            border-bottom: 2px solid #e74c3c;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .info-item {
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #ecf0f1;
        }

        .info-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .info-label {
            font-weight: 600;
            color: #7f8c8d;
            font-size: 0.85em;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .info-value {
            color: #2c3e50;
            font-size: 0.95em;
        }

        .history-card {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .history-card h3 {
            margin-top: 0;
            color: #2c3e50;
            font-size: 1.1em;
            border-bottom: 2px solid #f39c12;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .history-item {
            padding: 12px;
            background: #f8f9fa;
            border-radius: 5px;
            margin-bottom: 10px;
            border-left: 3px solid #3498db;
        }

        .history-action {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .history-details {
            font-size: 0.85em;
            color: #7f8c8d;
        }

        .history-time {
            font-size: 0.8em;
            color: #95a5a6;
            margin-top: 5px;
        }

        .no-history {
            text-align: center;
            padding: 20px;
            color: #95a5a6;
            font-style: italic;
        }

        @media (max-width: 968px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <!-- Include Sidebar -->
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="main-content">
        <div class="edit-container">
            <div class="page-header">
                <h1>
                    ✏️ Edit Asset
                    <span class="asset-code-badge"><?php echo htmlspecialchars($asset['asset_code']); ?></span>
                </h1>
                <a href="asset.php" class="back-btn">← Back to Inventory</a>
            </div>

            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success">
                    ✓ <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-error">
                    ✗ <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <div class="content-grid">
                <!-- Edit Form -->
                <div class="edit-form-card">
                    <h2>Asset Details</h2>
                    <form method="POST" action="">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="asset_name" class="required">Asset Name</label>
                                <input type="text" id="asset_name" name="asset_name"
                                    value="<?php echo htmlspecialchars($asset['asset_name']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="asset_code" class="required">Asset Code</label>
                                <input type="text" id="asset_code" name="asset_code"
                                    value="<?php echo htmlspecialchars($asset['asset_code']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="category" class="required">Category</label>
                                <select id="category" name="category" required>
                                    <option value="">Select Category</option>
                                    <option value="Computer" <?php echo $asset['category'] === 'Computer' ? 'selected' : ''; ?>>Computer</option>
                                    <option value="Laptop" <?php echo $asset['category'] === 'Laptop' ? 'selected' : ''; ?>>Laptop</option>
                                    <option value="Monitor" <?php echo $asset['category'] === 'Monitor' ? 'selected' : ''; ?>>Monitor</option>
                                    <option value="Printer" <?php echo $asset['category'] === 'Printer' ? 'selected' : ''; ?>>Printer</option>
                                    <option value="Mobile Device" <?php echo $asset['category'] === 'Mobile Device' ? 'selected' : ''; ?>>Mobile Device</option>
                                    <option value="Network Equipment" <?php echo $asset['category'] === 'Network Equipment' ? 'selected' : ''; ?>>Network Equipment</option>
                                    <option value="Other" <?php echo $asset['category'] === 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="brand">Brand</label>
                                <input type="text" id="brand" name="brand"
                                    value="<?php echo htmlspecialchars($asset['brand']); ?>">
                            </div>

                            <div class="form-group">
                                <label for="model">Model</label>
                                <input type="text" id="model" name="model"
                                    value="<?php echo htmlspecialchars($asset['model']); ?>">
                            </div>

                            <div class="form-group">
                                <label for="serial_number">Serial Number</label>
                                <input type="text" id="serial_number" name="serial_number"
                                    value="<?php echo htmlspecialchars($asset['serial_number']); ?>">
                            </div>

                            <div class="form-group">
                                <label for="purchase_date">Purchase Date</label>
                                <input type="date" id="purchase_date" name="purchase_date"
                                    value="<?php echo $asset['purchase_date']; ?>">
                            </div>

                            <div class="form-group">
                                <label for="purchase_cost">Purchase Cost ($)</label>
                                <input type="number" id="purchase_cost" name="purchase_cost"
                                    step="0.01" min="0"
                                    value="<?php echo $asset['purchase_cost']; ?>">
                            </div>

                            <div class="form-group">
                                <label for="supplier">Supplier</label>
                                <input type="text" id="supplier" name="supplier"
                                    value="<?php echo htmlspecialchars($asset['supplier']); ?>">
                            </div>

                            <div class="form-group">
                                <label for="warranty_expiry">Warranty Expiry</label>
                                <input type="date" id="warranty_expiry" name="warranty_expiry"
                                    value="<?php echo $asset['warranty_expiry']; ?>">
                            </div>

                            <div class="form-group">
                                <label for="location">Location</label>
                                <input type="text" id="location" name="location"
                                    value="<?php echo htmlspecialchars($asset['location']); ?>">
                            </div>

                            <div class="form-group">
                                <label for="department">Department</label>
                                <select id="department" name="department">
                                    <option value="">Select Department</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo htmlspecialchars($dept); ?>"
                                            <?php echo $asset['department'] === $dept ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="assigned_to">Assign To User</label>
                                <select id="assigned_to" name="assigned_to">
                                    <option value="">-- Leave Unassigned --</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['user_id']; ?>"
                                            <?php echo $asset['assigned_to'] == $user['user_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                            (<?php echo htmlspecialchars($user['username']); ?>)
                                            <?php if ($user['department']): ?>
                                                - <?php echo htmlspecialchars($user['department']); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="status">Status</label>
                                <select id="status" name="status">
                                    <option value="available" <?php echo $status_value === 'available' ? 'selected' : ''; ?>>Available</option>
                                    <option value="in_use" <?php echo $status_value === 'in_use' ? 'selected' : ''; ?>>In Use</option>
                                    <option value="maintenance" <?php echo $status_value === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                    <option value="retired" <?php echo $status_value === 'retired' ? 'selected' : ''; ?>>Retired</option>
                                </select>
                            </div>
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label for="description">Description</label>
                                <textarea id="description" name="description" rows="4"><?php echo htmlspecialchars($asset['description']); ?></textarea>
                            </div>
                        </div>

                        <div class="form-actions">
                            <a href="asset.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" name="update_asset" class="btn btn-primary">Update Asset</button>
                        </div>
                    </form>
                </div>

                <!-- Sidebar -->
                <div class="info-sidebar">
                    <!-- Asset Info -->
                    <div class="info-card">
                        <h3>📋 Asset Information</h3>
                        <div class="info-item">
                            <div class="info-label">Current Status</div>
                            <div class="info-value">
                                <span class="status-badge status-<?php echo $status_value; ?>">
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $status_value))); ?>
                                </span>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Currently Assigned To</div>
                            <div class="info-value">
                                <?php if ($asset['assigned_user_id']): ?>
                                    <?php echo htmlspecialchars($asset['assigned_user_name']); ?><br>
                                    <small style="color: #7f8c8d;"><?php echo htmlspecialchars($asset['assigned_username']); ?></small>
                                <?php else: ?>
                                    <span style="color: #95a5a6;">Unassigned</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Created By</div>
                            <div class="info-value"><?php echo htmlspecialchars($asset['created_by_name'] ?: 'Unknown'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Created On</div>
                            <div class="info-value"><?php echo date('M d, Y', strtotime($asset['created_at'])); ?></div>
                        </div>
                        <?php if ($asset['updated_at']): ?>
                            <div class="info-item">
                                <div class="info-label">Last Updated</div>
                                <div class="info-value"><?php echo date('M d, Y H:i', strtotime($asset['updated_at'])); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- History -->
                    <div class="history-card">
                        <h3>📜 Recent History</h3>
                        <?php if (count($history) > 0): ?>
                            <?php foreach ($history as $record): ?>
                                <div class="history-item">
                                    <div class="history-action">
                                        <?php
                                        $action_icon = [
                                            'assigned' => '✓',
                                            'unassigned' => '✗',
                                            'reassigned' => '↔'
                                        ];
                                        echo $action_icon[$record['action_type']] ?? '•';
                                        echo ' ' . ucfirst($record['action_type']);
                                        ?>
                                    </div>
                                    <div class="history-details">
                                        <?php if ($record['action_type'] === 'assigned'): ?>
                                            Assigned to <strong><?php echo htmlspecialchars($record['new_user_name']); ?></strong>
                                        <?php elseif ($record['action_type'] === 'unassigned'): ?>
                                            Unassigned from <strong><?php echo htmlspecialchars($record['prev_user_name']); ?></strong>
                                        <?php elseif ($record['action_type'] === 'reassigned'): ?>
                                            From <strong><?php echo htmlspecialchars($record['prev_user_name']); ?></strong>
                                            to <strong><?php echo htmlspecialchars($record['new_user_name']); ?></strong>
                                        <?php endif; ?>
                                        <br>
                                        By: <?php echo htmlspecialchars($record['performer_name']); ?>
                                    </div>
                                    <div class="history-time">
                                        <?php echo date('M d, Y H:i', strtotime($record['created_at'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-history">No history records available</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-update status when assigning user
        const assignedToSelect = document.getElementById('assigned_to');
        const statusSelect = document.getElementById('status');

        assignedToSelect.addEventListener('change', function() {
            if (this.value) {
                // If assigning to someone and current status is available
                if (statusSelect.value === 'available') {
                    statusSelect.value = 'in_use';
                }
            } else {
                // If unassigning and current status is in_use
                if (statusSelect.value === 'in_use') {
                    statusSelect.value = 'available';
                }
            }
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const assetName = document.getElementById('asset_name').value.trim();
            const assetCode = document.getElementById('asset_code').value.trim();
            const category = document.getElementById('category').value;

            if (!assetName || !assetCode || !category) {
                e.preventDefault();
                alert('Please fill in all required fields: Asset Name, Asset Code, and Category');
                return false;
            }
        });
    </script>
</body>

</html>