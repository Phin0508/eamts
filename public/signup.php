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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .container {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 900px;
            width: 100%;
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px;
            text-align: center;
            color: white;
        }

        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
        }

        .logo i {
            font-size: 40px;
            color: white;
        }

        .header h1 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .header p {
            font-size: 16px;
            opacity: 0.95;
        }

        .form-container {
            padding: 40px;
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
            color: #2d3748;
            font-weight: 600;
            font-size: 14px;
        }

        .form-group label .required {
            color: #e53e3e;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s;
            background: white;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        .password-container {
            position: relative;
        }

        .password-container input {
            padding-right: 45px;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            font-size: 20px;
            user-select: none;
            transition: all 0.3s;
        }

        .password-toggle:hover {
            transform: translateY(-50%) scale(1.1);
        }

        .password-strength {
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: all 0.3s;
            border-radius: 2px;
        }

        .password-strength-bar.strength-weak {
            width: 25%;
            background: #ef4444;
        }

        .password-strength-bar.strength-fair {
            width: 50%;
            background: #f59e0b;
        }

        .password-strength-bar.strength-good {
            width: 75%;
            background: #3b82f6;
        }

        .password-strength-bar.strength-strong {
            width: 100%;
            background: #10b981;
        }

        .strength-text {
            margin-top: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .password-requirements {
            margin-top: 12px;
            padding: 12px;
            background: #f7fafc;
            border-radius: 8px;
            display: none;
        }

        .password-requirements.show {
            display: block;
        }

        .requirement {
            font-size: 13px;
            color: #718096;
            padding: 4px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .requirement::before {
            content: '○';
            color: #cbd5e0;
            font-weight: bold;
        }

        .requirement.met {
            color: #10b981;
        }

        .requirement.met::before {
            content: '✓';
            color: #10b981;
        }

        .error-text {
            color: #e53e3e;
            font-size: 13px;
            margin-top: 6px;
            display: none;
        }

        .form-group.error input,
        .form-group.error select {
            border-color: #e53e3e;
        }

        .form-group.error .error-text {
            display: block;
        }

        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin: 24px 0;
        }

        .checkbox-group input[type="checkbox"] {
            margin-top: 4px;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-group label {
            font-size: 14px;
            color: #4a5568;
            cursor: pointer;
            flex: 1;
        }

        .checkbox-group a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .checkbox-group a:hover {
            text-decoration: underline;
        }

        .btn-primary {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .divider {
            display: flex;
            align-items: center;
            margin: 30px 0;
            color: #718096;
            font-size: 14px;
            font-weight: 500;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }

        .divider span {
            padding: 0 16px;
        }

        .login-link {
            text-align: center;
            color: #4a5568;
            font-size: 15px;
        }

        .login-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            body {
                padding: 20px;
            }

            .container {
                border-radius: 16px;
            }

            .header {
                padding: 30px 20px;
            }

            .header h1 {
                font-size: 24px;
            }

            .form-container {
                padding: 24px;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h1>Create Account</h1>
            <p>Join our E-Asset Management System</p>
        </div>

        <div class="form-container">
            <?php if (!empty($success_message)): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error_message; ?></span>
            </div>
            <?php endif; ?>

            <form id="signupForm" method="POST" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label for="firstName">First Name <span class="required">*</span></label>
                        <input type="text" id="firstName" name="firstName" placeholder="John" 
                               value="<?php echo htmlspecialchars($_POST['firstName'] ?? ''); ?>" required>
                        <div class="error-text">Please enter your first name</div>
                    </div>
                    <div class="form-group">
                        <label for="lastName">Last Name <span class="required">*</span></label>
                        <input type="text" id="lastName" name="lastName" placeholder="Doe" 
                               value="<?php echo htmlspecialchars($_POST['lastName'] ?? ''); ?>" required>
                        <div class="error-text">Please enter your last name</div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Email Address <span class="required">*</span></label>
                    <input type="email" id="email" name="email" placeholder="john.doe@company.com" 
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                    <div class="error-text">Please enter a valid email address</div>
                </div>

                <div class="form-group">
                    <label for="username">Username <span class="required">*</span></label>
                    <input type="text" id="username" name="username" placeholder="johndoe" 
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                    <div class="error-text">Username must be at least 3 characters</div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="password">Password <span class="required">*</span></label>
                        <div class="password-container">
                            <input type="password" id="password" name="password" placeholder="••••••••" required>
                            <span class="password-toggle" onclick="togglePassword('password')">
                                <i class="fas fa-eye"></i>
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
                        <div class="error-text">Password does not meet requirements</div>
                    </div>
                    <div class="form-group">
                        <label for="confirmPassword">Confirm Password <span class="required">*</span></label>
                        <div class="password-container">
                            <input type="password" id="confirmPassword" name="confirmPassword" placeholder="••••••••" required>
                            <span class="password-toggle" onclick="togglePassword('confirmPassword')">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                        <div class="error-text">Passwords do not match</div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" placeholder="+(60)123456789" 
                           value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                    <div class="error-text">Please enter a valid phone number</div>
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
                        <div class="error-text">Please select a department</div>
                    </div>
                    <div class="form-group">
                        <label for="role">Role <span class="required">*</span></label>
                        <select id="role" name="role" required>
                            <option value="">Select Role</option>
                            <option value="admin" <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Administrator</option>
                            <option value="manager" <?php echo (($_POST['role'] ?? '') === 'manager') ? 'selected' : ''; ?>>Manager</option>
                        </select>
                        <div class="error-text">Please select a role</div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="employeeId">Employee ID</label>
                    <input type="text" id="employeeId" name="employeeId" placeholder="EMP-12345" 
                           value="<?php echo htmlspecialchars($_POST['employeeId'] ?? ''); ?>">
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" id="terms" name="terms" required>
                    <label for="terms">I agree to the <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a></label>
                </div>

                <button type="submit" class="btn-primary">
                    <i class="fas fa-user-plus"></i> Create Account
                </button>
            </form>

            <div class="divider">
                <span>OR</span>
            </div>

            <div class="login-link">
                Already have an account? <a href="login.php"><i class="fas fa-sign-in-alt"></i> Sign In</a>
            </div>
        </div>
    </div>

    <script>
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const toggle = field.nextElementSibling.querySelector('i');
            const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
            field.setAttribute('type', type);
            toggle.className = type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
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
            let strength = 0;
            let strengthText = '';
            let strengthClass = '';
            
            if (password.length === 0) {
                strength = 0;
                strengthText = '';
            } else if (metCount <= 2) {
                strength = 1;
                strengthText = 'Weak';
                strengthClass = 'strength-weak';
            } else if (metCount === 3) {
                strength = 2;
                strengthText = 'Fair';
                strengthClass = 'strength-fair';
            } else if (metCount === 4) {
                strength = 3;
                strengthText = 'Good';
                strengthClass = 'strength-good';
            } else if (metCount === 5) {
                strength = 4;
                strengthText = 'Strong';
                strengthClass = 'strength-strong';
            }
            
            const strengthBar = document.getElementById('passwordStrengthBar');
            const strengthTextEl = document.getElementById('strengthText');
            
            strengthBar.className = 'password-strength-bar ' + strengthClass;
            strengthTextEl.textContent = strengthText;
            
            return { strength, metCount, requirements };
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
            }
        });

        document.getElementById('password').addEventListener('focus', function() {
            if (this.value.length > 0) {
                document.getElementById('passwordRequirements').classList.add('show');
            }
        });

        document.getElementById('signupForm').addEventListener('submit', function(e) {
            document.querySelectorAll('.form-group').forEach(group => {
                group.classList.remove('error');
            });
            
            let isValid = true;
            
            const firstName = document.getElementById('firstName');
            if (firstName.value.trim().length < 1) {
                firstName.closest('.form-group').classList.add('error');
                isValid = false;
            }
            
            const lastName = document.getElementById('lastName');
            if (lastName.value.trim().length < 1) {
                lastName.closest('.form-group').classList.add('error');
                isValid = false;
            }
            
            const email = document.getElementById('email');
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email.value)) {
                email.closest('.form-group').classList.add('error');
                isValid = false;
            }
            
            const username = document.getElementById('username');
            if (username.value.trim().length < 3) {
                username.closest('.form-group').classList.add('error');
                isValid = false;
            }
            
            const password = document.getElementById('password');
            const passwordCheck = checkPasswordStrength(password.value);
            
            if (password.value.length < 8) {
                password.closest('.form-group').classList.add('error');
                isValid = false;
            } else if (passwordCheck.metCount < 5) {
                password.closest('.form-group').classList.add('error');
                password.closest('.form-group').querySelector('.error-text').textContent = 'Password must meet all requirements';
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
                confirmPassword.closest('.form-group').classList.add('error');
                isValid = false;
            }
            
            const department = document.getElementById('department');
            if (!department.value) {
                department.closest('.form-group').classList.add('error');
                isValid = false;
            }
            
            const role = document.getElementById('role');
            if (!role.value) {
                role.closest('.form-group').classList.add('error');
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

        // Auto-hide error messages
        setTimeout(() => {
            const errorMsg = document.querySelector('.error-message');
            if (errorMsg) {
                errorMsg.style.transition = 'opacity 0.5s';
                errorMsg.style.opacity = '0';
                setTimeout(() => errorMsg.style.display = 'none', 500);
            }
        }, 8000);
    </script>
</body>
</html>