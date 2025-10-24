<?php
session_start();

// Database connection
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

// Check admin login
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// Handle NEW skill request approvals (from expert_skill_requests table)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'], $_POST['action'])) {
    $id = (int) $_POST['request_id'];
    $action = $_POST['action'] === 'approve' ? 'approved' : 'rejected';

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE expert_skill_requests SET status=? WHERE request_id=?");
        $stmt->execute([$action, $id]);

        if ($stmt->rowCount()) {
            if ($action === 'approved') {
                // Fetch the request details including category
                $getRequest = $pdo->prepare("
                    SELECT expert_id, proposed_title, category, proposed_description, skill_image_r
                    FROM expert_skill_requests
                    WHERE request_id=?
                ");
                $getRequest->execute([$id]);
                $request = $getRequest->fetch(PDO::FETCH_ASSOC);

                if ($request) {
                    // Insert into skills table
                    $insertSkill = $pdo->prepare("
                        INSERT INTO skills (expert_id, title, category, description, skill_image, is_approved, created_at)
                        VALUES (?, ?, ?, ?, ?, 1, NOW())
                    ");
                    $insertSkill->execute([
                        $request['expert_id'],
                        $request['proposed_title'],
                        $request['category'],
                        $request['proposed_description'],
                        $request['skill_image_r'],
                    ]);

                    // Notify expert
                    $expert_notification_query = "INSERT INTO notifications (user_id, type, message, is_read, created_at) 
                                                 VALUES (?, 'info', ?, 0, NOW())";
                    $expert_stmt = $pdo->prepare($expert_notification_query);
                    $expert_message = "Your skill '{$request['proposed_title']}' has been approved and is now live!";
                    $expert_stmt->execute([$request['expert_id'], $expert_message]);
                }

                $_SESSION['flash_success'] = "Skill request approved and added to skills.";
            } else {
                // Get expert_id for rejection notification
                $getRequest = $pdo->prepare("SELECT expert_id, proposed_title FROM expert_skill_requests WHERE request_id=?");
                $getRequest->execute([$id]);
                $request = $getRequest->fetch(PDO::FETCH_ASSOC);

                if ($request) {
                    // Notify expert of rejection
                    $expert_notification_query = "INSERT INTO notifications (user_id, type, message, is_read, created_at) 
                                                 VALUES (?, 'alert', ?, 0, NOW())";
                    $expert_stmt = $pdo->prepare($expert_notification_query);
                    $expert_message = "Your skill request '{$request['proposed_title']}' was rejected by an administrator.";
                    $expert_stmt->execute([$request['expert_id'], $expert_message]);
                }

                $_SESSION['flash_success'] = "Skill request rejected.";
            }
        } else {
            $_SESSION['flash_error'] = "Failed to update request.";
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollback();
        $_SESSION['flash_error'] = "Error processing skill request: " . $e->getMessage();
    }

    header("Location: a_dashboard.php");
    exit();
}

// Handle edited skill approvals
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['skill_id'], $_POST['action_edit'])) {
    $skill_id = (int) $_POST['skill_id'];
    $action = $_POST['action_edit'] === 'approve' ? 'approved' : 'rejected';

    try {
        $pdo->beginTransaction();

        if ($action === 'approved') {
            // Simply set is_approved back to 1
            $stmt = $pdo->prepare("UPDATE skills SET is_approved = 1 WHERE skill_id = ?");
            $stmt->execute([$skill_id]);

            // Get skill and expert info for notification
            $skill_query = "SELECT s.title, s.expert_id, up.full_name as expert_name 
                           FROM skills s 
                           LEFT JOIN user_profiles up ON s.expert_id = up.user_id 
                           WHERE s.skill_id = ?";
            $skill_stmt = $pdo->prepare($skill_query);
            $skill_stmt->execute([$skill_id]);
            $skill_data = $skill_stmt->fetch(PDO::FETCH_ASSOC);

            // Notify expert
            $expert_notification_query = "INSERT INTO notifications (user_id, type, message, is_read, created_at) 
                                         VALUES (?, 'info', ?, 0, NOW())";
            $expert_stmt = $pdo->prepare($expert_notification_query);
            $expert_message = "Your edited skill '{$skill_data['title']}' has been approved and is now live!";
            $expert_stmt->execute([$skill_data['expert_id'], $expert_message]);

            $_SESSION['flash_success'] = "Skill edit approved successfully.";
        } else {
            // Reject - delete the skill and its dependencies
            $skill_query = "SELECT s.title, s.expert_id FROM skills s WHERE s.skill_id = ?";
            $skill_stmt = $pdo->prepare($skill_query);
            $skill_stmt->execute([$skill_id]);
            $skill_data = $skill_stmt->fetch(PDO::FETCH_ASSOC);

            // Delete dependencies first
            $pdo->prepare("DELETE FROM schedule WHERE skill_id = ?")->execute([$skill_id]);
            $pdo->prepare("DELETE FROM availability_dates WHERE skill_id = ?")->execute([$skill_id]);
            $pdo->prepare("DELETE FROM reviews WHERE skill_id = ?")->execute([$skill_id]);
            $pdo->prepare("DELETE FROM registration WHERE skill_id = ?")->execute([$skill_id]);

            // Delete the skill
            $pdo->prepare("DELETE FROM skills WHERE skill_id = ?")->execute([$skill_id]);

            // Notify expert
            $expert_notification_query = "INSERT INTO notifications (user_id, type, message, is_read, created_at) 
                                         VALUES (?, 'alert', ?, 0, NOW())";
            $expert_stmt = $pdo->prepare($expert_notification_query);
            $expert_message = "Your skill edit for '{$skill_data['title']}' was rejected and the skill has been removed.";
            $expert_stmt->execute([$skill_data['expert_id'], $expert_message]);

            $_SESSION['flash_success'] = "Skill edit rejected and removed.";
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollback();
        $_SESSION['flash_error'] = "Error processing skill edit: " . $e->getMessage();
    }

    header("Location: a_dashboard.php");
    exit();
}

// Handle review deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_review'])) {
    $review_id = (int) $_POST['review_id'];

    try {
        $pdo->beginTransaction();

        // Get review details for notification
        $review_query = "SELECT r.review_id, r.learner_id, r.skill_id, r.comment, 
                                s.title as skill_title, up.full_name as learner_name
                         FROM reviews r
                         LEFT JOIN skills s ON r.skill_id = s.skill_id
                         LEFT JOIN user_profiles up ON r.learner_id = up.user_id
                         WHERE r.review_id = ?";
        $review_stmt = $pdo->prepare($review_query);
        $review_stmt->execute([$review_id]);
        $review_data = $review_stmt->fetch(PDO::FETCH_ASSOC);

        if ($review_data) {
            // Delete the review
            $delete_stmt = $pdo->prepare("DELETE FROM reviews WHERE review_id = ?");
            $delete_stmt->execute([$review_id]);

            // Notify the learner
            $notification_query = "INSERT INTO notifications (user_id, type, message, is_read, created_at) 
                                   VALUES (?, 'alert', ?, 0, NOW())";
            $notif_stmt = $pdo->prepare($notification_query);
            $message = "Your review for '{$review_data['skill_title']}' has been removed by an administrator.";
            $notif_stmt->execute([$review_data['learner_id'], $message]);

            $_SESSION['flash_success'] = "Review deleted successfully.";
        } else {
            $_SESSION['flash_error'] = "Review not found.";
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollback();
        $_SESSION['flash_error'] = "Error deleting review: " . $e->getMessage();
    }

    header("Location: a_dashboard.php");
    exit();
}

// Handle user deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $user_id = (int) $_POST['user_id'];

    try {
        $pdo->beginTransaction();

        // Delete related records first (due to foreign key constraints)
        $pdo->prepare("DELETE FROM activity_log WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM availability_dates WHERE expert_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM expert_skill_requests WHERE expert_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM registration WHERE learner_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM reviews WHERE learner_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM skills WHERE expert_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM user_profiles WHERE user_id = ?")->execute([$user_id]);
        $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$user_id]);

        $pdo->commit();
        $_SESSION['flash_success'] = "User deleted successfully.";
    } catch (Exception $e) {
        $pdo->rollback();
        $_SESSION['flash_error'] = "Error deleting user: " . $e->getMessage();
    }
}

