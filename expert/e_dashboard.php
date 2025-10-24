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

// Check if user is logged in and is an expert
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'expert') {
    header('Location: login.php');
    exit();
}

$expert_id = $_SESSION['user_id'];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Remove availability slot
    if (isset($_POST['action']) && $_POST['action'] === 'remove_slot') {
        $availability_id = $_POST['availability_id'];

        try {
            // Verify the slot belongs to this expert
            $verify_query = "SELECT availability_id FROM availability_dates WHERE availability_id = ? AND expert_id = ?";
            $verify_stmt = $pdo->prepare($verify_query);
            $verify_stmt->execute([$availability_id, $expert_id]);

            if ($verify_stmt->rowCount() > 0) {
                $delete_query = "DELETE FROM availability_dates WHERE availability_id = ? AND expert_id = ?";
                $delete_stmt = $pdo->prepare($delete_query);
                $delete_stmt->execute([$availability_id, $expert_id]);

                echo json_encode(['success' => true, 'message' => 'Availability slot removed successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Slot not found or unauthorized']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error removing slot: ' . $e->getMessage()]);
        }
        exit();
    }

    // Mark registration as completed
    if (isset($_POST['action']) && $_POST['action'] === 'mark_completed') {
        $registration_id = $_POST['registration_id'];

        try {
            // Verify the registration belongs to this expert's skill
            $verify_query = "SELECT r.registration_id FROM registration r 
                           JOIN skills s ON r.skill_id = s.skill_id 
                           WHERE r.registration_id = ? AND s.expert_id = ?";
            $verify_stmt = $pdo->prepare($verify_query);
            $verify_stmt->execute([$registration_id, $expert_id]);

            if ($verify_stmt->rowCount() > 0) {
                $update_query = "UPDATE registration SET status = 'completed', completed_at = NOW() WHERE registration_id = ?";
                $update_stmt = $pdo->prepare($update_query);
                $update_stmt->execute([$registration_id]);

                echo json_encode(['success' => true, 'message' => 'Registration marked as completed']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Registration not found or unauthorized']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error updating registration: ' . $e->getMessage()]);
        }
        exit();
    }

    // Mark all notifications as read
    if (isset($_POST['action']) && $_POST['action'] === 'mark_all_notifications_read') {
        try {
            $update_query = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";
            $update_stmt = $pdo->prepare($update_query);
            $update_stmt->execute([$expert_id]);

            echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error updating notifications: ' . $e->getMessage()]);
        }
        exit();
    }
}

$isTopExpert = false;
$expertStats = null;

$topExpertCheckQuery = "
    SELECT 
        COUNT(DISTINCT r.registration_id) as completed_count,
        COALESCE(AVG(rev.rating), 0) as avg_rating,
        COUNT(DISTINCT rev.review_id) as review_count
    FROM users u
    JOIN skills s ON u.user_id = s.expert_id
    JOIN registration r ON s.skill_id = r.skill_id AND r.status = 'completed'
    LEFT JOIN reviews rev ON s.skill_id = rev.skill_id
    WHERE u.user_id = ?
    GROUP BY u.user_id
";

$stmt = $pdo->prepare($topExpertCheckQuery);
$stmt->execute([$expert_id]);
$expertStats = $stmt->fetch(PDO::FETCH_ASSOC);

if ($expertStats) {
    // Check if meets Top Expert criteria
    if ($expertStats['completed_count'] >= 5 && $expertStats['avg_rating'] >= 4.5) {
        $isTopExpert = true;
    }
}

// Fetch expert profile information
$profile_query = "SELECT up.full_name, up.bio, up.profile_picture, u.email, u.created_at 
                  FROM user_profiles up 
                  JOIN users u ON up.user_id = u.user_id 
                  WHERE u.user_id = ?";
$profile_stmt = $pdo->prepare($profile_query);
$profile_stmt->execute([$expert_id]);
$expert_profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);

// Fetch expert's skills statistics
$skills_stats_query = "SELECT 
    COUNT(*) as total_skills,
    SUM(CASE WHEN is_approved = 1 THEN 1 ELSE 0 END) as approved_skills,
    SUM(CASE WHEN is_approved = 0 THEN 1 ELSE 0 END) as pending_skills
    FROM skills WHERE expert_id = ?";
