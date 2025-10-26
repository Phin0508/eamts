<?php
session_start();

// Restrict access to admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

require_once '../auth/config/database.php';
require_once '../auth/helpers/EmailHelper.php';

$error_message = '';
$success_message = '';

// Fetch active departments
$departments_list = [];
try {
    $dept_query = $pdo->query("SELECT dept_id, dept_name, dept_code FROM departments WHERE is_active = 1 ORDER BY dept_name ASC");
    $departments_list = $dept_query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments_list = [
        ['dept_name' => 'IT', 'dept_code' => 'IT'],
        ['dept_name' => 'Human Resources', 'dept_code' => 'HR'],
        ['dept_name' => 'Finance', 'dept_code' => 'FIN'],
    ];
}

/**
 * Generate username from first and last name
 */
function generateUsername($firstName, $lastName, $pdo) {
    $baseUsername = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName . $lastName));
    
    if (strlen($baseUsername) < 3) {
        $baseUsername = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName . substr($lastName, 0, 3)));
    }
    
    $username = $baseUsername;
    $counter = 1;
    
    while (usernameExists($username, $pdo)) {
        $username = $baseUsername . $counter;
        $counter++;
    }
    
    return $username;
}

function usernameExists($username, $pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Generate secure random password
 */
function generateRandomPassword($length = 12) {
    $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lowercase = 'abcdefghijklmnopqrstuvwxyz';
    $numbers = '0123456789';
    $special = '!@#$%^&*()_+-=';
    
    $password = '';
    $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
    $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
    $password .= $numbers[random_int(0, strlen($numbers) - 1)];
    $password .= $special[random_int(0, strlen($special) - 1)];
    
    $allChars = $uppercase . $lowercase . $numbers . $special;
    for ($i = 4; $i < $length; $i++) {
        $password .= $allChars[random_int(0, strlen($allChars) - 1)];
    }
    
    return str_shuffle($password);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $first_name = trim($_POST['firstName']);
        $last_name = trim($_POST['lastName']);
        $email = trim($_POST['email']);
        $phone = !empty($_POST['phone']) ? trim($_POST['phone']) : null;
        $department = $_POST['department'];
        $role = $_POST['role'];
        $employee_id = !empty($_POST['employeeId']) ? trim($_POST['employeeId']) : null;
        
        // Validation
        $errors = [];
        
        if (empty($first_name)) $errors[] = "First name is required";
        if (empty($last_name)) $errors[] = "Last name is required";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required";
        if (empty($department)) $errors[] = "Department is required";
        if (empty($role)) $errors[] = "Role is required";
        
        // Check if email already exists
        if (empty($errors)) {
            $check_stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? OR (employee_id IS NOT NULL AND employee_id = ?)");
            $check_stmt->execute([$email, $employee_id]);
            if ($check_stmt->rowCount() > 0) {
                $errors[] = "Email or employee ID already exists";
            }
        }
        
        if (empty($errors)) {
            // Generate username and temporary password
            $username = generateUsername($first_name, $last_name, $pdo);
            $temporary_password = generateRandomPassword(12);
            
            // Generate reset token
            $reset_token = bin2hex(random_bytes(32));
            $reset_token_expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            // Hash password
            $password_hash = password_hash($temporary_password, PASSWORD_ARGON2ID);
            
            // Insert user into database
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    first_name, last_name, email, username, password_hash,
                    phone, department, role, employee_id,
                    is_active, is_verified, must_change_password,
                    password_reset_token, password_reset_expiry,
                    created_at, updated_at, created_by
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, 1, ?, ?, NOW(), NOW(), ?
                )
            ");
            
            if ($stmt->execute([
                $first_name, $last_name, $email, $username, $password_hash,
                $phone, $department, $role, $employee_id,
                $reset_token, $reset_token_expiry, $_SESSION['user_id']
            ])) {
                $new_user_id = $pdo->lastInsertId();
                
                // Send welcome email with reset link
                $emailHelper = new EmailHelper();
                $user_data = [
                    'user_id' => $new_user_id,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'username' => $username,
                    'role' => $role,
                    'department' => $department,
                    'temporary_password' => $temporary_password,
                    'reset_token' => $reset_token
                ];
                
                if ($emailHelper->sendNewUserWelcomeEmail($user_data)) {
                    $success_message = "User account created successfully! Welcome email sent to $email";
                    
                    // Log the action
                    $log_stmt = $pdo->prepare("
                        INSERT INTO activity_logs (user_id, action, details, ip_address, created_at)
                        VALUES (?, 'user_created', ?, ?, NOW())
                    ");
                    $log_stmt->execute([
                        $_SESSION['user_id'],
                        "Created new user account: $username ($email)",
                        $_SERVER['REMOTE_ADDR']
                    ]);
                } else {
                    $success_message = "User created but email failed to send. Please manually provide credentials:<br>";
                    $success_message .= "Username: <strong>$username</strong><br>";
                    $success_message .= "Temporary Password: <strong>$temporary_password</strong>";
                }
                
                // Clear form
                $_POST = [];
            } else {
                $errors[] = "Failed to create user account";
            }
        }
        
        if (!empty($errors)) {
            $error_message = implode("<br>", $errors);
        }
        
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New User - E-Asset Management</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 40px;
        }
        
        .header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .header h1 {
            color: #2c3e50;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .header p {
            color: #6c757d;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #2c3e50;
            font-weight: 600;
        }
        
        .form-group label .required {
            color: #dc3545;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .info-box h4 {
            color: #1976D2;
            margin-bottom: 8px;
        }
        
        .info-box p {
            color: #495057;
            font-size: 14px;
            margin: 5px 0;
        }
        
        .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            flex: 1;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .btn-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <span>👤</span> Create New User Account
            </h1>
            <p>Add a new employee to the E-Asset Management System</p>
        </div>
        
        <?php if (!empty($success_message)): ?>
        <div class="alert alert-success">
            <?php echo $success_message; ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
        <div class="alert alert-error">
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>
        
        <div class="info-box">
            <h4>📧 Automatic Account Setup</h4>
            <p>• A unique username will be automatically generated based on the employee's name</p>
            <p>• A secure temporary password will be created automatically</p>
            <p>• The employee will receive an email with a password reset link</p>
            <p>• They must set a new password on first login</p>
        </div>
        
        <form method="POST" action="">
            <div class="form-row">
                <div class="form-group">
                    <label for="firstName">First Name <span class="required">*</span></label>
                    <input type="text" id="firstName" name="firstName" 
                           value="<?php echo htmlspecialchars($_POST['firstName'] ?? ''); ?>" 
                           placeholder="John" required>
                </div>
                <div class="form-group">
                    <label for="lastName">Last Name <span class="required">*</span></label>
                    <input type="text" id="lastName" name="lastName" 
                           value="<?php echo htmlspecialchars($_POST['lastName'] ?? ''); ?>" 
                           placeholder="Doe" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="email">Email Address <span class="required">*</span></label>
                <input type="email" id="email" name="email" 
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                       placeholder="john.doe@company.com" required>
            </div>
            
            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" 
                       value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" 
                       placeholder="+(60)123456789">
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="department">Department <span class="required">*</span></label>
                    <select id="department" name="department" required>
                        <option value="">Select Department</option>
                        <?php foreach ($departments_list as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept['dept_name']); ?>"
                                    <?php echo (($_POST['department'] ?? '') === $dept['dept_name']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['dept_name']); ?>
                                <?php if (isset($dept['dept_code'])): ?>
                                    (<?php echo htmlspecialchars($dept['dept_code']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="role">Role <span class="required">*</span></label>
                    <select id="role" name="role" required>
                        <option value="">Select Role</option>
                        <option value="admin" <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Administrator</option>
                        <option value="manager" <?php echo (($_POST['role'] ?? '') === 'manager') ? 'selected' : ''; ?>>Manager</option>
                        <option value="employee" <?php echo (($_POST['role'] ?? '') === 'employee') ? 'selected' : ''; ?>>Employee</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label for="employeeId">Employee ID</label>
                <input type="text" id="employeeId" name="employeeId" 
                       value="<?php echo htmlspecialchars($_POST['employeeId'] ?? ''); ?>" 
                       placeholder="EMP-12345">
            </div>
            
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">
                    ✓ Create User Account
                </button>
                <a href="dashboard.php" class="btn btn-secondary">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</body>
</html>