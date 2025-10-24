<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['role'])) {
    header("Location: ../auth/login.html");
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

// Fetch All Top Experts (at least 5 completed registrations and average rating >= 4.5)
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
";
$topExpertsResult = $conn->query($topExpertsQuery);

function getDashboardLink()
{
    if (!isset($_SESSION['role'])) {
        return "../auth/login.html";
    }
    switch ($_SESSION['role']) {
        case 'admin':
            return "../admin/a_dashboard.php";
        case 'expert':
            return "../expert/e_dashboard.php";
        case 'learner':
            return "../learner/l_dashboard.php";
        default:
            return "../homepage.php";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Top Experts - HiveMind</title>
    <link rel="icon" type="image/x-icon" href="../assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/base.css" rel="stylesheet">
    <style>
        .expert-profile-card {
            background: #fff;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }

        .expert-profile-card:hover {
            border-color: #FFD700;
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(255, 215, 0, 0.2);
        }

        .rank-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.2rem;
            color: #fff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .rank-1 {
            background: linear-gradient(135deg, #FFD700, #FFA500);
        }

        .rank-2 {
            background: linear-gradient(135deg, #C0C0C0, #A8A8A8);
        }

        .rank-3 {
            background: linear-gradient(135deg, #CD7F32, #8B4513);
        }

        .rank-other {
            background: linear-gradient(135deg, #6c757d, #495057);
        }

        .expert-header {
            display: flex;
            gap: 1.5rem;
            align-items: start;
            margin-bottom: 1.5rem;
        }

        .expert-avatar-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #FFD700;
            box-shadow: 0 4px 15px rgba(255, 215, 0, 0.3);
        }

        .expert-info h4 {
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 700;
        }

        .top-expert-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: linear-gradient(135deg, #FFD700, #FFA500);
            color: #000;
            padding: 0.3rem 0.9rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            box-shadow: 0 2px 8px rgba(255, 215, 0, 0.4);
        }

        .rating-display {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }

        .rating-stars {
            color: #FFD700;
        }

        .expert-stats-row {
            display: flex;
            gap: 2rem;
            margin-top: 0.75rem;
        }

        .stat-box {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .expert-bio {
            color: #666;
            line-height: 1.6;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #FFD700;
        }

        .skills-section h5 {
            color: #333;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #FFD700;
        }

        .skill-item {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            transition: all 0.3s ease;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .skill-item:hover {
            border-color: #FFD700;
            box-shadow: 0 3px 12px rgba(255, 215, 0, 0.2);
            transform: translateX(5px);
        }

        .skill-info h6 {
            margin: 0 0 0.3rem 0;
            color: #333;
            font-weight: 600;
        }

        .skill-category {
            font-size: 0.85rem;
            color: #666;
        }

        .page-header {
            background: linear-gradient(135deg, #FFD700, #FFA500);
            color: #000;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(255, 215, 0, 0.3);
        }

        .no-experts-msg {
            text-align: center;
            padding: 3rem;
            background: #f8f9fa;
            border-radius: 12px;
            margin-top: 2rem;
        }

        .no-experts-msg i {
            font-size: 4rem;
            color: #ccc;
            margin-bottom: 1rem;
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
                            <a class="nav-link" href="<?php echo getDashboardLink(); ?>">
                                <i class="bi bi-speedometer2 me-2"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item mb-2">
                            <a class="nav-link" href="../homepage.php">
                                <i class="bi bi-house me-2"></i> Home
                            </a>
                        </li>
                        <li class="nav-item mb-2">
                            <a class="nav-link" href="browse.php">
                                <i class="bi bi-search me-2"></i> Browse Skills
                            </a>
                        </li>
                        <li class="nav-item mb-2">
                            <a class="nav-link active" href="top_experts.php">
                                <i class="bi bi-trophy-fill me-2"></i> Top Experts
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-10 ms-sm-auto col-lg-10 px-0">
                <!-- Header -->
                <div class="header-bar d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <img src="../assets/images/logo.png" alt="HiveMind Logo" class="logo" width="auto" height="40">
                        <h5 class="mb-0">Top Experts</h5>
                    </div>
                    <div>
                        <a href="profile.php" class="btn btn-outline-secondary btn-sm me-2">
                            <i class="bi bi-person-circle"></i> Profile
                        </a>
                        <a href="../auth/logout.php" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </div>
                </div>

                <!-- Page Content -->
                <div class="container py-4">
                    <div class="page-header">
                        <h2 class="mb-2">
                            <i class="bi bi-trophy-fill me-2"></i>
                            HiveMind Top Experts
                        </h2>
                        <p class="mb-0">Experts with 5+ completed sessions and 4.5+ average rating</p>
                    </div>

                    <?php if ($topExpertsResult && $topExpertsResult->num_rows > 0): ?>
                        <?php
                        $rank = 1;
                        while ($expert = $topExpertsResult->fetch_assoc()):
                            // Fetch skills for this expert
                            $skillsQuery = "SELECT skill_id, title, category, description 
                                          FROM skills 
                                          WHERE expert_id = ? AND is_approved = 1 
                                          ORDER BY created_at DESC";
                            $skillsStmt = $conn->prepare($skillsQuery);
                            $skillsStmt->bind_param('i', $expert['user_id']);
                            $skillsStmt->execute();
                            $skillsResult = $skillsStmt->get_result();
                            ?>
                            <div class="expert-profile-card">
                                <!-- Rank Badge -->
                                <div class="rank-badge <?php
                                if ($rank == 1)
                                    echo 'rank-1';
                                elseif ($rank == 2)
                                    echo 'rank-2';
                                elseif ($rank == 3)
                                    echo 'rank-3';
                                else
                                    echo 'rank-other';
                                ?>">
                                    #<?php echo $rank; ?>
                                </div>

                                <!-- Expert Header -->
                                <div class="expert-header">

                                    <div class="expert-info flex-grow-1">
                                        <div class="top-expert-badge">
                                            <i class="bi bi-star-fill"></i>
                                            TOP EXPERT
                                        </div>

                                        <h4><?php echo htmlspecialchars($expert['full_name']); ?></h4>

                                        <div class="rating-display">
                                            <div class="rating-stars">
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
                                                for ($i = $fullStars + ($halfStar ? 1 : 0); $i < 5; $i++) {
                                                    echo '<i class="bi bi-star"></i>';
                                                }
                                                ?>
                                            </div>
                                            <strong><?php echo number_format($rating, 1); ?> / 5.0</strong>
                                        </div>

                                        <div class="expert-stats-row">
                                            <div class="stat-box">
                                                <i class="bi bi-check-circle-fill text-success"></i>
                                                <strong><?php echo $expert['completed_count']; ?></strong>
                                                <span>Completed Sessions</span>
                                            </div>
                                            <div class="stat-box">
                                                <i class="bi bi-chat-left-text-fill text-primary"></i>
                                                <strong><?php echo $expert['review_count']; ?></strong>
                                                <span>Reviews</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Expert Bio -->
                                <?php if (!empty($expert['bio'])): ?>
                                    <div class="expert-bio">
                                        <strong><i class="bi bi-quote me-2"></i>About:</strong>
                                        <?php echo htmlspecialchars($expert['bio']); ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Skills Section -->
                                <div class="skills-section">
                                    <h5><i class="bi bi-mortarboard-fill me-2"></i>Skills Offered</h5>

                                    <?php if ($skillsResult->num_rows > 0): ?>
                                        <?php while ($skill = $skillsResult->fetch_assoc()): ?>
                                            <div class="skill-item">
                                                <div class="skill-info">
                                                    <h6><?php echo htmlspecialchars($skill['title']); ?></h6>
                                                    <div class="skill-category">
                                                        <span
                                                            class="badge bg-primary"><?php echo htmlspecialchars($skill['category']); ?></span>
                                                    </div>
                                                </div>
                                                <a href="view_skill.php?skill_id=<?php echo $skill['skill_id']; ?>"
                                                    class="btn btn-sm btn-warning">
                                                    View Details <i class="bi bi-arrow-right ms-1"></i>
                                                </a>
                                            </div>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <p class="text-muted">No skills listed yet.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php
                            $rank++;
                            $skillsStmt->close();
                        endwhile;
                        ?>
                    <?php else: ?>
                        <div class="no-experts-msg">
                            <i class="bi bi-trophy"></i>
                            <h4>No Top Experts Yet</h4>
                            <p class="text-muted">
                                Experts need at least 5 completed sessions and a 4.5+ average rating to be featured here.
                            </p>
                        </div>
                    <?php endif; ?>

                    <div class="text-center mt-4">
                        <a href="../homepage.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-2"></i>Back to Home
                        </a>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
<?php $conn->close(); ?>