$skills_stats_stmt = $pdo->prepare($skills_stats_query);
$skills_stats_stmt->execute([$expert_id]);
$skills_stats = $skills_stats_stmt->fetch(PDO::FETCH_ASSOC);

// fetch all slots for this expert, joined to skill name
$sql = "
  SELECT ad.availability_id, ad.skill_id, ad.start_date, ad.start_time, ad.end_time, s.title AS skill_name
  FROM availability_dates ad
  LEFT JOIN skills s ON s.skill_id = ad.skill_id
  WHERE ad.expert_id = :expert_id
  ORDER BY s.title ASC, ad.start_date ASC, ad.start_time ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':expert_id' => $expert_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// group by skill
$slots_by_skill = [];
foreach ($rows as $r) {
    $skill_name = $r['skill_name'] ?: '(No skill)';
    $key = $r['skill_id'] === null ? 'none' : (string) $r['skill_id'];
    if (!isset($slots_by_skill[$key])) {
        $slots_by_skill[$key] = [
            'skill_name' => $skill_name,
            'slots' => []
        ];
    }
    $slots_by_skill[$key]['slots'][] = $r;
}

// Fetch total registrations for expert's skills
$registrations_query = "SELECT COUNT(*) as total_registrations
                       FROM registration r 
                       JOIN skills s ON r.skill_id = s.skill_id 
                       WHERE s.expert_id = ?";
$registrations_stmt = $pdo->prepare($registrations_query);
$registrations_stmt->execute([$expert_id]);
$total_registrations = $registrations_stmt->fetch(PDO::FETCH_ASSOC)['total_registrations'];

// Fetch average rating
$rating_query = "SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews
                FROM reviews r 
                JOIN skills s ON r.skill_id = s.skill_id 
                WHERE s.expert_id = ?";
$rating_stmt = $pdo->prepare($rating_query);
$rating_stmt->execute([$expert_id]);
$rating_data = $rating_stmt->fetch(PDO::FETCH_ASSOC);

// Fetch recent messages
$messages_query = "SELECT m.message_text, m.sent_at, up.full_name, m.is_read
                  FROM messages m
                  JOIN chatrooms c ON m.chat_room_id = c.chat_room_id
                  JOIN users u ON (CASE WHEN c.participant_one_id = ? THEN c.participant_two_id ELSE c.participant_one_id END) = u.user_id
                  JOIN user_profiles up ON u.user_id = up.user_id
                  WHERE (c.participant_one_id = ? OR c.participant_two_id = ?) 
                  AND m.sender_id != ?
                  ORDER BY m.sent_at DESC LIMIT 5";
$messages_stmt = $pdo->prepare($messages_query);
$messages_stmt->execute([$expert_id, $expert_id, $expert_id, $expert_id]);
$recent_messages = $messages_stmt->fetchAll(PDO::FETCH_ASSOC);

$recent_registrations_query = "
    SELECT 
        r.registration_id,
        r.status,
        r.registered_at,
        up.full_name as learner_name,
        s.title as skill_title,
        sch.date as schedule_date,
        sch.start_time,
        sch.end_time
    FROM registration r
    JOIN user_profiles up ON r.learner_id = up.user_id
    JOIN skills s ON r.skill_id = s.skill_id
    LEFT JOIN schedule sch ON r.schedule_id = sch.schedule_id
    WHERE s.expert_id = ?
    ORDER BY r.registered_at DESC
    LIMIT 5
";

$stmt = $pdo->prepare($recent_registrations_query);
$stmt->execute([$expert_id]);
$recent_registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Fetch notifications for the expert
$notifications_query = "SELECT notification_id, type, message, is_read, created_at
                       FROM notifications 
                       WHERE user_id = ?
                       ORDER BY created_at DESC LIMIT 5";
$notifications_stmt = $pdo->prepare($notifications_query);
$notifications_stmt->execute([$expert_id]);
$notifications = $notifications_stmt->fetchAll(PDO::FETCH_ASSOC);

