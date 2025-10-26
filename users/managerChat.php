<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

include("../auth/config/database.php");

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
$role = $_SESSION['role'];

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
    <link rel="stylesheet" href="../auth/inc/navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
        }

        .chat-container {
            display: flex;
            height: calc(100vh - 60px);
            margin-left: 250px;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        /* Sidebar - Users List */
        .chat-sidebar {
            width: 340px;
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            background: #fafbfc;
        }

        .chat-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .chat-header h2 {
            font-size: 1.25rem;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chat-header h2 i {
            font-size: 1.5rem;
        }

        .user-profile-mini {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-top: 0.5rem;
            padding: 0.75rem;
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
        }

        .user-profile-mini .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            color: #667eea;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }

        .user-profile-mini .info h4 {
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .user-profile-mini .info p {
            font-size: 0.75rem;
            opacity: 0.9;
        }

        .search-box {
            position: relative;
            margin: 1rem;
        }

        .search-box input {
            width: 100%;
            padding: 0.75rem 0.875rem 0.75rem 2.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.875rem;
            transition: all 0.2s;
        }

        .search-box input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            padding: 0 1rem 1rem 1rem;
        }

        .filter-tab {
            flex: 1;
            padding: 0.5rem;
            border: none;
            background: #e2e8f0;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
        }

        .filter-tab.active {
            background: #667eea;
            color: white;
        }

        .users-list {
            flex: 1;
            overflow-y: auto;
            padding: 0.5rem;
        }

        .user-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 0.5rem;
            position: relative;
        }

        .user-item:hover {
            background: #f1f5f9;
            transform: translateX(4px);
        }

        .user-item.active {
            background: linear-gradient(135deg, #e0e7ff 0%, #ede9fe 100%);
            border-left: 3px solid #667eea;
        }

        .user-avatar {
            position: relative;
            margin-right: 1rem;
        }

        .avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
        }

        .status-indicator {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
            font-size: 0.95rem;
            color: #2c3e50;
            margin-bottom: 0.35rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-weight: 600;
        }

        .user-info p {
            font-size: 0.8rem;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .user-info p i {
            font-size: 0.7rem;
        }

        .unread-badge {
            background: #667eea;
            color: white;
            font-size: 0.7rem;
            padding: 0.3rem 0.6rem;
            border-radius: 12px;
            font-weight: 700;
            min-width: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(102, 126, 234, 0.3);
        }

        .last-message {
            font-size: 0.75rem;
            color: #94a3b8;
            margin-top: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Chat Area */
        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #f8fafc;
        }

        .chat-main-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .chat-main-header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .chat-main-header h3 {
            font-size: 1.15rem;
            color: #2c3e50;
        }

        .chat-status {
            font-size: 0.8rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.35rem;
            margin-top: 0.25rem;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .status-dot.online { background: #10b981; }
        .status-dot.away { background: #f59e0b; }
        .status-dot.busy { background: #ef4444; }
        .status-dot.offline { background: #94a3b8; }

        .chat-actions {
            display: flex;
            gap: 0.5rem;
        }

        .chat-btn {
            padding: 0.5rem 0.875rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.2s;
        }

        .btn-icon {
            background: transparent;
            color: #64748b;
            padding: 0.625rem;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-icon:hover {
            background: #f1f5f9;
            color: #667eea;
        }

        /* Messages Area */
        .messages-area {
            flex: 1;
            overflow-y: auto;
            padding: 2rem 1.5rem;
            background: linear-gradient(to bottom, #f8fafc 0%, #ffffff 100%);
        }

        .date-divider {
            text-align: center;
            margin: 2rem 0 1.5rem 0;
        }

        .date-divider span {
            background: #e2e8f0;
            color: #64748b;
            padding: 0.375rem 1rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .message {
            display: flex;
            margin-bottom: 1.5rem;
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
            margin: 0 0.875rem;
        }

        .message-avatar .avatar {
            width: 36px;
            height: 36px;
            font-size: 0.875rem;
        }

        .message-content {
            max-width: 65%;
        }

        .message-bubble {
            padding: 0.875rem 1.125rem;
            border-radius: 16px;
            margin-bottom: 0.375rem;
            word-wrap: break-word;
            line-height: 1.5;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
        }

        .message.received .message-bubble {
            background: white;
            border: 1px solid #e2e8f0;
            color: #2c3e50;
            border-bottom-left-radius: 4px;
        }

        .message.sent .message-bubble {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .message-time {
            font-size: 0.7rem;
            color: #94a3b8;
            padding: 0 0.625rem;
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }

        .message.sent .message-time {
            justify-content: flex-end;
        }

        .message-time i {
            font-size: 0.65rem;
        }

        /* Message Input */
        .message-input-area {
            padding: 1.25rem 1.5rem;
            border-top: 1px solid #e2e8f0;
            background: white;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
        }

        .message-input-container {
            display: flex;
            gap: 0.875rem;
            align-items: flex-end;
        }

        .message-input-wrapper {
            flex: 1;
            position: relative;
            background: #f8fafc;
            border-radius: 24px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
        }

        .message-input-wrapper:focus-within {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .message-input {
            width: 100%;
            padding: 0.875rem 3.5rem 0.875rem 1.25rem;
            border: none;
            background: transparent;
            border-radius: 24px;
            font-size: 0.9rem;
            resize: none;
            max-height: 120px;
            font-family: inherit;
        }

        .message-input:focus {
            outline: none;
        }

        .input-actions {
            position: absolute;
            right: 0.875rem;
            bottom: 0.875rem;
            display: flex;
            gap: 0.5rem;
        }

        .input-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 1.1rem;
            padding: 0.375rem;
            transition: all 0.2s;
            border-radius: 6px;
        }

        .input-btn:hover {
            color: #667eea;
            background: rgba(102, 126, 234, 0.1);
        }

        .send-button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0.875rem 1.75rem;
            border-radius: 24px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .send-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
        }

        .send-button:active {
            transform: translateY(0);
        }

        .send-button:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
            box-shadow: none;
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
            padding: 2rem;
        }

        .empty-state i {
            font-size: 5rem;
            margin-bottom: 1.5rem;
            opacity: 0.4;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 0.75rem;
            color: #2c3e50;
        }

        .empty-state p {
            font-size: 1rem;
            max-width: 300px;
        }

        .no-users-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #94a3b8;
        }

        .no-users-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .chat-container {
                margin-left: 0;
            }

            .chat-sidebar {
                width: 100%;
                display: none;
            }

            .chat-sidebar.mobile-active {
                display: flex;
            }

            .chat-main {
                display: none;
            }

            .chat-main.mobile-active {
                display: flex;
            }

            .message-content {
                max-width: 85%;
            }

            .chat-main-header-left button {
                display: flex !important;
            }
        }

        /* Scrollbar */
        .users-list::-webkit-scrollbar,
        .messages-area::-webkit-scrollbar {
            width: 6px;
        }

        .users-list::-webkit-scrollbar-track,
        .messages-area::-webkit-scrollbar-track {
            background: transparent;
        }

        .users-list::-webkit-scrollbar-thumb,
        .messages-area::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }

        .users-list::-webkit-scrollbar-thumb:hover,
        .messages-area::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>
<body>
    <?php include("../auth/inc/Msidebar.php"); ?>

    <div class="chat-container">
        <!-- Sidebar - Users List -->
        <div class="chat-sidebar" id="chatSidebar">
            <div class="chat-header">
                <h2><i class="fas fa-comments"></i> Messages</h2>
                <div class="user-profile-mini">
                    <div class="avatar">
                        <?php 
                        echo strtoupper(substr($_SESSION['first_name'], 0, 1) . substr($_SESSION['last_name'], 0, 1)); 
                        ?>
                    </div>
                    <div class="info">
                        <h4><?php echo htmlspecialchars($user_name); ?></h4>
                        <p><i class="fas fa-circle" style="color: #10b981; font-size: 0.5rem;"></i> Online</p>
                    </div>
                </div>
            </div>

            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchUsers" placeholder="Search colleagues...">
            </div>

            <div class="filter-tabs">
                <button class="filter-tab active" data-filter="all">
                    <i class="fas fa-users"></i> All
                </button>
                <button class="filter-tab" data-filter="online">
                    <i class="fas fa-circle" style="color: #10b981;"></i> Online
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
                <p>Choose a colleague from the sidebar to start chatting</p>
            </div>

            <div id="chatArea" style="display: none; flex-direction: column; flex: 1;">
                <div class="chat-main-header">
                    <div class="chat-main-header-left">
                        <button class="btn-icon" id="backBtn" style="display: none;">
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
                        <button class="chat-btn btn-icon" title="Search in conversation">
                            <i class="fas fa-search"></i>
                        </button>
                        <button class="chat-btn btn-icon" title="More options">
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

    <script>
        const currentUserId = <?php echo $user_id; ?>;
        const currentUserName = "<?php echo htmlspecialchars($user_name); ?>";
        let activeConversationId = null;
        let activeUserId = null;
        let messagePolling = null;
        let statusPolling = null;
        let allUsers = [];

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadUsers();
            startStatusPolling();
            setupEventListeners();
        });

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
                    filterUsers('', filter);
                });
            });

            // Back button for mobile
            document.getElementById('backBtn').addEventListener('click', function() {
                document.getElementById('chatSidebar').classList.add('mobile-active');
                document.getElementById('chatMain').classList.remove('mobile-active');
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
                        <p>No colleagues available</p>
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
            let filtered = allUsers;

            // Apply search filter
            if (searchTerm) {
                filtered = filtered.filter(user => 
                    user.name.toLowerCase().includes(searchTerm) ||
                    user.department.toLowerCase().includes(searchTerm)
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
                document.getElementById('chatMain').classList.add('mobile-active');
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

        // Play notification sound on new message (optional)
        let lastMessageCount = 0;
        setInterval(() => {
            if (activeConversationId) {
                fetch(`../api/chat_get_messages.php?conversation_id=${activeConversationId}`)
                    .then(r => r.json())
                    .then(messages => {
                        if (messages.length > lastMessageCount && lastMessageCount > 0) {
                            // New message received - you can add notification here
                            document.title = '(1) New Message - Chat';
                            setTimeout(() => {
                                document.title = 'Chat - E-Asset Management System';
                            }, 3000);
                        }
                        lastMessageCount = messages.length;
                    });
            }
        }, 5000);
        
    </script>
</body>
</html>