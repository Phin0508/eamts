<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authorization check - Only employees can use this sidebar
if (!isset($_SESSION['user_id']) || strtolower(trim($_SESSION['role'])) !== 'employee') {
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
            <p class="user-role">Employee</p>
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
            <!-- EMPLOYEE MENU ITEMS -->
            <li class="nav-item">
                <a href="userDashboard.php" class="nav-link">
                    <i class="nav-icon">📊</i>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="userAsset.php" class="nav-link">
                    <i class="nav-icon">💼</i>
                    <span class="nav-text">My Assets</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="userTicket.php" class="nav-link">
                    <i class="nav-icon">🎫</i>
                    <span class="nav-text">My Tickets</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="userCreateticket.php" class="nav-link">
                    <i class="nav-icon">➕</i>
                    <span class="nav-text">Create Ticket</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../users/userChat.php" class="nav-link" <?php if (basename($_SERVER['PHP_SELF']) == 'userChat.php') echo 'class="active"'; ?>>
                    <i class="nav-icon">💬</i>
                    <span class="nav-text">Messages</span>
                    <span id="unreadBadge" class="badge-notification" style="display: none;"></span>
                </a>
            </li>

            <!-- COMMON MENU ITEMS -->
            <li class="nav-divider"></li>
            <li class="nav-item">
                <a href="userProfile.php" class="nav-link">
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
                <span class="session-label">Role:</span>
                <span class="session-value">Employee</span>
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
    console.log('Employee Sidebar Loaded');

    function initializeSidebar() {
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const mobileToggle = document.getElementById('mobileToggle');
        const overlay = document.getElementById('sidebarOverlay');

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                sidebar.classList.toggle('collapsed');
                sidebar.offsetWidth;
            });
        }

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

        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('show');
                this.classList.remove('show');
            });
        }

        function handleResize() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
            }
        }

        window.addEventListener('resize', handleResize);
        handleResize();
    }

    function highlightActivePage() {
        const currentPath = window.location.pathname;
        const currentFile = currentPath.split('/').pop();

        document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
            const linkHref = link.getAttribute('href');
            const linkFile = linkHref.split('/').pop();

            if (currentFile === linkFile) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initializeSidebar();
            highlightActivePage();
        });
    } else {
        initializeSidebar();
        highlightActivePage();
    }

    function updateUnreadCount() {
        fetch('../api/chat_get_unread_count.php')
            .then(r => r.json())
            .then(data => {
                const badge = document.getElementById('unreadBadge');
                if (data.unread > 0) {
                    badge.textContent = data.unread;
                    badge.style.display = 'inline-block';
                    // Update page title
                    document.title = `(${data.unread}) Messages - E-Asset Management`;
                } else {
                    badge.style.display = 'none';
                }
            })
            .catch(err => console.error('Error loading unread count:', err));
    }

    // Update every 10 seconds
    setInterval(updateUnreadCount, 10000);
    updateUnreadCount(); // Initial load
</script>