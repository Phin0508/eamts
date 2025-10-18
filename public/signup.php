<?php
session_start();

// Clear any existing remember me cookies to prevent auto-login
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
    unset($_COOKIE['remember_token']); // Also unset from current request
}
?>
<?php
// Include database configuration
include("../auth/config/database.php");

$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data and sanitize
        $first_name = trim($_POST['firstName']);
        $last_name = trim($_POST['lastName']);
        $email = trim($_POST['email']);
        $username = trim($_POST['username']);
        $password = $_POST['password'];
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
        if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters";
        if (empty($department)) $errors[] = "Department is required";
        if (empty($role)) $errors[] = "Role is required";
        
        // Validate role against allowed values
        $allowed_roles = ['admin', 'manager', 'employee'];
        if (!in_array($role, $allowed_roles)) {
            $errors[] = "Invalid role selected";
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
            // Hash the password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

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
                session_start();
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
                    <span class="error-message">Password must be at least 8 characters</span>
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
                        <option value="IT" <?php echo (($_POST['department'] ?? '') === 'IT') ? 'selected' : ''; ?>>IT</option>
                        <option value="HR" <?php echo (($_POST['department'] ?? '') === 'HR') ? 'selected' : ''; ?>>Human Resources</option>
                        <option value="Finance" <?php echo (($_POST['department'] ?? '') === 'Finance') ? 'selected' : ''; ?>>Finance</option>
                        <option value="Operations" <?php echo (($_POST['department'] ?? '') === 'Operations') ? 'selected' : ''; ?>>Operations</option>
                        <option value="Sales" <?php echo (($_POST['department'] ?? '') === 'Sales') ? 'selected' : ''; ?>>Sales</option>
                        <option value="Marketing" <?php echo (($_POST['department'] ?? '') === 'Marketing') ? 'selected' : ''; ?>>Marketing</option>
                        <option value="Engineering" <?php echo (($_POST['department'] ?? '') === 'Engineering') ? 'selected' : ''; ?>>Engineering</option>
                        <option value="Support" <?php echo (($_POST['department'] ?? '') === 'Support') ? 'selected' : ''; ?>>Support</option>
                    </select>
                    <span class="error-message">Please select a department</span>
                </div>
                <div class="form-group">
                    <label for="role">Role *</label>
                    <select id="role" name="role" required>
                        <option value="">Select Role</option>
                        <option value="admin" <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Administrator</option>
                        <option value="manager" <?php echo (($_POST['role'] ?? '') === 'manager') ? 'selected' : ''; ?>>Manager</option>
                        <option value="employee" <?php echo (($_POST['role'] ?? '') === 'employee') ? 'selected' : ''; ?>>Employee</option>
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

        // Client-side validation (additional to server-side)
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
            
            // Validate password
            const password = document.getElementById('password');
            if (password.value.length < 8) {
                password.parentElement.nextElementSibling.style.display = 'block';
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
            // Redirect to login page or dashboard
            window.location.href = 'login.php?from=signup';
        }, 3000);
        <?php endif; ?>
    </script>
</body>
</html>