<?php
session_start();

// Database configuration
$host = 'localhost';
$dbname = 'hivemind';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

$current_user_id = $_SESSION['user_id'];

// Handle message sending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $chat_room_id = $_POST['chat_room_id'];
    $message_text = trim($_POST['message_text']);

    if (!empty($message_text)) {
        $send_query = "INSERT INTO messages (chat_room_id, sender_id, message_text) VALUES (?, ?, ?)";
        $send_stmt = $pdo->prepare($send_query);
        $send_stmt->execute([$chat_room_id, $current_user_id, $message_text]);
    }
}

// Get the other user ID from URL parameter
$other_user_id = isset($_GET['to']) ? (int) $_GET['to'] : null;

if (!$other_user_id) {
    header('Location: ../homepage.php');
    exit();
}

// Get other user details (could be expert, learner, or admin)
$other_user_query = "SELECT u.user_id, up.full_name, up.profile_picture, u.role 
                     FROM users u 
                     JOIN user_profiles up ON u.user_id = up.user_id 
                     WHERE u.user_id = ?";
$other_user_stmt = $pdo->prepare($other_user_query);
$other_user_stmt->execute([$other_user_id]);
$other_user = $other_user_stmt->fetch(PDO::FETCH_ASSOC);

if (!$other_user) {
    header('Location: ../homepage.php');
    exit();
}

// Check if chatroom already exists between current user and other user
$chatroom_query = "SELECT chat_room_id FROM chatrooms 
                   WHERE (participant_one_id = ? AND participant_two_id = ?) 
                   OR (participant_one_id = ? AND participant_two_id = ?)";
$chatroom_stmt = $pdo->prepare($chatroom_query);
$chatroom_stmt->execute([$current_user_id, $other_user_id, $other_user_id, $current_user_id]);
$chatroom = $chatroom_stmt->fetch(PDO::FETCH_ASSOC);

// If no chatroom exists, create one
if (!$chatroom) {
    $create_chatroom_query = "INSERT INTO chatrooms (participant_one_id, participant_two_id) VALUES (?, ?)";
    $create_chatroom_stmt = $pdo->prepare($create_chatroom_query);
    $create_chatroom_stmt->execute([$current_user_id, $other_user_id]);
    $chat_room_id = $pdo->lastInsertId();
} else {
    $chat_room_id = $chatroom['chat_room_id'];
}

// Mark messages as read for current user
$mark_read_query = "UPDATE messages SET is_read = 1 
                    WHERE chat_room_id = ? AND sender_id != ?";
$mark_read_stmt = $pdo->prepare($mark_read_query);
$mark_read_stmt->execute([$chat_room_id, $current_user_id]);

// Fetch all messages for this chatroom
$messages_query = "SELECT m.message_text, m.sent_at, m.sender_id, up.full_name 
                   FROM messages m
                   JOIN users u ON m.sender_id = u.user_id
                   JOIN user_profiles up ON u.user_id = up.user_id
                   WHERE m.chat_room_id = ?
                   ORDER BY m.sent_at ASC";
$messages_stmt = $pdo->prepare($messages_query);
$messages_stmt->execute([$chat_room_id]);
$messages = $messages_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current user's name for display
$current_user_query = "SELECT up.full_name FROM user_profiles up WHERE up.user_id = ?";
$current_user_stmt = $pdo->prepare($current_user_query);
$current_user_stmt->execute([$current_user_id]);
$current_user = $current_user_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - HiveMind</title>
    <link rel="icon" type="image/x-icon" href="../assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-accent: #8B4513;
            --secondary-accent: #D2691E;
            --bright-gold: #FFD700;
            --deep-brown: #654321;
            --soft-bg: #F5F5DC;
            --dark-text: #333;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, var(--deep-brown), var(--secondary-accent));
            padding: 1rem 0;
        }

        .navbar-brand {
            font-weight: bold;
            font-size: 1.5rem;
            color: white !important;
        }

        .nav-link {
            color: white !important;
        }

        .chat-container {
            max-width: 800px;
            margin: 2rem auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            height: 80vh;
            display: flex;
            flex-direction: column;
        }

        .chat-header {
            background: linear-gradient(135deg, var(--primary-accent), var(--secondary-accent));
            color: white;
            padding: 1rem;
            display: flex;
            align-items: center;
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 1rem;
            border: 3px solid white;
        }

        .user-name {
            font-size: 1.2rem;
            font-weight: bold;
        }

        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
            background-color: #f8f9fa;
        }

        .message {
            margin-bottom: 1rem;
            display: flex;
        }

        .message.own {
            justify-content: flex-end;
        }

        .message.other {
            justify-content: flex-start;
        }

        .message-bubble {
            max-width: 70%;
            padding: 0.75rem 1rem;
            border-radius: 18px;
            position: relative;
            word-wrap: break-word;
        }

        .message.own .message-bubble {
            background: linear-gradient(135deg, var(--primary-accent), var(--secondary-accent));
            color: white;
            border-bottom-right-radius: 5px;
        }

        .message.other .message-bubble {
            background: white;
            color: var(--dark-text);
            border: 1px solid #ddd;
            border-bottom-left-radius: 5px;
        }

        .message-time {
            font-size: 0.75rem;
            opacity: 0.7;
            margin-top: 0.25rem;
        }

        .message-input-container {
            background: white;
            padding: 1rem;
            border-top: 1px solid #ddd;
        }

        .message-form {
            display: flex;
            gap: 0.5rem;
        }

        .message-input {
            flex: 1;
            border: 2px solid #ddd;
            border-radius: 25px;
            padding: 0.75rem 1rem;
            resize: none;
            outline: none;
            font-family: inherit;
        }

        .message-input:focus {
            border-color: var(--primary-accent);
        }

        .send-button {
            background: linear-gradient(135deg, var(--primary-accent), var(--secondary-accent));
            color: white;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .send-button:hover {
            transform: scale(1.05);
        }

        .no-messages {
            text-align: center;
            color: #6c757d;
            font-style: italic;
            padding: 2rem;
        }

        .back-button {
            position: absolute;
            left: 1rem;
            top: 1rem;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            text-decoration: none;
        }

        .back-button:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
        }
    </style>
