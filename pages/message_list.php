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

// Get all chatrooms for current user with last message and other participant info
$conversations_query = "
    SELECT DISTINCT
        c.chat_room_id,
        c.participant_one_id,
        c.participant_two_id,
        c.created_at as chatroom_created,
        -- Get the other participant (not current user)
        CASE 
            WHEN c.participant_one_id = ? THEN c.participant_two_id
            ELSE c.participant_one_id
        END as other_user_id,
        -- Get other participant's profile info
        up.full_name as other_user_name,
        up.profile_picture as other_user_picture,
        u.role as other_user_role,
        -- Get last message info
        (SELECT message_text 
         FROM messages 
         WHERE chat_room_id = c.chat_room_id 
         ORDER BY sent_at DESC 
         LIMIT 1) as last_message,
        (SELECT sent_at 
         FROM messages 
         WHERE chat_room_id = c.chat_room_id 
         ORDER BY sent_at DESC 
         LIMIT 1) as last_message_time,
        (SELECT sender_id 
         FROM messages 
         WHERE chat_room_id = c.chat_room_id 
         ORDER BY sent_at DESC 
         LIMIT 1) as last_message_sender,
        -- Count unread messages
        (SELECT COUNT(*) 
         FROM messages 
         WHERE chat_room_id = c.chat_room_id 
         AND sender_id != ? 
         AND is_read = 0) as unread_count
    FROM chatrooms c
    JOIN users u ON (
        CASE 
            WHEN c.participant_one_id = ? THEN c.participant_two_id = u.user_id
            ELSE c.participant_one_id = u.user_id
        END
    )
    JOIN user_profiles up ON u.user_id = up.user_id
    WHERE c.participant_one_id = ? OR c.participant_two_id = ?
    ORDER BY 
        CASE WHEN last_message_time IS NULL THEN c.created_at ELSE last_message_time END DESC
";

