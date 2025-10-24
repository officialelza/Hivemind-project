<?php
session_start();
// 🚫 Stop browser from caching this page
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1
header("Pragma: no-cache"); // HTTP 1.0
header("Expires: 0"); // Proxies

if (!isset($_SESSION['role'])) {
    header("Location: ../auth/login.php");
    exit();
}

$full_name = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'];

// Connect DB
$host = "localhost";
$username = "root";
$password = "";
$database = "hivemind";
$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get skill ID
$skill_id = isset($_GET['skill_id']) ? intval($_GET['skill_id']) : 0;

// Fetch skill details
$query = "SELECT s.title, s.category, s.description, s.expert_id, s.skill_image, u.email, p.full_name, p.bio, p.profile_picture 
          FROM skills s 
          JOIN users u ON s.expert_id = u.user_id 
          JOIN user_profiles p ON u.user_id = p.user_id 
          WHERE s.skill_id = $skill_id";
$result = $conn->query($query);

if (!$result || $result->num_rows === 0) {
    die("Skill not found.");
}

$skill = $result->fetch_assoc();

// Fetch reviews for this skill
$reviews_query = "SELECT r.rating, r.comment, r.created_at, up.full_name as learner_name
                  FROM reviews r 
                  JOIN user_profiles up ON r.learner_id = up.user_id 
                  WHERE r.skill_id = $skill_id 
                  ORDER BY r.created_at DESC";
$reviews_result = $conn->query($reviews_query);
$reviews = [];
if ($reviews_result && $reviews_result->num_rows > 0) {
    while ($review = $reviews_result->fetch_assoc()) {
        $reviews[] = $review;
    }
}

