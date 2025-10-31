<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authorization check - Only managers can use this sidebar
if (!isset($_SESSION['user_id']) || strtolower(trim($_SESSION['role'])) !== 'manager') {
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
            <p class="user-role">Manager</p>
            <p class="user-department"><?php echo htmlspecialchars($department); ?></p>
        </div>
        <div class="user-status">
            <span class="status-indicator"></span>
            <span class="status-text">Online</span>
        </div>
    </div>

    <!-- Navigation Menu -->
    <nav class="sidebar-nav">
        <ul class="nav-menu">
            <!-- MANAGER DASHBOARD & PERSONAL -->
            <li class="nav-item">
                <a href="managerDashboard.php" class="nav-link">
                    <i class="nav-icon"></i>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="managerAsset.php" class="nav-link">
                    <i class="nav-icon"></i>
                    <span class="nav-text">My Assets</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="managerCreateticket.php" class="nav-link">
                    <i class="nav-icon"></i>
                    <span class="nav-text">Create Ticket</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="managerTicket.php" class="nav-link">
                    <i class="nav-icon"></i>
                    <span class="nav-text">My Ticket</span>
                </a>
            </li>
            
            <!-- DEPARTMENT MANAGEMENT SECTION -->
            <li class="nav-divider"></li>
            <li class="nav-section-title">
                <span>Department Management</span>
            </li>
            
            <li class="nav-item">
                <a href="departmentAsset.php" class="nav-link">
                    <i class="nav-icon"></i>
                    <span class="nav-text">Department Assets</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="departmentTicket.php" class="nav-link">
                    <i class="nav-icon"></i>
                    <span class="nav-text">Department Tickets</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="../users/managerChat.php" class="nav-link">
                    <i class="nav-icon"></i>
                    <span class="nav-text">Messages</span>
                    <span id="unreadBadge" class="badge-notification" style="display: none;"></span>
                </a>
            </li>
            <li class="nav-item">
                <a href="teamMembers.php" class="nav-link">
                    <i class="nav-icon"></i>
                    <span class="nav-text">Team Members</span>
                </a>
            </li>
            
            <!-- REPORTS & ANALYTICS SECTION -->
            <li class="nav-divider"></li>
            <li class="nav-section-title">
                <span>Reports & Analytics</span>
            </li>
            
            <li class="nav-item">
                <a href="departmentReport.php" class="nav-link">
                    <i class="nav-icon"></i>
                    <span class="nav-text">Department Reports</span>
                </a>
            </li>
            
            <!-- COMMON MENU ITEMS -->
            <li class="nav-divider"></li>
            <li class="nav-item">
                <a href="managerProfile.php" class="nav-link">
                    <i class="nav-icon"></i>
                    <span class="nav-text">My Profile</span>
                </a>
            </li>
            <li class="nav-item">
                    <a href="#" class="nav-link coming-soon-link" onclick="showComingSoon(event)">
                        <i class="nav-icon icon-settings"></i>
                        <span class="nav-text">System Settings</span>
                        <span class="badge-coming-soon">Soon</span>
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
                <span class="session-value">Manager</span>
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
            <i class="logout-icon"></i>
            <span class="logout-text">Logout</span>
        </a>
    </div>
</div>
<!-- Coming Soon Modal -->
<div class="coming-soon-modal" id="comingSoonModal">
    <div class="coming-soon-content">
        <div class="coming-soon-icon">
            <i class="fas fa-rocket"></i>
        </div>
        <h2>Coming Soon!</h2>
        <p>This feature is currently under development and will be available in a future update.</p>
        <p class="coming-soon-detail">We're working hard to bring you the best experience possible.</p>
        <button class="btn-close-modal" onclick="closeComingSoon()">
            <i class="fas fa-times"></i> Got it
        </button>
    </div>
</div>
<style>
/* Coming Soon Badge */
.badge-coming-soon {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    padding: 3px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-left: auto;
    animation: pulse-coming-soon 2s infinite;
}

@keyframes pulse-coming-soon {
    0%, 100% {
        transform: scale(1);
        opacity: 1;
    }
    50% {
        transform: scale(1.05);
        opacity: 0.9;
    }
}

.coming-soon-link {
    opacity: 0.7;
    cursor: pointer;
}

.coming-soon-link:hover {
    opacity: 1;
}

/* Coming Soon Modal */
.coming-soon-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    z-index: 10000;
    animation: fadeIn 0.3s ease;
    align-items: center;
    justify-content: center;
}

.coming-soon-modal.show {
    display: flex;
}

.coming-soon-content {
    background: white;
    border-radius: 20px;
    padding: 40px;
    max-width: 450px;
    width: 90%;
    text-align: center;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.coming-soon-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 40px;
    color: white;
    animation: rocket-bounce 1s ease-in-out infinite;
}

@keyframes rocket-bounce {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-10px);
    }
}

.coming-soon-content h2 {
    font-size: 28px;
    font-weight: 700;
    color: #1a202c;
    margin-bottom: 12px;
}

.coming-soon-content p {
    color: #4a5568;
    font-size: 15px;
    line-height: 1.6;
    margin-bottom: 12px;
}

.coming-soon-detail {
    color: #718096;
    font-size: 14px;
    margin-bottom: 24px;
}

.btn-close-modal {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    padding: 12px 32px;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.btn-close-modal:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
}

.btn-close-modal i {
    margin-right: 8px;
}
</style>
<!-- Sidebar Overlay for Mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script>
    console.log('Manager Sidebar Loaded - Role: Manager');
    // Coming Soon Modal Functions
    function showComingSoon(event) {
        event.preventDefault();
        const modal = document.getElementById('comingSoonModal');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeComingSoon() {
        const modal = document.getElementById('comingSoonModal');
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
    }

    // Close modal when clicking outside
    document.addEventListener('click', function(event) {
        const modal = document.getElementById('comingSoonModal');
        if (event.target === modal) {
            closeComingSoon();
        }
    });

    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeComingSoon();
        }
    });
    
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
        const currentFile = currentPath.split('/').pop();
        
        document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
            const linkHref = link.getAttribute('href');
            const linkFile = linkHref.split('/').pop();
            
            // Match by filename
            if (currentFile === linkFile) {
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

    // Unread message counter update
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

    // Update every 60 seconds
    setInterval(updateUnreadCount, 60000);
    updateUnreadCount(); // Initial load
</script>