// Count unread notifications
$unread_notifications_query = "SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = 0";
$unread_notifications_stmt = $pdo->prepare($unread_notifications_query);
$unread_notifications_stmt->execute([$expert_id]);
$unread_count = $unread_notifications_stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];

// Fetch expert's skills
$skills_query = "SELECT s.skill_id, s.title, s.category, s.description, s.is_approved, s.created_at,
                        COUNT(r.registration_id) as total_registrations
                 FROM skills s
                 LEFT JOIN registration r ON s.skill_id = r.skill_id
                 WHERE s.expert_id = ?
                 GROUP BY s.skill_id
                 ORDER BY s.created_at DESC";
$skills_stmt = $pdo->prepare($skills_query);
$skills_stmt->execute([$expert_id]);
$expert_skills = $skills_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent reviews for expert's skills
$recent_reviews_query = "SELECT r.review_id, r.rating, r.comment, r.created_at, 
                                s.title as skill_title, up.full_name as learner_name
                         FROM reviews r
                         JOIN skills s ON r.skill_id = s.skill_id
                         JOIN user_profiles up ON r.learner_id = up.user_id
                         WHERE s.expert_id = ?
                         ORDER BY r.created_at DESC LIMIT 5";
$recent_reviews_stmt = $pdo->prepare($recent_reviews_query);
$recent_reviews_stmt->execute([$expert_id]);
$recent_reviews = $recent_reviews_stmt->fetchAll(PDO::FETCH_ASSOC);

// list of my requests
$sql = "SELECT request_id, proposed_title, status, submitted_at
        FROM expert_skill_requests
        WHERE expert_id = ?
        ORDER BY submitted_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$expert_id]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// quick counts
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($requests as $r)
    $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expert Dashboard - HiveMind</title>
    <link rel="icon" type="image/x-icon" href="../assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/e_dash.css" rel="stylesheet">
    <style>
        .dashboard-header {
            background: linear-gradient(135deg, var(--deep-brown), var(--secondary-accent));
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary-accent);
        }

        .metric-card {
            background: linear-gradient(135deg, var(--primary-accent), var(--bright-gold));
            color: var(--dark-text);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            margin-bottom: 1rem;
        }

        .metric-value {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .no-data {
            text-align: center;
            color: #6c757d;
            padding: 2rem;
            font-style: italic;
        }

        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }

        .rating-stars {
            color: var(--soft-bg);
        }

        .recent-activity {
            max-height: 300px;
            overflow-y: auto;
        }

        .remove-slot:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .mark-complete-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
    </style>
</head>

