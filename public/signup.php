<?php
session_start();

// Clear any existing remember me cookies to prevent auto-login
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
    unset($_COOKIE['remember_token']); // Also unset from current request
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
 * Checks for industry-standard password requirements
 */
function validatePassword($password) {
    $errors = [];
    
    // Minimum length check
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    
    // Maximum length check (prevent DoS attacks)
    if (strlen($password) > 128) {
        $errors[] = "Password must not exceed 128 characters";
    }
    
    // Check for at least one uppercase letter
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    // Check for at least one lowercase letter
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    // Check for at least one number
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    // Check for at least one special character
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'",.<>?\/\\|`~]/', $password)) {
        $errors[] = "Password must contain at least one special character (!@#$%^&*()_+-=[]{}etc.)";
    }
    
    // Check for common patterns
    $common_patterns = [
        '/(.)\1{2,}/', // Three or more repeated characters
        '/^[0-9]+$/', // Only numbers
        '/^[a-zA-Z]+$/', // Only letters
    ];
    
    foreach ($common_patterns as $pattern) {
        if (preg_match($pattern, $password)) {
            $errors[] = "Password contains common patterns. Please use a more complex password";
            break;
        }
    }
    
    // Check against common weak passwords
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
        // Get form data and sanitize
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
        
        // Server-side validation
        $errors = [];
        
        if (empty($first_name)) $errors[] = "First name is required";
        if (empty($last_name)) $errors[] = "Last name is required";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required";
        if (strlen($username) < 3) $errors[] = "Username must be at least 3 characters";
        
        // Enhanced password validation
        $password_errors = validatePassword($password);
        if (!empty($password_errors)) {
            $errors = array_merge($errors, $password_errors);
        }
        
        // Check password confirmation
        if ($password !== $confirm_password) {
            $errors[] = "Passwords do not match";
        }
        
        // Check if password contains username, first name, or last name
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
        
        // Validate role against allowed values - ONLY ADMIN AND MANAGER
        $allowed_roles = ['admin', 'manager'];
        if (!in_array($role, $allowed_roles)) {
            $errors[] = "Invalid role selected";
        }
        
        // Validate department exists in database
        if (!empty($department)) {
            $dept_check = $pdo->prepare("SELECT dept_id FROM departments WHERE dept_name = ? AND is_active = 1");
            $dept_check->execute([$department]);
            if ($dept_check->rowCount() === 0) {
                $errors[] = "Invalid department selected";
            }
        }
        
        // Check if username, email, or employee_id already exists
        if (empty($errors)) {
            $check_stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ? OR email = ? OR (employee_id IS NOT NULL AND employee_id = ?)");
            $check_stmt->execute([$username, $email, $employee_id]);
            if ($check_stmt->rowCount() > 0) {
                $errors[] = "Username, email, or employee ID already exists";
            }
        }

        // If no errors, insert into database
        if (empty($errors)) {
            // Hash the password with stronger algorithm
            $password_hash = password_hash($password, PASSWORD_ARGON2ID);

            // Prepare insert statement
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    first_name, last_name, email, username, password_hash, 
                    phone, department, role, employee_id, is_active, is_verified, 
                    created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW()
                )
            ");

            // Execute the statement
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
                // Set session flag to prevent auto-login
                $_SESSION['just_registered'] = true;

                // Redirect to login page with success message
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
    <title>E-Asset Management System - Sign Up</title>
    <link rel="stylesheet" href="../style/signup.css">
    <style>
        
    </style>
