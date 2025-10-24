<?php
session_start();
// Stop browser from caching this page
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1
header("Pragma: no-cache"); // HTTP 1.0
header("Expires: 0"); // Proxies
// Connect DB
$host = "localhost";
$username = "root";
$password = "";
$database = "hivemind";
$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch approved skills with expert info
$query = "SELECT s.skill_id, s.title, s.category, s.description, s.skill_image, s.created_at,
                 up.full_name as expert_name, up.profile_picture as expert_picture
          FROM skills s
          LEFT JOIN users u ON s.expert_id = u.user_id
          LEFT JOIN user_profiles up ON u.user_id = up.user_id
          WHERE s.is_approved = 1 
          ORDER BY s.created_at DESC";
$result = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Skills - HiveMind</title>
    <link rel="icon" href="../assets/images/logo.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
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

        .nav-link:hover {
            color: var(--bright-gold) !important;
        }

        .logo-space {
            background: var(--bright-gold);
            color: var(--deep-brown);
            padding: 0.5rem;
            border-radius: 50%;
            margin-right: 0.5rem;
        }

        .page-header {
            background: linear-gradient(135deg, var(--deep-brown), var(--secondary-accent));
            color: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
            text-align: center;
        }

        .skill-card {
            border: none;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            border-radius: 12px;
            margin-bottom: 2rem;
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .skill-card:hover {
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            transform: translateY(-5px);
        }

        .skill-card-header {
            background: var(--soft-bg);
            padding: 1.5rem;
            border-bottom: 2px solid var(--primary-accent);
        }

        .skill-card-body {
            padding: 1.5rem;
        }

        .skill-category {
            background: var(--primary-accent);
            color: white;
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            display: inline-block;
            margin-bottom: 1rem;
        }

        .expert-info {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
            padding: 0.75rem;
            background: var(--soft-bg);
            border-radius: 8px;
        }

        .expert-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 0.75rem;
            border: 2px solid var(--primary-accent);
        }

        .btn-primary {
            background: var(--primary-accent);
            border-color: var(--primary-accent);
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: var(--deep-brown);
            border-color: var(--deep-brown);
            transform: translateY(-2px);
        }

        .no-skills {
            text-align: center;
            color: #6c757d;
            padding: 4rem 2rem;
            font-style: italic;
        }

        .skill-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }

        .search-bar {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .form-control {
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 0.75rem;
        }

        .form-control:focus {
            border-color: var(--primary-accent);
            box-shadow: 0 0 0 0.2rem rgba(139, 69, 19, 0.25);
        }
    </style>
</head>

<body>
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
                <?php if (isset($_SESSION['user_id'])): ?>
                    <?php if ($_SESSION['role'] == 'learner'): ?>
                        <a class="nav-link" href="../learner/l_dashboard.php">Dashboard</a>
                    <?php elseif ($_SESSION['role'] == 'expert'): ?>
                        <a class="nav-link" href="../expert/e_dashboard.php">Dashboard</a>
                    <?php endif; ?>
                    <a class="nav-link" href="../pages/profile.php">Profile</a>
                    <a class="nav-link" href="../auth/logout.php">Logout</a>
                <?php else: ?>
                    <a class="nav-link" href="../auth/login.php">Login</a>
                    <a class="nav-link" href="../auth/register.php">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Page Header -->
    <div class="page-header">
        <div class="container">
            <h1><i class="fas fa-search me-3"></i>Browse Skills</h1>
            <p class="lead mb-0">Discover amazing skills taught by our expert community</p>
        </div>
    </div>

    <div class="container">
        <!-- Search Bar -->
        <div class="search-bar">
            <div class="row">
                <div class="col-md-8">
                    <input type="text" class="form-control" id="searchInput"
                        placeholder="Search skills by title, category, or expert...">
                </div>
                <div class="col-md-4">
                    <select class="form-control" id="categoryFilter">
                        <option value="">All Categories</option>
                        <option value="Programming">Programming</option>
                        <option value="Music">Music</option>
                        <option value="Art & Design">Art & Design</option>
                        <option value="Cooking">Cooking</option>
                        <option value="Personal Development">Personal Development</option>
                        <option value="Design">Design</option>
                        <option value="Faith">Faith</option>
                        <option value="Soft Skills">Soft Skills</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Skills Grid -->
        <div class="row" id="skillsContainer">
            <?php if ($result && $result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <div class="col-lg-4 col-md-6 skill-item" data-category="<?php echo htmlspecialchars($row['category']); ?>"
                        data-title="<?php echo htmlspecialchars($row['title']); ?>"
                        data-expert="<?php echo htmlspecialchars($row['expert_name'] ?? ''); ?>">
                        <div class="skill-card">
                            <?php if (!empty($row['skill_image'])): ?>
                                <img src="<?php echo htmlspecialchars($row['skill_image']); ?>" alt="Skill Image"
                                    class="skill-image">
                            <?php endif; ?>

                            <div class="skill-card-header">
                                <span class="skill-category"><?php echo htmlspecialchars($row['category']); ?></span>
                                <h5 class="mb-0"><?php echo htmlspecialchars($row['title']); ?></h5>
                            </div>

                            <div class="skill-card-body">
                                <p class="text-muted mb-3">
                                    <?php echo htmlspecialchars(substr($row['description'], 0, 120)) . '...'; ?>
                                </p>

                                <?php if ($row['expert_name']): ?>
                                    <div class="expert-info">
                                        <?php if ($row['expert_picture']): ?>
                                            <img src="<?php echo htmlspecialchars($row['expert_picture']); ?>" alt="Expert"
                                                class="expert-avatar">
                                        <?php else: ?>
                                            <div class="expert-avatar bg-secondary d-flex align-items-center justify-content-center">
                                                <i class="fas fa-user text-white"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <small class="text-muted">Expert:</small><br>
                                            <strong><?php echo htmlspecialchars($row['expert_name']); ?></strong>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        <i class="fas fa-calendar me-1"></i>
                                        <?php echo date('M d, Y', strtotime($row['created_at'])); ?>
                                    </small>
                                    <a href="view_skill.php?skill_id=<?php echo $row['skill_id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-eye me-2"></i>View Details
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="no-skills">
                        <i class="fas fa-search fa-4x mb-3"></i>
                        <h4>No Skills Available</h4>
                        <p>Check back later for new skills from our expert community!</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Search and filter functionality
        document.addEventListener('DOMContentLoaded', function () {
            const searchInput = document.getElementById('searchInput');
            const categoryFilter = document.getElementById('categoryFilter');
            const skillItems = document.querySelectorAll('.skill-item');

            function filterSkills() {
                const searchTerm = searchInput.value.toLowerCase();
                const selectedCategory = categoryFilter.value;

                skillItems.forEach(item => {
                    const title = item.dataset.title.toLowerCase();
                    const category = item.dataset.category;
                    const expert = item.dataset.expert.toLowerCase();

                    const matchesSearch = title.includes(searchTerm) ||
                        category.toLowerCase().includes(searchTerm) ||
                        expert.includes(searchTerm);
                    const matchesCategory = !selectedCategory || category === selectedCategory;

                    if (matchesSearch && matchesCategory) {
                        item.style.display = 'block';
                    } else {
                        item.style.display = 'none';
                    }
                });
            }

            searchInput.addEventListener('input', filterSkills);
            categoryFilter.addEventListener('change', filterSkills);
        });

        // Page refresh on browser back/forward
        window.addEventListener("pageshow", function (event) {
            if (event.persisted) {
                window.location.reload();
            }
        });
    </script>
</body>

</html>