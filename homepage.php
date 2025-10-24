<?php
session_start();
// Stop browser from caching this page
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1
header("Pragma: no-cache"); // HTTP 1.0
header("Expires: 0"); // Proxies

if (!isset($_SESSION['role'])) {
    header("Location: auth/login.php");
    exit();
}

$full_name = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'];

// DB connection
$host = "localhost";
$username = "root";
$password = "";
$database = "hivemind";

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch 3 random skills for "Browse Various Skills"
$browseSkills = [];
$browseQuery = "SELECT skill_id, title, description FROM skills WHERE is_approved = 1 ORDER BY RAND() LIMIT 3";
if ($browseResult = $conn->query($browseQuery)) {
    while ($row = $browseResult->fetch_assoc()) {
        $browseSkills[] = $row;
    }
}

// Fetch 3 skills with most registrations for "Now Grossing Skills"
$grossingSkills = [];
$grossingQuery = "SELECT s.skill_id, s.title, s.description, COUNT(r.registration_id) as registration_count 
                  FROM skills s 
                  LEFT JOIN registration r ON s.skill_id = r.skill_id 
                  WHERE s.is_approved = 1 
                  GROUP BY s.skill_id, s.title, s.description 
                  ORDER BY registration_count DESC, s.created_at DESC 
                  LIMIT 3";
if ($grossingResult = $conn->query($grossingQuery)) {
    while ($row = $grossingResult->fetch_assoc()) {
        $grossingSkills[] = $row;
    }
}

// Fetch Top 3 Experts (at least 5 completed registrations and average rating >= 4.5)
$topExperts = [];
$topExpertsQuery = "
    SELECT 
        u.user_id,
        up.full_name,
        up.profile_picture,
        up.bio,
        COUNT(DISTINCT r.registration_id) as completed_count,
        COALESCE(AVG(rev.rating), 0) as avg_rating,
        COUNT(DISTINCT rev.review_id) as review_count
    FROM users u
    JOIN user_profiles up ON u.user_id = up.user_id
    JOIN skills s ON u.user_id = s.expert_id
    JOIN registration r ON s.skill_id = r.skill_id AND r.status = 'completed'
    LEFT JOIN reviews rev ON s.skill_id = rev.skill_id
    WHERE u.role = 'expert'
    GROUP BY u.user_id, up.full_name, up.profile_picture, up.bio
    HAVING completed_count >= 5 AND avg_rating >= 4.5
    ORDER BY avg_rating DESC, completed_count DESC
    LIMIT 3
";
if ($topExpertsResult = $conn->query($topExpertsQuery)) {
    while ($row = $topExpertsResult->fetch_assoc()) {
        $topExperts[] = $row;
    }
}