</head>
<body>
    <div class="container">
        <div class="logo-section">
            <div class="logo">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5z"/>
                </svg>
            </div>
            <h1>Create Account</h1>
            <p class="subtitle">Join our E-Asset Management System</p>
        </div>

        <?php if (!empty($success_message)): ?>
        <div class="success-message" id="successMessage" style="display: block;">
            <?php echo htmlspecialchars($success_message); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
        <div class="error-message-box" style="display: block; background-color: #fee; color: #c33; padding: 10px; border-radius: 5px; margin-bottom: 20px;">
            <?php echo $error_message; ?>
        </div>
        <?php endif; ?>

        <form id="signupForm" method="POST" action="">
            <div class="form-row">
                <div class="form-group">
                    <label for="firstName">First Name *</label>
                    <input type="text" id="firstName" name="firstName" placeholder="John" 
                           value="<?php echo htmlspecialchars($_POST['firstName'] ?? ''); ?>" required>
                    <span class="error-message">Please enter your first name</span>
                </div>
                <div class="form-group">
                    <label for="lastName">Last Name *</label>
                    <input type="text" id="lastName" name="lastName" placeholder="Doe" 
                           value="<?php echo htmlspecialchars($_POST['lastName'] ?? ''); ?>" required>
                    <span class="error-message">Please enter your last name</span>
                </div>
            </div>

            <div class="form-group">
                <label for="email">Email Address *</label>
                <input type="email" id="email" name="email" placeholder="john.doe@company.com" 
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                <span class="error-message">Please enter a valid email address</span>
            </div>

            <div class="form-group">
                <label for="username">Username *</label>
                <input type="text" id="username" name="username" placeholder="johndoe" 
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                <span class="error-message">Username must be at least 3 characters</span>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="password">Password *</label>
                    <div class="password-container">
                        <input type="password" id="password" name="password" placeholder="••••••••" required>
                        <span class="password-toggle" onclick="togglePassword('password')">👁</span>
                    </div>
                    <div class="password-strength" id="passwordStrength">
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
                    <span class="error-message">Password does not meet requirements</span>
                </div>
                <div class="form-group">
                    <label for="confirmPassword">Confirm Password *</label>
                    <div class="password-container">
                        <input type="password" id="confirmPassword" name="confirmPassword" placeholder="••••••••" required>
                        <span class="password-toggle" onclick="togglePassword('confirmPassword')">👁</span>
                    </div>
                    <span class="error-message">Passwords do not match</span>
                </div>
            </div>

            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" placeholder="+(60)123456789" 
                       value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                <span class="error-message">Please enter a valid phone number</span>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="department">Department *</label>
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
                    <span class="error-message">Please select a department</span>
                </div>
                <div class="form-group">
                    <label for="role">Role *</label>
                    <select id="role" name="role" required>
                        <option value="">Select Role</option>
                        <option value="admin" <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Administrator</option>
                        <option value="manager" <?php echo (($_POST['role'] ?? '') === 'manager') ? 'selected' : ''; ?>>Manager</option>
                    </select>
                    <span class="error-message">Please select a role</span>
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

            <button type="submit" class="btn-primary">Create Account</button>
        </form>

        <div class="divider">
            <span>OR</span>
        </div>

        <div class="login-link">
            Already have an account? <a href="login.php">Sign In</a>
        </div>
    </div>

    <script>
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const toggle = field.nextElementSibling;
            const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
            field.setAttribute('type', type);
            toggle.textContent = type === 'password' ? '👁' : '🙈';
        }

        // Password strength checker
        function checkPasswordStrength(password) {
            const requirements = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[!@#$%^&*()_+\-=\[\]{};:'",.<>?\/\\|`~]/.test(password)
            };
            
            // Update requirement indicators
            document.getElementById('req-length').classList.toggle('met', requirements.length);
            document.getElementById('req-uppercase').classList.toggle('met', requirements.uppercase);
            document.getElementById('req-lowercase').classList.toggle('met', requirements.lowercase);
            document.getElementById('req-number').classList.toggle('met', requirements.number);
            document.getElementById('req-special').classList.toggle('met', requirements.special);
            
            // Calculate strength
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
            
            // Update UI
            const strengthBar = document.getElementById('passwordStrengthBar');
            const strengthTextEl = document.getElementById('strengthText');
            
            strengthBar.className = 'password-strength-bar ' + strengthClass;
            strengthTextEl.textContent = strengthText;
            strengthTextEl.style.color = getComputedStyle(strengthBar).backgroundColor;
            
            return { strength, metCount, requirements };
        }

        // Real-time password validation
        document.getElementById('password').addEventListener('input', function(e) {
            const password = e.target.value;
            const strengthIndicator = document.getElementById('passwordStrength');
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

        // Show requirements on focus
        document.getElementById('password').addEventListener('focus', function() {
            if (this.value.length > 0) {
                document.getElementById('passwordRequirements').classList.add('show');
            }
        });

        // Enhanced client-side validation
        document.getElementById('signupForm').addEventListener('submit', function(e) {
            // Reset error messages
            document.querySelectorAll('.error-message').forEach(msg => {
                msg.style.display = 'none';
            });
            
            let isValid = true;
            
            // Validate first name
            const firstName = document.getElementById('firstName');
            if (firstName.value.trim().length < 1) {
                firstName.nextElementSibling.style.display = 'block';
                isValid = false;
            }
            
            // Validate last name
            const lastName = document.getElementById('lastName');
            if (lastName.value.trim().length < 1) {
                lastName.nextElementSibling.style.display = 'block';
                isValid = false;
            }
            
            // Validate email
            const email = document.getElementById('email');
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email.value)) {
                email.nextElementSibling.style.display = 'block';
                isValid = false;
            }
            
            // Validate username
            const username = document.getElementById('username');
            if (username.value.trim().length < 3) {
                username.nextElementSibling.style.display = 'block';
                isValid = false;
            }
            
            // Enhanced password validation
            const password = document.getElementById('password');
            const passwordCheck = checkPasswordStrength(password.value);
            
            if (password.value.length < 8) {
                password.parentElement.nextElementSibling.nextElementSibling.nextElementSibling.nextElementSibling.style.display = 'block';
                isValid = false;
            } else if (passwordCheck.metCount < 5) {
                password.parentElement.nextElementSibling.nextElementSibling.nextElementSibling.nextElementSibling.style.display = 'block';
                password.parentElement.nextElementSibling.nextElementSibling.nextElementSibling.nextElementSibling.textContent = 'Password must meet all requirements';
                isValid = false;
            }
            
            // Check if password contains username
            if (username.value && password.value.toLowerCase().includes(username.value.toLowerCase())) {
                alert('Password cannot contain your username');
                isValid = false;
            }
            
            // Check if password contains first or last name
            if (firstName.value.length > 2 && password.value.toLowerCase().includes(firstName.value.toLowerCase())) {
                alert('Password cannot contain your first name');
                isValid = false;
            }
            if (lastName.value.length > 2 && password.value.toLowerCase().includes(lastName.value.toLowerCase())) {
                alert('Password cannot contain your last name');
                isValid = false;
            }
            
            // Validate password match
            const confirmPassword = document.getElementById('confirmPassword');
            if (password.value !== confirmPassword.value) {
                confirmPassword.parentElement.nextElementSibling.style.display = 'block';
                isValid = false;
            }
            
            // Validate department
            const department = document.getElementById('department');
            if (!department.value) {
                department.nextElementSibling.style.display = 'block';
                isValid = false;
            }
            
            // Validate role
            const role = document.getElementById('role');
            if (!role.value) {
                role.nextElementSibling.style.display = 'block';
                isValid = false;
            }
            
            // Validate terms
            const terms = document.getElementById('terms');
            if (!terms.checked) {
                alert('Please accept the Terms of Service and Privacy Policy');
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
            }
        });

        // Auto-hide success message after 5 seconds and redirect
        <?php if (!empty($success_message)): ?>
        setTimeout(() => {
            document.getElementById('successMessage').style.display = 'none';
            window.location.href = 'login.php?from=signup';
        }, 3000);
        <?php endif; ?>
    </script>
</body>
</html>