<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="../homepage.php">
                <div class="logo-space d-inline-flex me-2">
                    <img src="../assets/images/hivemind_logo.png" alt="HiveMind Logo" class="logo" width="auto"
                        height="40">
                </div>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../homepage.php">Homepage</a>
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
                    <?php if ($expert_profile && $expert_profile['profile_picture']): ?>
                        <img src="<?= htmlspecialchars($expert_profile['profile_picture']) ?>" alt="Profile Picture"
                            class="profile-avatar">
                    <?php else: ?>
                        <div class="profile-avatar bg-secondary d-flex align-items-center justify-content-center">
                            <i class="fas fa-user fa-2x text-white"></i>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="col-md-10">
                    <h2>Welcome back, <?= htmlspecialchars($expert_profile['full_name'] ?? 'Expert') ?>!</h2>
                    <p class="mb-0">Expert since <?= date('F Y', strtotime($expert_profile['created_at'])) ?></p>
                    <?php if ($expert_profile['bio']): ?>
                        <p class="mt-2 mb-0"><small><?= htmlspecialchars($expert_profile['bio']) ?></small></p>
                    <?php endif; ?>
                </div>

                <?php if ($isTopExpert): ?>
                    <div class="top-expert-banner" style="
                        background: linear-gradient(135deg, #FFD700, #FFA500);
                        color: #000;
                        padding: 2.0rem;
                        border-radius: 12px;
                        margin-bottom: 1.5rem;
                        box-shadow: 0 4px 15px rgba(255, 215, 0, 0.3);
                        position: relative;
                        overflow: hidden;
                    ">
                        <div style="position: absolute; top: 0; right: 0; opacity: 0.1; font-size: 8rem;">
                            <i class="bi bi-trophy-fill"></i>
                        </div>
                        <div style="position: relative; z-index: 1;">
                            <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 0.5rem;">
                                <i class="bi bi-star-fill" style="font-size: 2rem;"></i>
                                <h4 style="margin: 0; font-weight: 700;">Congratulations! You're a Top Expert!</h4>
                            </div>
                            <p style="margin: 0; font-size: 1rem; opacity: 0.9;">
                                You've achieved <?php echo $expertStats['completed_count']; ?> completed sessions
                                with an outstanding <?php echo number_format($expertStats['avg_rating'], 1); ?> average
                                rating!
                            </p>
                            <div style="margin-top: 1rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                                <div
                                    style="background: rgba(0,0,0,0.1); padding: 0.5rem 1rem; border-radius: 8px; font-weight: 600;">
                                    <i class="bi bi-check-circle-fill me-2"></i>
                                    <?php echo $expertStats['completed_count']; ?> Completed Sessions
                                </div>
                                <div
                                    style="background: rgba(0,0,0,0.1); padding: 0.5rem 1rem; border-radius: 8px; font-weight: 600;">
                                    <i class="bi bi-star-fill me-2"></i>
                                    <?php echo number_format($expertStats['avg_rating'], 1); ?> Average Rating
                                </div>
                                <div
                                    style="background: rgba(0,0,0,0.1); padding: 0.5rem 1rem; border-radius: 8px; font-weight: 600;">
                                    <i class="bi bi-chat-left-text-fill me-2"></i>
                                    <?php echo $expertStats['review_count']; ?> Reviews
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Optional: Show progress towards Top Expert status -->
                    <div class="top-expert-progress" style="
                        background: #f8f9fa;
                        border: 2px dashed #dee2e6;
                        padding: 1.5rem;
                        border-radius: 12px;
                        margin-bottom: 1.5rem;
                    ">
                        <h5 style="margin-bottom: 1rem; color: #666;">
                            <i class="bi bi-trophy me-2"></i>Progress to Top Expert Status
                        </h5>
                        <?php if ($expertStats): ?>
                            <div style="margin-bottom: 1rem;">
                                <div style="margin-bottom: 0.75rem;">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                                        <span style="font-size: 0.9rem; color: #666;">Completed Sessions</span>
                                        <span style="font-weight: 600;">
                                            <?php echo $expertStats['completed_count']; ?> / 5
                                        </span>
                                    </div>
                                    <div style="background: #e0e0e0; height: 8px; border-radius: 4px; overflow: hidden;">
                                        <div style="
                        background: linear-gradient(90deg, #FFD700, #FFA500);
                        height: 100%;
                        width: <?php echo min(($expertStats['completed_count'] / 5) * 100, 100); ?>%;
                        transition: width 0.3s ease;
                    "></div>
                                    </div>
                                </div>
                                <div>
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                                        <span style="font-size: 0.9rem; color: #666;">Average Rating</span>
                                        <span style="font-weight: 600;">
                                            <?php echo number_format($expertStats['avg_rating'], 1); ?> / 4.5
                                        </span>
                                    </div>
                                    <div style="background: #e0e0e0; height: 8px; border-radius: 4px; overflow: hidden;">
                                        <div style="
                        background: linear-gradient(90deg, #FFD700, #FFA500);
                        height: 100%;
                        width: <?php echo min(($expertStats['avg_rating'] / 4.5) * 100, 100); ?>%;
                        transition: width 0.3s ease;
                    "></div>
                                    </div>
                                </div>
                            </div>
                            <p style="margin: 0; font-size: 0.85rem; color: #666;">
                                <i class="bi bi-info-circle me-1"></i>
                                Complete 5+ sessions with a 4.5+ average rating to become a Top Expert!
                            </p>
                        <?php else: ?>
                            <p style="margin: 0; color: #666;">
                                Complete your first sessions and get reviews to track your progress towards Top Expert status!
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <div class="container">
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="metric-card">
                    <div class="metric-value"><?= $skills_stats['total_skills'] ?? 0 ?></div>
                    <div>Total Skills</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="metric-card">
                    <div class="metric-value"><?= $skills_stats['approved_skills'] ?? 0 ?></div>
                    <div>Approved Skills</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="metric-card">
                    <div class="metric-value"><?= $total_registrations ?></div>
                    <div>Total Registrations</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="metric-card">
                    <div class="metric-value">
                        <?php if ($rating_data['avg_rating']): ?>
                            <?= number_format($rating_data['avg_rating'], 1) ?>
                            <span class="rating-stars ms-1">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i
                                        class="fas fa-star<?= $i <= round($rating_data['avg_rating']) ? '' : ' text-muted' ?>"></i>
                                <?php endfor; ?>
                            </span>
                        <?php else: ?>
                            <small>No ratings yet</small>
                        <?php endif; ?>
                    </div>
                    <div>Average Rating</div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Your Skills Section -->
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-lightbulb me-2"></i>Your Skills</h5>
                        <a href="add_skill.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus me-1"></i>Add New Skill
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($expert_skills)): ?>
                            <div class="no-data">
                                <i class="fas fa-lightbulb fa-3x mb-3 text-muted"></i>
                                <p>No skills posted yet. Start by adding your first skill!</p>
                                <a href="add_skill.php" class="btn btn-primary">Add Your First Skill</a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Skill Title</th>
                                            <th>Category</th>
                                            <th>Status</th>
                                            <th>Registrations</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($expert_skills as $skill): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($skill['title']) ?></strong>
                                                    <br>
                                                    <small
                                                        class="text-muted"><?= htmlspecialchars(substr($skill['description'], 0, 50)) ?>...</small>
                                                </td>
                                                <td><?= htmlspecialchars($skill['category']) ?></td>
                                                <td>
                                                    <?php if ($skill['is_approved']): ?>
                                                        <span class="badge bg-success status-badge">Approved</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning status-badge">Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-primary"><?= $skill['total_registrations'] ?></span>
                                                </td>
                                                <td><?= date('M d, Y', strtotime($skill['created_at'])) ?></td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="../pages/view_skill.php?skill_id=<?= $skill['skill_id'] ?>"
                                                            class="btn btn-outline-primary btn-sm">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="edit_skill.php?id=<?= $skill['skill_id'] ?>"
                                                            class="btn btn-outline-secondary btn-sm">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="expert_availability.php?id=<?= $skill['skill_id'] ?>"
                                                            class="btn btn-sm btn-warning" title="Edit Availability">
                                                            <i class="fas fa-calendar-alt"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- My Availability -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>My Availability</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <?php foreach ($slots_by_skill as $skill_id => $group): ?>
                                <div class="col-12 col-md-6 col-lg-4">
                                    <div class="card h-100 shadow-sm">
                                        <div class="card-header d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="card-title mb-0"><?= htmlspecialchars($group['skill_name']) ?>
                                                </h6>
                                                <small class="text-muted">Slots: <?= count($group['slots']) ?></small>
                                            </div>
                                            <span class="badge bg-primary align-self-start">Skill</span>
                                        </div>

                                        <ul class="list-group list-group-flush">
                                            <?php foreach ($group['slots'] as $slot):
                                                // build UTC DateTimes from start_date + start_time and end_time
                                                $start = DateTime::createFromFormat('Y-m-d H:i:s', $slot['start_date'] . ' ' . $slot['start_time'], new DateTimeZone('UTC'));
                                                $end = DateTime::createFromFormat('Y-m-d H:i:s', $slot['start_date'] . ' ' . $slot['end_time'], new DateTimeZone('UTC'));
                                                if ($start) {
                                                    $display = $start->format('D, M j, Y • H:i');
                                                    if ($end)
                                                        $display .= ' — ' . $end->format('H:i');
                                                    $display .= ' UTC';
                                                } else {
                                                    $display = htmlspecialchars($slot['start_date']);
                                                }
                                                ?>
                                                <li class="list-group-item d-flex justify-content-between align-items-center slot-item"
                                                    data-id="<?= htmlspecialchars($slot['availability_id']) ?>">
                                                    <div class="small">
                                                        <div class="fw-medium"><?= htmlspecialchars($display) ?></div>
                                                    </div>
                                                    <div class="ms-2">
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-slot"
                                                            data-availability-id="<?= htmlspecialchars($slot['availability_id']) ?>"
                                                            title="Remove slot">Remove</button>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>

                                        <div class="card-body">
                                            <a href="expert_availability.php?skill_id=<?= urlencode($skill_id) ?>"
                                                class="btn btn-sm btn-outline-secondary">Add slot for this skill</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- My Skill Requests -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-tasks me-2"></i>My Skill Requests</h5>
                    </div>
                    <div class="card-body">
                        <!-- Quick Counts -->
                        <div class="row mb-3 text-center">
                            <div class="col">
                                <span class="badge bg-warning"><?= $counts['pending'] ?? 0 ?></span>
                                <div class="small text-muted">Pending</div>
                            </div>
                            <div class="col">
                                <span class="badge bg-success"><?= $counts['approved'] ?? 0 ?></span>
                                <div class="small text-muted">Approved</div>
                            </div>
                            <div class="col">
                                <span class="badge bg-danger"><?= $counts['rejected'] ?? 0 ?></span>
                                <div class="small text-muted">Rejected</div>
                            </div>
                        </div>

                        <!-- Requests Table -->
                        <?php if (empty($requests)): ?>
                            <div class="no-data text-center text-muted py-4">
                                <i class="fas fa-info-circle fa-2x mb-2"></i>
                                <p>No skill requests found.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Status</th>
                                            <th>Submitted</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($requests as $r): ?>
                                            <?php
                                            $badge = $r['status'] == 'approved' ? 'success'
                                                : ($r['status'] == 'rejected' ? 'danger' : 'warning');
                                            ?>
                                            <tr>
                                                <td><?= htmlspecialchars($r['proposed_title']) ?></td>
                                                <td><span class="badge bg-<?= $badge ?>"><?= ucfirst($r['status']) ?></span>
                                                </td>
                                                <td><?= htmlspecialchars(date('M d, Y', strtotime($r['submitted_at']))) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
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
                                    style="background-color: var(--soft-bg);">
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
                <div class="card mb-4">
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
                                <div class="d-flex mb-3 p-2 rounded" style="background-color: var(--soft-bg);">
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between">
                                            <strong class="small"><?= htmlspecialchars($message['full_name']) ?></strong>
                                            <small class="text-muted"><?= date('M d', strtotime($message['sent_at'])) ?></small>
                                        </div>
                                        <p class="small mb-0">
                                            <?= htmlspecialchars(substr($message['message_text'], 0, 50)) ?>...
                                        </p>
                                    </div>
                                    <?php if (!$message['is_read']): ?>
                                        <div class="ms-2">
                                            <span class="badge bg-primary rounded-pill">New</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="text-center">
                                <a href="../pages/message_list.php" class="btn btn-outline-primary btn-sm">View All
                                    Messages</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Registrations -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-user-plus me-2"></i>Recent Registrations</h6>
                    </div>
                    <div class="card-body recent-activity">
                        <?php if (empty($recent_registrations)): ?>
                            <div class="no-data">
                                <i class="fas fa-user-plus fa-2x mb-2 text-muted"></i>
                                <p class="small">No recent registrations</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_registrations as $registration): ?>
                                <div class="d-flex justify-content-between align-items-start mb-3 p-3 rounded registration-item"
                                    style="background-color: var(--soft-bg); border-left: 4px solid var(--primary-accent);"
                                    data-registration-id="<?= $registration['registration_id'] ?>">

                                    <!-- Left side: Learner and Skill info -->
                                    <div class="flex-grow-1">
                                        <strong
                                            class="d-block mb-1"><?= htmlspecialchars($registration['learner_name']) ?></strong>
                                        <small class="text-muted d-block mb-2">
                                            <i class="fas fa-book me-1"></i>
                                            <?= htmlspecialchars($registration['skill_title']) ?>
                                        </small>

                                        <!-- Time Slot Information -->
                                        <?php if (!empty($registration['schedule_date'])): ?>
                                            <div class="time-slot-info mt-2 p-2 rounded"
                                                style="background-color: rgba(255, 215, 0, 0.1); border-left: 3px solid #FFD700;">
                                                <small class="d-block mb-1">
                                                    <i class="fas fa-calendar-day me-1 text-warning"></i>
                                                    <strong>Scheduled:</strong>
                                                    <?= date('l, F j, Y', strtotime($registration['schedule_date'])) ?>
                                                </small>
                                                <small class="d-block">
                                                    <i class="fas fa-clock me-1 text-warning"></i>
                                                    <strong>Time:</strong>
                                                    <?= date('g:i A', strtotime($registration['start_time'])) ?> -
                                                    <?= date('g:i A', strtotime($registration['end_time'])) ?>
                                                </small>
                                            </div>
                                        <?php else: ?>
                                            <small class="text-muted">
                                                <i class="fas fa-info-circle me-1"></i>
                                                No schedule assigned
                                            </small>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Right side: Status and Actions -->
                                    <div class="text-end ms-3" style="min-width: 140px;">
                                        <div class="mb-2">
                                            <span
                                                class="badge bg-<?= $registration['status'] === 'completed' ? 'success' : ($registration['status'] === 'cancelled' ? 'danger' : 'warning') ?> status-badge registration-status">
                                                <?= ucfirst($registration['status']) ?>
                                            </span>
                                        </div>

                                        <?php if ($registration['status'] === 'scheduled'): ?>
                                            <button class="btn btn-sm btn-success mark-complete-btn mb-2"
                                                data-registration-id="<?= $registration['registration_id'] ?>">
                                                <i class="fas fa-check me-1"></i>Mark Complete
                                            </button>
                                        <?php endif; ?>

                                        <div class="mt-1">
                                            <small class="text-muted">
                                                <i class="fas fa-calendar-plus me-1"></i>
                                                Registered: <?= date('M d', strtotime($registration['registered_at'])) ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>


                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Reviews -->
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-star me-2"></i>Recent Reviews</h6>
                    </div>
                    <div class="card-body recent-activity">
                        <?php if (empty($recent_reviews)): ?>
                            <div class="no-data">
                                <i class="fas fa-star fa-2x mb-2 text-muted"></i>
                                <p class="small">No reviews yet</p>
                                <small class="text-muted">Reviews will appear here when learners rate your skills.</small>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_reviews as $review): ?>
                                <div class="d-flex justify-content-between align-items-start mb-3 p-3 rounded"
                                    style="background-color: var(--soft-bg);">
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center mb-2">
                                            <strong class="me-2"><?= htmlspecialchars($review['learner_name']) ?></strong>
                                            <div class="rating-stars">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <i class="fas fa-star<?= $i <= $review['rating'] ? '' : ' text-muted' ?>"></i>
                                                <?php endfor; ?>
                                            </div>
                                        </div>
                                        <div class="mb-2">
                                            <small class="text-muted">Skill:
                                                <?= htmlspecialchars($review['skill_title']) ?></small>
                                        </div>
                                        <p class="small mb-1"><?= htmlspecialchars($review['comment']) ?></p>
                                        <small
                                            class="text-muted"><?= date('M d, Y', strtotime($review['created_at'])) ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="text-center">
                                <a href="#" class="btn btn-outline-primary btn-sm">View All Reviews</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Modal for Confirmations -->
    <div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmModalLabel">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="confirmModalBody">
                    Are you sure you want to perform this action?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmActionBtn">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global variables for modal
        let currentAction = null;
        let currentId = null;

        // Initialize modal
        const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));

        // Remove availability slot functionality
        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-slot')) {
                e.preventDefault();

                const availabilityId = e.target.getAttribute('data-availability-id');
                const slotItem = e.target.closest('.slot-item');
                const slotText = slotItem.querySelector('.fw-medium').textContent;

                currentAction = 'remove_slot';
                currentId = availabilityId;

                document.getElementById('confirmModalLabel').textContent = 'Remove Availability Slot';
                document.getElementById('confirmModalBody').textContent =
                    `Are you sure you want to remove the slot: ${slotText}?`;
                document.getElementById('confirmActionBtn').textContent = 'Remove';
                document.getElementById('confirmActionBtn').className = 'btn btn-danger';

                confirmModal.show();
            }
        });

        // Mark registration as completed functionality
        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('mark-complete-btn') || e.target.closest('.mark-complete-btn')) {
                e.preventDefault();

                const button = e.target.classList.contains('mark-complete-btn') ? e.target : e.target.closest('.mark-complete-btn');
                const registrationId = button.getAttribute('data-registration-id');
                const registrationItem = button.closest('.registration-item');
                const learnerName = registrationItem.querySelector('strong').textContent;
                const skillTitle = registrationItem.querySelector('.text-muted').textContent;

                currentAction = 'mark_completed';
                currentId = registrationId;

                document.getElementById('confirmModalLabel').textContent = 'Mark Registration Complete';
                document.getElementById('confirmModalBody').textContent =
                    `Mark the registration for ${learnerName} in "${skillTitle}" as completed?`;
                document.getElementById('confirmActionBtn').textContent = 'Mark Complete';
                document.getElementById('confirmActionBtn').className = 'btn btn-success';

                confirmModal.show();
            }
        });

        // Handle confirm button click
        document.getElementById('confirmActionBtn').addEventListener('click', function () {
            if (currentAction && currentId) {
                performAction(currentAction, currentId);
            }
        });

        // Perform the actual action
        function performAction(action, id) {
            const formData = new FormData();

            if (action === 'remove_slot') {
                formData.append('action', 'remove_slot');
                formData.append('availability_id', id);
            } else if (action === 'mark_completed') {
                formData.append('action', 'mark_completed');
                formData.append('registration_id', id);
            }

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    confirmModal.hide();

                    if (data.success) {
                        showAlert('success', data.message);

                        if (action === 'remove_slot') {
                            // Remove the slot from the UI
                            const slotItem = document.querySelector(`[data-id="${id}"]`);
                            if (slotItem) {
                                slotItem.remove();

                                // Update slot count
                                const card = slotItem.closest('.card');
                                const slotsCountElement = card.querySelector('.text-muted');
                                const currentCount = parseInt(slotsCountElement.textContent.match(/\d+/)[0]);
                                slotsCountElement.textContent = `Slots: ${currentCount - 1}`;

                                // If no more slots, you might want to hide the card or show a message
                                if (currentCount - 1 === 0) {
                                    const list = card.querySelector('.list-group');
                                    list.innerHTML = '<li class="list-group-item text-muted text-center">No slots available</li>';
                                }
                            }
                        } else if (action === 'mark_completed') {
                            // Update the registration status
                            const registrationItem = document.querySelector(`[data-registration-id="${id}"]`);
                            if (registrationItem) {
                                const statusBadge = registrationItem.querySelector('.registration-status');
                                const completeBtn = registrationItem.querySelector('.mark-complete-btn');

                                statusBadge.textContent = 'Completed';
                                statusBadge.className = 'badge bg-success status-badge registration-status';

                                if (completeBtn) {
                                    completeBtn.remove();
                                }
                            }
                        }
                    } else {
                        showAlert('danger', data.message);
                    }
                })
                .catch(error => {
                    confirmModal.hide();
                    console.error('Error:', error);
                    showAlert('danger', 'An error occurred while processing your request.');
                });
        }

        // Show alert function
        function showAlert(type, message) {
            const alertHtml = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;

            // Insert the alert at the top of the container
            const container = document.querySelector('.container');
            const firstChild = container.firstElementChild;
            const alertDiv = document.createElement('div');
            alertDiv.innerHTML = alertHtml;
            container.insertBefore(alertDiv, firstChild);

            // Auto-remove alert after 5 seconds
            setTimeout(() => {
                const alert = alertDiv.querySelector('.alert');
                if (alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            }, 5000);
        }

        // Reset modal state when closed
        document.getElementById('confirmModal').addEventListener('hidden.bs.modal', function () {
            currentAction = null;
            currentId = null;
        });

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