</head>

<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="../homepage.php">
                <i class="fas fa-brain me-2"></i>HiveMind
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../homepage.php">Homepage</a>
                <a class="nav-link" href="../pages/browse.php">Browse Skills</a>
                <a class="nav-link" href="../pages/profile.php">Profile</a>
                <a class="nav-link" href="../auth/logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <a href="javascript:history.back()" class="back-button">
            <i class="fas fa-arrow-left"></i>
        </a>

        <div class="chat-container">
            <!-- Chat Header -->
            <div class="chat-header">
                <div class="d-flex align-items-center w-100">
                    <?php if ($other_user['profile_picture']): ?>
                        <img src="<?= htmlspecialchars($other_user['profile_picture']) ?>" alt="User" class="user-avatar">
                    <?php else: ?>
                        <div class="user-avatar bg-secondary d-flex align-items-center justify-content-center">
                            <i class="fas fa-user"></i>
                        </div>
                    <?php endif; ?>
                    <div>
                        <div class="user-name"><?= htmlspecialchars($other_user['full_name']) ?></div>
                        <small><?= ucfirst($other_user['role']) ?></small>
                    </div>
                </div>
            </div>

            <!-- Messages Container -->
            <div class="messages-container" id="messagesContainer">
                <?php if (empty($messages)): ?>
                    <div class="no-messages">
                        <i class="fas fa-comments fa-3x mb-3"></i>
                        <p>No messages yet. Start the conversation!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($messages as $message): ?>
                        <div class="message <?= $message['sender_id'] == $current_user_id ? 'own' : 'other' ?>">
                            <div class="message-bubble">
                                <div class="message-text">
                                    <?= htmlspecialchars($message['message_text']) ?>
                                </div>
                                <div class="message-time">
                                    <?= date('M j, g:i A', strtotime($message['sent_at'])) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Message Input -->
            <div class="message-input-container">
                <form method="POST" class="message-form" id="messageForm">
                    <input type="hidden" name="chat_room_id" value="<?= $chat_room_id ?>">
                    <textarea name="message_text" class="message-input" placeholder="Type your message..." rows="1"
                        required id="messageInput"></textarea>
                    <button type="submit" name="send_message" class="send-button">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-scroll to bottom of messages
        function scrollToBottom() {
            const messagesContainer = document.getElementById('messagesContainer');
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        // Scroll to bottom on page load
        window.addEventListener('load', scrollToBottom);

        // Auto-resize textarea
        const messageInput = document.getElementById('messageInput');
        messageInput.addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });

        // Send message on Enter key (but allow Shift+Enter for new line)
        messageInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                document.getElementById('messageForm').submit();
            }
        });

        // Auto-refresh messages every 3 seconds
        setInterval(function () {
            if (document.hidden) return; // Don't refresh if tab is not active

            fetch(window.location.href)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const newDoc = parser.parseFromString(html, 'text/html');
                    const newMessages = newDoc.getElementById('messagesContainer').innerHTML;
                    const currentMessages = document.getElementById('messagesContainer').innerHTML;

                    if (newMessages !== currentMessages) {
                        document.getElementById('messagesContainer').innerHTML = newMessages;
                        scrollToBottom();
                    }
                })
                .catch(error => console.log('Error refreshing messages:', error));
        }, 3000);

        // Scroll to bottom after form submission
        document.getElementById('messageForm').addEventListener('submit', function () {
            setTimeout(scrollToBottom, 100);
        });
    </script>
</body>

</html>