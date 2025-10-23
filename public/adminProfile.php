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
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px;
            color: white;
            text-align: center;
        }

        .header h1 {
            font-size: 28px;
            margin-bottom: 5px;
        }

        .header p {
            opacity: 0.9;
            font-size: 14px;
        }

        .content {
            padding: 40px;
        }

        .tabs {
            display: flex;
            border-bottom: 2px solid #e0e0e0;
            margin-bottom: 30px;
            gap: 10px;
        }

        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
        }

        .tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }

        .tab:hover {
            color: #667eea;
        }

        .tab-content {
            display: none;
            animation: fadeIn 0.3s;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }

        input, select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        input:disabled {
            background-color: #f5f5f5;
            cursor: not-allowed;
            color: #999;
        }

        .password-container {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            user-select: none;
            font-size: 20px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            margin-top: 10px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }

        .btn-secondary {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
            padding: 14px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }

        .btn-secondary:hover {
            background: #f8f9ff;
        }

        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }

        .error-message-box {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }

        .info-card {
            background: #f8f9ff;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }

        .info-card h3 {
            color: #667eea;
            margin-bottom: 10px;
            font-size: 16px;
        }

        .info-card p {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }

        .account-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-item {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .info-item label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }

        .info-item .value {
            font-size: 16px;
            color: #333;
            font-weight: 500;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge.active {
            background: #d4edda;
            color: #155724;
        }

        .badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .back-button {
            position: absolute;
            top: 30px;
            left: 30px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .back-button:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateX(-3px);
        }

        @media (max-width: 768px) {
            .form-row, .account-info {
                grid-template-columns: 1fr;
            }

            .content {
                padding: 20px;
            }

            .tabs {
                overflow-x: auto;
            }

            .back-button {
                position: static;
                margin-bottom: 20px;
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="../public/dashboard.php" class="back-button">← Back to Homepage</a>
            <h1>Account Settings</h1>
            <p>Manage your profile and account preferences</p>
        </div>

        <div class="content">
            <?php if (!empty($success_message)): ?>
            <div class="success-message" id="successMessage">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
            <div class="error-message-box">
                <?php echo $error_message; ?>
            </div>
            <?php endif; ?>

            <div class="tabs">
                <button class="tab active" onclick="switchTab('profile')">Profile</button>
                <button class="tab" onclick="switchTab('security')">Security</button>
                <button class="tab" onclick="switchTab('account')">Account Info</button>
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
                        <small style="color: #666; font-size: 12px;">Username cannot be changed</small>
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

                    <button type="submit" class="btn-primary">Save Changes</button>
                </form>
            </div>

            <!-- Security Tab -->
            <div id="security" class="tab-content">
                <div class="info-card">
                    <h3>🔒 Password Security</h3>
                    <p>Choose a strong password to keep your account secure. We recommend using at least 8 characters with a mix of letters, numbers, and symbols.</p>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-group">
                        <label for="currentPassword">Current Password *</label>
                        <div class="password-container">
                            <input type="password" id="currentPassword" name="currentPassword" required>
                            <span class="password-toggle" onclick="togglePassword('currentPassword')">👁</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="newPassword">New Password *</label>
                        <div class="password-container">
                            <input type="password" id="newPassword" name="newPassword" required>
                            <span class="password-toggle" onclick="togglePassword('newPassword')">👁</span>
                        </div>
                        <small style="color: #666; font-size: 12px;">Password must be at least 8 characters</small>
                    </div>

                    <div class="form-group">
                        <label for="confirmPassword">Confirm New Password *</label>
                        <div class="password-container">
                            <input type="password" id="confirmPassword" name="confirmPassword" required>
                            <span class="password-toggle" onclick="togglePassword('confirmPassword')">👁</span>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">Change Password</button>
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
                    <h3>ℹ️ Account Information</h3>
                    <p>Your account was created on <?php echo date('F j, Y', strtotime($user['created_at'])); ?>. You are currently registered as a <?php echo htmlspecialchars($user['role']); ?> in the <?php echo htmlspecialchars($user['department']); ?> department.</p>
                </div>

                <button onclick="window.location.href='../public/dashboard.php'" class="btn-secondary" style="width: auto;">
                    ← Back to Dashboard
                </button>
            </div>
        </div>
    </div>

    <script>
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
            event.target.classList.add('active');
        }

        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const toggle = field.nextElementSibling;
            const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
            field.setAttribute('type', type);
            toggle.textContent = type === 'password' ? '👁' : '🙈';
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
        document.querySelector('#security form').addEventListener('submit', function(e) {
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
    </script>
</body>
</html>