<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Get user information from session
$user_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
$role = $_SESSION['role'];
?>

<nav class="navbar">
    <div class="navbar-container">
        <!-- Logo Section -->
        <div class="navbar-logo">
            <svg class="logo-icon" viewBox="0 0 24 24">
                <path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5z"/>
            </svg>
            <span class="logo-text">E-Asset Management</span>
        </div>

        <!-- Navigation Links -->
        <div class="navbar-nav">
            <a href="../dashboard/index.php" class="nav-link">
                <i class="nav-icon">🏠</i>
                <span>Dashboard</span>
            </a>
            <a href="../assets/index.php" class="nav-link">
                <i class="nav-icon">📦</i>
                <span>Assets</span>
            </a>
            <a href="../reports/index.php" class="nav-link">
                <i class="nav-icon">📊</i>
                <span>Reports</span>
            </a>
            <?php if ($role === 'admin' || $role === 'manager'): ?>
            <a href="../admin/index.php" class="nav-link">
                <i class="nav-icon">⚙️</i>
                <span>Admin</span>
            </a>
            <?php endif; ?>
        </div>

        <!-- User Menu -->
        <div class="navbar-user">
            <div class="user-dropdown">
                <button class="user-toggle" id="userDropdownToggle">
                    <div class="user-avatar">
                        <span><?php echo strtoupper(substr($_SESSION['first_name'], 0, 1)); ?></span>
                    </div>
                    <div class="user-info">
                        <span class="user-name"><?php echo htmlspecialchars($user_name); ?></span>
                        <span class="user-role"><?php echo htmlspecialchars(ucfirst($role)); ?></span>
                    </div>
                    <i class="dropdown-arrow">▼</i>
                </button>
                
                <div class="user-dropdown-menu" id="userDropdownMenu">
                    <a href="../profile/index.php" class="dropdown-item">
                        <i class="item-icon">👤</i>
                        <span>My Profile</span>
                    </a>
                    <a href="../settings/index.php" class="dropdown-item">
                        <i class="item-icon">⚙️</i>
                        <span>Settings</span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="../auth/logout.php" class="dropdown-item logout-item" onclick="return confirm('Are you sure you want to logout?')">
                        <i class="item-icon">🚪</i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Mobile Menu Toggle -->
        <button class="mobile-toggle" id="mobileToggle">
            <span></span>
            <span></span>
            <span></span>
        </button>
    </div>
</nav>

<script>
// User dropdown toggle
document.getElementById('userDropdownToggle').addEventListener('click', function() {
    const dropdown = document.getElementById('userDropdownMenu');
    dropdown.classList.toggle('show');
});

// Mobile menu toggle
document.getElementById('mobileToggle').addEventListener('click', function() {
    const nav = document.querySelector('.navbar-nav');
    const userMenu = document.querySelector('.navbar-user');
    nav.classList.toggle('show');
    userMenu.classList.toggle('show');
    this.classList.toggle('active');
});

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('userDropdownMenu');
    const toggle = document.getElementById('userDropdownToggle');
    
    if (!toggle.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.classList.remove('show');
    }
});

// Highlight active page
const currentPage = window.location.pathname;
const navLinks = document.querySelectorAll('.nav-link');

navLinks.forEach(link => {
    if (currentPage.includes(link.getAttribute('href').split('/')[1])) {
        link.classList.add('active');
    }
});
</script>