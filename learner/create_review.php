<?php
session_start();

// Check if user is logged in and is a learner
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'learner') {
    header('Location: ../auth/login.php');
    exit();
}

$learner_id = $_SESSION['user_id'];
$skill_id = isset($_GET['skill_id']) ? intval($_GET['skill_id']) : 0;

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

// Fetch skill details
$skill_query = "SELECT s.title, s.category, s.description, s.expert_id, up.full_name as expert_name
                FROM skills s 
                JOIN users u ON s.expert_id = u.user_id 
                JOIN user_profiles up ON u.user_id = up.user_id 
                WHERE s.skill_id = ?";
$skill_stmt = $pdo->prepare($skill_query);
$skill_stmt->execute([$skill_id]);
$skill = $skill_stmt->fetch(PDO::FETCH_ASSOC);

if (!$skill) {
    die("Skill not found.");
}

// Check if user has enrolled in this skill
$enrollment_query = "SELECT registration_id FROM registration 
                     WHERE learner_id = ? AND skill_id = ? 
                     AND status IN ('scheduled', 'completed')";
$enrollment_stmt = $pdo->prepare($enrollment_query);
$enrollment_stmt->execute([$learner_id, $skill_id]);
$is_enrolled = $enrollment_stmt->fetch(PDO::FETCH_ASSOC);

// If not enrolled, show alert and redirect
if (!$is_enrolled) {
    echo "<script>
        alert('You need to enroll in this skill before writing a review.');
        window.location.href = '../learner/enroll.php?skill_id=" . $skill_id . "';
    </script>";
    exit();
}

// Check if user has already reviewed this skill
$existing_review_query = "SELECT review_id FROM reviews WHERE learner_id = ? AND skill_id = ?";
$existing_review_stmt = $pdo->prepare($existing_review_query);
$existing_review_stmt->execute([$learner_id, $skill_id]);
$existing_review = $existing_review_stmt->fetch(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = isset($_POST['rating']) ? intval($_POST['rating']) : 0;
    $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';

    // Validation
    $errors = [];

    if ($rating < 1 || $rating > 5) {
        $errors[] = "Please select a valid rating.";
    }

    if (empty($comment)) {
        $errors[] = "Please write a review comment.";
    }

    if (strlen($comment) > 500) {
        $errors[] = "Review comment must be less than 500 characters.";
    }

    // Check if user has already reviewed this skill
    if ($existing_review) {
        $errors[] = "You have already reviewed this skill.";
    }

    if (empty($errors)) {
        try {
            // Insert review
            $insert_query = "INSERT INTO reviews (learner_id, skill_id, rating, comment, created_at) 
                            VALUES (?, ?, ?, ?, NOW())";
            $insert_stmt = $pdo->prepare($insert_query);
            $insert_stmt->execute([$learner_id, $skill_id, $rating, $comment]);

            // Create notification for expert
            $notification_query = "INSERT INTO notifications (user_id, type, message, is_read, created_at) 
                                  VALUES (?, 'info', ?, 0, NOW())";
            $notification_message = "You received a new {$rating}-star review for your skill: " . $skill['title'];
            $notification_stmt = $pdo->prepare($notification_query);
            $notification_stmt->execute([$skill['expert_id'], $notification_message]);

            $success_message = "Review submitted successfully!";

        } catch (Exception $e) {
            $errors[] = "Error submitting review: " . $e->getMessage();
        }
    }
}