// System Health
$stats = [
    'users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'skills' => $pdo->query("SELECT COUNT(*) FROM skills")->fetchColumn(),
    'reviews' => $pdo->query("SELECT COUNT(*) FROM reviews")->fetchColumn(),
];

// Recent Activity - Limited to 15 most recent
$query = "
    SELECT * FROM activity_log 
    ORDER BY performed_at DESC
    LIMIT 15
";
$stmt = $pdo->query($query);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all users with their profiles
$users_query = "
    SELECT u.user_id, u.email, u.role, u.status, u.created_at, 
           up.full_name, up.phone_number
    FROM users u
    LEFT JOIN user_profiles up ON u.user_id = up.user_id
    ORDER BY u.created_at DESC
";
$users = $pdo->query($users_query)->fetchAll(PDO::FETCH_ASSOC);

// Fetch all skills with expert info
$skills_query = "
    SELECT s.skill_id, s.title, s.category, s.is_approved, s.created_at,
           up.full_name as expert_name,
           COUNT(r.registration_id) as total_registrations
    FROM skills s
    LEFT JOIN users u ON s.expert_id = u.user_id
    LEFT JOIN user_profiles up ON u.user_id = up.user_id
    LEFT JOIN registration r ON s.skill_id = r.skill_id
    GROUP BY s.skill_id
    ORDER BY s.created_at DESC
