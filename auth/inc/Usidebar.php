<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// NOTE: Authorization checks should be done in the parent file BEFORE including this sidebar
// This file only handles display logic

// Get user information from session (with safety checks)
if (!isset($_SESSION['user_id'])) {
    // If session doesn't exist, don't display sidebar
    return;
}

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
    <nav class="sidebar-nav">
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="../public/dashboard.php" class="nav-link">
                    <i class="nav-icon">📊</i>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../public/asset.php" class="nav-link">
                    <i class="nav-icon">💼</i>
                    <span class="nav-text">My Assets</span>
                </a>
            </li>
            
            <?php if ($role === 'manager'): ?>
            <li class="nav-divider"></li>
            <li class="nav-section-title">
                <span>Department Management</span>
            </li>
            
            <li class="nav-item">
                <a href="../manager/department_assets.php" class="nav-link">
                    <i class="nav-icon">🏢</i>
                    <span class="nav-text">Department Assets</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../manager/department_tickets.php" class="nav-link">
                    <i class="nav-icon">🎫</i>
                    <span class="nav-text">Department Tickets</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../manager/department_requests.php" class="nav-link">
                    <i class="nav-icon">📝</i>
                    <span class="nav-text">Department Requests</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../manager/team_members.php" class="nav-link">
                    <i class="nav-icon">👥</i>
                    <span class="nav-text">Team Members</span>
                </a>
            </li>
            
            <li class="nav-divider"></li>
            <li class="nav-section-title">
                <span>Reports & Analytics</span>
            </li>
            
            <li class="nav-item">
                <a href="../manager/department_reports.php" class="nav-link">
                    <i class="nav-icon">📈</i>
                    <span class="nav-text">Department Reports</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../manager/asset_analytics.php" class="nav-link">
                    <i class="nav-icon">📉</i>
                    <span class="nav-text">Asset Analytics</span>
                </a>
            </li>
            <?php endif; ?>
            
            <li class="nav-divider"></li>
            <li class="nav-item">
                <a href="../users/userProfile.php" class="nav-link">
                    <i class="nav-icon">👤</i>
                    <span class="nav-text">My Profile</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../settings/index.php" class="nav-link">
                    <i class="nav-icon">⚙️</i>
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
                <span class="session-label">Department:</span>
                <span class="session-value"><?php echo htmlspecialchars($department); ?></span>
            </div>
            <div class="session-item">
                <span class="session-label">Login Time:</span>
                <span class="session-value"><?php echo htmlspecialchars($login_time); ?></span>
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
    // Sidebar toggle functionality
    function initializeSidebar() {
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const mobileToggle = document.getElementById('mobileToggle');
        const overlay = document.getElementById('sidebarOverlay');

        // Desktop sidebar toggle
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                sidebar.classList.toggle('collapsed');
                sidebar.offsetWidth;
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
        handleResize();
    }

    // Highlight active page
    function highlightActivePage() {
        const currentPath = window.location.pathname;
        
        document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
            const linkHref = link.getAttribute('href');
            
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
</script>