// Get learner profile for display
$learner_query = "SELECT full_name FROM user_profiles WHERE user_id = ?";
$learner_stmt = $pdo->prepare($learner_query);
$learner_stmt->execute([$learner_id]);
$learner_profile = $learner_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Review - HiveMind</title>
    <link rel="icon" type="image/x-icon" href="../assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/base.css" rel="stylesheet">
    <style>
        .review-container {
            max-width: 800px;
            margin: 0 auto;
        }

        .skill-card {
            background: #fff;
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
            margin-bottom: 2rem;
        }

        .review-form {
            background: #fff;
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
        }

        .rating-input {
            display: none;
        }

        .star {
            font-size: 2rem;
            color: #ddd;
            cursor: pointer;
            transition: color 0.2s;
        }

        .star:hover,
        .star.active {
            color: #ffc107;
        }

        .btn-submit {
            background: var(--deep-brown);
            border-color: var(--deep-brown);
            color: #fff;
            padding: 0.75rem 2rem;
            font-weight: 600;
            border-radius: 8px;
            transition: all var(--transition);
        }

        .btn-submit:hover {
            background: var(--secondary-accent);
            border-color: var(--secondary-accent);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.12);
        }

        .alert {
            border-radius: var(--radius-md);
        }

        .character-count {
            font-size: 0.875rem;
            color: #6c757d;
        }

        .back-btn {
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

        .back-btn:hover {
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
                            <a class="nav-link" href="l_dashboard.php"><i class="bi bi-speedometer2 me-2"></i>
                                Dashboard</a>
                        </li>
                        <li class="nav-item mb-2">
                            <a class="nav-link" href="../homepage.php"><i class="bi bi-house me-2"></i> Home</a>
                        </li>
                        <li class="nav-item mb-2">
                            <a class="nav-link" href="../pages/browse.php"><i class="bi bi-search me-2"></i> Browse
                                Skills</a>
                        </li>
                        <li class="nav-item mb-2">
                            <a class="nav-link active" href="#"><i class="bi bi-star me-2"></i> Write Review</a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="col-md-10 ms-sm-auto col-lg-10 px-0">
                <!-- Header -->
                <div class="header-bar d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <div class="logo-placeholder me-3">
                            <span>Logo</span>
                        </div>
                        <h5 class="mb-0">Write a Review</h5>
                    </div>
                    <div>
                        <a href="../pages/profile.php" class="btn btn-outline-secondary btn-sm me-2">
                            <i class="bi bi-person-circle"></i> Profile
                        </a>
                        <a href="../auth/logout.php" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </div>
                </div>

                <!-- Page Content -->
                <div class="container py-4">
                    <div class="review-container">
                        <!-- Success Message -->
                        <?php if (isset($success_message)): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle me-2"></i>
                                <?php echo htmlspecialchars($success_message); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <!-- Error Messages -->
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <ul class="mb-0">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <!-- Already Reviewed Message -->
                        <?php if ($existing_review): ?>
                            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                                <i class="bi bi-info-circle me-2"></i>
                                You have already reviewed this skill.
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <!-- Skill Information -->
                        <div class="skill-card">
                            <h3 class="mb-3"><?php echo htmlspecialchars($skill['title']); ?></h3>
                            <div class="mb-3">
                                <span
                                    class="badge bg-primary me-2"><?php echo htmlspecialchars($skill['category']); ?></span>
                                <span class="text-muted">by
                                    <?php echo htmlspecialchars($skill['expert_name']); ?></span>
                            </div>
                            <p class="text-muted mb-0"><?php echo htmlspecialchars($skill['description']); ?></p>
                        </div>

                        <!-- Review Form -->
                        <?php if (!$existing_review): ?>
                            <div class="review-form">
                                <h4 class="mb-4">Write Your Review</h4>

                                <form method="POST" action="">
                                    <!-- Rating Section -->
                                    <div class="mb-4">
                                        <label class="form-label fw-bold">Rating *</label>
                                        <div class="rating-input-group">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <input type="radio" name="rating" value="<?php echo $i; ?>"
                                                    id="star<?php echo $i; ?>" class="rating-input" required>
                                                <label for="star<?php echo $i; ?>" class="star" data-rating="<?php echo $i; ?>">
                                                    <i class="bi bi-star-fill"></i>
                                                </label>
                                            <?php endfor; ?>
                                        </div>
                                        <div class="mt-2">
                                            <small class="text-muted">Click on a star to rate</small>
                                        </div>
                                    </div>

                                    <!-- Comment Section -->
                                    <div class="mb-4">
                                        <label for="comment" class="form-label fw-bold">Review Comment *</label>
                                        <textarea class="form-control" id="comment" name="comment" rows="5"
                                            placeholder="Share your experience with this skill..." maxlength="500"
                                            required><?php echo isset($_POST['comment']) ? htmlspecialchars($_POST['comment']) : ''; ?></textarea>
                                        <div class="d-flex justify-content-between mt-1">
                                            <div class="character-count">
                                                <span id="charCount">0</span>/500 characters
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Submit Buttons -->
                                    <div class="d-flex gap-3">
                                        <button type="submit" class="btn btn-submit">
                                            <i class="bi bi-star me-2"></i>Submit Review
                                        </button>
                                        <a href="../pages/view_skill.php?skill_id=<?php echo $skill_id; ?>"
                                            class="back-btn">
                                            <i class="bi bi-arrow-left me-2"></i>Back to Skill
                                        </a>
                                    </div>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="review-form text-center">
                                <h4 class="mb-4">Already Reviewed</h4>
                                <p class="text-muted mb-4">You have already submitted a review for this skill.</p>
                                <a href="../pages/view_skill.php?skill_id=<?php echo $skill_id; ?>" class="back-btn">
                                    <i class="bi bi-arrow-left me-2"></i>Back to Skill
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Star rating functionality
        document.addEventListener('DOMContentLoaded', function () {
            const stars = document.querySelectorAll('.star');
            const ratingInputs = document.querySelectorAll('.rating-input');

            stars.forEach(star => {
                star.addEventListener('click', function () {
                    const rating = parseInt(this.dataset.rating);

                    // Clear all stars
                    stars.forEach(s => s.classList.remove('active'));

                    // Activate stars up to clicked star
                    for (let i = 0; i < rating; i++) {
                        stars[i].classList.add('active');
                    }

                    // Check the corresponding input
                    document.getElementById('star' + rating).checked = true;
                });

                star.addEventListener('mouseenter', function () {
                    const rating = parseInt(this.dataset.rating);

                    // Clear all stars
                    stars.forEach(s => s.classList.remove('active'));

                    // Activate stars up to hovered star
                    for (let i = 0; i < rating; i++) {
                        stars[i].classList.add('active');
                    }
                });
            });

            // Reset stars on mouse leave (unless one is selected)
            document.querySelector('.rating-input-group').addEventListener('mouseleave', function () {
                const checkedInput = document.querySelector('.rating-input:checked');
                if (!checkedInput) {
                    stars.forEach(s => s.classList.remove('active'));
                } else {
                    const rating = parseInt(checkedInput.value);
                    stars.forEach(s => s.classList.remove('active'));
                    for (let i = 0; i < rating; i++) {
                        stars[i].classList.add('active');
                    }
                }
            });

            // Character count for comment
            const commentTextarea = document.getElementById('comment');
            const charCount = document.getElementById('charCount');

            commentTextarea.addEventListener('input', function () {
                const count = this.value.length;
                charCount.textContent = count;

                if (count > 450) {
                    charCount.style.color = '#dc3545';
                } else if (count > 400) {
                    charCount.style.color = '#fd7e14';
                } else {
                    charCount.style.color = '#6c757d';
                }
            });

            // Initialize character count
            charCount.textContent = commentTextarea.value.length;
        });
    </script>
</body>

</html>