";
$skills = $pdo->query($skills_query)->fetchAll(PDO::FETCH_ASSOC);

$reviews_query = "
    SELECT r.review_id, r.rating, r.comment, r.created_at,
           up.full_name as learner_name,
           s.title as skill_title,
           s.skill_id
    FROM reviews r
    LEFT JOIN user_profiles up ON r.learner_id = up.user_id
    LEFT JOIN skills s ON r.skill_id = s.skill_id
    ORDER BY r.created_at DESC
";
$reviews = $pdo->query($reviews_query)->fetchAll(PDO::FETCH_ASSOC);

// Pending Skills Approval - Get from BOTH expert_skill_requests AND unapproved skills
$pending_skills_requests = $pdo->query("
    SELECT r.request_id,
       r.proposed_title   AS title,
       up.full_name       AS expert,
       r.proposed_description,
       r.status,
       r.submitted_at,
       'new' as request_type
    FROM expert_skill_requests r
    JOIN user_profiles up ON r.expert_id = up.user_id
    WHERE r.status = 'pending'
    ORDER BY r.submitted_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$pending_skills_edits = $pdo->query("
    SELECT s.skill_id as request_id,
       s.title,
       up.full_name AS expert,
       s.description as proposed_description,
       'pending' as status,
       s.created_at as submitted_at,
       'edit' as request_type
    FROM skills s
    JOIN user_profiles up ON s.expert_id = up.user_id
    WHERE s.is_approved = 0
    ORDER BY s.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Merge both arrays
$pending_skills = array_merge($pending_skills_requests, $pending_skills_edits);

// Sort by submitted_at
usort($pending_skills, function ($a, $b) {
    return strtotime($b['submitted_at']) - strtotime($a['submitted_at']);
});

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - HiveMind</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="../assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/a_dash.css" rel="stylesheet">
    <style>
        .metric-card {
            background: #fffbe6;
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

        .recent-activity {
            max-height: 300px;
            overflow-y: auto;
        }

        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }

        .btn-group-sm .btn {
            margin: 0 2px;
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="#"><img src="../assets/images/logo.png" alt="HiveMind Logo"
                    style="width:40px;height:40px;"> HiveMind Admin</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../homepage.php">Homepage</a>
                <a class="nav-link" href="../pages/profile.php">Profile</a>
                <a class="nav-link" href="../auth/logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <?php if (isset($_SESSION['flash_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= $_SESSION['flash_success'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= $_SESSION['flash_error'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <div class="container mt-4">
        <!-- System Health -->
        <div class="row mb-4">
            <div class="col-lg-4 col-md-6">
                <div class="metric-card">
                    <div class="metric-value"><?= $stats['users'] ?></div>
                    <div>Total Users</div>
                </div>
            </div>
            <div class="col-lg-4 col-md-6">
                <div class="metric-card">
                    <div class="metric-value"><?= $stats['skills'] ?></div>
                    <div>Total Skills</div>
                </div>
            </div>
            <div class="col-lg-4 col-md-6">
                <div class="metric-card">
                    <div class="metric-value"><?= $stats['reviews'] ?></div>
                    <div>Total Reviews</div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Pending Skills Approval -->
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-clock me-2"></i>Pending Skills Approval</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pending_skills)): ?>
                            <div class="text-muted">No pending skills for approval.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Skill Title</th>
                                            <th>Expert</th>
                                            <th>Type</th>
                                            <th>Submitted on</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_skills as $skill): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($skill['title']) ?></td>
                                                <td><?= htmlspecialchars($skill['expert']) ?></td>
                                                <td>
                                                    <?php if ($skill['request_type'] === 'new'): ?>
                                                        <span class="badge bg-info">New Skill</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">Edited Skill</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($skill['submitted_at']) ?></td>
                                                <td>
                                                    <?php if ($skill['request_type'] === 'new'): ?>
                                                        <!-- Original approval process for new skills -->
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="request_id"
                                                                value="<?= $skill['request_id'] ?>">
                                                            <button type="submit" name="action" value="approve"
                                                                class="btn btn-success btn-sm">Approve</button>
                                                        </form>
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="request_id"
                                                                value="<?= $skill['request_id'] ?>">
                                                            <button type="submit" name="action" value="reject"
                                                                class="btn btn-danger btn-sm">Reject</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <!-- Approval process for edited skills -->
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="skill_id"
                                                                value="<?= $skill['request_id'] ?>">
                                                            <button type="submit" name="action_edit" value="approve"
                                                                class="btn btn-success btn-sm">Approve Edit</button>
                                                        </form>
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="skill_id"
                                                                value="<?= $skill['request_id'] ?>">
                                                            <button type="submit" name="action_edit" value="reject"
                                                                class="btn btn-danger btn-sm">Reject Edit</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Users Management -->
            <div class="col-lg-12">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-users me-2"></i>User Management</h5>
                    </div>
                    <div class="card-body">
                        <?php if (isset($_GET['msg'])): ?>
                            <div class="alert alert-success">
                                <?= htmlspecialchars($_GET['msg']) ?>
                            </div>
                        <?php endif; ?>

                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Phone</th>
                                        <th>Joined</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><?= $user['user_id'] ?></td>
                                            <td><?= htmlspecialchars($user['full_name'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($user['email']) ?></td>
                                            <td>
                                                <span
                                                    class="badge bg-<?= $user['role'] === 'admin' ? 'danger' : ($user['role'] === 'expert' ? 'warning' : 'primary') ?>">
                                                    <?= ucfirst($user['role']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span
                                                    class="badge bg-<?= $user['status'] === 'active' ? 'success' : 'secondary' ?>">
                                                    <?= ucfirst($user['status']) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($user['phone_number'] ?? 'N/A') ?></td>
                                            <td><?= date('M d, Y', strtotime($user['created_at'])) ?></td>
                                            <td>
                                                <?php if ($user['role'] !== 'admin'): ?>
                                                    <form method="get" action="delete_user.php" class="d-inline"
                                                        onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">
                                                        <input type="hidden" name="id" value="<?= $user['user_id'] ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-muted">Protected</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Skills Management Section -->
            <div class="col-lg-12">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-lightbulb me-2"></i>Skills Management</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Title</th>
                                        <th>Category</th>
                                        <th>Expert</th>
                                        <th>Status</th>
                                        <th>Registrations</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($skills as $skill): ?>
                                        <tr>
                                            <td><?= $skill['skill_id'] ?></td>
                                            <td><?= htmlspecialchars($skill['title']) ?></td>
                                            <td><?= htmlspecialchars($skill['category']) ?></td>
                                            <td><?= htmlspecialchars($skill['expert_name'] ?? 'N/A') ?></td>
                                            <td>
                                                <span class="badge bg-<?= $skill['is_approved'] ? 'success' : 'warning' ?>">
                                                    <?= $skill['is_approved'] ? 'Approved' : 'Pending' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?= $skill['total_registrations'] ?></span>
                                            </td>
                                            <td><?= date('M d, Y', strtotime($skill['created_at'])) ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="../pages/view_skill.php?skill_id=<?= $skill['skill_id'] ?>"
                                                        class="btn btn-outline-primary btn-sm" target="_blank">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="delete_skill.php?id=<?= $skill['skill_id'] ?>"
                                                        class="btn btn-danger btn-sm"
                                                        onclick="return confirm('Are you sure you want to delete this skill? This will also delete all related registrations and reviews.')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reviews Management Section -->
            <div class="col-lg-12">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-star me-2"></i>Reviews Management</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($reviews)): ?>
                            <div class="text-muted">No reviews available.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Learner</th>
                                            <th>Skill</th>
                                            <th>Rating</th>
                                            <th>Comment</th>
                                            <th>Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($reviews as $review): ?>
                                            <tr>
                                                <td><?= $review['review_id'] ?></td>
                                                <td><?= htmlspecialchars($review['learner_name'] ?? 'N/A') ?></td>
                                                <td>
                                                    <a href="../pages/view_skill.php?skill_id=<?= $review['skill_id'] ?>"
                                                        target="_blank">
                                                        <?= htmlspecialchars($review['skill_title']) ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <span class="badge bg-warning text-dark">
                                                        <?= $review['rating'] ?> <i class="fas fa-star"></i>
                                                    </span>
                                                </td>
                                                <td><?= htmlspecialchars(substr($review['comment'] ?? '', 0, 50)) ?><?= strlen($review['comment'] ?? '') > 50 ? '...' : '' ?>
                                                </td>
                                                <td><?= date('M d, Y', strtotime($review['created_at'])) ?></td>
                                                <td>
                                                    <form method="post" class="d-inline"
                                                        onsubmit="return confirm('Are you sure you want to delete this review?')">
                                                        <input type="hidden" name="review_id"
                                                            value="<?= $review['review_id'] ?>">
                                                        <button type="submit" name="delete_review"
                                                            class="btn btn-danger btn-sm">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="container my-4">
                <h3 class="mb-4">Activity Log</h3>
                <div class="row">
                    <?php if (empty($logs)): ?>
                        <p>No records available.</p>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <div class="col-md-6 mb-3">
                                <div class="card">
                                    <div class="card-header">
                                        <?php
                                        switch ($log['action']) {
                                            case 'registration':
                                                echo "User Registered";
                                                break;
                                            case 'add_skill':
                                                echo "Skill Added";
                                                break;
                                            case 'edit_skill':
                                                echo "Skill Edited";
                                                break;
                                            case 'login':
                                                echo "User Logged In";
                                                break;
                                            case 'logout':
                                                echo "User Logged Out";
                                                break;
                                            case 'enrollment':
                                                echo "Enrollment";
                                                break;
                                            case 'review_submitted':
                                                echo "Review Submitted";
                                                break;
                                        }
                                        ?>
                                    </div>
                                    <div class="card-body">
                                        <p class="mb-0">
                                            <?= htmlspecialchars($log['details']) ?><br>
                                            <span class="badge bg-warning text-dark">
                                                <?= date("M d, Y", strtotime($log['performed_at'])) ?>
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>