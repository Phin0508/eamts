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
        }

        /* Sidebar - Users List */
        .chat-sidebar {
            width: 320px;
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            background: #fafbfc;
        }

        .chat-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            background: white;
        }

        .chat-header h2 {
            font-size: 1.25rem;
            color: #2c3e50;
            margin-bottom: 0.75rem;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 0.625rem 0.875rem 0.625rem 2.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.875rem;
        }

        .search-box i {
            position: absolute;
            left: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .users-list {
            flex: 1;
            overflow-y: auto;
            padding: 0.5rem;
        }

        .user-item {
            display: flex;
            align-items: center;
            padding: 0.875rem;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.2s;
            margin-bottom: 0.25rem;
        }

        .user-item:hover {
            background: #f1f5f9;
        }

        .user-item.active {
            background: #e0e7ff;
        }

        .user-avatar {
            position: relative;
            margin-right: 0.875rem;
        }

        .avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
        }

        .status-indicator {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid white;
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
            font-size: 0.9rem;
            color: #2c3e50;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-info p {
            font-size: 0.8rem;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .unread-badge {
            background: #667eea;
            color: white;
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 10px;
            font-weight: 600;
        }

        /* Chat Area */
        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .chat-main-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: white;
        }

        .chat-main-header-left {
            display: flex;
            align-items: center;
        }

        .chat-main-header h3 {
            font-size: 1.1rem;
            color: #2c3e50;
            margin-left: 0.875rem;
        }

        .chat-status {
            font-size: 0.8rem;
            color: #64748b;
            margin-left: 0.875rem;
        }

        .chat-actions {
            display: flex;
            gap: 0.5rem;
        }

        .chat-btn {
            padding: 0.5rem 0.875rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.2s;
        }

        .btn-icon {
            background: transparent;
            color: #64748b;
            padding: 0.5rem;
        }

        .btn-icon:hover {
            background: #f1f5f9;
            color: #2c3e50;
        }

        /* Messages Area */
        .messages-area {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
            background: #f8fafc;
        }

        .message {
            display: flex;
            margin-bottom: 1.5rem;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(10px);
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
            margin: 0 0.75rem;
        }

        .message-avatar .avatar {
            width: 36px;
            height: 36px;
            font-size: 0.875rem;
        }

        .message-content {
            max-width: 60%;
        }

        .message-bubble {
            padding: 0.75rem 1rem;
            border-radius: 12px;
            margin-bottom: 0.25rem;
            word-wrap: break-word;
        }

        .message.received .message-bubble {
            background: white;
            border: 1px solid #e2e8f0;
            color: #2c3e50;
        }

        .message.sent .message-bubble {
            background: #667eea;
            color: white;
        }

        .message-time {
            font-size: 0.75rem;
            color: #94a3b8;
            padding: 0 0.5rem;
        }

        .message.sent .message-time {
            text-align: right;
        }

        .typing-indicator {
            display: none;
            padding: 0.75rem 1rem;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            width: fit-content;
        }

        .typing-indicator.active {
            display: block;
        }

        .typing-dots {
            display: flex;
            gap: 0.25rem;
        }

        .typing-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #94a3b8;
            animation: typing 1.4s infinite;
        }

        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }

        @keyframes typing {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-10px); }
        }

        /* Message Input */
        .message-input-area {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e2e8f0;
            background: white;
        }

        .message-input-container {
            display: flex;
            gap: 0.75rem;
            align-items: flex-end;
        }

        .message-input-wrapper {
            flex: 1;
            position: relative;
        }

        .message-input {
            width: 100%;
            padding: 0.875rem 3rem 0.875rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            font-size: 0.9rem;
            resize: none;
            max-height: 120px;
            font-family: inherit;
        }

        .message-input:focus {
            outline: none;
            border-color: #667eea;
        }

        .input-actions {
            position: absolute;
            right: 0.75rem;
            bottom: 0.75rem;
            display: flex;
            gap: 0.5rem;
        }

        .input-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 1.1rem;
            padding: 0.25rem;
            transition: color 0.2s;
        }

        .input-btn:hover {
            color: #667eea;
        }

        .send-button {
            background: #667eea;
            color: white;
            border: none;
            padding: 0.875rem 1.5rem;
            border-radius: 24px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.2s;
        }

        .send-button:hover {
            background: #5568d3;
        }

        .send-button:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
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
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            font-size: 0.9rem;
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
    </style>
