<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Include database configuration
include("../auth/config/database.php");

$error_message = '';
$success_message = '';
$user_id = $_SESSION['user_id'];

// Fetch current user data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        header("Location: login.php");
        exit();
    }
} catch (PDOException $e) {
    $error_message = "Error fetching user data: " . $e->getMessage();
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'update_profile') {
        try {
            $first_name = trim($_POST['firstName']);
            $last_name = trim($_POST['lastName']);
            $email = trim($_POST['email']);
            $phone = !empty($_POST['phone']) ? trim($_POST['phone']) : null;
            $department = $_POST['department'];
            $employee_id = !empty($_POST['employeeId']) ? trim($_POST['employeeId']) : null;
            
            $errors = [];
            
            if (empty($first_name)) $errors[] = "First name is required";
            if (empty($last_name)) $errors[] = "Last name is required";
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required";
            if (empty($department)) $errors[] = "Department is required";
            
            // Check if email or employee_id is taken by another user
            if (empty($errors)) {
                $check_stmt = $pdo->prepare("SELECT user_id FROM users WHERE (email = ? OR (employee_id IS NOT NULL AND employee_id = ?)) AND user_id != ?");
                $check_stmt->execute([$email, $employee_id, $user_id]);
                if ($check_stmt->rowCount() > 0) {
                    $errors[] = "Email or Employee ID already exists";
                }
            }
            
            if (empty($errors)) {
                $update_stmt = $pdo->prepare("
                    UPDATE users 
                    SET first_name = ?, last_name = ?, email = ?, phone = ?, 
                        department = ?, employee_id = ?, updated_at = NOW()
                    WHERE user_id = ?
                ");
                
                if ($update_stmt->execute([$first_name, $last_name, $email, $phone, $department, $employee_id, $user_id])) {
                    $success_message = "Profile updated successfully!";
                    // Refresh user data
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $errors[] = "Failed to update profile";
                }
            }
            
            if (!empty($errors)) {
                $error_message = implode("<br>", $errors);
            }
            
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
    
    // Handle password change
    if ($_POST['action'] === 'change_password') {
        try {
            $current_password = $_POST['currentPassword'];
            $new_password = $_POST['newPassword'];
            $confirm_password = $_POST['confirmPassword'];
            
            $errors = [];
            
            // Verify current password
            if (!password_verify($current_password, $user['password_hash'])) {
                $errors[] = "Current password is incorrect";
            }
            
            if (strlen($new_password) < 8) {
                $errors[] = "New password must be at least 8 characters";
            }
            
            if ($new_password !== $confirm_password) {
                $errors[] = "New passwords do not match";
            }
            
            if (empty($errors)) {
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
                
                if ($update_stmt->execute([$new_password_hash, $user_id])) {
                    $success_message = "Password changed successfully!";
                } else {
                    $errors[] = "Failed to change password";
                }
            }
            
            if (!empty($errors)) {
                $error_message = implode("<br>", $errors);
            }
            
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Asset Management System - User Settings</title>
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
            min-height: 100vh;
            color: #2d3748;
        }

        /* Main Layout */
        .main-container {
            margin-left: 260px;
            padding: 30px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }

        .main-container.sidebar-collapsed {
            margin-left: 80px;
        }

        /* Page Header */
        .page-header {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-header h1 i {
            color: #7c3aed;
        }

        .page-header p {
            color: #718096;
            font-size: 15px;
        }

        /* Messages */
        .success-message {
            background: linear-gradient(135deg, #d4f4dd 0%, #c3e6cb 100%);
            color: #155724;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            border-left: 4px solid #28a745;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
        }

        .error-message-box {
            background: linear-gradient(135deg, #ffe6e6 0%, #ffd4d4 100%);
            color: #721c24;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            border-left: 4px solid #dc3545;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
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

        /* Card Container */
        .settings-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            overflow: hidden;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 2px solid #e2e8f0;
            background: #fafbfc;
            padding: 0 30px;
            gap: 8px;
        }

        .tab {
            padding: 18px 28px;
            background: none;
            border: none;
            color: #718096;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            position: relative;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tab:hover {
            color: #7c3aed;
            background: rgba(124, 58, 237, 0.05);
        }

        .tab.active {
            color: #7c3aed;
            border-bottom-color: #7c3aed;
            background: white;
        }

        .tab i {
            font-size: 16px;
        }

        /* Tab Content */
        .tab-content {
            display: none;
            padding: 40px;
            animation: fadeIn 0.4s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 24px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        label {
            display: block;
            margin-bottom: 10px;
            color: #2d3748;
            font-weight: 600;
            font-size: 14px;
            letter-spacing: 0.2px;
        }

        input, select {
            width: 100%;
            padding: 13px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
            font-family: inherit;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

        input:disabled {
            background: #f7fafc;
            cursor: not-allowed;
            color: #a0aec0;
        }

        small {
            display: block;
            margin-top: 6px;
            color: #718096;
            font-size: 13px;
        }

        /* Password Container */
        .password-container {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            user-select: none;
            font-size: 18px;
            color: #a0aec0;
            transition: color 0.2s;
        }

        .password-toggle:hover {
            color: #7c3aed;
        }

        /* Buttons */
        .btn-primary {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            border: none;
            padding: 14px 32px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            margin-top: 12px;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(124, 58, 237, 0.4);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: white;
            color: #7c3aed;
            border: 2px solid #7c3aed;
            padding: 14px 32px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 12px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-secondary:hover {
            background: #f7f4fe;
            transform: translateY(-2px);
        }

        /* Info Card */
        .info-card {
            background: linear-gradient(135deg, #f7f4fe 0%, #ede9fe 100%);
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 28px;
            border-left: 4px solid #7c3aed;
        }

        .info-card h3 {
            color: #6d28d9;
            margin-bottom: 12px;
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-card p {
            color: #4c1d95;
            font-size: 14px;
            line-height: 1.7;
        }

        /* Account Info Grid */
        .account-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-item {
            padding: 20px;
            background: linear-gradient(135deg, #fafbfc 0%, #f7fafc 100%);
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            transition: all 0.3s;
        }

        .info-item:hover {
            border-color: #cbd5e0;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .info-item label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-item .value {
            font-size: 16px;
            color: #1a202c;
            font-weight: 600;
        }

        /* Badge */
        .badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge.active {
            background: linear-gradient(135deg, #d4f4dd 0%, #c3e6cb 100%);
            color: #155724;
        }

        .badge.inactive {
            background: linear-gradient(135deg, #ffe6e6 0%, #ffd4d4 100%);
            color: #721c24;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .main-container {
                margin-left: 80px;
            }

            .main-container.sidebar-collapsed {
                margin-left: 80px;
            }
        }

        @media (max-width: 768px) {
            .main-container {
                margin-left: 0;
                padding: 20px;
            }

            .main-container.sidebar-collapsed {
                margin-left: 0;
            }

            .page-header {
                padding: 20px;
            }

            .page-header h1 {
                font-size: 24px;
            }

            .tabs {
                overflow-x: auto;
                padding: 0 15px;
                -webkit-overflow-scrolling: touch;
            }

            .tab {
                padding: 16px 20px;
                font-size: 14px;
                white-space: nowrap;
            }

            .tab-content {
                padding: 24px 20px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .account-info {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .page-header h1 {
                font-size: 20px;
            }

            .page-header p {
                font-size: 14px;
            }
        }
    </style>
    <link rel="stylesheet" href="../auth/inc/navigation.css">
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="main-container" id="mainContainer">
        <!-- Page Header -->
        <div class="page-header">
            <h1><i class="fas fa-user-cog"></i> Account Settings</h1>
            <p>Manage your profile information, security settings, and account preferences</p>
        </div>

        <!-- Messages -->
        <?php if (!empty($success_message)): ?>
        <div class="success-message" id="successMessage">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($success_message); ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
        <div class="error-message-box">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo $error_message; ?></span>
        </div>
        <?php endif; ?>

        <!-- Settings Card -->
        <div class="settings-card">
            <div class="tabs">
                <button class="tab active" onclick="switchTab('profile')">
                    <i class="fas fa-user"></i> Profile
                </button>
                <button class="tab" onclick="switchTab('security')">
                    <i class="fas fa-lock"></i> Security
                </button>
                <button class="tab" onclick="switchTab('account')">
                    <i class="fas fa-info-circle"></i> Account Info
                </button>
            </div>

            <!-- Profile Tab -->
            <div id="profile" class="tab-content active">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="firstName">First Name *</label>
                            <input type="text" id="firstName" name="firstName" 
                                   value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="lastName">Last Name *</label>
                            <input type="text" id="lastName" name="lastName" 
                                   value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input type="email" id="email" name="email" 
                               value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" 
                               value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                        <small>Username cannot be changed</small>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" 
                               value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="department">Department *</label>
                            <select id="department" name="department" required>
                                <option value="">Select Department</option>
                                <option value="IT" <?php echo ($user['department'] === 'IT') ? 'selected' : ''; ?>>IT</option>
                                <option value="HR" <?php echo ($user['department'] === 'HR') ? 'selected' : ''; ?>>Human Resources</option>
                                <option value="Finance" <?php echo ($user['department'] === 'Finance') ? 'selected' : ''; ?>>Finance</option>
                                <option value="Operations" <?php echo ($user['department'] === 'Operations') ? 'selected' : ''; ?>>Operations</option>
                                <option value="Sales" <?php echo ($user['department'] === 'Sales') ? 'selected' : ''; ?>>Sales</option>
                                <option value="Marketing" <?php echo ($user['department'] === 'Marketing') ? 'selected' : ''; ?>>Marketing</option>
                                <option value="Engineering" <?php echo ($user['department'] === 'Engineering') ? 'selected' : ''; ?>>Engineering</option>
                                <option value="Support" <?php echo ($user['department'] === 'Support') ? 'selected' : ''; ?>>Support</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="employeeId">Employee ID</label>
                            <input type="text" id="employeeId" name="employeeId" 
                                   value="<?php echo htmlspecialchars($user['employee_id'] ?? ''); ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>
            </div>

            <!-- Security Tab -->
            <div id="security" class="tab-content">
                <div class="info-card">
                    <h3><i class="fas fa-shield-alt"></i> Password Security</h3>
                    <p>Choose a strong password to keep your account secure. We recommend using at least 8 characters with a mix of letters, numbers, and symbols.</p>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-group">
                        <label for="currentPassword">Current Password *</label>
                        <div class="password-container">
                            <input type="password" id="currentPassword" name="currentPassword" required>
                            <span class="password-toggle" onclick="togglePassword('currentPassword')">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="newPassword">New Password *</label>
                        <div class="password-container">
                            <input type="password" id="newPassword" name="newPassword" required>
                            <span class="password-toggle" onclick="togglePassword('newPassword')">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                        <small>Password must be at least 8 characters</small>
                    </div>

                    <div class="form-group">
                        <label for="confirmPassword">Confirm New Password *</label>
                        <div class="password-container">
                            <input type="password" id="confirmPassword" name="confirmPassword" required>
                            <span class="password-toggle" onclick="togglePassword('confirmPassword')">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                </form>
            </div>

            <!-- Account Info Tab -->
            <div id="account" class="tab-content">
                <div class="account-info">
                    <div class="info-item">
                        <label>User ID</label>
                        <div class="value"><?php echo htmlspecialchars($user['user_id']); ?></div>
                    </div>
                    <div class="info-item">
                        <label>Username</label>
                        <div class="value"><?php echo htmlspecialchars($user['username']); ?></div>
                    </div>
                    <div class="info-item">
                        <label>Role</label>
                        <div class="value" style="text-transform: capitalize;">
                            <?php echo htmlspecialchars($user['role']); ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <label>Account Status</label>
                        <div class="value">
                            <span class="badge <?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-item">
                        <label>Member Since</label>
                        <div class="value">
                            <?php echo date('F j, Y', strtotime($user['created_at'])); ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <label>Last Updated</label>
                        <div class="value">
                            <?php echo date('F j, Y', strtotime($user['updated_at'])); ?>
                        </div>
                    </div>
                </div>

                <div class="info-card">
                    <h3><i class="fas fa-info-circle"></i> Account Information</h3>
                    <p>Your account was created on <?php echo date('F j, Y', strtotime($user['created_at'])); ?>. You are currently registered as a <?php echo htmlspecialchars($user['role']); ?> in the <?php echo htmlspecialchars($user['department']); ?> department.</p>
                </div>

                <button onclick="window.location.href='../public/dashboard.php'" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </button>
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

        // Listen for sidebar toggle (if your sidebar has a toggle button)
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

        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked tab
            event.target.closest('.tab').classList.add('active');
        }

        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const toggle = field.parentElement.querySelector('.password-toggle i');
            const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
            field.setAttribute('type', type);
            toggle.className = type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
        }

        // Auto-hide success message after 5 seconds
        <?php if (!empty($success_message)): ?>
        setTimeout(() => {
            const msg = document.getElementById('successMessage');
            if (msg) {
                msg.style.transition = 'opacity 0.5s';
                msg.style.opacity = '0';
                setTimeout(() => msg.style.display = 'none', 500);
            }
        }, 5000);
        <?php endif; ?>

        // Form validation for password change
        const securityForm = document.querySelector('#security form');
        if (securityForm) {
            securityForm.addEventListener('submit', function(e) {
                const newPassword = document.getElementById('newPassword').value;
                const confirmPassword = document.getElementById('confirmPassword').value;
                
                if (newPassword.length < 8) {
                    e.preventDefault();
                    alert('New password must be at least 8 characters long');
                    return;
                }
                
                if (newPassword !== confirmPassword) {
                    e.preventDefault();
                    alert('New passwords do not match');
                    return;
                }
            });
        }
    </script>
</body>
</html>