$conversations_stmt = $pdo->prepare($conversations_query);
$conversations_stmt->execute([
    $current_user_id,
    $current_user_id,
    $current_user_id,
    $current_user_id,
    $current_user_id
]);
$conversations = $conversations_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current user info for display
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

        .messages-header {
            background: linear-gradient(135deg, var(--deep-brown), var(--secondary-accent));
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }

        .conversations-container {
            max-width: 800px;
            margin: 0 auto;
        }

        .conversation-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 1rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            overflow: hidden;
        }

        .conversation-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            color: inherit;
            text-decoration: none;
        }

        .conversation-content {
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-accent);
            flex-shrink: 0;
        }

        .conversation-info {
            flex: 1;
            min-width: 0;
        }

        .user-name {
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--dark-text);
            margin-bottom: 0.25rem;
        }

        .user-role {
            display: inline-block;
            background: var(--primary-accent);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 15px;
            font-size: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .last-message {
            color: #6c757d;
            font-size: 0.9rem;
            margin: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .message-time {
            font-size: 0.8rem;
            color: #9ca3af;
            margin-top: 0.25rem;
        }

        .conversation-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        .unread-badge {
            background: var(--bright-gold);
            color: var(--dark-text);
            border-radius: 50%;
            width: 25px;
            height: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: bold;
        }

        .no-conversations {
            text-align: center;
            color: #6c757d;
            padding: 4rem 2rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .no-conversations i {
            color: var(--primary-accent);
            margin-bottom: 1rem;
        }

        .search-box {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .search-input {
            border: 2px solid #ddd;
            border-radius: 25px;
            padding: 0.75rem 1rem;
            width: 100%;
            outline: none;
        }

        .search-input:focus {
            border-color: var(--primary-accent);
        }

        .message-preview {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .sender-indicator {
            font-size: 0.8rem;
            color: #9ca3af;
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
                <a class="nav-link active" href="message_list.php">Messages</a>
                <a class="nav-link" href="../pages/profile.php">Profile</a>
                <a class="nav-link" href="../auth/logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Messages Header -->
    <div class="messages-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h2><i class="fas fa-comments me-2"></i>Messages</h2>
                    <p class="mb-0">
                        <?php
                        if ($_SESSION['role'] === 'expert') {
                            echo "Your conversations with learners";
                        } else {
                            echo "Your conversations with experts";
                        }
                        ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="conversations-container">
            <!-- Search Box -->
            <div class="search-box">
                <input type="text" class="search-input" placeholder="Search conversations..." id="searchInput">
            </div>

            <!-- Conversations List -->
            <div id="conversationsList">
                <?php if (empty($conversations)): ?>
                    <div class="no-conversations">
                        <i class="fas fa-comments fa-4x"></i>
                        <h4>No conversations yet</h4>
                        <p>Start messaging experts by visiting their skill pages and clicking "Message Expert"</p>
                        <a href="../pages/browse.php" class="btn btn-primary mt-3">
                            <i class="fas fa-search me-2"></i>Browse Skills
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($conversations as $conversation): ?>
                        <a href="messages.php?to=<?= $conversation['other_user_id'] ?>" class="conversation-card">
                            <div class="conversation-content">
                                <!-- User Avatar -->
                                <div>
                                    <?php if ($conversation['other_user_picture']): ?>
                                        <img src="<?= htmlspecialchars($conversation['other_user_picture']) ?>"
                                            alt="<?= htmlspecialchars($conversation['other_user_name']) ?>" class="user-avatar">
                                    <?php else: ?>
                                        <div class="user-avatar bg-secondary d-flex align-items-center justify-content-center">
                                            <i class="fas fa-user text-white fa-lg"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Conversation Info -->
                                <div class="conversation-info">
                                    <div class="user-name"><?= htmlspecialchars($conversation['other_user_name']) ?></div>
                                    <span class="user-role"><?= ucfirst($conversation['other_user_role']) ?></span>

                                    <?php if ($conversation['last_message']): ?>
                                        <div class="message-preview">
                                            <span class="sender-indicator">
                                                <?= $conversation['last_message_sender'] == $current_user_id ? 'You:' : '' ?>
                                            </span>
                                            <p class="last-message">
                                                <?= htmlspecialchars(substr($conversation['last_message'], 0, 60)) ?>
                                                <?= strlen($conversation['last_message']) > 60 ? '...' : '' ?>
                                            </p>
                                        </div>
                                        <div class="message-time">
                                            <?= date('M j, Y g:i A', strtotime($conversation['last_message_time'])) ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="last-message">No messages yet</p>
                                        <div class="message-time">
                                            Started <?= date('M j, Y', strtotime($conversation['chatroom_created'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Conversation Meta -->
                                <div class="conversation-meta">
                                    <?php if ($conversation['unread_count'] > 0): ?>
                                        <div class="unread-badge">
                                            <?= $conversation['unread_count'] ?>
                                        </div>
                                    <?php endif; ?>
                                    <i class="fas fa-chevron-right text-muted"></i>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function () {
            const searchTerm = this.value.toLowerCase();
            const conversations = document.querySelectorAll('.conversation-card');

            conversations.forEach(function (conversation) {
                const userName = conversation.querySelector('.user-name').textContent.toLowerCase();
                const lastMessage = conversation.querySelector('.last-message') ?
                    conversation.querySelector('.last-message').textContent.toLowerCase() : '';

                if (userName.includes(searchTerm) || lastMessage.includes(searchTerm)) {
                    conversation.style.display = 'block';
                } else {
                    conversation.style.display = 'none';
                }
            });
        });

        // Auto-refresh conversations every 30 seconds
        setInterval(function () {
            if (document.hidden) return; // Don't refresh if tab is not active

            fetch(window.location.href)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const newDoc = parser.parseFromString(html, 'text/html');
                    const newConversations = newDoc.getElementById('conversationsList').innerHTML;
                    const currentConversations = document.getElementById('conversationsList').innerHTML;

                    if (newConversations !== currentConversations) {
                        document.getElementById('conversationsList').innerHTML = newConversations;
                    }
                })
                .catch(error => console.log('Error refreshing conversations:', error));
        }, 30000);
    </script>
</body>

</html>