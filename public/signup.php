<?php
session_start();

// Clear any existing remember me cookies to prevent auto-login
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
    unset($_COOKIE['remember_token']);
}

// Include database configuration
include("../auth/config/database.php");

$error_message = '';
$success_message = '';

// Fetch active departments from database for the dropdown
$departments_list = [];
try {
    $dept_query = $pdo->query("SELECT dept_id, dept_name, dept_code FROM departments WHERE is_active = 1 ORDER BY dept_name ASC");
    $departments_list = $dept_query->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If departments table doesn't exist or error, use fallback
    $departments_list = [
        ['dept_name' => 'IT', 'dept_code' => 'IT'],
        ['dept_name' => 'Human Resources', 'dept_code' => 'HR'],
        ['dept_name' => 'Finance', 'dept_code' => 'FIN'],
        ['dept_name' => 'Operations', 'dept_code' => 'OPS'],
        ['dept_name' => 'Sales', 'dept_code' => 'SALES'],
        ['dept_name' => 'Marketing', 'dept_code' => 'MKT'],
        ['dept_name' => 'Engineering', 'dept_code' => 'ENG'],
        ['dept_name' => 'Support', 'dept_code' => 'SUP']
    ];
}

/**
 * Enhanced password validation function
 */
function validatePassword($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    
    if (strlen($password) > 128) {
        $errors[] = "Password must not exceed 128 characters";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'",.<>?\/\\|`~]/', $password)) {
        $errors[] = "Password must contain at least one special character (!@#$%^&*()_+-=[]{}etc.)";
    }
    
    $common_patterns = [
        '/(.)\1{2,}/',
        '/^[0-9]+$/',
        '/^[a-zA-Z]+$/',
    ];
    
    foreach ($common_patterns as $pattern) {
        if (preg_match($pattern, $password)) {
            $errors[] = "Password contains common patterns. Please use a more complex password";
            break;
        }
    }
    
    $weak_passwords = [
        'password', 'Password', 'password1', 'Password1', 'Password123',
        '12345678', '123456789', 'qwerty123', 'abc123456', 'password123',
        'admin123', 'welcome123', 'letmein123'
    ];
    
    foreach ($weak_passwords as $weak) {
        if (stripos($password, $weak) !== false) {
            $errors[] = "Password is too common. Please choose a stronger password";
            break;
        }
    }
    
    return $errors;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $first_name = trim($_POST['firstName']);
        $last_name = trim($_POST['lastName']);
        $email = trim($_POST['email']);
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirmPassword'];
        $phone = !empty($_POST['phone']) ? trim($_POST['phone']) : null;
        $department = !empty($_POST['department']) ? $_POST['department'] : null;
        $role = $_POST['role'];
        $employee_id = !empty($_POST['employeeId']) ? trim($_POST['employeeId']) : null;
        
        $errors = [];
        
        if (empty($first_name)) $errors[] = "First name is required";
        if (empty($last_name)) $errors[] = "Last name is required";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required";
        if (strlen($username) < 3) $errors[] = "Username must be at least 3 characters";
        
        $password_errors = validatePassword($password);
        if (!empty($password_errors)) {
            $errors = array_merge($errors, $password_errors);
        }
        
        if ($password !== $confirm_password) {
            $errors[] = "Passwords do not match";
        }
        
        if (!empty($username) && stripos($password, $username) !== false) {
            $errors[] = "Password cannot contain your username";
        }
        if (!empty($first_name) && strlen($first_name) > 2 && stripos($password, $first_name) !== false) {
            $errors[] = "Password cannot contain your first name";
        }
        if (!empty($last_name) && strlen($last_name) > 2 && stripos($password, $last_name) !== false) {
            $errors[] = "Password cannot contain your last name";
        }
        
        if (empty($department)) $errors[] = "Department is required";
        if (empty($role)) $errors[] = "Role is required";
        
        $allowed_roles = ['admin', 'manager'];
        if (!in_array($role, $allowed_roles)) {
            $errors[] = "Invalid role selected";
        }
        
        if (!empty($department)) {
            $dept_check = $pdo->prepare("SELECT dept_id FROM departments WHERE dept_name = ? AND is_active = 1");
            $dept_check->execute([$department]);
            if ($dept_check->rowCount() === 0) {
                $errors[] = "Invalid department selected";
            }
        }
        
        if (empty($errors)) {
            $check_stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ? OR email = ? OR (employee_id IS NOT NULL AND employee_id = ?)");
            $check_stmt->execute([$username, $email, $employee_id]);
            if ($check_stmt->rowCount() > 0) {
                $errors[] = "Username, email, or employee ID already exists";
            }
        }

        if (empty($errors)) {
            $password_hash = password_hash($password, PASSWORD_ARGON2ID);

            $stmt = $pdo->prepare("
                INSERT INTO users (
                    first_name, last_name, email, username, password_hash, 
                    phone, department, role, employee_id, is_active, is_verified, 
                    created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW()
                )
            ");

            if ($stmt->execute([
                $first_name,
                $last_name,
                $email,
                $username,
                $password_hash,
                $phone,
                $department,
                $role,
                $employee_id
            ])) {
                $_SESSION['just_registered'] = true;
                header("Location: login.php?message=registered&from=signup");
                exit();
            } else {
                $errors[] = "Failed to create account. Please try again.";
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
    <title>Sign Up - E-Asset Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8f9fc; min-height: 100vh; display: flex; align-items: center;
            justify-content: center; padding: 40px 20px; position: relative; overflow-x: hidden;
        }
        body::before {
            content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
            background: linear-gradient(135deg, rgba(124, 58, 237, 0.05) 0%, rgba(109, 40, 217, 0.05) 25%,
                rgba(124, 58, 237, 0.03) 50%, rgba(109, 40, 217, 0.03) 75%, rgba(124, 58, 237, 0.05) 100%);
            animation: rotate 20s linear infinite; z-index: 0;
        }
        @keyframes rotate { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .signup-wrapper {
            position: relative; z-index: 1; width: 100%; max-width: 1200px;
            display: grid; grid-template-columns: 400px 1fr; background: white;
            border-radius: 24px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08); overflow: hidden;
        }
        .signup-branding {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            padding: 60px 40px; display: flex; flex-direction: column; justify-content: center;
            color: white; position: relative; overflow: hidden;
        }
        .signup-branding::before {
            content: ''; position: absolute; top: -50%; right: -50%; width: 200%; height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: pulse 15s ease-in-out infinite;
        }
        @keyframes pulse { 0%, 100% { transform: scale(1); opacity: 0.5; } 50% { transform: scale(1.2); opacity: 0.8; } }
        .brand-content { position: relative; z-index: 1; }
        .brand-logo {
            width: 90px; height: 90px; background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px); border-radius: 20px; display: flex;
            align-items: center; justify-content: center; font-size: 42px;
            margin-bottom: 30px; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
        }
        .brand-content h1 { font-size: 28px; font-weight: 700; margin-bottom: 16px; line-height: 1.3; }
        .brand-content p { font-size: 15px; opacity: 0.9; line-height: 1.6; margin-bottom: 40px; }
        .feature-list { list-style: none; }
        .feature-list li {
            display: flex; align-items: center; gap: 12px; margin-bottom: 16px;
            font-size: 14px; opacity: 0.95;
        }
        .feature-list li i {
            width: 28px; height: 28px; background: rgba(255, 255, 255, 0.2);
            border-radius: 7px; display: flex; align-items: center; justify-content: center;
            font-size: 12px; flex-shrink: 0;
        }
        .signup-container { padding: 50px 60px; overflow-y: auto; max-height: 90vh; }
        .signup-container::-webkit-scrollbar { width: 8px; }
        .signup-container::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .signup-container::-webkit-scrollbar-thumb { background: #7c3aed; border-radius: 10px; }
        .signup-header { margin-bottom: 35px; }
        .signup-header h2 { font-size: 32px; font-weight: 700; color: #1a202c; margin-bottom: 8px; }
        .signup-header p { color: #718096; font-size: 15px; }
        .alert {
            padding: 14px 18px; border-radius: 12px; margin-bottom: 24px; font-size: 14px;
            display: flex; align-items: flex-start; gap: 10px; animation: slideDown 0.3s ease;
            font-weight: 500; line-height: 1.5;
        }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .alert-error {
            background: linear-gradient(135deg, #ffe6e6 0%, #ffd4d4 100%);
            color: #721c24; border-left: 4px solid #dc3545;
        }
        .alert-success {
            background: linear-gradient(135deg, #d4f4dd 0%, #c3e6cb 100%);
            color: #155724; border-left: 4px solid #28a745;
        }
        .alert i { margin-top: 2px; flex-shrink: 0; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 10px; color: #2d3748; font-weight: 600; font-size: 14px; }
        .form-group label .required { color: #ef4444; }
        .input-wrapper { position: relative; }
        .input-icon {
            position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
            color: #718096; font-size: 14px; z-index: 1;
        }
        .form-group input, .form-group select {
            width: 100%; padding: 13px 16px 13px 45px; border: 2px solid #e2e8f0;
            border-radius: 12px; font-size: 14px; font-family: inherit;
            transition: all 0.3s; background: white;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none; border-color: #7c3aed; box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }
        .form-group select {
            padding-left: 45px; cursor: pointer; appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23718096' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 16px center;
        }
        .password-wrapper { position: relative; }
        .password-toggle {
            position: absolute; right: 16px; top: 50%; transform: translateY(-50%);
            cursor: pointer; color: #718096; font-size: 16px; transition: color 0.3s; z-index: 2;
        }
        .password-toggle:hover { color: #7c3aed; }
        .password-strength { height: 4px; background: #e2e8f0; border-radius: 2px; margin-top: 8px; overflow: hidden; }
        .password-strength-bar { height: 100%; width: 0%; transition: all 0.3s; border-radius: 2px; }
        .password-strength-bar.strength-weak { width: 25%; background: #ef4444; }
        .password-strength-bar.strength-fair { width: 50%; background: #f59e0b; }
        .password-strength-bar.strength-good { width: 75%; background: #3b82f6; }
        .password-strength-bar.strength-strong { width: 100%; background: #10b981; }
        .strength-text { margin-top: 6px; font-size: 12px; font-weight: 600; }
        .password-requirements { margin-top: 12px; padding: 14px; background: #f7fafc; border-radius: 10px; display: none; }
        .password-requirements.show { display: block; }
        .requirement {
            font-size: 13px; color: #718096; padding: 5px 0; display: flex;
            align-items: center; gap: 8px; transition: color 0.3s;
        }
        .requirement::before { content: '○'; color: #cbd5e0; font-weight: bold; font-size: 16px; }
        .requirement.met { color: #10b981; }
        .requirement.met::before { content: '✓'; color: #10b981; }
        .checkbox-group { display: flex; align-items: flex-start; gap: 10px; margin: 24px 0; }
        .checkbox-group input[type="checkbox"] {
            margin-top: 3px; width: 18px; height: 18px; cursor: pointer;
            accent-color: #7c3aed; flex-shrink: 0;
        }
        .checkbox-group label {
            font-size: 14px; color: #4a5568; cursor: pointer;
            flex: 1; font-weight: 500; line-height: 1.5;
        }
        .checkbox-group a { color: #7c3aed; text-decoration: none; font-weight: 600; }
        .checkbox-group a:hover { text-decoration: underline; }
        .btn-signup {
            width: 100%; padding: 16px; background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white; border: none; border-radius: 12px; font-size: 16px; font-weight: 700;
            cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 14px rgba(124, 58, 237, 0.4);
            display: flex; align-items: center; justify-content: center; gap: 10px;
        }
        .btn-signup:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(124, 58, 237, 0.5); }
        .btn-signup:active { transform: translateY(0); }
        .divider {
            display: flex; align-items: center; text-align: center; margin: 28px 0;
            color: #718096; font-size: 14px;
        }
        .divider::before, .divider::after { content: ''; flex: 1; border-bottom: 1px solid #e2e8f0; }
        .divider span { padding: 0 16px; font-weight: 600; }
        .login-link { text-align: center; color: #718096; font-size: 15px; }
        .login-link a { color: #7c3aed; text-decoration: none; font-weight: 700; transition: color 0.3s; }
        .login-link a:hover { color: #6d28d9; text-decoration: underline; }
        @media (max-width: 1100px) {
            .signup-wrapper { grid-template-columns: 1fr; max-width: 700px; }
            .signup-branding { display: none; }
            .signup-container { max-height: none; }
        }
        @media (max-width: 768px) {
            body { padding: 20px 15px; }
            .signup-container { padding: 40px 30px; }
            .signup-header h2 { font-size: 26px; }
            .form-row { grid-template-columns: 1fr; gap: 0; }
        }
        @media (max-width: 480px) {
            .signup-container { padding: 30px 20px; }
            .signup-header h2 { font-size: 24px; }
            .form-group input, .form-group select { padding: 12px 14px 12px 40px; }
        }
    </style>
</head>
<body>
    <div class="signup-wrapper">
        <div class="signup-branding">
            <div class="brand-content">
                <div class="brand-logo">🔐</div>
                <h1>Join Our E-Asset Management System</h1>
                <p>Create your account to start managing assets efficiently and securely.</p>
                <ul class="feature-list">
                    <li><i class="fas fa-check"></i><span>Comprehensive asset tracking</span></li>
                    <li><i class="fas fa-check"></i><span>Advanced security features</span></li>
                    <li><i class="fas fa-check"></i><span>Role-based access control</span></li>
                    <li><i class="fas fa-check"></i><span>Real-time notifications</span></li>
                    <li><i class="fas fa-check"></i><span>Detailed reporting & analytics</span></li>
                    <li><i class="fas fa-check"></i><span>Multi-department support</span></li>
                </ul>
            </div>
        </div>
        <div class="signup-container">
            <div class="signup-header">
                <h2>Create Account</h2>
                <p>Fill in your details to get started</p>
            </div>
            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error_message; ?></span>
            </div>
            <?php endif; ?>
            <form id="signupForm" method="POST" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label for="firstName">First Name <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" id="firstName" name="firstName" placeholder="John" 
                                   value="<?php echo htmlspecialchars($_POST['firstName'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="lastName">Last Name <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" id="lastName" name="lastName" placeholder="Doe" 
                                   value="<?php echo htmlspecialchars($_POST['lastName'] ?? ''); ?>" required>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="email">Email Address <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" id="email" name="email" placeholder="john.doe@company.com" 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="username">Username <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <i class="fas fa-at input-icon"></i>
                        <input type="text" id="username" name="username" placeholder="johndoe" 
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="password">Password <span class="required">*</span></label>
                        <div class="input-wrapper password-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" id="password" name="password" placeholder="Enter password" required>
                            <span class="password-toggle" onclick="togglePassword('password', 'toggleIcon1')">
                                <i class="fas fa-eye" id="toggleIcon1"></i>
                            </span>
                        </div>
                        <div class="password-strength">
                            <div class="password-strength-bar" id="passwordStrengthBar"></div>
                        </div>
                        <div class="strength-text" id="strengthText"></div>
                        <div class="password-requirements" id="passwordRequirements">
                            <div class="requirement" id="req-length">At least 8 characters</div>
                            <div class="requirement" id="req-uppercase">One uppercase letter</div>
                            <div class="requirement" id="req-lowercase">One lowercase letter</div>
                            <div class="requirement" id="req-number">One number</div>
                            <div class="requirement" id="req-special">One special character (!@#$%^&*)</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirmPassword">Confirm Password <span class="required">*</span></label>
                        <div class="input-wrapper password-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" id="confirmPassword" name="confirmPassword" placeholder="Confirm password" required>
                            <span class="password-toggle" onclick="togglePassword('confirmPassword', 'toggleIcon2')">
                                <i class="fas fa-eye" id="toggleIcon2"></i>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <div class="input-wrapper">
                        <i class="fas fa-phone input-icon"></i>
                        <input type="tel" id="phone" name="phone" placeholder="+(60)123456789" 
                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="department">Department <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <i class="fas fa-building input-icon"></i>
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
                    </div>
                    <div class="form-group">
                        <label for="role">Role <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <i class="fas fa-user-tag input-icon"></i>
                            <select id="role" name="role" required>
                                <option value="">Select Role</option>
                                <option value="admin" <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Administrator</option>
                                <option value="manager" <?php echo (($_POST['role'] ?? '') === 'manager') ? 'selected' : ''; ?>>Manager</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="employeeId">Employee ID</label>
                    <div class="input-wrapper">
                        <i class="fas fa-id-badge input-icon"></i>
                        <input type="text" id="employeeId" name="employeeId" placeholder="EMP-12345" 
                               value="<?php echo htmlspecialchars($_POST['employeeId'] ?? ''); ?>">
                    </div>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" id="terms" name="terms" required>
                    <label for="terms">I agree to the <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a></label>
                </div>
                <button type="submit" class="btn-signup">
                    <i class="fas fa-user-plus"></i> Create Account
                </button>
            </form>
            <div class="divider"><span>OR</span></div>
            <div class="login-link">
                Already have an account? <a href="login.php"><i class="fas fa-sign-in-alt"></i> Sign In</a>
            </div>
        </div>
    </div>
    <script>
        function togglePassword(fieldId, iconId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(iconId);
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        function checkPasswordStrength(password) {
            const requirements = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[!@#$%^&*()_+\-=\[\]{};:'",.<>?\/\\|`~]/.test(password)
            };
            
            document.getElementById('req-length').classList.toggle('met', requirements.length);
            document.getElementById('req-uppercase').classList.toggle('met', requirements.uppercase);
            document.getElementById('req-lowercase').classList.toggle('met', requirements.lowercase);
            document.getElementById('req-number').classList.toggle('met', requirements.number);
            document.getElementById('req-special').classList.toggle('met', requirements.special);
            
            const metCount = Object.values(requirements).filter(Boolean).length;
            let strengthText = '';
            let strengthClass = '';
            
            if (password.length === 0) {
                strengthText = '';
            } else if (metCount <= 2) {
                strengthText = 'Weak';
                strengthClass = 'strength-weak';
            } else if (metCount === 3) {
                strengthText = 'Fair';
                strengthClass = 'strength-fair';
            } else if (metCount === 4) {
                strengthText = 'Good';
                strengthClass = 'strength-good';
            } else if (metCount === 5) {
                strengthText = 'Strong';
                strengthClass = 'strength-strong';
            }
            
            const strengthBar = document.getElementById('passwordStrengthBar');
            const strengthTextEl = document.getElementById('strengthText');
            
            strengthBar.className = 'password-strength-bar ' + strengthClass;
            strengthTextEl.textContent = strengthText;
            strengthTextEl.style.color = strengthClass === 'strength-strong' ? '#10b981' : 
                                         strengthClass === 'strength-good' ? '#3b82f6' :
                                         strengthClass === 'strength-fair' ? '#f59e0b' : '#ef4444';
            
            return { metCount, requirements };
        }

        document.getElementById('password').addEventListener('input', function(e) {
            const password = e.target.value;
            const strengthIndicator = document.querySelector('.password-strength');
            const requirements = document.getElementById('passwordRequirements');
            
            if (password.length > 0) {
                strengthIndicator.style.display = 'block';
                requirements.classList.add('show');
                checkPasswordStrength(password);
            } else {
                strengthIndicator.style.display = 'none';
                requirements.classList.remove('show');
                document.getElementById('strengthText').textContent = '';
            }
        });

        document.getElementById('password').addEventListener('focus', function() {
            if (this.value.length > 0) {
                document.getElementById('passwordRequirements').classList.add('show');
            }
        });

        document.getElementById('signupForm').addEventListener('submit', function(e) {
            let isValid = true;
            
            const firstName = document.getElementById('firstName');
            if (firstName.value.trim().length < 1) {
                isValid = false;
            }
            
            const lastName = document.getElementById('lastName');
            if (lastName.value.trim().length < 1) {
                isValid = false;
            }
            
            const email = document.getElementById('email');
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email.value)) {
                isValid = false;
            }
            
            const username = document.getElementById('username');
            if (username.value.trim().length < 3) {
                isValid = false;
            }
            
            const password = document.getElementById('password');
            const passwordCheck = checkPasswordStrength(password.value);
            
            if (password.value.length < 8 || passwordCheck.metCount < 5) {
                alert('Password must meet all requirements');
                isValid = false;
            }
            
            if (username.value && password.value.toLowerCase().includes(username.value.toLowerCase())) {
                alert('Password cannot contain your username');
                isValid = false;
            }
            
            if (firstName.value.length > 2 && password.value.toLowerCase().includes(firstName.value.toLowerCase())) {
                alert('Password cannot contain your first name');
                isValid = false;
            }
            if (lastName.value.length > 2 && password.value.toLowerCase().includes(lastName.value.toLowerCase())) {
                alert('Password cannot contain your last name');
                isValid = false;
            }
            
            const confirmPassword = document.getElementById('confirmPassword');
            if (password.value !== confirmPassword.value) {
                alert('Passwords do not match');
                isValid = false;
            }
            
            const department = document.getElementById('department');
            if (!department.value) {
                isValid = false;
            }
            
            const role = document.getElementById('role');
            if (!role.value) {
                isValid = false;
            }
            
            const terms = document.getElementById('terms');
            if (!terms.checked) {
                alert('Please accept the Terms of Service and Privacy Policy');
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
            }
        });

        <?php if (!empty($success_message)): ?>
        setTimeout(() => {
            window.location.href = 'login.php?from=signup';
        }, 3000);
        <?php endif; ?>

        setTimeout(() => {
            const errorMsg = document.querySelector('.alert-error');
            if (errorMsg) {
                errorMsg.style.transition = 'opacity 0.5s';
                errorMsg.style.opacity = '0';
                setTimeout(() => errorMsg.style.display = 'none', 500);
            }
        }, 8000);
    </script>
</body>
</html>