</head>
<body>
    <?php include("../auth/inc/sidebar.php"); ?>

    <div class="chat-container">
        <!-- Sidebar - Users List -->
        <div class="chat-sidebar" id="chatSidebar">
            <div class="chat-header">
                <h2>Messages</h2>
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchUsers" placeholder="Search users...">
                </div>
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
                            <div class="chat-status" id="headerStatusText"></div>
                        </div>
                    </div>
                    <div class="chat-actions">
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
                                placeholder="Type a message..."
                                rows="1"
                            ></textarea>
                            <div class="input-actions">
                                <button class="input-btn" title="Attach file">
                                    <i class="fas fa-paperclip"></i>
                                </button>
                                <button class="input-btn" title="Emoji">
                                    <i class="fas fa-smile"></i>
                                </button>
                            </div>
                        </div>
                        <button class="send-button" id="sendBtn">
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
                document.querySelectorAll('.user-item').forEach(item => {
                    const name = item.querySelector('h4').textContent.toLowerCase();
                    item.style.display = name.includes(searchTerm) ? 'flex' : 'none';
                });
            });

            // Back button for mobile
            document.getElementById('backBtn').addEventListener('click', function() {
                document.getElementById('chatSidebar').classList.add('mobile-active');
                document.getElementById('chatMain').classList.remove('mobile-active');
            });

            // Update status before leaving
            window.addEventListener('beforeunload', updateUserStatus.bind(null, 'offline'));
        }

        async function loadUsers() {
            try {
                const response = await fetch('../api/chat_get_users.php');
                const users = await response.json();
                
                const usersList = document.getElementById('usersList');
                usersList.innerHTML = users.map(user => `
                    <div class="user-item" onclick="openChat(${user.user_id}, '${user.name}', '${user.status}')">
                        <div class="user-avatar">
                            <div class="avatar">${user.initials}</div>
                            <span class="status-indicator status-${user.status}"></span>
                        </div>
                        <div class="user-info">
                            <h4>${user.name}</h4>
                            <p>${user.department} • ${user.role}</p>
                        </div>
                        ${user.unread > 0 ? `<span class="unread-badge">${user.unread}</span>` : ''}
                    </div>
                `).join('');
            } catch (error) {
                console.error('Error loading users:', error);
            }
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
            document.getElementById('headerStatusText').textContent = getStatusText(status);
            
            // Mark user as active
            document.querySelectorAll('.user-item').forEach(item => item.classList.remove('active'));
            event.currentTarget.classList.add('active');
            
            // Mobile view
            if (window.innerWidth <= 768) {
                document.getElementById('chatSidebar').classList.remove('mobile-active');
                document.getElementById('chatMain').classList.add('mobile-active');
                document.getElementById('backBtn').style.display = 'block';
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
                const shouldScroll = messagesArea.scrollHeight - messagesArea.scrollTop === messagesArea.clientHeight;
                
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
                                <div class="message-time">${formatTime(msg.created_at)}</div>
                            </div>
                        </div>
                    `;
                }).join('');
                
                if (shouldScroll || messages.length > 0) {
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
                }
            } catch (error) {
                console.error('Error sending message:', error);
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
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Cleanup on page unload
        window.addEventListener('beforeunload', () => {
            if (messagePolling) clearInterval(messagePolling);
            if (statusPolling) clearInterval(statusPolling);
        });
    </script>
</body>
</html>