function getDashboardLink()
{
    if (!isset($_SESSION['role'])) {
        return "auth/login.html"; // fallback
    }
    switch ($_SESSION['role']) {
        case 'admin':
            return "../admin/a_dashboard.php";
        case 'expert':
            return "../expert/e_dashboard.php";
        case 'learner':
            return "../learner/l_dashboard.php";
        default:
            return "homepage.php";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($skill['title']); ?> - HiveMind</title>
    <link rel="icon" type="image/x-icon" href="assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/base.css" rel="stylesheet">
    <style>
        .skill-image {
            width: 100%;
            max-height: 300px;
            object-fit: cover;
            border-radius: var(--radius-lg);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .expert-card {
            background: #fff;
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
            transition: transform var(--transition), box-shadow var(--transition);
        }

        .expert-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }

        .expert-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary-accent);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .skill-content {
            background: #fff;
            padding: 2rem;
            border-radius: var(--radius-lg);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
            margin-bottom: 2rem;
        }

        .category-badge {
            display: inline-block;
            background: var(--primary-accent);
            color: var(--dark-text);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        .btn-enroll {
            background: var(--deep-brown);
            border-color: var(--deep-brown);
            color: #fff;
            padding: 0.75rem 2rem;
            font-weight: 600;
            border-radius: 8px;
            transition: all var(--transition);
            text-decoration: none;
            display: inline-block;
        }

        .btn-enroll:hover {
            background: var(--secondary-accent);
            border-color: var(--secondary-accent);
            color: #fff;
            transform: translateY(-3px);
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.12);
        }

        .btn-contact {
            background: var(--primary-accent);
            border-color: var(--primary-accent);
            color: var(--dark-text);
            padding: 0.6rem 1.5rem;
            font-weight: 600;
            border-radius: 8px;
            transition: all var(--transition);
            text-decoration: none;
            display: inline-block;
        }

        .btn-contact:hover {
            background: var(--bright-gold);
            border-color: var(--bright-gold);
            color: var(--dark-text);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-2 col-lg-2 d-md-block sidebar py-4">
                <div class="px-3">
                    <h4 class="mb-4">HiveMind</h4>
                    <ul class="nav flex-column">
                        <li class="nav-item mb-2">
                            <a class="nav-link" href="<?php echo getDashboardLink(); ?>"><i
                                    class="bi bi-speedometer2 me-2"></i> Dashboard</a>
                        </li>
                        <li class="nav-item mb-2">
                            <a class="nav-link" href="../homepage.php"><i class="bi bi-house me-2"></i> Home</a>
                        </li>
                        <li class="nav-item mb-2">
                            <a class="nav-link" href="../pages/message_list.php"><i class="bi bi-chat-dots me-2"></i>
                                Messages</a>
                        </li>
                        <li class="nav-item mb-2">
                            <a class="nav-link active" href="pages/browse.php"><i class="bi bi-search me-2"></i> Browse
                                Skills</a>
                        </li>

                    </ul>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-10 ms-sm-auto col-lg-10 px-0">
                <!-- Header -->
                <div class="header-bar d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <!-- Logo Placeholder -->
                        <div class="logo-placeholder me-3">
                            <span><img class="logo-placeholder" src="../assets/images/logo.png"
                                    alt="HiveMind Logo"></span>
                        </div>
                        <h5 class="mb-0">Skill Details</h5>
                    </div>
                    <div>
                        <a href="profile.php" class="btn btn-outline-secondary btn-sm me-2"><i
                                class="bi bi-person-circle"></i> Profile</a>
                        <a href="../auth/logout.php" class="btn btn-outline-danger btn-sm"><i
                                class="bi bi-box-arrow-right"></i>
                            Logout</a>
                    </div>
                </div>

                <!-- Page Content -->
                <div class="container py-4">
                    <div class="row g-4">
                        <!-- Skill Info -->
                        <div class="col-md-8">
                            <div class="skill-content">
                                <h2 class="section-title"><?php echo htmlspecialchars($skill['title']); ?></h2>
                                <div class="category-badge">
                                    <i class="bi bi-tag me-1"></i>
                                    <?php echo htmlspecialchars($skill['category']); ?>
                                </div>

                                <p class="lead mb-4" style="color: #6c757d; line-height: 1.6;">
                                    <?php echo htmlspecialchars($skill['description']); ?>
                                </p>

                                <!-- Skill Image -->
                                <img src="<?php echo $skill['skill_image'] ? htmlspecialchars($skill['skill_image']) : '../uploads/skill-default.jpg'; ?>"
                                    alt="<?php echo htmlspecialchars($skill['title']); ?>" class="skill-image mb-4">

                                <div class="d-flex gap-3 align-items-center">
                                    <a href="../learner/enroll.php?skill_id=<?php echo $skill_id; ?>"
                                        class="btn-enroll">
                                        <i class="bi bi-play-circle me-2"></i>Enroll Now
                                    </a>
                                    <?php if ($role === 'learner'): ?>
                                        <a href="../learner/create_review.php?skill_id=<?php echo $skill_id; ?>"
                                            class="btn btn-outline-warning">
                                            <i class="bi bi-star me-2"></i>Review Now
                                        </a>
                                    <?php endif; ?>
                                    <a href="browse.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-arrow-left me-2"></i>Back to Browse
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Expert Info -->
                        <div class="col-md-4">
                            <div class="expert-card text-center">
                                <h5 class="section-title text-center">Meet Your Expert</h5>

                                <img src="<?php echo $skill['profile_picture'] ? htmlspecialchars($skill['profile_picture']) : 'assets/images/default-avatar.png'; ?>"
                                    alt="Expert Avatar" class="expert-avatar mb-3">

                                <h5 class="mb-2" style="color: var(--dark-text); font-weight: 600;">
                                    <?php echo htmlspecialchars($skill['full_name']); ?>
                                </h5>

                                <p class="text-muted mb-3" style="line-height: 1.5;">
                                    <?php echo htmlspecialchars($skill['bio']); ?>
                                </p>

                                <div class="mb-3">
                                    <small class="text-muted">
                                        <i class="bi bi-envelope me-1"></i>
                                        <?php echo htmlspecialchars($skill['email']); ?>
                                    </small>
                                </div>

                                <a href="messages.php?to=<?php echo $skill['expert_id']; ?>" class="btn-contact">
                                    <i class="bi bi-chat-text me-2"></i>Contact Expert
                                </a>
                            </div>
                        </div>

                        <!-- Reviews Section  -->
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="bi bi-star me-2"></i>Reviews</h5>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($reviews)): ?>
                                        <div class="reviews-list" style="max-height: 400px; overflow-y: auto;">
                                            <?php foreach ($reviews as $review): ?>
                                                <div class="review-item mb-3 p-3 border rounded">
                                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                                        <div>
                                                            <strong><?php echo htmlspecialchars($review['learner_name']); ?></strong>
                                                            <div class="rating-stars">
                                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                    <i
                                                                        class="bi bi-star<?= $i <= $review['rating'] ? '-fill text-warning' : ' text-muted' ?>"></i>
                                                                <?php endfor; ?>
                                                            </div>
                                                        </div>
                                                        <small
                                                            class="text-muted"><?php echo date('M d, Y', strtotime($review['created_at'])); ?></small>
                                                    </div>
                                                    <p class="mb-0 small"><?php echo htmlspecialchars($review['comment']); ?>
                                                    </p>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center text-muted py-4">
                                            <i class="bi bi-star fa-2x mb-2"></i>
                                            <p>No reviews yet.</p>
                                            <small>Be the first to review this skill!</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.addEventListener("pageshow", function (event) {
            // If page was restored from back/forward cache
            if (event.persisted) {
                window.location.reload();
            }
        });
    </script>
</body>

</html>