function getDashboardLink()
{
    if (!isset($_SESSION['role'])) {
        return "auth/login.html"; // fallback
    }
    switch ($_SESSION['role']) {
        case 'admin':
            return "admin/a_dashboard.php";
        case 'expert':
            return "expert/e_dashboard.php";
        case 'learner':
            return "learner/l_dashboard.php";
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
    <title>HiveMind Home</title>
    <link rel="icon" type="image/x-icon" href="assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/homepage.css" rel="stylesheet">
    <style>
        .expert-card {
            background: #fff;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
            height: 100%;
            position: relative;
            overflow: hidden;
        }

        .expert-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }

        .expert-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #FFD700, #FFA500);
        }

        .expert-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #FFD700;
            margin-bottom: 1rem;
        }

        .top-expert-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            background: linear-gradient(135deg, #FFD700, #FFA500);
            color: #000;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            box-shadow: 0 2px 8px rgba(255, 215, 0, 0.4);
        }

        .rating-stars {
            color: #FFD700;
            font-size: 0.9rem;
        }

        .expert-stats {
            display: flex;
            gap: 1rem;
            margin-top: 0.75rem;
            font-size: 0.85rem;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            color: #666;
        }

        .expert-bio {
            font-size: 0.9rem;
            color: #666;
            margin-top: 0.75rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
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
                            <a class="nav-link" href="pages/message_list.php"><i class="bi bi-chat-dots me-2"></i>
                                Messages</a>
                        </li>
                        <li class="nav-item mb-2">
                            <a class="nav-link" href="pages/browse.php"><i class="bi bi-search me-2"></i> Browse
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
                        <img src="assets/images/logo.png" alt="HiveMind Logo" class="logo" width="auto" height="40">
                        <h5 class="mb-0">Welcome, <?php echo htmlspecialchars($full_name); ?>!</h5>
                    </div>
                    <div>
                        <a href="pages/profile.php" class="btn btn-outline-secondary btn-sm me-2"><i
                                class="bi bi-person-circle"></i> Profile</a>
                        <a href="auth/logout.php" class="btn btn-outline-danger btn-sm"><i
                                class="bi bi-box-arrow-right"></i>
                            Logout</a>
                    </div>
                </div>

                <!-- Page Content -->
                <div class="container py-4">


                    <!-- Browse Various Skills -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4>Browse Various Skills</h4>
                            <a href="pages/browse.php" class="btn btn-primary btn-sm">View More</a>
                        </div>
                        <div class="row g-3">
                            <?php if (count($browseSkills) > 0): ?>
                                <?php foreach ($browseSkills as $skill): ?>
                                    <div class="col-md-4">
                                        <a href="pages/view_skill.php?skill_id=<?php echo $skill['skill_id']; ?>"
                                            class="text-decoration-none">
                                            <div class="p-3 skill-card">
                                                <h6><?php echo htmlspecialchars($skill['title']); ?></h6>
                                                <p><?php echo htmlspecialchars(substr($skill['description'], 0, 80)) . '...'; ?>
                                                </p>
                                            </div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>No skills available to display.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Now Grossing Skills -->
                    <div>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4>Now Grossing Skills</h4>
                            <a href="pages/browse.php" class="btn btn-primary btn-sm">View More</a>
                        </div>
                        <div class="row g-3">
                            <?php if (count($grossingSkills) > 0): ?>
                                <?php foreach ($grossingSkills as $skill): ?>
                                    <div class="col-md-4">
                                        <a href="pages/view_skill.php?skill_id=<?php echo $skill['skill_id']; ?>"
                                            class="text-decoration-none">
                                            <div class="p-3 skill-card">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <h6 class="mb-0"><?php echo htmlspecialchars($skill['title']); ?></h6>
                                                    <span class="badge bg-success"><?php echo $skill['registration_count']; ?>
                                                        enrolled</span>
                                                </div>
                                                <p><?php echo htmlspecialchars(substr($skill['description'], 0, 80)) . '...'; ?>
                                                </p>
                                            </div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>No trending skills available.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Top Experts Section -->
                    <div class="mt-5">
                        <?php if (count($topExperts) > 0): ?>
                            <div class="mb-5">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h4><i class="bi bi-trophy-fill text-warning me-2"></i>Top Experts</h4>
                                    <a href="pages/top_experts.php" class="btn btn-warning btn-sm">View All Top Experts</a>
                                </div>
                                <div class="row g-3">
                                    <?php foreach ($topExperts as $expert): ?>
                                        <div class="col-md-4">
                                            <div class="expert-card text-center">
                                                <div class="top-expert-badge">
                                                    <i class="bi bi-star-fill"></i>
                                                    TOP EXPERT
                                                </div>
                                                <?php if (!empty($expert['profile_picture'])): ?>
                                                    <img src="<?php echo htmlspecialchars($expert['profile_picture']); ?>"
                                                        alt="<?php echo htmlspecialchars($expert['full_name']); ?>"
                                                        class="expert-avatar">
                                                <?php else: ?>
                                                    <div class="expert-avatar d-flex align-items-center justify-content-center bg-secondary text-white"
                                                        style="font-size: 2rem;">
                                                        <i class="bi bi-person-fill"></i>
                                                    </div>
                                                <?php endif; ?>

                                                <h6 class="mb-1"><?php echo htmlspecialchars($expert['full_name']); ?></h6>

                                                <div class="rating-stars mb-2">
                                                    <?php
                                                    $rating = round($expert['avg_rating'], 1);
                                                    $fullStars = floor($rating);
                                                    $halfStar = ($rating - $fullStars) >= 0.5;

                                                    for ($i = 0; $i < $fullStars; $i++) {
                                                        echo '<i class="bi bi-star-fill"></i>';
                                                    }
                                                    if ($halfStar) {
                                                        echo '<i class="bi bi-star-half"></i>';
                                                    }
                                                    ?>
                                                    <span class="text-dark ms-1"><?php echo number_format($rating, 1); ?></span>
                                                </div>

                                                <div class="expert-stats justify-content-center">
                                                    <div class="stat-item">
                                                        <i class="bi bi-check-circle-fill text-success"></i>
                                                        <span><?php echo $expert['completed_count']; ?> sessions</span>
                                                    </div>
                                                    <div class="stat-item">
                                                        <i class="bi bi-chat-left-text-fill text-primary"></i>
                                                        <span><?php echo $expert['review_count']; ?> reviews</span>
                                                    </div>
                                                </div>

                                                <?php if (!empty($expert['bio'])): ?>
                                                    <p class="expert-bio"><?php echo htmlspecialchars($expert['bio']); ?></p>
                                                <?php endif; ?>

                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
    </script>
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