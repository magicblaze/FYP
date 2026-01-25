<?php
// 在页面最顶部启动会话
session_start();

// 检查用户是否登录
if (!isset($_SESSION['user'])) {
    header('Location: ../login.php');
    exit();
}

// 获取用户信息
$user = $_SESSION['user'];
$userType = $_SESSION['user']['role'] ?? 'manager';
$userId = $_SESSION['user']['managerid'] ?? $_SESSION['user']['id'] ?? 0;
$userName = $_SESSION['user']['mname'] ?? $_SESSION['user']['name'] ?? 'Manager';
$userAvatar = substr($userName, 0, 1);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - Manager | HappyDesign</title>

    <!-- 样式文件 -->
    <link rel="stylesheet" href="../css/ChatRoom_style.css">
    <link rel="stylesheet" href="../css/Manager_style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- 聊天页面专用样式 -->
    <style>
        .page-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .page-title {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 0;
        }
        
        .chat-container {
            height: calc(100vh - 180px);
            min-height: 600px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        /* 侧边栏样式 */
        .chat-sidebar {
            background-color: #f8f9fa;
            border-right: 1px solid #e9ecef;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        
        .chat-header {
            padding: 20px;
            background-color: #fff;
            border-bottom: 1px solid #e9ecef;
        }
        
        .chat-header h5 {
            margin: 0;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .chat-list {
            flex: 1;
            overflow-y: auto;
            padding: 0;
        }
        
        /* 用户列表项 */
        .chat-user-item {
            display: flex;
            align-items: center;
            padding: 16px 20px;
            cursor: pointer;
            transition: all 0.2s;
            border-bottom: 1px solid #f0f0f0;
            background-color: #fff;
        }
        
        .chat-user-item:hover {
            background-color: #f8f9fa;
        }
        
        .chat-user-item.active {
            background-color: #e3f2fd;
            border-left: 4px solid #2196F3;
        }
        
        .chat-user-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
            margin-right: 16px;
            flex-shrink: 0;
        }
        
        .chat-user-avatar.online::after {
            content: '';
            position: absolute;
            bottom: 4px;
            right: 4px;
            width: 10px;
            height: 10px;
            background-color: #4CAF50;
            border-radius: 50%;
            border: 2px solid white;
        }
        
        .user-info {
            flex: 1;
            min-width: 0;
        }
        
        .user-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 4px;
            font-size: 15px;
        }
        
        .user-last-message {
            color: #6c757d;
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .user-last-time {
            font-size: 12px;
            color: #95a5a6;
            white-space: nowrap;
        }
        
        .unread-badge {
            background-color: #e74c3c;
            color: white;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            min-width: 20px;
            text-align: center;
        }
        
        /* 主聊天区域 */
        .chat-main {
            display: flex;
            flex-direction: column;
            height: 100%;
            background-color: #fff;
        }
        
        .current-chat-header {
            display: flex;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
            background-color: #fff;
        }
        
        .header-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 20px;
            margin-right: 16px;
            flex-shrink: 0;
        }
        
        .header-info h5 {
            margin: 0 0 4px 0;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .header-info small {
            color: #7f8c8d;
            font-size: 13px;
        }
        
        /* 消息区域 */
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            background-color: #f5f7fa;
            display: flex;
            flex-direction: column;
        }
        
        /* 没有选择聊天的状态 */
        .no-chats-selected {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            text-align: center;
            color: #7f8c8d;
        }
        
        .no-chats-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        .no-chats-title {
            font-size: 20px;
            margin-bottom: 10px;
            font-weight: 500;
        }
        
        .no-chats-subtitle {
            font-size: 15px;
            opacity: 0.7;
        }
        
        /* 消息包装器 */
        .message-wrapper {
            display: flex;
            margin-bottom: 20px;
            max-width: 80%;
        }
        
        .message-wrapper.self {
            align-self: flex-end;
            flex-direction: row-reverse;
        }
        
        .message-wrapper.other {
            align-self: flex-start;
        }
        
        /* 消息头像 */
        .message-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            margin: 0 12px;
            flex-shrink: 0;
            align-self: flex-end;
        }
        
        .message-wrapper.self .message-avatar {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
        
        /* 消息气泡 */
        .message-bubble {
            background-color: white;
            border-radius: 18px;
            padding: 12px 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            position: relative;
            max-width: 100%;
            word-wrap: break-word;
        }
        
        .message-wrapper.self .message-bubble {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-bottom-right-radius: 4px;
        }
        
        .message-wrapper.other .message-bubble {
            background-color: white;
            border-bottom-left-radius: 4px;
        }
        
        /* 消息内容 */
        .message-content {
            line-height: 1.5;
            font-size: 15px;
        }
        
        /* 消息时间 */
        .message-time {
            font-size: 11px;
            opacity: 0.8;
            margin-top: 6px;
            text-align: right;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 4px;
        }
        
        .message-wrapper.self .message-time {
            color: rgba(255,255,255,0.8);
        }
        
        .message-wrapper.other .message-time {
            color: #7f8c8d;
        }
        
        /* 消息发送者 */
        .message-sender {
            font-size: 12px;
            color: #7f8c8d;
            margin-bottom: 4px;
            font-weight: 500;
        }
        
        /* 日期分隔符 */
        .date-separator {
            text-align: center;
            margin: 24px 0;
            position: relative;
        }
        
        .date-separator::before {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            top: 50%;
            height: 1px;
            background-color: #e0e0e0;
            z-index: 1;
        }
        
        .date-separator span {
            background-color: #f5f7fa;
            padding: 6px 16px;
            border-radius: 16px;
            font-size: 13px;
            color: #7f8c8d;
            position: relative;
            z-index: 2;
            font-weight: 500;
        }
        
        /* 消息输入区域 */
        .chat-input {
            border-top: 1px solid #e9ecef;
            background-color: #fff;
            padding: 20px;
        }
        
        .message-input-container {
            display: flex;
            align-items: flex-end;
            gap: 12px;
        }
        
        #messageInput {
            flex: 1;
            border: 1px solid #e0e0e0;
            border-radius: 24px;
            padding: 12px 20px;
            resize: none;
            font-size: 15px;
            line-height: 1.5;
            min-height: 48px;
            max-height: 120px;
            transition: all 0.3s;
        }
        
        #messageInput:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        #messageInput:disabled {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }
        
        .input-btn {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: 1px solid #e0e0e0;
            background-color: white;
            color: #667eea;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            flex-shrink: 0;
        }
        
        .input-btn:hover {
            background-color: #f8f9fa;
            border-color: #667eea;
        }
        
        .input-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        #sendMessage {
            background-color: #667eea;
            color: white;
            border: none;
        }
        
        #sendMessage:hover:not(:disabled) {
            background-color: #5a6fd8;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        /* 附件预览 */
        .attachment-preview {
            background-color: #f8f9fa;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 16px;
            border: 1px solid #e9ecef;
        }
        
        .attachment-preview img {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
        }
        
        .attachment-name {
            font-weight: 500;
            color: #2c3e50;
            margin-bottom: 4px;
        }
        
        .attachment-size {
            font-size: 12px;
            color: #7f8c8d;
        }
        
        .remove-attachment {
            color: #e74c3c;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: background-color 0.3s;
        }
        
        .remove-attachment:hover {
            background-color: #ffeaea;
        }
        
        /* 打字指示器 */
        .typing-indicator {
            padding: 8px 20px;
            font-size: 13px;
            color: #7f8c8d;
            font-style: italic;
            min-height: 20px;
        }
        
        /* 文件消息 */
        .file-message {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background-color: white;
            border-radius: 12px;
            border: 1px solid #e9ecef;
            margin-top: 8px;
        }
        
        .file-icon {
            font-size: 24px;
            color: #667eea;
        }
        
        .file-info {
            flex: 1;
        }
        
        .file-name {
            font-weight: 500;
            color: #2c3e50;
        }
        
        .file-size {
            font-size: 12px;
            color: #7f8c8d;
        }
        
        /* 图片消息 */
        .image-message img {
            max-width: 300px;
            max-height: 300px;
            border-radius: 12px;
            cursor: pointer;
            transition: transform 0.3s;
        }
        
        .image-message img:hover {
            transform: scale(1.02);
        }
        
        /* 新消息动画 */
        .new-message {
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* 移动端响应式 */
        @media (max-width: 768px) {
            .page-container {
                padding: 10px;
            }
            
            .toggle-sidebar {
                display: block !important;
                margin-left: auto;
            }
            
            .chat-container {
                height: calc(100vh - 120px);
                min-height: 500px;
            }
            
            .chat-sidebar {
                position: fixed;
                top: 0;
                left: -100%;
                width: 100%;
                height: 100%;
                z-index: 1050;
                background-color: white;
                transition: left 0.3s ease;
                padding-top: 70px;
            }
            
            .chat-sidebar.show {
                left: 0;
            }
            
            .message-wrapper {
                max-width: 90%;
            }
            
            .image-message img {
                max-width: 200px;
                max-height: 200px;
            }
        }
        
        /* 加载指示器 */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(102, 126, 234, 0.3);
            border-radius: 50%;
            border-top-color: #667eea;
            animation: spin 1s ease-in-out infinite;
            margin-right: 8px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* 错误提示 */
        .error-alert {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 400px;
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .error-alert.hide {
            animation: slideOut 0.3s ease-out forwards;
        }
        
        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
    </style>
</head>

<body>
    <!-- 导航栏 -->
    <nav class="nav-bar">
        <div class="nav-container">
            <a href="#" class="nav-brand">HappyDesign</a>
            <div class="nav-links">
                <a href="Manager_introduct.php">Introduct</a>
                <a href="Manager_MyOrder.php">MyOrder</a>
                <a href="Manager_Massage.php" class="active">Messages</a>
                <a href="Manager_Schedule.php">Schedule</a>
            </div>
            <div class="nav-user">
                <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
                <a href="../logout.php" class="logout-btn">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </div>
    </nav>

    <!-- 主内容区 -->
    <div class="page-container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="page-title">Messages</h1>
            <button class="btn btn-primary toggle-sidebar d-none" id="toggleSidebar">
                <i class="bi bi-list me-2"></i> Conversations
            </button>
        </div>
        
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="row g-0 chat-container">
                    <!-- 侧边栏 - 对话列表 -->
                    <div class="col-md-4 col-lg-3 chat-sidebar" id="chatSidebar">
                        <div class="chat-header">
                            <h5 class="mb-0">Conversations</h5>
                        </div>
                        <div class="chat-list" id="chatUserList">
                            <!-- 对话列表会在这里动态加载 -->
                            <div class="text-center py-5">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-3 text-muted">Loading conversations...</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 主聊天区域 -->
                    <div class="col-md-8 col-lg-9 chat-main">
                        <!-- 当前对话头部 -->
                        <div class="current-chat-header">
                            <div class="header-avatar">M</div>
                            <div class="header-info">
                                <h5 id="currentChatName">Select a conversation</h5>
                                <small id="chatStatus">Click on a conversation to start messaging</small>
                            </div>
                            <div id="chatActions" class="ms-auto d-none">
                                <button class="btn btn-sm btn-outline-secondary me-2" id="chatInfoBtn" title="Chat info">
                                    <i class="bi bi-info-circle"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" id="clearChatBtn" title="Clear chat">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- 消息容器 -->
                        <div class="chat-messages" id="chatMessages">
                            <div class="no-chats-selected">
                                <div>
                                    <i class="bi bi-chat-dots no-chats-icon"></i>
                                    <h5 class="no-chats-title">No conversation selected</h5>
                                    <p class="no-chats-subtitle">Select a conversation from the list to start messaging</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 消息输入 -->
                        <div class="chat-input">
                            <div id="attachmentPreview"></div>
                            <div class="message-input-container">
                                <button class="input-btn" type="button" id="emojiBtn" title="Emoji">
                                    <i class="bi bi-emoji-smile"></i>
                                </button>
                                <button class="input-btn" type="button" id="attachFileBtn" title="Attach file">
                                    <i class="bi bi-paperclip"></i>
                                </button>
                                <input type="file" id="fileInput" class="d-none" accept="image/*,.pdf,.doc,.docx,.txt">
                                <textarea class="form-control" id="messageInput" 
                                          placeholder="Type a message..." 
                                          rows="1" 
                                          disabled></textarea>
                                <button class="input-btn" type="button" id="sendMessageBtn" disabled>
                                    <i class="bi bi-send"></i>
                                </button>
                            </div>
                            <div class="typing-indicator" id="typingIndicator"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript 库 -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // 从PHP传递用户信息到JavaScript
        const CHAT_USER = {
            id: <?php echo json_encode($userId); ?>,
            type: <?php echo json_encode($userType); ?>,
            name: <?php echo json_encode($userName); ?>,
            avatar: <?php echo json_encode($userAvatar); ?>
        };
        
        console.log('Chat user initialized:', CHAT_USER);
    </script>
    
    <script>
        $(document).ready(function() {
            // 全局变量
            let currentRoomId = null;
            let currentRoomName = null;
            let pollInterval = null;
            let lastMessageId = 0;
            let pendingFile = null;
            let isTyping = false;
            let typingTimeout = null;
            
            // 初始化聊天
            function initChat() {
                console.log('Initializing chat for user:', CHAT_USER);
                
                // 加载对话列表
                loadChatRooms();
                
                // 设置事件监听器
                setupEventListeners();
                
                // 检查移动端并显示切换按钮
                checkMobileView();
                
                // 监听窗口大小变化
                $(window).on('resize', checkMobileView);
            }
            
            // 检查是否为移动端视图
            function checkMobileView() {
                if ($(window).width() <= 768) {
                    $('#toggleSidebar').removeClass('d-none');
                } else {
                    $('#toggleSidebar').addClass('d-none');
                    $('#chatSidebar').removeClass('show');
                }
            }
            
            // 设置事件监听器
            function setupEventListeners() {
                // 消息输入事件
                $('#messageInput').on('keypress', function(e) {
                    if (e.which === 13 && !e.shiftKey) {
                        e.preventDefault();
                        sendMessage();
                        return false;
                    }
                });
                
                // 自动调整文本区域高度
                $('#messageInput').on('input', function() {
                    this.style.height = 'auto';
                    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
                    
                    // 打字指示器
                    if (currentRoomId && !isTyping) {
                        isTyping = true;
                        sendTypingIndicator();
                        
                        if (typingTimeout) clearTimeout(typingTimeout);
                        typingTimeout = setTimeout(() => {
                            isTyping = false;
                        }, 2000);
                    }
                });
                
                // 发送按钮
                $('#sendMessageBtn').on('click', sendMessage);
                
                // 附件按钮
                $('#attachFileBtn').on('click', function() {
                    $('#fileInput').click();
                });
                
                // 文件选择
                $('#fileInput').on('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        previewAttachment(file);
                    }
                });
                
                // 切换侧边栏（移动端）
                $('#toggleSidebar').on('click', function() {
                    $('#chatSidebar').toggleClass('show');
                });
                
                // 表情按钮（占位符）
                $('#emojiBtn').on('click', function() {
                    showError('Emoji picker coming soon!');
                });
                
                // 清空聊天
                $('#clearChatBtn').on('click', function() {
                    if (confirm('Are you sure you want to clear this chat? This action cannot be undone.')) {
                        $('#chatMessages').html(`
                            <div class="text-center py-5">
                                <i class="bi bi-trash no-chats-icon"></i>
                                <h5 class="no-chats-title">Chat cleared</h5>
                                <p class="no-chats-subtitle">All messages have been removed</p>
                            </div>
                        `);
                        showSuccess('Chat cleared successfully');
                    }
                });
                
                // 聊天信息按钮
                $('#chatInfoBtn').on('click', function() {
                    if (currentRoomId) {
                        showError('Chat info feature coming soon!');
                    }
                });
                
                // 移动端点击外部关闭侧边栏
                $(document).on('click', function(e) {
                    if ($(window).width() <= 768) {
                        if (!$(e.target).closest('#chatSidebar').length && 
                            !$(e.target).closest('#toggleSidebar').length &&
                            $('#chatSidebar').hasClass('show')) {
                            $('#chatSidebar').removeClass('show');
                        }
                    }
                });
            }
            
            // 加载对话列表
            function loadChatRooms() {
                const apiUrl = '../Public/ChatApi.php?action=listRooms&user_type=' + 
                              encodeURIComponent(CHAT_USER.type) + '&user_id=' + CHAT_USER.id;
                
                console.log('Loading chat rooms from:', apiUrl);
                
                $.ajax({
                    url: apiUrl,
                    method: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        console.log('Chat rooms response:', response);
                        
                        if (response && !response.error) {
                            displayChatRooms(response);
                        } else {
                            const errorMsg = response?.error || 'Failed to load conversations';
                            showError(errorMsg);
                            $('#chatUserList').html(`
                                <div class="text-center py-5">
                                    <i class="bi bi-exclamation-triangle text-danger" style="font-size: 48px;"></i>
                                    <p class="mt-3 text-danger">${escapeHtml(errorMsg)}</p>
                                    <button class="btn btn-sm btn-outline-primary mt-2" onclick="loadChatRooms()">
                                        <i class="bi bi-arrow-clockwise"></i> Retry
                                    </button>
                                </div>
                            `);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error loading chat rooms:', error);
                        showError('Network error. Please check your connection.');
                        
                        $('#chatUserList').html(`
                            <div class="text-center py-5">
                                <i class="bi bi-wifi-off text-danger" style="font-size: 48px;"></i>
                                <p class="mt-3 text-danger">Connection failed</p>
                                <button class="btn btn-sm btn-outline-primary mt-2" onclick="loadChatRooms()">
                                    <i class="bi bi-arrow-clockwise"></i> Retry
                                </button>
                            </div>
                        `);
                    }
                });
            }
            
            // 显示对话列表
            function displayChatRooms(rooms) {
                const $userList = $('#chatUserList');
                
                if (!rooms || rooms.length === 0) {
                    $userList.html(`
                        <div class="text-center py-5">
                            <i class="bi bi-chat-dots text-muted" style="font-size: 48px; opacity: 0.3;"></i>
                            <p class="mt-3 text-muted">No conversations yet</p>
                            <button class="btn btn-sm btn-outline-primary mt-2" id="startNewChatBtn">
                                <i class="bi bi-plus-circle me-1"></i> Start New Chat
                            </button>
                        </div>
                    `);
                    
                    // 添加新对话按钮事件
                    $('#startNewChatBtn').on('click', function() {
                        showError('New chat feature coming soon!');
                    });
                    
                    return;
                }
                
                // 清空列表
                $userList.empty();
                
                // 按最后消息时间排序（最新的在前）
                rooms.sort((a, b) => {
                    const timeA = a.last_time ? new Date(a.last_time).getTime() : 0;
                    const timeB = b.last_time ? new Date(b.last_time).getTime() : 0;
                    return timeB - timeA;
                });
                
                // 添加每个对话
                rooms.forEach(room => {
                    const roomId = room.ChatRoomid || room.id || 0;
                    const roomName = room.other_name || room.roomname || `Chat ${roomId}`;
                    const lastMessage = room.last_message || 'No messages yet';
                    const lastTime = room.last_time ? formatTime(new Date(room.last_time)) : 'Just now';
                    const unreadCount = room.unread_count || 0;
                    const avatarText = (roomName || 'C').charAt(0).toUpperCase();
                    
                    // 创建对话项
                    const $roomItem = $(`
                        <div class="chat-user-item" data-room-id="${roomId}" data-room-name="${escapeHtml(roomName)}">
                            <div class="chat-user-avatar ${room.online ? 'online' : ''}">
                                ${avatarText}
                            </div>
                            <div class="user-info">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="user-name">${escapeHtml(roomName)}</div>
                                    <div class="user-last-time">${lastTime}</div>
                                </div>
                                <div class="user-last-message">${escapeHtml(lastMessage)}</div>
                            </div>
                            ${unreadCount > 0 ? 
                                `<div class="unread-badge">${unreadCount > 99 ? '99+' : unreadCount}</div>` 
                                : ''}
                        </div>
                    `);
                    
                    // 点击事件
                    $roomItem.on('click', function() {
                        selectChatRoom(room);
                    });
                    
                    $userList.append($roomItem);
                });
                
                // 如果有对话但未选中，选择第一个
                if (rooms.length > 0 && !currentRoomId) {
                    selectChatRoom(rooms[0]);
                }
            }
            
            // 选择对话
            function selectChatRoom(room) {
                const roomId = room.ChatRoomid || room.id || 0;
                
                if (!roomId) {
                    showError('Invalid chat room');
                    return;
                }
                
                // 停止之前的轮询
                if (pollInterval) {
                    clearInterval(pollInterval);
                }
                
                // 更新当前对话信息
                currentRoomId = roomId;
                currentRoomName = room.other_name || room.roomname || `Chat ${roomId}`;
                
                // 更新UI状态
                $('.chat-user-item').removeClass('active');
                $(`.chat-user-item[data-room-id="${roomId}"]`).addClass('active');
                
                // 更新聊天头部
                $('#currentChatName').text(currentRoomName);
                $('#chatStatus').html('<span class="text-success">●</span> Online');
                $('#chatActions').removeClass('d-none');
                
                // 隐藏"未选择对话"消息
                $('.no-chats-selected').hide();
                
                // 启用消息输入
                $('#messageInput').prop('disabled', false).attr('placeholder', 'Type a message...');
                $('#sendMessageBtn').prop('disabled', false);
                
                // 加载消息
                loadMessages();
                
                // 开始轮询新消息
                startPolling();
                
                // 手机端关闭侧边栏
                if ($(window).width() <= 768) {
                    $('#chatSidebar').removeClass('show');
                }
                
                console.log('Selected chat room:', roomId, currentRoomName);
            }
            
            // 加载消息
            function loadMessages() {
                if (!currentRoomId) return;
                
                $.ajax({
                    url: '../Public/ChatApi.php?action=getMessages&room=' + currentRoomId,
                    method: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if (response && !response.error) {
                            displayMessages(response);
                            
                            // 更新最后一条消息ID
                            if (response.length > 0) {
                                lastMessageId = Math.max(...response.map(m => m.messageid || m.id || 0));
                            }
                        } else {
                            showError('Failed to load messages');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error loading messages:', error);
                        showError('Error loading messages');
                    }
                });
            }
            
            // 开始轮询新消息
            function startPolling() {
                if (pollInterval) {
                    clearInterval(pollInterval);
                }
                
                pollInterval = setInterval(() => {
                    pollNewMessages();
                    checkTypingIndicator();
                }, 3000); // 每3秒检查一次
            }
            
            // 轮询新消息
            function pollNewMessages() {
                if (!currentRoomId) return;
                
                $.ajax({
                    url: '../Public/ChatApi.php?action=getMessages&room=' + currentRoomId + '&since=' + lastMessageId,
                    method: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if (response && response.length > 0) {
                            response.forEach(message => {
                                displayMessage(message, false);
                                
                                // 更新最后一条消息ID
                                const messageId = message.messageid || message.id || 0;
                                if (messageId > lastMessageId) {
                                    lastMessageId = messageId;
                                }
                            });
                            
                            // 滚动到底部
                            scrollToBottom();
                        }
                    },
                    error: function() {
                        // 静默失败，会在下一次轮询时重试
                    }
                });
            }
            
            // 检查打字指示器
            function checkTypingIndicator() {
                if (!currentRoomId) return;
                
                $.ajax({
                    url: '../Public/ChatApi.php?action=getTyping&room=' + currentRoomId,
                    method: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if (response && response.typing && 
                            !(response.sender === CHAT_USER.type && response.sender_id == CHAT_USER.id)) {
                            $('#typingIndicator').html(`
                                <i class="bi bi-pencil-square"></i> 
                                <span class="ms-1">${response.sender || 'Someone'} is typing...</span>
                            `);
                        } else {
                            $('#typingIndicator').empty();
                        }
                    }
                });
            }
            
            // 发送打字指示器
            function sendTypingIndicator() {
                if (!currentRoomId) return;
                
                $.ajax({
                    url: '../Public/ChatApi.php?action=typing',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        room: currentRoomId,
                        sender_type: CHAT_USER.type,
                        sender_id: CHAT_USER.id
                    }),
                    success: function() {
                        // 打字指示器已发送
                    }
                });
            }
            
            // 显示多条消息
            function displayMessages(messages) {
                const $messages = $('#chatMessages');
                $messages.empty();
                
                if (!messages || messages.length === 0) {
                    $messages.html(`
                        <div class="text-center py-5">
                            <i class="bi bi-chat-left no-chats-icon"></i>
                            <h5 class="no-chats-title">No messages yet</h5>
                            <p class="no-chats-subtitle">Send a message to start the conversation</p>
                        </div>
                    `);
                    return;
                }
                
                // 按日期分组消息
                let currentDate = null;
                
                messages.forEach(message => {
                    const messageDate = new Date(message.timestamp).toDateString();
                    
                    // 添加日期分隔符
                    if (currentDate !== messageDate) {
                        currentDate = messageDate;
                        const formattedDate = formatDate(new Date(message.timestamp));
                        $messages.append(`
                            <div class="date-separator">
                                <span>${formattedDate}</span>
                            </div>
                        `);
                    }
                    
                    displayMessage(message, true);
                });
                
                scrollToBottom();
            }
            
            // 显示单条消息
            function displayMessage(message, isInitialLoad = true) {
                const $messages = $('#chatMessages');
                
                // 检查是否已经显示过这条消息
                const messageId = message.messageid || message.id || 0;
                if (messageId && $(`[data-message-id="${messageId}"]`).length > 0) {
                    return; // 避免重复显示
                }
                
                const isSelf = message.sender_type === CHAT_USER.type && 
                              parseInt(message.sender_id) === parseInt(CHAT_USER.id);
                
                const senderName = message.sender_name || 
                                  `${message.sender_type} ${message.sender_id}`;
                
                const content = message.content || '';
                const time = message.timestamp ? new Date(message.timestamp) : new Date();
                const formattedTime = formatMessageTime(time);
                
                // 头像文本
                const avatarText = isSelf ? CHAT_USER.avatar : (senderName || 'U').charAt(0).toUpperCase();
                
                // 消息内容
                let messageContent = '';
                
                // 检查是否有附件
                if (message.attachment || (message.uploaded_file && message.uploaded_file.filepath)) {
                    const fileUrl = message.attachment || 
                                   (message.uploaded_file ? message.uploaded_file.filepath : '');
                    const fileName = message.uploaded_file ? 
                                   message.uploaded_file.filename : 'File';
                    
                    if (message.message_type === 'image') {
                        messageContent = `
                            <div class="image-message">
                                <img src="${escapeHtml(fileUrl)}" 
                                     alt="${escapeHtml(fileName)}" 
                                     onclick="openImageModal('${escapeHtml(fileUrl)}')">
                            </div>
                        `;
                    } else {
                        messageContent = `
                            <div class="file-message">
                                <div class="file-icon">
                                    <i class="bi bi-file-earmark"></i>
                                </div>
                                <div class="file-info">
                                    <div class="file-name">${escapeHtml(fileName)}</div>
                                    <div class="file-size">${formatFileSize(message.uploaded_file?.size || 0)}</div>
                                </div>
                                <a href="${escapeHtml(fileUrl)}" 
                                   download="${escapeHtml(fileName)}" 
                                   class="input-btn" title="Download">
                                    <i class="bi bi-download"></i>
                                </a>
                            </div>
                        `;
                    }
                } else if (message.share) {
                    // 分享内容（设计或产品）
                    const share = message.share;
                    messageContent = `
                        <div class="share-message">
                            <div class="card">
                                <div class="row g-0">
                                    <div class="col-4">
                                        <img src="${escapeHtml(share.image || '')}" 
                                             class="img-fluid rounded-start" 
                                             alt="${escapeHtml(share.title || '')}">
                                    </div>
                                    <div class="col-8">
                                        <div class="card-body p-3">
                                            <h6 class="card-title">${escapeHtml(share.title || 'Shared Item')}</h6>
                                            <p class="card-text small text-muted">${escapeHtml(share.type || 'design')}</p>
                                            <a href="${escapeHtml(share.url || '#')}" 
                                               target="_blank" 
                                               class="btn btn-sm btn-outline-primary">
                                                View
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    // 普通文本消息
                    messageContent = `<div class="message-content">${escapeHtml(content)}</div>`;
                }
                
                const messageHtml = `
                    <div class="message-wrapper ${isSelf ? 'self' : 'other'} ${isInitialLoad ? '' : 'new-message'}" 
                         data-message-id="${messageId}">
                        ${!isSelf ? `<div class="message-avatar">${avatarText}</div>` : ''}
                        <div class="${isSelf ? 'text-end' : ''}">
                            ${!isSelf ? `<div class="message-sender">${escapeHtml(senderName)}</div>` : ''}
                            <div class="message-bubble">
                                ${messageContent}
                                <div class="message-time">
                                    <span>${formattedTime}</span>
                                    ${isSelf ? 
                                        (message.read ? 
                                            '<i class="bi bi-check2-all ms-1"></i>' : 
                                            '<i class="bi bi-check2 ms-1"></i>') 
                                        : ''}
                                </div>
                            </div>
                        </div>
                        ${isSelf ? `<div class="message-avatar">${avatarText}</div>` : ''}
                    </div>
                `;
                
                $messages.append(messageHtml);
            }
            
            // 发送消息
            function sendMessage() {
                const messageText = $('#messageInput').val().trim();
                const file = pendingFile;
                
                if (!messageText && !file) {
                    return;
                }
                
                if (!currentRoomId) {
                    showError('Please select a conversation first');
                    return;
                }
                
                // 准备FormData
                const formData = new FormData();
                formData.append('sender_type', CHAT_USER.type);
                formData.append('sender_id', CHAT_USER.id);
                formData.append('room', currentRoomId);
                
                if (messageText) {
                    formData.append('content', messageText);
                }
                
                if (file) {
                    formData.append('attachment', file);
                    if (file.type.startsWith('image/')) {
                        formData.append('message_type', 'image');
                    }
                }
                
                // 禁用发送按钮并显示加载状态
                $('#sendMessageBtn').prop('disabled', true);
                $('#sendMessageBtn').html('<span class="loading-spinner"></span>');
                
                $.ajax({
                    url: '../Public/ChatApi.php?action=sendMessage',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        console.log('Send message response:', response);
                        
                        if (response.ok && response.message) {
                            // 清空输入
                            $('#messageInput').val('');
                            $('#messageInput').css('height', 'auto');
                            
                            // 清空附件预览
                            $('#attachmentPreview').empty();
                            pendingFile = null;
                            $('#fileInput').val('');
                            
                            // 显示发送的消息
                            displayMessage(response.message, false);
                            
                            // 更新最后消息ID
                            const messageId = response.message.messageid || 
                                            response.message.id || 
                                            response.id || 0;
                            if (messageId > lastMessageId) {
                                lastMessageId = messageId;
                            }
                            
                            // 滚动到底部
                            scrollToBottom();
                            
                            // 清空打字指示器
                            $('#typingIndicator').empty();
                            isTyping = false;
                            
                        } else {
                            showError('Failed to send message: ' + (response.error || 'Unknown error'));
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error sending message:', error);
                        showError('Failed to send message. Please try again.');
                    },
                    complete: function() {
                        // 恢复发送按钮
                        $('#sendMessageBtn').prop('disabled', false);
                        $('#sendMessageBtn').html('<i class="bi bi-send"></i>');
                    }
                });
            }
            
            // 预览附件
            function previewAttachment(file) {
                const $preview = $('#attachmentPreview');
                $preview.empty();
                
                const fileSize = formatFileSize(file.size);
                const fileName = escapeHtml(file.name);
                
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        $preview.html(`
                            <div class="attachment-preview">
                                <img src="${e.target.result}" alt="Preview">
                                <div>
                                    <div class="attachment-name">${fileName}</div>
                                    <div class="attachment-size">${fileSize}</div>
                                </div>
                                <div class="remove-attachment" onclick="removeAttachment()" title="Remove">
                                    <i class="bi bi-x-lg"></i>
                                </div>
                            </div>
                        `);
                    };
                    reader.readAsDataURL(file);
                } else {
                    $preview.html(`
                        <div class="attachment-preview">
                            <i class="bi bi-file-earmark" style="font-size: 2.5rem;"></i>
                            <div>
                                <div class="attachment-name">${fileName}</div>
                                <div class="attachment-size">${fileSize}</div>
                            </div>
                            <div class="remove-attachment" onclick="removeAttachment()" title="Remove">
                                <i class="bi bi-x-lg"></i>
                            </div>
                        </div>
                    `);
                }
                
                pendingFile = file;
            }
            
            // 移除附件
            window.removeAttachment = function() {
                $('#attachmentPreview').empty();
                pendingFile = null;
                $('#fileInput').val('');
            };
            
            // 打开图片模态框
            window.openImageModal = function(imageUrl) {
                const modalHtml = `
                    <div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Image Preview</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body text-center p-0">
                                    <img src="${imageUrl}" class="img-fluid" alt="Preview">
                                </div>
                                <div class="modal-footer">
                                    <a href="${imageUrl}" download class="btn btn-primary">
                                        <i class="bi bi-download me-1"></i> Download
                                    </a>
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                        Close
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                $('body').append(modalHtml);
                const modal = new bootstrap.Modal(document.getElementById('imagePreviewModal'));
                modal.show();
                
                // 移除模态框
                $('#imagePreviewModal').on('hidden.bs.modal', function() {
                    $(this).remove();
                });
            };
            
            // 工具函数
            function escapeHtml(text) {
                if (!text) return '';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
            
            function formatTime(date) {
                if (!(date instanceof Date)) {
                    date = new Date(date);
                }
                const now = new Date();
                const diffMs = now - date;
                const diffMins = Math.floor(diffMs / 60000);
                const diffHours = Math.floor(diffMs / 3600000);
                const diffDays = Math.floor(diffMs / 86400000);
                
                if (diffMins < 1) return 'Just now';
                if (diffMins < 60) return `${diffMins}m ago`;
                if (diffHours < 24) return `${diffHours}h ago`;
                if (diffDays < 7) return `${diffDays}d ago`;
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            }
            
            function formatMessageTime(date) {
                return date.toLocaleTimeString('en-US', { 
                    hour: '2-digit', 
                    minute: '2-digit',
                    hour12: true 
                }).toLowerCase();
            }
            
            function formatDate(date) {
                const today = new Date();
                const yesterday = new Date(today);
                yesterday.setDate(yesterday.getDate() - 1);
                
                if (date.toDateString() === today.toDateString()) {
                    return 'Today';
                } else if (date.toDateString() === yesterday.toDateString()) {
                    return 'Yesterday';
                } else {
                    return date.toLocaleDateString('en-US', { 
                        weekday: 'long',
                        month: 'short', 
                        day: 'numeric',
                        year: date.getFullYear() !== today.getFullYear() ? 'numeric' : undefined
                    });
                }
            }
            
            function formatFileSize(bytes) {
                if (bytes === 0) return '0 B';
                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }
            
            function scrollToBottom() {
                const $messages = $('#chatMessages');
                $messages.scrollTop($messages[0].scrollHeight);
            }
            
            function showError(message) {
                showAlert(message, 'danger');
            }
            
            function showSuccess(message) {
                showAlert(message, 'success');
            }
            
            function showAlert(message, type) {
                const alertId = 'alert-' + Date.now();
                const alertHtml = `
                    <div id="${alertId}" class="alert alert-${type} alert-dismissible fade show error-alert" role="alert">
                        <i class="bi bi-${type === 'danger' ? 'exclamation-triangle' : 'check-circle'} me-2"></i>
                        <span>${escapeHtml(message)}</span>
                        <button type="button" class="btn-close" onclick="closeAlert('${alertId}')"></button>
                    </div>
                `;
                
                $('body').append(alertHtml);
                
                // 5秒后自动消失
                setTimeout(() => {
                    closeAlert(alertId);
                }, 5000);
            }
            
            window.closeAlert = function(alertId) {
                const $alert = $('#' + alertId);
                if ($alert.length) {
                    $alert.addClass('hide');
                    setTimeout(() => {
                        $alert.remove();
                    }, 300);
                }
            };
            
            // 初始化聊天
            initChat();
        });
    </script>
</body>
</html>