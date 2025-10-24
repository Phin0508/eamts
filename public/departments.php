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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
        }

        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }

        .btn-primary {
            background: white;
            color: #667eea;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-info {
            background: #3b82f6;
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            padding: 30px;
            background: #f9fafb;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .stat-card h3 {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .number {
            font-size: 36px;
            font-weight: bold;
            color: #667eea;
        }

        .stat-card .label {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 5px;
        }

        .message {
            padding: 15px;
            margin: 20px 30px;
            border-radius: 6px;
            font-size: 14px;
        }

        .success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #10b981;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #ef4444;
        }

        .departments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            padding: 30px;
        }

        .department-card {
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 25px;
            transition: all 0.3s;
            position: relative;
        }

        .department-card:hover {
            border-color: #667eea;
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.15);
            transform: translateY(-2px);
        }

        .department-card.inactive {
            opacity: 0.6;
            background: #f9fafb;
        }

        .dept-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .dept-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 15px;
        }

        .dept-title {
            flex: 1;
        }

        .dept-title h3 {
            font-size: 20px;
            color: #111827;
            margin-bottom: 5px;
        }

        .dept-code {
            font-size: 12px;
            color: #6b7280;
            font-weight: 600;
            background: #f3f4f6;
            padding: 4px 10px;
            border-radius: 4px;
            display: inline-block;
        }

        .dept-description {
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
            margin: 15px 0;
            min-height: 40px;
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

        .dept-info-item strong {
            color: #374151;
            min-width: 80px;
        }

        .dept-info-item span {
            color: #6b7280;
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
            font-weight: bold;
            color: #667eea;
        }

        .dept-stat-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
        }

        .dept-actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-active {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            overflow-y: auto;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 12px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            margin: 20px;
        }

        .modal-header {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 20px;
            color: #111827;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 25px;
            justify-content: flex-end;
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #6b7280;
        }

        .empty-state svg {
            width: 100px;
            height: 100px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            .departments-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .header-actions {
                flex-direction: column;
                gap: 10px;
                align-items: stretch;
            }
        }
    </style>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>
    <div class="container">
        <div class="header">
            <h1>🏢 Department Management</h1>
            <p>Manage organizational departments and structure</p>
            <div class="header-actions">
                <div>
                    <span style="opacity: 0.9;">Welcome, <?php echo htmlspecialchars($_SESSION['first_name']); ?></span>
                </div>
                <div style="display: flex; gap: 10px;">
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                    <button onclick="openAddModal()" class="btn btn-primary">+ Add New Department</button>
                    <?php endif; ?>
                    <a href="dashboard.php" class="btn btn-primary">← Back to Dashboard</a>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Departments</h3>
                <div class="number"><?php echo $stats['total_departments']; ?></div>
                <div class="label">Across organization</div>
            </div>
            <div class="stat-card">
                <h3>Active Departments</h3>
                <div class="number" style="color: #10b981;"><?php echo $stats['active_departments']; ?></div>
                <div class="label">Currently operational</div>
            </div>
            <div class="stat-card">
                <h3>Total Employees</h3>
                <div class="number" style="color: #3b82f6;"><?php echo $total_employees; ?></div>
                <div class="label">Across all departments</div>
            </div>
            <div class="stat-card">
                <h3>Total Budget</h3>
                <div class="number" style="color: #f59e0b;">$<?php echo number_format($stats['total_budget'] ?? 0, 0); ?></div>
                <div class="label">Combined budget allocation</div>
            </div>
        </div>

        <!-- Messages -->
        <?php if (!empty($success_message)): ?>
        <div class="message success">
            ✓ <?php echo htmlspecialchars($success_message); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
        <div class="message error">
            ✗ <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>

        <!-- Departments Grid -->
        <div class="departments-grid">
            <?php if (count($departments) > 0): ?>
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
                            <strong>👤 Manager:</strong>
                            <span><?php echo $dept['manager_name'] ? htmlspecialchars($dept['manager_name']) : 'Not assigned'; ?></span>
                        </div>
                        <div class="dept-info-item">
                            <strong>📍 Location:</strong>
                            <span><?php echo htmlspecialchars($dept['location'] ?: 'Not specified'); ?></span>
                        </div>
                        <div class="dept-info-item">
                            <strong>📅 Created:</strong>
                            <span><?php echo date('M d, Y', strtotime($dept['created_at'])); ?></span>
                        </div>
                    </div>

                    <?php if ($_SESSION['role'] === 'admin'): ?>
                    <div class="dept-actions">
                        <button onclick='openEditModal(<?php echo json_encode($dept); ?>)' class="btn btn-sm btn-info">
                            Edit
                        </button>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="dept_id" value="<?php echo $dept['dept_id']; ?>">
                            <input type="hidden" name="new_status" value="<?php echo $dept['is_active'] ? 0 : 1; ?>">
                            <button type="submit" class="btn btn-sm <?php echo $dept['is_active'] ? 'btn-warning' : 'btn-success'; ?>">
                                <?php echo $dept['is_active'] ? 'Deactivate' : 'Activate'; ?>
                            </button>
                        </form>
                        <button onclick="confirmDelete(<?php echo $dept['dept_id']; ?>, '<?php echo htmlspecialchars($dept['dept_name']); ?>', <?php echo $dept['employee_count']; ?>)" 
                                class="btn btn-sm btn-danger">
                            Delete
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
            <div class="empty-state">
                <svg fill="currentColor" viewBox="0 0 20 20">
                    <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/>
                </svg>
                <h3>No departments found</h3>
                <p>Start by creating your first department</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add/Edit Department Modal -->
    <div id="departmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle">Add New Department</div>
            <form method="POST" id="departmentForm">
                <input type="hidden" name="action" id="formAction" value="add_department">
                <input type="hidden" name="dept_id" id="deptId">

                <div class="form-group">
                    <label for="dept_name">Department Name *</label>
                    <input type="text" id="dept_name" name="dept_name" required>
                </div>

                <div class="form-group">
                    <label for="dept_code">Department Code *</label>
                    <input type="text" id="dept_code" name="dept_code" placeholder="e.g., IT, HR, FIN" required>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" placeholder="Brief description of the department"></textarea>
                </div>

                <div class="form-group">
                    <label for="manager_id">Department Manager</label>
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
                    <label for="location">Location</label>
                    <input type="text" id="location" name="location" placeholder="e.g., Building A, Floor 3">
                </div>

                <div class="form-group">
                    <label for="budget">Annual Budget ($)</label>
                    <input type="number" id="budget" name="budget" min="0" step="1000" placeholder="0">
                </div>

                <div class="modal-buttons">
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-success" id="submitBtn">Add Department</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">Confirm Delete</div>
            <p>Are you sure you want to delete <strong id="deleteDeptName"></strong>?</p>
            <p id="deleteWarning" style="color: #ef4444; font-size: 14px; margin-top: 10px;"></p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="action" value="delete_department">
                <input type="hidden" name="dept_id" id="deleteDeptId">
                <div class="modal-buttons">
                    <button type="button" class="btn" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Department</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add New Department';
            document.getElementById('formAction').value = 'add_department';
            document.getElementById('submitBtn').textContent = 'Add Department';
            document.getElementById('departmentForm').reset();
            document.getElementById('departmentModal').classList.add('active');
        }

        function openEditModal(dept) {
            document.getElementById('modalTitle').textContent = 'Edit Department';
            document.getElementById('formAction').value = 'update_department';
            document.getElementById('submitBtn').textContent = 'Update Department';
            document.getElementById('deptId').value = dept.dept_id;
            document.getElementById('dept_name').value = dept.dept_name;
            document.getElementById('dept_code').value = dept.dept_code;
            document.getElementById('description').value = dept.description || '';
            document.getElementById('manager_id').value = dept.manager_id || '';
            document.getElementById('location').value = dept.location || '';
            document.getElementById('budget').value = dept.budget || '';
            document.getElementById('departmentModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('departmentModal').classList.remove('active');
        }

        function confirmDelete(deptId, deptName, employeeCount) {
            document.getElementById('deleteDeptId').value = deptId;
            document.getElementById('deleteDeptName').textContent = deptName;
            
            const warning = document.getElementById('deleteWarning');
            if (employeeCount > 0) {
                warning.textContent = `⚠️ This department has ${employeeCount} employee(s). Please reassign them before deleting.`;
                document.getElementById('deleteForm').querySelector('button[type="submit"]').disabled = true;
            } else {
                warning.textContent = 'This action cannot be undone.';
                document.getElementById('deleteForm').querySelector('button[type="submit"]').disabled = false;
            }
            
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }

        // Close modals when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });

        // Auto-hide messages after 5 seconds
         setTimeout(() => {
            const messages = document.querySelectorAll('.message');
            messages.forEach(msg => {
                msg.style.transition = 'opacity 0.5s';
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>