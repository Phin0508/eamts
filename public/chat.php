<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

include("../auth/config/database.php");

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];

// Update user status to online
try {
    $stmt = $pdo->prepare("INSERT INTO chat_users (user_id, status, last_activity) 
                          VALUES (?, 'online', NOW()) 
                          ON DUPLICATE KEY UPDATE status = 'online', last_activity = NOW()");
    $stmt->execute([$user_id]);
} catch (PDOException $e) {
    error_log("Status update error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat - E-Asset Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8f9fc;
            color: #2d3748;
            overflow: hidden;
        }

        /* Main Container */
        .container {
            margin-left: 260px;
            transition: margin-left 0.3s ease;
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 260px;
        }

        .container.sidebar-collapsed {
            margin-left: 80px;
            left: 80px;
        }

        .chat-container {
            display: flex;
            height: 100%;
            width: 100%;
            overflow: hidden;
            background: white;
            border-radius: 0;
        }

        /* Sidebar - Users List */
        .chat-sidebar {
            width: 380px;
            min-width: 380px;
            border-right: 2px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            background: #fafbfc;
            height: 100%;
        }

        .chat-header {
            padding: 30px;
            border-bottom: 2px solid #e2e8f0;
            background: white;
        }

        .chat-header h2 {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .chat-header h2 i {
            color: #7c3aed;
        }

        .chat-header p {
            color: #718096;
            font-size: 15px;
            margin-bottom: 20px;
        }

        .user-profile-mini {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: linear-gradient(135deg, #f7f4fe 0%, #ede9fe 100%);
            border-radius: 12px;
            border: 2px solid #e9d5ff;
        }

        .user-profile-mini .avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        }

        .user-profile-mini .info h4 {
            font-size: 15px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 4px;
        }

        .user-profile-mini .info p {
            font-size: 13px;
            color: #718096;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .search-box {
            position: relative;
            margin: 24px;
            margin-bottom: 16px;
        }

        .search-box input {
            width: 100%;
            padding: 14px 16px 14px 48px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s;
            background: white;
        }

        .search-box input:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

        .search-box i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 16px;
        }

        .filter-tabs {
            display: flex;
            gap: 12px;
            padding: 0 24px 24px 24px;
        }

        .filter-tab {
            flex: 1;
            padding: 12px 16px;
            border: none;
            background: white;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            color: #718096;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 2px solid #e2e8f0;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .filter-tab:hover {
            border-color: #cbd5e0;
            background: #f7fafc;
        }

        .filter-tab.active {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            border-color: #7c3aed;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        }

        .users-list {
            flex: 1;
            overflow-y: auto;
            padding: 0 16px;
        }

        .user-item {
            display: flex;
            align-items: center;
            padding: 16px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 8px;
            position: relative;
            background: white;
            border: 2px solid transparent;
        }

        .user-item:hover {
            background: #f7fafc;
            border-color: #e2e8f0;
            transform: translateX(4px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .user-item.active {
            background: linear-gradient(135deg, #f7f4fe 0%, #ede9fe 100%);
            border-color: #7c3aed;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.15);
        }

        .user-avatar {
            position: relative;
            margin-right: 14px;
            flex-shrink: 0;
        }

        .avatar {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 18px;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        }

        .status-indicator {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .status-online { background: #10b981; }
        .status-away { background: #f59e0b; }
        .status-busy { background: #ef4444; }
        .status-offline { background: #94a3b8; }

        .user-info {
            flex: 1;
            min-width: 0;
        }

        .user-info h4 {
            font-size: 15px;
            color: #1a202c;
            margin-bottom: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-weight: 700;
        }

        .user-info p {
            font-size: 13px;
            color: #718096;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .user-info p i {
            font-size: 11px;
        }

        .unread-badge {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            font-size: 11px;
            padding: 6px 10px;
            border-radius: 20px;
            font-weight: 700;
            min-width: 24px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4);
            flex-shrink: 0;
        }

        /* Chat Area */
        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            height: 100%;
            background: #f8f9fc;
        }

        .chat-main-header {
            padding: 24px 30px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: white;
            min-height: 90px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .chat-main-header-left {
            display: flex;
            align-items: center;
            min-width: 0;
            gap: 16px;
        }

        .chat-main-header h3 {
            font-size: 20px;
            font-weight: 700;
            color: #1a202c;
        }

        .chat-status {
            font-size: 13px;
            color: #718096;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 4px;
            font-weight: 600;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .status-dot.online { background: #10b981; }
        .status-dot.away { background: #f59e0b; }
        .status-dot.busy { background: #ef4444; }
        .status-dot.offline { background: #94a3b8; }

        .chat-actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        .chat-btn {
            padding: 10px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            background: transparent;
            color: #718096;
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .chat-btn:hover {
            background: #f7fafc;
            color: #7c3aed;
            transform: translateY(-2px);
        }

        /* Messages Area */
        .messages-area {
            flex: 1;
            overflow-y: auto;
            padding: 30px;
            background: #f8f9fc;
        }

        .date-divider {
            text-align: center;
            margin: 32px 0 24px 0;
        }

        .date-divider span {
            background: white;
            color: #718096;
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 2px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .message {
            display: flex;
            margin-bottom: 20px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message.sent {
            flex-direction: row-reverse;
        }

        .message-avatar {
            margin: 0 14px;
            flex-shrink: 0;
        }

        .message-avatar .avatar {
            width: 40px;
            height: 40px;
            font-size: 14px;
        }

        .message-content {
            max-width: 65%;
        }

        .message-bubble {
            padding: 14px 18px;
            border-radius: 16px;
            margin-bottom: 6px;
            word-wrap: break-word;
            line-height: 1.6;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            font-size: 14px;
        }

        .message.received .message-bubble {
            background: white;
            border: 2px solid #e2e8f0;
            color: #2d3748;
            border-bottom-left-radius: 4px;
        }

        .message.sent .message-bubble {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            border-bottom-right-radius: 4px;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        }

        .message-time {
            font-size: 11px;
            color: #94a3b8;
            padding: 0 8px;
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
        }

        .message.sent .message-time {
            justify-content: flex-end;
        }

        .message-time i {
            font-size: 10px;
        }

        /* Message Input */
        .message-input-area {
            padding: 24px 30px;
            border-top: 2px solid #e2e8f0;
            background: white;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.04);
        }

        .message-input-container {
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }

        .message-input-wrapper {
            flex: 1;
            position: relative;
            background: #f8f9fc;
            border-radius: 16px;
            border: 2px solid #e2e8f0;
            transition: all 0.3s;
            min-width: 0;
        }

        .message-input-wrapper:focus-within {
            border-color: #7c3aed;
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

        .message-input {
            width: 100%;
            padding: 14px 120px 14px 18px;
            border: none;
            background: transparent;
            border-radius: 16px;
            font-size: 14px;
            resize: none;
            max-height: 120px;
            font-family: inherit;
            color: #2d3748;
        }

        .message-input:focus {
            outline: none;
        }

        .message-input::placeholder {
            color: #94a3b8;
        }

        .input-actions {
            position: absolute;
            right: 12px;
            bottom: 12px;
            display: flex;
            gap: 8px;
        }

        .input-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 18px;
            padding: 8px;
            transition: all 0.3s;
            border-radius: 8px;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .input-btn:hover {
            color: #7c3aed;
            background: rgba(124, 58, 237, 0.1);
            transform: scale(1.1);
        }

        .send-button {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 16px;
            cursor: pointer;
            font-weight: 700;
            transition: all 0.3s;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4);
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
            font-size: 14px;
        }

        .send-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(124, 58, 237, 0.5);
        }

        .send-button:active {
            transform: translateY(0);
        }

        .send-button:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        /* Empty State */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #94a3b8;
            text-align: center;
            padding: 40px;
        }

        .empty-state i {
            font-size: 80px;
            margin-bottom: 24px;
            opacity: 0.3;
            color: #7c3aed;
        }

        .empty-state h3 {
            font-size: 24px;
            margin-bottom: 12px;
            color: #1a202c;
            font-weight: 700;
        }

        .empty-state p {
            font-size: 15px;
            max-width: 400px;
            color: #718096;
        }

        .no-users-state {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }

        .no-users-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.3;
            color: #7c3aed;
        }

        .no-users-state p {
            color: #718096;
            font-size: 14px;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .container {
                margin-left: 80px;
                left: 80px;
            }

            .container.sidebar-collapsed {
                margin-left: 80px;
                left: 80px;
            }

            .chat-sidebar {
                width: 340px;
                min-width: 340px;
            }
        }

        @media (max-width: 768px) {
            .container {
                margin-left: 0;
                left: 0;
            }

            .container.sidebar-collapsed {
                margin-left: 0;
                left: 0;
            }

            .chat-sidebar {
                position: absolute;
                left: 0;
                top: 0;
                bottom: 0;
                width: 100%;
                min-width: 100%;
                z-index: 10;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }

            .chat-sidebar.mobile-active {
                transform: translateX(0);
            }

            .chat-main {
                width: 100%;
            }

            #backBtn {
                display: flex !important;
            }

            .message-content {
                max-width: 85%;
            }

            .chat-main-header h3 {
                font-size: 16px;
            }

            .send-button span {
                display: none;
            }

            .send-button {
                padding: 14px;
            }

            .chat-header {
                padding: 20px;
            }

            .message-input-area {
                padding: 16px 20px;
            }
        }

        /* Scrollbar */
        .users-list::-webkit-scrollbar,
        .messages-area::-webkit-scrollbar {
            width: 8px;
        }

        .users-list::-webkit-scrollbar-track,
        .messages-area::-webkit-scrollbar-track {
            background: transparent;
        }

        .users-list::-webkit-scrollbar-thumb,
        .messages-area::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        .users-list::-webkit-scrollbar-thumb:hover,
        .messages-area::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="container" id="mainContainer">
        <div class="chat-container">
            <!-- Sidebar - Users List -->
            <div class="chat-sidebar" id="chatSidebar">
                <div class="chat-header">
                    <h2><i class="fas fa-comments"></i> Messages</h2>
                    <p>Connect with your team</p>
                    <div class="user-profile-mini">
                        <div class="avatar">
                            <?php 
                            echo strtoupper(substr($_SESSION['first_name'], 0, 1) . substr($_SESSION['last_name'], 0, 1)); 
                            ?>
                        </div>
                        <div class="info">
                            <h4><?php echo htmlspecialchars($user_name); ?></h4>
                            <p><i class="fas fa-circle" style="color: #10b981; font-size: 8px;"></i> Online</p>
                        </div>
                    </div>
                </div>

                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchUsers" placeholder="Search conversations...">
                </div>

                <div class="filter-tabs">
                    <button class="filter-tab active" data-filter="all">
                        <i class="fas fa-users"></i> All
                    </button>
                    <button class="filter-tab" data-filter="online">
                        <i class="fas fa-circle" style="font-size: 8px;"></i> Online
                    </button>
                </div>

                <div class="users-list" id="usersList">
                    <!-- Users will be loaded here via JavaScript -->
                </div>
            </div>

            <!-- Chat Main Area -->
            <div class="chat-main" id="chatMain">
                <div class="empty-state" id="emptyState">
                    <i class="fas fa-comments"></i>
                    <h3>Select a conversation</h3>
                    <p>Choose a user from the sidebar to start chatting</p>
                </div>

                <div id="chatArea" style="display: none; flex-direction: column; flex: 1; height: 100%;">
                    <div class="chat-main-header">
                        <div class="chat-main-header-left">
                            <button class="chat-btn" id="backBtn" style="display: none;">
                                <i class="fas fa-arrow-left"></i>
                            </button>
                            <div class="user-avatar">
                                <div class="avatar" id="headerAvatar"></div>
                                <span class="status-indicator" id="headerStatus"></span>
                            </div>
                            <div>
                                <h3 id="headerName"></h3>
                                <div class="chat-status" id="headerStatusText">
                                    <span class="status-dot" id="headerStatusDot"></span>
                                    <span id="headerStatusLabel"></span>
                                </div>
                            </div>
                        </div>
                        <div class="chat-actions">
                            <button class="chat-btn" title="Search in conversation">
                                <i class="fas fa-search"></i>
                            </button>
                            <button class="chat-btn" title="More options">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                        </div>
                    </div>

                    <div class="messages-area" id="messagesArea">
                        <!-- Messages will be loaded here -->
                    </div>

                    <div class="message-input-area">
                        <div class="message-input-container">
                            <div class="message-input-wrapper">
                                <textarea 
                                    class="message-input" 
                                    id="messageInput" 
                                    placeholder="Type your message..."
                                    rows="1"
                                ></textarea>
                                <div class="input-actions">
                                    <button class="input-btn" title="Attach file">
                                        <i class="fas fa-paperclip"></i>
                                    </button>
                                    <button class="input-btn" title="Add emoji">
                                        <i class="fas fa-smile"></i>
                                    </button>
                                </div>
                            </div>
                            <button class="send-button" id="sendBtn">
                                <span>Send</span>
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const currentUserId = <?php echo $user_id; ?>;
        const currentUserName = "<?php echo htmlspecialchars($user_name); ?>";
        let activeConversationId = null;
        let activeUserId = null;
        let messagePolling = null;
        let statusPolling = null;
        let allUsers = [];
        let lastMessageCount = 0;

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadUsers();
            startStatusPolling();
            setupEventListeners();
            updateMainContainer();
        });

        function updateMainContainer() {
            const mainContainer = document.getElementById('mainContainer');
            const sidebar = document.querySelector('.sidebar');
            
            if (sidebar && sidebar.classList.contains('collapsed')) {
                mainContainer.classList.add('sidebar-collapsed');
            } else {
                mainContainer.classList.remove('sidebar-collapsed');
            }
        }

        // Listen for sidebar changes
        document.addEventListener('click', function(e) {
            if (e.target.closest('.toggle-sidebar') || e.target.closest('.toggle-btn')) {
                setTimeout(updateMainContainer, 50);
            }
        });

        // Observe sidebar changes
        const observer = new MutationObserver(updateMainContainer);
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) {
            observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
        }

        function setupEventListeners() {
            // Send message
            document.getElementById('sendBtn').addEventListener('click', sendMessage);
            document.getElementById('messageInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });

            // Auto-resize textarea
            document.getElementById('messageInput').addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 120) + 'px';
            });

            // Search users
            document.getElementById('searchUsers').addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                filterUsers(searchTerm);
            });

            // Filter tabs
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    
                    const filter = this.dataset.filter;
                    const searchTerm = document.getElementById('searchUsers').value.toLowerCase();
                    filterUsers(searchTerm, filter);
                });
            });

            // Back button for mobile
            document.getElementById('backBtn').addEventListener('click', function() {
                document.getElementById('chatSidebar').classList.add('mobile-active');
            });

            // Update status before leaving
            window.addEventListener('beforeunload', function() {
                updateUserStatus('offline');
            });
        }

        async function loadUsers() {
            try {
                const response = await fetch('../api/chat_get_users.php');
                const users = await response.json();
                allUsers = users;
                
                displayUsers(users);
            } catch (error) {
                console.error('Error loading users:', error);
                document.getElementById('usersList').innerHTML = `
                    <div class="no-users-state">
                        <i class="fas fa-exclamation-circle"></i>
                        <p>Could not load users</p>
                    </div>
                `;
            }
        }

        function displayUsers(users) {
            const usersList = document.getElementById('usersList');
            
            if (users.length === 0) {
                usersList.innerHTML = `
                    <div class="no-users-state">
                        <i class="fas fa-users"></i>
                        <p>No users available</p>
                    </div>
                `;
                return;
            }

            usersList.innerHTML = users.map(user => `
                <div class="user-item" data-status="${user.status}" onclick="openChat(${user.user_id}, '${escapeHtml(user.name)}', '${user.status}')">
                    <div class="user-avatar">
                        <div class="avatar">${user.initials}</div>
                        <span class="status-indicator status-${user.status}"></span>
                    </div>
                    <div class="user-info">
                        <h4>${escapeHtml(user.name)}</h4>
                        <p>
                            <i class="fas fa-building"></i>
                            ${escapeHtml(user.department)} • ${escapeHtml(user.role)}
                        </p>
                    </div>
                    ${user.unread > 0 ? `<span class="unread-badge">${user.unread}</span>` : ''}
                </div>
            `).join('');
        }

        function filterUsers(searchTerm = '', statusFilter = 'all') {
            // Get current active filter if not provided
            if (!statusFilter) {
                const activeTab = document.querySelector('.filter-tab.active');
                statusFilter = activeTab ? activeTab.dataset.filter : 'all';
            }

            let filtered = allUsers;

            // Apply search filter
            if (searchTerm) {
                filtered = filtered.filter(user => 
                    user.name.toLowerCase().includes(searchTerm) ||
                    user.department.toLowerCase().includes(searchTerm) ||
                    user.role.toLowerCase().includes(searchTerm)
                );
            }

            // Apply status filter
            if (statusFilter === 'online') {
                filtered = filtered.filter(user => user.status === 'online');
            }

            displayUsers(filtered);
        }

        async function openChat(userId, userName, status) {
            activeUserId = userId;
            
            // Update UI
            document.getElementById('emptyState').style.display = 'none';
            document.getElementById('chatArea').style.display = 'flex';
            
            // Update header
            const initials = userName.split(' ').map(n => n[0]).join('');
            document.getElementById('headerAvatar').textContent = initials;
            document.getElementById('headerName').textContent = userName;
            document.getElementById('headerStatus').className = `status-indicator status-${status}`;
            document.getElementById('headerStatusDot').className = `status-dot ${status}`;
            document.getElementById('headerStatusLabel').textContent = getStatusText(status);
            
            // Mark user as active
            document.querySelectorAll('.user-item').forEach(item => item.classList.remove('active'));
            event.currentTarget.classList.add('active');
            
            // Mobile view
            if (window.innerWidth <= 768) {
                document.getElementById('chatSidebar').classList.remove('mobile-active');
                document.getElementById('backBtn').style.display = 'flex';
            }
            
            // Get or create conversation
            await getOrCreateConversation(userId);
            
            // Load messages
            loadMessages();
            
            // Start polling for new messages
            if (messagePolling) clearInterval(messagePolling);
            messagePolling = setInterval(loadMessages, 2000);
        }

        async function getOrCreateConversation(userId) {
            try {
                const formData = new FormData();
                formData.append('user_id', userId);
                
                const response = await fetch('../api/chat_get_conversation.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                activeConversationId = data.conversation_id;
            } catch (error) {
                console.error('Error getting conversation:', error);
            }
        }

        async function loadMessages() {
            if (!activeConversationId) return;
            
            try {
                const response = await fetch(`../api/chat_get_messages.php?conversation_id=${activeConversationId}`);
                const messages = await response.json();
                
                const messagesArea = document.getElementById('messagesArea');
                const shouldScroll = messagesArea.scrollHeight - messagesArea.scrollTop <= messagesArea.clientHeight + 100;
                
                messagesArea.innerHTML = messages.map(msg => {
                    const isSent = msg.sender_id == currentUserId;
                    const initials = msg.sender_name.split(' ').map(n => n[0]).join('');
                    
                    return `
                        <div class="message ${isSent ? 'sent' : 'received'}">
                            ${!isSent ? `
                                <div class="message-avatar">
                                    <div class="avatar">${initials}</div>
                                </div>
                            ` : ''}
                            <div class="message-content">
                                <div class="message-bubble">${escapeHtml(msg.message)}</div>
                                <div class="message-time">
                                    <i class="fas fa-clock"></i>
                                    ${formatTime(msg.created_at)}
                                    ${isSent ? '<i class="fas fa-check-double" style="color: #10b981;"></i>' : ''}
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');
                
                if (shouldScroll) {
                    messagesArea.scrollTop = messagesArea.scrollHeight;
                }

                // Check for new messages for notifications
                if (messages.length > lastMessageCount && lastMessageCount > 0) {
                    const lastMsg = messages[messages.length - 1];
                    if (lastMsg.sender_id != currentUserId) {
                        document.title = '(1) New Message - Chat';
                        setTimeout(() => {
                            document.title = 'Chat - E-Asset Management System';
                        }, 3000);
                    }
                }
                lastMessageCount = messages.length;
            } catch (error) {
                console.error('Error loading messages:', error);
            }
        }

        async function sendMessage() {
            const input = document.getElementById('messageInput');
            const message = input.value.trim();
            
            if (!message || !activeConversationId) return;
            
            // Disable send button
            const sendBtn = document.getElementById('sendBtn');
            sendBtn.disabled = true;
            
            try {
                const formData = new FormData();
                formData.append('conversation_id', activeConversationId);
                formData.append('message', message);
                
                const response = await fetch('../api/chat_send_message.php', {
                    method: 'POST',
                    body: formData
                });
                
                if (response.ok) {
                    input.value = '';
                    input.style.height = 'auto';
                    loadMessages();
                    loadUsers(); // Refresh user list to update unread counts
                }
            } catch (error) {
                console.error('Error sending message:', error);
            } finally {
                sendBtn.disabled = false;
                input.focus();
            }
        }

        function startStatusPolling() {
            updateUserStatus('online');
            statusPolling = setInterval(() => {
                updateUserStatus('online');
                loadUsers();
            }, 30000); // Update every 30 seconds
        }

        async function updateUserStatus(status) {
            try {
                const formData = new FormData();
                formData.append('status', status);
                await fetch('../api/chat_update_status.php', {
                    method: 'POST',
                    body: formData
                });
            } catch (error) {
                console.error('Error updating status:', error);
            }
        }

        function getStatusText(status) {
            const texts = {
                'online': 'Online',
                'away': 'Away',
                'busy': 'Busy',
                'offline': 'Offline'
            };
            return texts[status] || 'Unknown';
        }

        function formatTime(timestamp) {
            const date = new Date(timestamp);
            const now = new Date();
            const diff = now - date;
            
            if (diff < 60000) return 'Just now';
            if (diff < 3600000) return Math.floor(diff / 60000) + 'm ago';
            if (diff < 86400000) return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
            
            const yesterday = new Date(now);
            yesterday.setDate(yesterday.getDate() - 1);
            if (date.toDateString() === yesterday.toDateString()) {
                return 'Yesterday ' + date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
            }
            
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' ' + 
                   date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML.replace(/\n/g, '<br>');
        }

        // Cleanup on page unload
        window.addEventListener('beforeunload', () => {
            if (messagePolling) clearInterval(messagePolling);
            if (statusPolling) clearInterval(statusPolling);
        });
    </script>
</body>
</html>