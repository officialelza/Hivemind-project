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

// Check if user is logged in and is a learner
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'learner') {
    header('Location: login.php');
    exit();
}

$learner_id = $_SESSION['user_id'];

// Handle AJAX requests for marking notifications as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_all_notifications_read') {
    header('Content-Type: application/json');

    try {
        $update_query = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";
        $update_stmt = $pdo->prepare($update_query);
        $update_stmt->execute([$learner_id]);

        echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error updating notifications: ' . $e->getMessage()]);
    }
    exit();
}

$registration_stats_query = "SELECT 
    COUNT(*) as total_registrations,
    SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as pending_registrations,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_registrations,
    SUM(CASE WHEN status IN ('scheduled', 'completed') THEN 1 ELSE 0 END) as approved_registrations
    FROM registration WHERE learner_id = ?";
$registration_stats_stmt = $pdo->prepare($registration_stats_query);
$registration_stats_stmt->execute([$learner_id]);
$registration_stats = $registration_stats_stmt->fetch(PDO::FETCH_ASSOC);

// Set default values if no registrations exist
if (!$registration_stats || $registration_stats['total_registrations'] == 0) {
    $registration_stats = [
        'total_registrations' => 0,
        'pending_registrations' => 0,
        'completed_registrations' => 0,
        'approved_registrations' => 0
    ];
}

// FIXED: Recent registrations query - now includes schedule data
$recent_registrations_query = "
    SELECT r.registration_id, r.status, r.registered_at,
           s.title AS skill_title,
           u.user_id AS expert_user_id, up.full_name AS expert_name,
           sch.date AS schedule_date, sch.start_time, sch.end_time
    FROM registration r
    LEFT JOIN skills s ON r.skill_id = s.skill_id
    LEFT JOIN users u ON s.expert_id = u.user_id
    LEFT JOIN user_profiles up ON u.user_id = up.user_id
    LEFT JOIN schedule sch ON r.schedule_id = sch.schedule_id
    WHERE r.learner_id = ?
    ORDER BY r.registered_at DESC
    LIMIT 6
";
$recent_registrations_stmt = $pdo->prepare($recent_registrations_query);
$recent_registrations_stmt->execute([$learner_id]);
$recent_registrations = $recent_registrations_stmt->fetchAll(PDO::FETCH_ASSOC);

// FIXED: Upcoming sessions query - simplified and corrected status values based on your schema
$upcoming_sessions_query = "SELECT r.registration_id, r.status, r.registered_at, 
                           s.title as skill_title, s.category, s.description,
                           up.full_name as expert_name, up.profile_picture as expert_picture
                           FROM registration r
                           JOIN skills s ON r.skill_id = s.skill_id
                           JOIN users u ON s.expert_id = u.user_id
                           JOIN user_profiles up ON u.user_id = up.user_id
                           WHERE r.learner_id = ? AND r.status = 'scheduled'
                           ORDER BY r.registered_at DESC LIMIT 5";
$upcoming_sessions_stmt = $pdo->prepare($upcoming_sessions_query);
$upcoming_sessions_stmt->execute([$learner_id]);
$upcoming_sessions = $upcoming_sessions_stmt->fetchAll(PDO::FETCH_ASSOC);

// FIXED: Messages query - your messages table might be empty, but this query is correct
$messages_query = "SELECT m.message_text, m.sent_at, up.full_name as sender_name, m.is_read
                  FROM messages m
                  JOIN chatrooms c ON m.chat_room_id = c.chat_room_id
                  JOIN users u ON m.sender_id = u.user_id
                  JOIN user_profiles up ON u.user_id = up.user_id
                  WHERE (c.participant_one_id = ? OR c.participant_two_id = ?) 
                  AND m.sender_id != ?
                  ORDER BY m.sent_at DESC LIMIT 5";
$messages_stmt = $pdo->prepare($messages_query);
$messages_stmt->execute([$learner_id, $learner_id, $learner_id]);
$recent_messages = $messages_stmt->fetchAll(PDO::FETCH_ASSOC);

// FIXED: Reviews query - your reviews table is empty, but query structure is correct
$reviews_query = "SELECT r.rating, r.comment, r.created_at, s.title as skill_title, 
                 up.full_name as expert_name
                 FROM reviews r
                 JOIN skills s ON r.skill_id = s.skill_id
                 JOIN users u ON s.expert_id = u.user_id
                 JOIN user_profiles up ON u.user_id = up.user_id
                 WHERE r.learner_id = ?
                 ORDER BY r.created_at DESC LIMIT 5";
$reviews_stmt = $pdo->prepare($reviews_query);
$reviews_stmt->execute([$learner_id]);
$learner_reviews = $reviews_stmt->fetchAll(PDO::FETCH_ASSOC);

// FIXED: Learner profile query with better error handling
$profile_query = "SELECT up.full_name, up.bio, up.profile_picture, u.email, u.created_at 
                  FROM users u
                  LEFT JOIN user_profiles up ON up.user_id = u.user_id 
                  WHERE u.user_id = ?";
