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
$username = $_SESSION['username'];
$email = $_SESSION['email'];
$role = $_SESSION['role'];
$department = $_SESSION['department'];
$login_time = isset($_SESSION['login_time']) ? date('Y-m-d H:i:s', $_SESSION['login_time']) : 'Unknown';
$user_initial = strtoupper(substr($_SESSION['first_name'], 0, 1));
?>

<div class="sidebar" id="sidebar">
    <!-- Sidebar Header -->
    <div class="sidebar-header">
        <button class="sidebar-toggle" id="sidebarToggle">
            <span></span>
            <span></span>
            <span></span>
        </button>
    </div>

    <!-- User Profile Section -->
    <div class="sidebar-user-profile">
        <div class="user-avatar-large">
            <span><?php echo $user_initial; ?></span>
        </div>
        <div class="user-details">
            <h3 class="user-name"><?php echo htmlspecialchars($user_name); ?></h3>
            <p class="user-role"><?php echo htmlspecialchars(ucfirst($role)); ?></p>
            <p class="user-department"><?php echo htmlspecialchars($department); ?></p>
        </div>
        <div class="user-status">
            <span class="status-indicator online"></span>
            <span class="status-text">Online</span>
        </div>
    </div>

    <!-- Navigation Menu -->
    <li class="nav-item">
        <a href="../public/dashboard.php" class="nav-link" data-tooltip="Dashboard">
            <i class="nav-icon">📊</i>
            <span class="nav-text">Dashboard</span>
        </a>
    </li>
    <li class="nav-item">
        <a href="../public/asset.php" class="nav-link" data-tooltip="My Assets">
            <i class="nav-icon">📦</i>
            <span class="nav-text">My Assets</span>
        </a>
    </li>
    <li class="nav-item">
        <a href="../public/userV.php" class="nav-link" data-tooltip="Requests">
            <i class="nav-icon">📋</i>
            <span class="nav-text">Requests</span>
        </a>
    </li>
    <li class="nav-item">
        <a href="../public/ticket.php" class="nav-link" data-tooltip="Tickets">
            <i class="nav-icon">🎫</i>
            <span class="nav-text">Tickets</span>
        </a>
    </li>
    <li class="nav-item">
        <a href="../reports/index.php" class="nav-link" data-tooltip="Reports">
            <i class="nav-icon">📈</i>
            <span class="nav-text">Reports</span>
        </a>
    </li>

    <?php if ($role === 'admin' || $role === 'manager'): ?>
        <li class="nav-divider"></li>
        <li class="nav-section-title">
            <span>Administration</span>
        </li>
        <li class="nav-item">
            <a href="../admin/users.php" class="nav-link">
                <i class="nav-icon"></i>
                <span class="nav-text">Users</span>
            </a>
        </li>
        <li class="nav-item">
            <a href="../admin/departments.php" class="nav-link">
                <i class="nav-icon"></i>
                <span class="nav-text">Departments</span>
            </a>
        </li>
        <li class="nav-item">
            <a href="../admin/settings.php" class="nav-link">
                <i class="nav-icon"></i>
                <span class="nav-text">System Settings</span>
            </a>
        </li>
    <?php endif; ?>

    <li class="nav-divider"></li>
    <li class="nav-item">
        <a href="../users/userProfile.php" class="nav-link">
            <i class="nav-icon"></i>
            <span class="nav-text">My Profile</span>
        </a>
    </li>
    <li class="nav-item">
        <a href="../settings/index.php" class="nav-link">
            <i class="nav-icon"></i>
            <span class="nav-text">Settings</span>
        </a>
    </li>
    </ul>
    </nav>

    <!-- Session Information -->
    <div class="sidebar-session-info">
        <div class="session-details">
            <h4>Session Info</h4>
            <div class="session-item">
                <span class="session-label">User ID:</span>
                <span class="session-value"><?php echo htmlspecialchars($_SESSION['user_id']); ?></span>
            </div>
            <div class="session-item">
                <span class="session-label">Login Time:</span>
                <span class="session-value"><?php echo htmlspecialchars($login_time); ?></span>
            </div>
            <div class="session-item">
                <span class="session-label">Session ID:</span>
                <span class="session-value"><?php echo htmlspecialchars(substr(session_id(), 0, 8)) . '...'; ?></span>
            </div>
        </div>
    </div>

    <!-- Logout Button -->
    <div class="sidebar-footer">
        <a href="../auth/api/logout.php" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
            <i class="logout-icon">🚪</i>
            <span class="logout-text">Logout</span>
        </a>
    </div>
</div>

<!-- Sidebar Overlay for Mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script>
    // FIXED: Proper sidebar toggle functionality
    function initializeSidebar() {
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const mobileToggle = document.getElementById('mobileToggle');
        const overlay = document.getElementById('sidebarOverlay');
        const mainContent = document.querySelector('.main-content');

        // Desktop sidebar toggle
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                console.log('Toggle clicked - current state:', sidebar.classList.contains('collapsed'));

                sidebar.classList.toggle('collapsed');

                // Force reflow to ensure transition works
                sidebar.offsetWidth;

                console.log('New state:', sidebar.classList.contains('collapsed'));
            });
        }

        // Mobile toggle
        if (mobileToggle) {
            mobileToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                if (window.innerWidth <= 768) {
                    sidebar.classList.toggle('show');
                    overlay.classList.toggle('show');
                }
            });
        }

        // Close sidebar when clicking overlay (mobile)
        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('show');
                this.classList.remove('show');
            });
        }

        // Handle window resize
        function handleResize() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
            }
        }

        window.addEventListener('resize', handleResize);

        // Initial setup
        handleResize();
    }

    // FIXED: Highlight active page based on current URL (no preventDefault)
    function highlightActivePage() {
        const currentPath = window.location.pathname;

        document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
            // Get the href attribute and create full path
            const linkHref = link.getAttribute('href');

            // Check if current page matches the link
            if (currentPath.endsWith(linkHref) || currentPath.includes(linkHref)) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });
    }

    // Initialize when DOM is loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar();
            highlightActivePage();
        });
    } else {
        initializeSidebar();
        highlightActivePage();
    }

    // Demo: Make stat cards clickable (if they exist on the page)
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('click', function() {
                const label = this.querySelector('.stat-label');
                if (label) {
                    alert(`${label.textContent} feature coming soon!`);
                }
            });
        });
    });
</script>