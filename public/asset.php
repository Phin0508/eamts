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

// Include database connection - SAME AS SIGNUP.PHP
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
                    header("Location: asset.php?success=1");
                    exit();
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
            $log_stmt = $pdo->prepare("INSERT INTO asset_history (asset_id, action_type, previous_user_id, new_user_id, performed_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $log_stmt->execute([$asset_id, $action, $current_user_id, $assigned_to, $_SESSION['user_id']]);

            header("Location: asset.php?assigned=1");
            exit();
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
?>

<!-- Your HTML continues here exactly as before -->

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - E-Asset</title>
    <link rel="stylesheet" href="../style/asset.css">
    <style>

    </style>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
</head>

<body>

    <!-- Include Sidebar -->
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="main-content">
        <div class="inventory-container">
            <div class="inventory-header">
                <h1>📦 Inventory Management</h1>
                <button class="btn btn-primary" id="toggleFormBtn">
                    <span id="formBtnText">+ Add New Asset</span>
                </button>
            </div>

            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    ✓ Asset added successfully!
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['assigned'])): ?>
                <div class="alert alert-success">
                    ✓ Asset assignment updated successfully!
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-error">
                    ✗ <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- Add Asset Form -->
            <div class="form-card" id="assetForm">
                <h2>Add New Asset</h2>
                <form method="POST" action="">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="asset_name" class="required">Asset Name</label>
                            <input type="text" id="asset_name" name="asset_name" required>
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
                            <input type="text" id="serial_number" name="serial_number">
                        </div>

                        <div class="form-group">
                            <label for="purchase_date">Purchase Date</label>
                            <input type="date" id="purchase_date" name="purchase_date">
                        </div>

                        <div class="form-group">
                            <label for="purchase_cost">Purchase Cost ($)</label>
                            <input type="number" id="purchase_cost" name="purchase_cost" step="0.01" min="0">
                        </div>

                        <div class="form-group">
                            <label for="supplier">Supplier</label>
                            <input type="text" id="supplier" name="supplier">
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

                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="Available">Available</option>
                                <option value="In Use">In Use</option>
                                <option value="Maintenance">Maintenance</option>
                                <option value="Retired">Retired</option>
                            </select>
                        </div>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" placeholder="Additional notes or specifications..."></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" id="cancelBtn">Cancel</button>
                        <button type="submit" name="add_asset" class="btn btn-primary">Add Asset</button>
                    </div>
                </form>
            </div>

            <!-- Assets Table -->
            <div class="assets-table">
                <h2>Asset Inventory</h2>
                <?php if (count($assets) > 0): ?>
                    <table>
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
                                            <strong><?php echo htmlspecialchars($asset['asset_code']); ?></strong>
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
                                                <span class="user-email"><?php echo htmlspecialchars($asset['assigned_username']); ?></span>
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
                                    <td><?php echo $asset['purchase_date'] ? date('Y-m-d', strtotime($asset['purchase_date'])) : '-'; ?></td>
                                    <td class="action-buttons">
                                        <button class="btn btn-success btn-small assign-btn"
                                            data-asset-id="<?php echo $asset['id']; ?>"
                                            data-asset-name="<?php echo htmlspecialchars($asset['asset_name']); ?>"
                                            data-current-user="<?php echo $asset['assigned_user_id'] ?: ''; ?>">
                                            <?php echo $asset['assigned_user_id'] ? 'Reassign' : 'Assign'; ?>
                                        </button>
                                        <a href="assetEdit.php?id=<?php echo $asset['id']; ?>" class="btn btn-primary btn-small">Edit</a>
                                        <a href="assetHistory.php?id=<?php echo $asset['id']; ?>" class="btn btn-secondary btn-small">History</a>
                                    </td>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <p>No assets found in inventory. Add your first asset to get started!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Assignment Modal -->
    <div id="assignModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-close">&times;</span>
                <h3>Assign Asset to User</h3>
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
                    <button type="button" class="btn btn-secondary modal-cancel">Cancel</button>
                    <button type="submit" name="assign_asset" class="btn btn-success">Update Assignment</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Toggle form visibility
        const toggleFormBtn = document.getElementById('toggleFormBtn');
        const assetForm = document.getElementById('assetForm');
        const cancelBtn = document.getElementById('cancelBtn');
        const formBtnText = document.getElementById('formBtnText');

        toggleFormBtn.addEventListener('click', function() {
            assetForm.classList.toggle('active');
            if (assetForm.classList.contains('active')) {
                formBtnText.textContent = '✕ Close Form';
                assetForm.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            } else {
                formBtnText.textContent = '+ Add New Asset';
            }
        });

        cancelBtn.addEventListener('click', function() {
            assetForm.classList.remove('active');
            formBtnText.textContent = '+ Add New Asset';
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
            });
        });

        modalClose.addEventListener('click', function() {
            assignModal.classList.remove('active');
        });

        modalCancel.addEventListener('click', function() {
            assignModal.classList.remove('active');
        });

        window.addEventListener('click', function(event) {
            if (event.target === assignModal) {
                assignModal.classList.remove('active');
            }
        });
    </script>
</body>

</html>