$profile_stmt = $pdo->prepare($profile_query);
$profile_stmt->execute([$learner_id]);
$learner_profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);

// Debug: Check if profile was found
if (!$learner_profile) {
    // Fallback: get just user data if profile doesn't exist
    $user_query = "SELECT email, created_at FROM users WHERE user_id = ?";
    $user_stmt = $pdo->prepare($user_query);
    $user_stmt->execute([$learner_id]);
    $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);

    $learner_profile = [
        'full_name' => null,
        'bio' => null,
        'profile_picture' => null,
        'email' => $user_data['email'] ?? 'Unknown',
        'created_at' => $user_data['created_at'] ?? date('Y-m-d H:i:s')
    ];
}

// Ensure we have default values for any missing fields
$learner_profile = array_merge([
    'full_name' => 'Learner',
    'bio' => null,
    'profile_picture' => null,
    'email' => 'Unknown',
    'created_at' => date('Y-m-d H:i:s')
], $learner_profile ?: []);

// FIXED: Notifications query - your notifications table is empty, but query is correct
$notifications_query = "SELECT notification_id, type, message, is_read, created_at
                       FROM notifications 
                       WHERE user_id = ?
                       ORDER BY created_at DESC LIMIT 5";
$notifications_stmt = $pdo->prepare($notifications_query);
$notifications_stmt->execute([$learner_id]);
$notifications = $notifications_stmt->fetchAll(PDO::FETCH_ASSOC);

// Count unread notifications
$unread_notifications_query = "SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = 0";
$unread_notifications_stmt = $pdo->prepare($unread_notifications_query);
$unread_notifications_stmt->execute([$learner_id]);
$unread_count = $unread_notifications_stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Learner Dashboard - HiveMind</title>
    <link rel="icon" type="image/x-icon" href="../assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/l_dash.css">
    <style>

    </style>
</head>

