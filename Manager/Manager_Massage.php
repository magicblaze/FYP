<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - Manager | HappyDesign</title>

    <link rel="stylesheet" href="../css/ChatRoom_style.css">
    <link rel="stylesheet" href="../css/Manager_style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="nav-bar">
        <div class="nav-container">
            <a href="#" class="nav-brand">HappyDesign</a>
            <div class="nav-links">
                <a href="Manager_introduct.php">Introduct</a>
                <a href="Manager_MyOrder.php">MyOrder</a>
                <a href="Manager_Massage.php" class="active">Messages</a>
                <a href="Manager_Schedule.php">Schedule</a>
            </div>
            <div class="user-info">
                <span>Welcome, <?php echo htmlspecialchars($user_name); ?></span>
                <a href="../logout.php" class="btn-logout">Logout</a>
            </div>
        </div>
    </nav>




    <!-- Main Content -->
    <div class="page-container">
        <div class="d-flex justify-between align-center mb-4">
            <h1 class="page-title">Messages</h1>
            <button class="btn btn-primary toggle-sidebar" id="toggleSidebar">
                <i class="bi bi-list"></i> Conversations
            </button>
        </div>
        
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="row g-0 chat-container">
                    <!-- Sidebar - Chat List -->
                    <div class="col-md-4 col-lg-3 chat-sidebar" id="chatSidebar">
                        <div class="chat-header">
                            <h5 class="mb-0">Conversations</h5>
                        </div>
                        <div id="chatUserList" class="list-group list-group-flush">
                            <!-- Chat users will be loaded here -->
                            <div class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2 text-muted">Loading conversations...</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Main Chat Area -->
                    <div class="col-md-8 col-lg-9 chat-main">
                        <div class="chat-header d-flex justify-content-between align-items-center">
                            <div class="current-chat-header" id="currentChatInfo">
                                <div class="header-avatar">M</div>
                                <div class="header-info">
                                    <h5>Select a conversation</h5>
                                    <small id="chatStatus">Click to start messaging</small>
                                </div>
                            </div>
                            <div id="chatActions" class="d-none">
                                <button class="btn btn-sm btn-outline-secondary" id="clearChat">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Messages Container -->
                        <div class="chat-messages" id="chatMessages">
                            <div class="no-chats-selected">
                                <div>
                                    <i class="bi bi-chat-dots no-chats-icon"></i>
                                    <h5 class="no-chats-title">No conversation selected</h5>
                                    <p class="no-chats-subtitle">Select a conversation from the list to start messaging</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Message Input -->
                        <div class="chat-input">
                            <div id="attachmentPreview"></div>
                            <div class="message-input-container">
                                <button class="input-btn" type="button" id="emojiBtn" title="Emoji">
                                    <i class="bi bi-emoji-smile"></i>
                                </button>
                                <button class="input-btn" type="button" id="attachFile" title="Attach file">
                                    <i class="bi bi-paperclip"></i>
                                </button>
                                <input type="file" id="fileInput" class="d-none" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx">
                                <textarea class="form-control" id="messageInput" placeholder="Type a message..." rows="1" disabled></textarea>
                                <button class="input-btn" type="button" id="sendMessage" disabled>
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

    <!-- Include jQuery -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    
    <script>
        $(document).ready(function() {
            let currentUser = null;
            let currentRoomId = null;
            let userId = null;
            let userType = 'manager';
            let pollInterval = null;
            let lastMessageId = 0;
            let pendingFile = null;
            
            // Get user info from session (simulated)
            function getUserInfo() {
                return {
                    id: 1, // Manager's ID
                    name: 'Manager',
                    role: 'manager',
                    avatar: 'M'
                };
            }
            
            // Initialize
            function initChat() {
                const userInfo = getUserInfo();
                userId = userInfo.id;
                userType = userInfo.role;
                
                loadChatRooms();
                
                // Set up event listeners
                $('#messageInput').on('keypress', function(e) {
                    if (e.which === 13 && !e.shiftKey) {
                        e.preventDefault();
                        sendMessage();
                    }
                });
                
                // Auto-resize textarea
                $('#messageInput').on('input', function() {
                    this.style.height = 'auto';
                    this.style.height = (this.scrollHeight) + 'px';
                });
                
                $('#sendMessage').on('click', sendMessage);
                
                $('#attachFile').on('click', function() {
                    $('#fileInput').click();
                });
                
                $('#fileInput').on('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        previewAttachment(file);
                    }
                });
                
                $('#toggleSidebar').on('click', function() {
                    $('#chatSidebar').toggleClass('show');
                });
                
                // Close sidebar when clicking outside on mobile
                $(document).on('click', function(e) {
                    if ($(window).width() <= 768) {
                        if (!$(e.target).closest('#chatSidebar').length && 
                            !$(e.target).closest('#toggleSidebar').length &&
                            $('#chatSidebar').hasClass('show')) {
                            $('#chatSidebar').removeClass('show');
                        }
                    }
                });
                
                // Enable input when a chat is selected
                $('#messageInput').prop('disabled', false);
                $('#sendMessage').prop('disabled', false);
                
                // Emoji button placeholder
                $('#emojiBtn').on('click', function() {
                    alert('Emoji picker would open here');
                });
            }
            
            // Load chat rooms
            function loadChatRooms() {
                $.ajax({
                    url: '../designer/ChatApi.php?action=listRooms&user_type=' + userType + '&user_id=' + userId,
                    method: 'GET',
                    dataType: 'json',
                    success: function(rooms) {
                        displayChatRooms(rooms);
                    },
                    error: function(xhr, status, error) {
                        console.error('Error loading chat rooms:', error);
                        $('#chatUserList').html('<div class="text-center py-4 text-danger">Error loading conversations</div>');
                    }
                });
            }
            
            // Display chat rooms in sidebar
            function displayChatRooms(rooms) {
                const $userList = $('#chatUserList');
                $userList.empty();
                
                if (!rooms || rooms.length === 0) {
                    $userList.html('<div class="text-center py-4 text-muted">No conversations yet</div>');
                    return;
                }
                
                rooms.forEach(room => {
                    const roomName = room.roomname || `Room ${room.ChatRoomid}`;
                    const lastMessage = room.last_message || 'No messages yet';
                    const lastTime = room.last_time ? formatTime(room.last_time) : '';
                    const unreadCount = room.unread_count || 0;
                    const avatarText = roomName.charAt(0).toUpperCase();
                    
                    const $userItem = $(`
                        <div class="chat-user-item" data-room-id="${room.ChatRoomid}">
                            <div class="chat-user-avatar ${room.online ? 'online' : ''}">${avatarText}</div>
                            <div class="user-info">
                                <div class="d-flex justify-content-between">
                                    <div class="user-name">${escapeHtml(roomName)}</div>
                                    <div class="user-last-time">${lastTime}</div>
                                </div>
                                <div class="user-last-message">${escapeHtml(lastMessage)}</div>
                            </div>
                            ${unreadCount > 0 ? `<div class="unread-badge">${unreadCount}</div>` : ''}
                        </div>
                    `);
                    
                    $userItem.on('click', function() {
                        selectChatRoom(room);
                    });
                    
                    $userList.append($userItem);
                });
            }
            
            // Select a chat room
            function selectChatRoom(room) {
                currentRoomId = room.ChatRoomid;
                currentUser = room;
                
                // Update UI
                $('.chat-user-item').removeClass('active');
                $(`.chat-user-item[data-room-id="${room.ChatRoomid}"]`).addClass('active');
                
                // Update header
                const roomName = room.roomname || `Room ${room.ChatRoomid}`;
                const avatarText = roomName.charAt(0).toUpperCase();
                $('#currentChatInfo').html(`
                    <div class="header-avatar">${avatarText}</div>
                    <div class="header-info">
                        <h5>${escapeHtml(roomName)}</h5>
                        <small id="chatStatus">Online</small>
                    </div>
                `);
                $('#chatActions').removeClass('d-none');
                
                // Hide "no chat selected" message
                $('.no-chats-selected').hide();
                
                // Load messages
                loadMessages();
                
                // Start polling for new messages
                if (pollInterval) {
                    clearInterval(pollInterval);
                }
                pollInterval = setInterval(pollMessages, 2000);
                
                // Close sidebar on mobile
                if ($(window).width() <= 768) {
                    $('#chatSidebar').removeClass('show');
                }
            }
            
            // Load messages for current room
            function loadMessages() {
                if (!currentRoomId) return;
                
                $.ajax({
                    url: `../designer/ChatApi.php?action=getMessages&room=${currentRoomId}`,
                    method: 'GET',
                    dataType: 'json',
                    success: function(messages) {
                        displayMessages(messages);
                        
                        // Update last message ID
                        if (messages.length > 0) {
                            lastMessageId = Math.max(...messages.map(m => m.messageid || m.id || 0));
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error loading messages:', error);
                    }
                });
            }
            
            // Poll for new messages
            function pollMessages() {
                if (!currentRoomId) return;
                
                $.ajax({
                    url: `../designer/ChatApi.php?action=getMessages&room=${currentRoomId}&since=${lastMessageId}`,
                    method: 'GET',
                    dataType: 'json',
                    success: function(messages) {
                        if (messages && messages.length > 0) {
                            messages.forEach(message => {
                                displayMessage(message, false);
                                
                                // Update last message ID
                                const messageId = message.messageid || message.id || 0;
                                if (messageId > lastMessageId) {
                                    lastMessageId = messageId;
                                }
                            });
                            
                            // Scroll to bottom
                            const $messages = $('#chatMessages');
                            $messages.scrollTop($messages[0].scrollHeight);
                        }
                    }
                });
            }
            
            // Display messages
            function displayMessages(messages) {
                const $messages = $('#chatMessages');
                $messages.empty();
                
                if (!messages || messages.length === 0) {
                    $messages.html('<div class="text-center py-4 text-muted">No messages yet. Start the conversation!</div>');
                    return;
                }
                
                // Group messages by date
                let currentDate = null;
                
                messages.forEach(message => {
                    const messageDate = new Date(message.timestamp).toDateString();
                    
                    // Add date separator if date changed
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
                
                // Scroll to bottom
                $messages.scrollTop($messages[0].scrollHeight);
            }
            
            // Display a single message
            function displayMessage(message, isInitialLoad = true) {
                const $messages = $('#chatMessages');
                
                const isSelf = message.sender_type === userType && message.sender_id == userId;
                const senderName = message.sender_name || `${message.sender_type} ${message.sender_id}`;
                const content = message.content || '';
                const time = message.timestamp ? new Date(message.timestamp) : new Date();
                const formattedTime = formatMessageTime(time);
                
                // Determine avatar text
                const avatarText = isSelf ? 'M' : senderName.charAt(0).toUpperCase();
                
                // Handle attachments
                let messageContent = '';
                if (message.attachment || (message.uploaded_file && message.uploaded_file.filepath)) {
                    const fileUrl = message.attachment || (message.uploaded_file ? message.uploaded_file.filepath : '');
                    const fileName = message.uploaded_file ? message.uploaded_file.filename : 'File';
                    const fileType = message.message_type || 'file';
                    
                    if (fileType === 'image') {
                        messageContent = `
                            <div class="image-message">
                                <img src="${escapeHtml(fileUrl)}" alt="${escapeHtml(fileName)}">
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
                                    <div class="file-size">${formatFileSize(message.uploaded_file ? message.uploaded_file.filesize : 0)}</div>
                                </div>
                                <a href="${escapeHtml(fileUrl)}" target="_blank" class="input-btn">
                                    <i class="bi bi-download"></i>
                                </a>
                            </div>
                        `;
                    }
                } else {
                    messageContent = `<div class="message-content">${escapeHtml(content)}</div>`;
                }
                
                // Status indicator for self messages
                const statusIcon = isSelf ? 
                    (message.read ? '<i class="bi bi-check-all"></i>' : '<i class="bi bi-check"></i>') : '';
                
                const messageHtml = `
                    <div class="message-wrapper ${isSelf ? 'self' : 'other'} ${isInitialLoad ? '' : 'new-message'}">
                        <div class="message-avatar">${avatarText}</div>
                        <div>
                            ${!isSelf ? `<div class="message-sender">${escapeHtml(senderName)}</div>` : ''}
                            <div class="message-bubble">
                                ${messageContent}
                                <div class="message-time">${formattedTime} ${statusIcon}</div>
                            </div>
                        </div>
                    </div>
                `;
                
                if (isInitialLoad) {
                    $messages.append(messageHtml);
                } else {
                    $messages.append(messageHtml);
                }
            }
            
            // Send message
            function sendMessage() {
                const message = $('#messageInput').val().trim();
                const file = pendingFile;
                
                if (!message && !file) {
                    return;
                }
                
                if (!currentRoomId) {
                    alert('Please select a conversation first.');
                    return;
                }
                
                const formData = new FormData();
                formData.append('sender_type', userType);
                formData.append('sender_id', userId);
                formData.append('room', currentRoomId);
                
                if (message) {
                    formData.append('content', message);
                }
                
                if (file) {
                    formData.append('attachment', file);
                    formData.append('message_type', file.type.startsWith('image/') ? 'image' : 'file');
                }
                
                // Disable send button during upload
                $('#sendMessage').prop('disabled', true);
                $('#sendMessage').html('<i class="bi bi-hourglass"></i>');
                
                $.ajax({
                    url: '../designer/ChatApi.php?action=sendMessage',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.ok && response.message) {
                            // Clear input
                            $('#messageInput').val('');
                            $('#messageInput').css('height', 'auto');
                            
                            // Clear attachment preview
                            $('#attachmentPreview').empty();
                            pendingFile = null;
                            $('#fileInput').val('');
                            
                            // Display sent message
                            displayMessage(response.message, false);
                            
                            // Update last message ID
                            const messageId = response.message.messageid || response.message.id || response.id || 0;
                            if (messageId > lastMessageId) {
                                lastMessageId = messageId;
                            }
                            
                            // Scroll to bottom
                            const $messages = $('#chatMessages');
                            $messages.scrollTop($messages[0].scrollHeight);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error sending message:', error);
                        alert('Error sending message. Please try again.');
                    },
                    complete: function() {
                        $('#sendMessage').prop('disabled', false);
                        $('#sendMessage').html('<i class="bi bi-send"></i>');
                    }
                });
            }
            
            // Preview attachment
            function previewAttachment(file) {
                const $preview = $('#attachmentPreview');
                $preview.empty();
                
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        $preview.html(`
                            <div class="attachment-preview d-flex align-items-center">
                                <img src="${e.target.result}" alt="Preview" style="width: 60px; height: 60px;">
                                <div class="ms-2">
                                    <div class="attachment-name">${escapeHtml(file.name)}</div>
                                    <div class="attachment-size">${formatFileSize(file.size)}</div>
                                </div>
                                <div class="ms-auto remove-attachment" onclick="removeAttachment()">
                                    <i class="bi bi-x-circle"></i>
                                </div>
                            </div>
                        `);
                    };
                    reader.readAsDataURL(file);
                } else {
                    $preview.html(`
                        <div class="attachment-preview d-flex align-items-center">
                            <i class="bi bi-file-earmark" style="font-size: 2rem;"></i>
                            <div class="ms-2">
                                <div class="attachment-name">${escapeHtml(file.name)}</div>
                                <div class="attachment-size">${formatFileSize(file.size)}</div>
                            </div>
                            <div class="ms-auto remove-attachment" onclick="removeAttachment()">
                                <i class="bi bi-x-circle"></i>
                            </div>
                        </div>
                    `);
                }
                
                pendingFile = file;
            }
            
            // Remove attachment
            window.removeAttachment = function() {
                $('#attachmentPreview').empty();
                pendingFile = null;
                $('#fileInput').val('');
            };
            
            // Utility functions
            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
            
            function formatTime(dateString) {
                const date = new Date(dateString);
                const now = new Date();
                const diffMs = now - date;
                const diffMins = Math.floor(diffMs / 60000);
                const diffHours = Math.floor(diffMs / 3600000);
                const diffDays = Math.floor(diffMs / 86400000);
                
                if (diffMins < 1) {
                    return 'Just now';
                } else if (diffMins < 60) {
                    return `${diffMins}m ago`;
                } else if (diffHours < 24) {
                    return `${diffHours}h ago`;
                } else if (diffDays < 7) {
                    return `${diffDays}d ago`;
                } else {
                    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                }
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
                        day: 'numeric' 
                    });
                }
            }
            
            function formatFileSize(bytes) {
                if (bytes === 0) return '0 Bytes';
                const k = 1024;
                const sizes = ['Bytes', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }
            
            // Clear chat
            $('#clearChat').on('click', function() {
                if (confirm('Are you sure you want to clear this chat?')) {
                    $('#chatMessages').empty();
                    $('#chatMessages').html('<div class="text-center py-4 text-muted">No messages yet. Start the conversation!</div>');
                }
            });
            
            // Initialize chat
            initChat();
        });
    </script>
</body>
</html>