<body class="theme-l">
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="../homepage.php">
                <div class="logo-space d-inline-flex me-2">
                    <i class="fas fa-brain fs-4"></i>
                </div>
                HiveMind
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../homepage.php">Homepage</a>
                <a class="nav-link" href="../pages/browse.php">Browse Skills</a>
                <a class="nav-link" href="../pages/message_list.php">Messages</a>
                <a class="nav-link" href="../pages/profile.php">Profile</a>
                <a class="nav-link" href="../auth/logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Dashboard Header -->
    <div class="dashboard-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-2">
                    <?php if ($learner_profile && $learner_profile['profile_picture']): ?>
                        <img src="<?= htmlspecialchars($learner_profile['profile_picture']) ?>" alt="Profile Picture"
                            class="profile-avatar">
                    <?php else: ?>
                        <div class="profile-avatar bg-secondary d-flex align-items-center justify-content-center">
                            <i class="fas fa-user fa-2x text-white"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-10">
                    <h2>Welcome back, <?= htmlspecialchars($learner_profile['full_name'] ?? 'Learner') ?>!</h2>
                    <p class="mb-0">Learning since <?= date('F Y', strtotime($learner_profile['created_at'])) ?></p>
                    <?php if ($learner_profile['bio']): ?>
                        <p class="mt-2 mb-0"><small><?= htmlspecialchars($learner_profile['bio']) ?></small></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="metric-card">
                    <div class="metric-value"><?= $registration_stats['total_registrations'] ?? 0 ?></div>
                    <div>Total Registrations</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="metric-card">
                    <div class="metric-value"><?= $registration_stats['approved_registrations'] ?? 0 ?></div>
                    <div>Active Sessions</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="metric-card">
                    <div class="metric-value"><?= $registration_stats['pending_registrations'] ?? 0 ?></div>
                    <div>Pending Registrations</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="metric-card">
                    <div class="metric-value"><?= count($learner_reviews) ?></div>
                    <div>Reviews Given</div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Main Content -->
            <div class="col-lg-8">
                <!-- Upcoming Sessions Section -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="fas fa-list-check me-2"></i>Recent Registrations</h6>
                    </div>
                    <div class="card-body recent-activity">
                        <?php if (empty($recent_registrations)): ?>
                            <div class="no-data text-center">
                                <i class="fas fa-calendar-plus fa-2x mb-2 text-muted"></i>
                                <p class="small">No recent registrations</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_registrations as $reg): ?>
                                <div class="d-flex justify-content-between align-items-start mb-3 p-2"
                                    style="background-color:var(--soft-bg); border-radius:8px;">
                                    <div class="flex-grow-1">
                                        <strong class="small"><?= htmlspecialchars($reg['skill_title'] ?? 'Skill') ?></strong>
                                        <div class="small text-muted">
                                            Expert: <?= htmlspecialchars($reg['expert_name'] ?? '—') ?>
                                            <?php if (!empty($reg['schedule_date'])): ?>
                                                • <?= date('M d, Y', strtotime($reg['schedule_date'])) ?>
                                                <?= !empty($reg['start_time']) ? ' @ ' . date('g:i A', strtotime($reg['start_time'])) : '' ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="small mt-1">Registered:
                                            <?= date('M d, g:i A', strtotime($reg['registered_at'])) ?>
                                        </div>
                                    </div>
                                    <div style="text-align:right">
                                        <span
                                            class="badge 
                                            <?= $reg['status'] === 'scheduled' ? 'bg-success' : ($reg['status'] === 'completed' ? 'bg-primary' : 'bg-secondary') ?>">
                                            <?= htmlspecialchars(ucfirst($reg['status'])) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>


                <!-- My Reviews Section -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-star me-2"></i>My Reviews</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($learner_reviews)): ?>
                            <div class="no-data">
                                <i class="fas fa-star fa-3x mb-3 text-muted"></i>
                                <h5>No Reviews Yet</h5>
                                <p>Complete your first session and leave a review to help other learners!</p>
                            </div>
                        <?php else: ?>
                            <div class="recent-activity">
                                <?php foreach ($learner_reviews as $review): ?>
                                    <div class="d-flex justify-content-between align-items-start mb-3 p-3"
                                        style="background-color: var(--soft-bg); border-radius: 8px;">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center mb-2">
                                                <strong class="me-2"><?= htmlspecialchars($review['skill_title']) ?></strong>
                                                <div class="rating-stars">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <i
                                                            class="fas fa-star<?= $i <= $review['rating'] ? '' : ' text-muted' ?>"></i>
                                                    <?php endfor; ?>
                                                </div>
                                            </div>
                                            <p class="mb-1 small"><?= htmlspecialchars($review['comment']) ?></p>
                                            <small class="text-muted">Expert:
                                                <?= htmlspecialchars($review['expert_name']) ?></small>
                                        </div>
                                        <small
                                            class="text-muted"><?= date('M d, Y', strtotime($review['created_at'])) ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Sidebar -->
            <div class="col-lg-4">
                <!-- Notifications -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="fas fa-bell me-2"></i>Notifications</h6>
                        <?php if ($unread_count > 0): ?>
                            <span class="badge bg-danger"><?= $unread_count ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body recent-activity">
                        <?php if (empty($notifications)): ?>
                            <div class="no-data">
                                <i class="fas fa-bell fa-2x mb-2 text-muted"></i>
                                <p class="small">No notifications</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($notifications as $notification): ?>
                                <div class="d-flex mb-3 p-2 rounded <?= !$notification['is_read'] ? 'bg-light border-start border-3 border-warning' : '' ?>"
                                    style="background-color:var(--soft-bg); border-radius:8px;">
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="flex-grow-1">
                                                <div class="d-flex align-items-center mb-1">
                                                    <i
                                                        class="fas fa-<?= $notification['type'] === 'alert' ? 'exclamation-triangle text-warning' : ($notification['type'] === 'info' ? 'info-circle text-info' : 'bell text-primary') ?> me-2"></i>
                                                    <small
                                                        class="text-muted"><?= date('M d, g:i A', strtotime($notification['created_at'])) ?></small>
                                                </div>
                                                <p class="small mb-0 <?= !$notification['is_read'] ? 'fw-bold' : '' ?>">
                                                    <?= htmlspecialchars($notification['message']) ?>
                                                </p>
                                            </div>
                                            <?php if (!$notification['is_read']): ?>
                                                <div class="ms-2">
                                                    <span class="badge bg-warning rounded-pill">New</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="text-center">
                                <a href="#" class="btn btn-outline-primary btn-sm" onclick="markAllAsRead()">Mark All as
                                    Read</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Messages -->
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-comments me-2"></i>Recent Messages</h6>
                    </div>
                    <div class="card-body recent-activity">
                        <?php if (empty($recent_messages)): ?>
                            <div class="no-data">
                                <i class="fas fa-comments fa-2x mb-2 text-muted"></i>
                                <p class="small">No recent messages</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_messages as $message): ?>
                                <div class="message-item <?= !$message['is_read'] ? 'unread' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <strong class="small"><?= htmlspecialchars($message['sender_name']) ?></strong>
                                            <p class="small mb-1">
                                                <?= htmlspecialchars(substr($message['message_text'], 0, 60)) ?>...
                                            </p>
                                            <small
                                                class="text-muted"><?= date('M d, g:i A', strtotime($message['sent_at'])) ?></small>
                                        </div>
                                        <?php if (!$message['is_read']): ?>
                                            <span class="badge bg-warning">New</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="text-center mt-3">
                                <a href="../pages/message_list.php" class="btn btn-outline-primary btn-sm">View All
                                    Messages</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mark all notifications as read
        function markAllAsRead() {
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=mark_all_notifications_read'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Reload the page to update the notification display
                        location.reload();
                    } else {
                        console.error('Error marking notifications as read:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
        }
    </script>
</body>

</html>