<?php
session_start();
// 🚫 Stop browser from caching this page
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1
header("Pragma: no-cache"); // HTTP 1.0
header("Expires: 0"); // Proxies

if (!isset($_SESSION['role'])) {
    header("Location: auth/login.html");
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

$error = '';
$success = '';

// use logged-in user as expert_id (adjust if you store under another key)
$expert_id = (int) ($_SESSION['user_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['proposed_title'] ?? '');
    $desc = trim($_POST['proposed_description'] ?? '');
    $category = trim($_POST['proposed_category'] ?? '');

    if ($title === '' || $desc === '' || $category === '') {
        $error = 'Please fill all required fields.';
    } else {
        // Handle image upload
        $imagePath = null; // will store web-accessible path or null
        if (!empty($_FILES['skill_image']) && $_FILES['skill_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['skill_image'];
            $maxBytes = 2 * 1024 * 1024; // 2MB
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = 'Image upload error.';
            } elseif ($file['size'] > $maxBytes) {
                $error = 'Image exceeds 2MB.';
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!in_array($mime, $allowed, true)) {
                    $error = 'Unsupported image type.';
                } else {
                    // ensure upload dir exists (server path)
                    $uploadDirServer = __DIR__ . '/../expert/uploads/skills/';
                    if (!is_dir($uploadDirServer)) {
                        mkdir($uploadDirServer, 0755, true);
                    }

                    // create unique filename
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $safeName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                    $dest = $uploadDirServer . $safeName;

                    if (!move_uploaded_file($file['tmp_name'], $dest)) {
                        $error = 'Failed to save uploaded image.';
                    } else {
                        // path (adjust if your app runs in a subfolder)
                        $imagePath = '../expert/uploads/skills/' . $safeName;
                    }
                }
            }
        }

        // Insert into DB if no upload error
        if ($error === '') {
            $sql = "INSERT INTO expert_skill_requests
                      (expert_id, proposed_title, proposed_description, category, skill_image_r, status, submitted_at)
                    VALUES (?, ?, ?, ?, ?, 'pending', NOW())";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $error = 'DB prepare failed.';
            } else {
                // bind imagePath (may be null)
                $stmt->bind_param('issss', $expert_id, $title, $desc, $category, $imagePath);
                if ($stmt->execute()) {
                    $success = 'Skill request submitted successfully.';

                    // --- activity log ---
                    $res = $conn->query("SELECT full_name FROM user_profiles WHERE user_id = $expert_id LIMIT 1");
                    if ($res && $row = $res->fetch_assoc()) {
                        $name = $row['full_name'];
                    }

                    require_once "../includes/log_activity.php";
                    $details = "Expert $name added skill request: $title";
                    log_activity(
                        $conn,
                        $_SESSION['user_id'],
                        $_SESSION['role'],
                        'add_skill',
                        $details
                    );

                    // reset form values if you show the form again
                    $title = $desc = $category = '';
                } else {
                    $error = 'DB error: could not submit request.';
                }
                $stmt->close();
            }
        }
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
            return "../homepage.php";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Skill - HiveMind</title>
    <link rel="icon" type="image/x-icon" href="../assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/base.css" rel="stylesheet">
    <style>
        main {
            margin: 0 auto;
            /* Centers the main content */
            float: none;
            /* Removes any float */
            padding-top: 2rem;
        }

        .header-bar {
            background: #fff;
            padding: 1rem;
            border-radius: var(--radius-lg);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
            margin-bottom: 2rem;
        }

        .container-fluid {
            max-width: 1400px;
            /* Limits the maximum width */
            margin: 0 auto;
        }

        .form-container {
            background: #fff;
            padding: 2rem;
            border-radius: var(--radius-lg);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-control {
            padding: 0.75rem;
            border-radius: 8px;
            border: 2px solid #e9ecef;
            background: #fff;
            color: var(--dark-text);
            transition: border-color var(--transition), box-shadow var(--transition);
            font-size: 1rem;
        }

        .form-control:focus {
            border-color: var(--primary-accent);
            box-shadow: 0 0 0 0.2rem rgba(239, 187, 20, 0.25);
            outline: 0;
        }

        .form-label {
            font-weight: 600;
            color: var(--dark-text);
            margin-bottom: 0.5rem;
            display: block;
        }

        .form-text {
            color: #6c757d;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }

        .btn-submit {
            background: var(--deep-brown);
            border-color: var(--deep-brown);
            color: #fff;
            padding: 0.75rem 2rem;
            font-weight: 600;
            border-radius: 8px;
            transition: all var(--transition);
            text-decoration: none;
            display: inline-block;
            border: none;
            cursor: pointer;
        }

        .btn-submit:hover {
            background: var(--secondary-accent);
            border-color: var(--secondary-accent);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.12);
        }

        .alert {
            border: none;
            border-radius: var(--radius-lg);
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .image-upload-area {
            border: 2px dashed #e9ecef;
            border-radius: var(--radius-lg);
            padding: 2rem;
            text-align: center;
            transition: border-color var(--transition), background-color var(--transition);
            background: #fafafa;
        }

        .image-upload-area:hover {
            border-color: var(--primary-accent);
            background: rgba(239, 187, 20, 0.05);
        }

        .image-upload-area.dragover {
            border-color: var(--primary-accent);
            background: rgba(239, 187, 20, 0.1);
        }

        .preview-image {
            max-width: 200px;
            max-height: 150px;
            object-fit: cover;
            border-radius: var(--radius-lg);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            margin-top: 1rem;
        }

        .file-input {
            display: none;
        }

        .file-input-label {
            background: var(--primary-accent);
            color: var(--dark-text);
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all var(--transition);
            display: inline-block;
            font-weight: 600;
        }

        .file-input-label:hover {
            background: var(--bright-gold);
            transform: translateY(-1px);
        }

        .skill-info-card {
            background: linear-gradient(135deg, var(--primary-accent), var(--bright-gold));
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            color: var(--dark-text);
            margin-bottom: 2rem;
        }

        .skill-info-card h6 {
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .skill-info-card p {
            margin: 0;
            opacity: 0.8;
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row justify-content-center">

            <!-- Main Content -->
            <main class="col-md-10 ms-sm-auto col-lg-10 px-0">

                <!-- Page Content -->
                <div class="container py-4">
                    <div class="row justify-content-center">
                        <div class="col-lg-8">
                            <!-- Info Card -->
                            <div class="skill-info-card">
                                <h6><i class="bi bi-info-circle me-2"></i>Submit Your Skill for Review</h6>
                                <p>Share your expertise with our community! Fill out the form below to submit your skill
                                    for admin approval.</p>
                            </div>

                            <!-- Form Container -->
                            <div class="form-container">
                                <h2 class="section-title mb-4">Skill Request Form</h2>

                                <?php if ($error): ?>
                                    <div class="alert alert-danger">
                                        <i class="bi bi-exclamation-triangle me-2"></i>
                                        <?= htmlspecialchars($error) ?>
                                    </div>
                                <?php elseif ($success): ?>
                                    <div class="alert alert-success">
                                        <i class="bi bi-check-circle me-2"></i>
                                        <?= htmlspecialchars($success) ?>
                                    </div>
                                <?php endif; ?>

                                <form method="post" enctype="multipart/form-data" novalidate>
                                    <div class="form-group">
                                        <label for="proposed_title" class="form-label">
                                            <i class="bi bi-card-heading me-2"></i>Skill Title *
                                        </label>
                                        <input id="proposed_title" name="proposed_title" class="form-control" required
                                            placeholder="Enter a clear and descriptive title for your skill"
                                            value="<?= htmlspecialchars($title ?? '') ?>">
                                        <div class="form-text">This will be the main title displayed to learners</div>
                                    </div>

                                    <div class="form-group">
                                        <label for="proposed_description" class="form-label">
                                            <i class="bi bi-card-text me-2"></i>Description *
                                        </label>
                                        <textarea id="proposed_description" name="proposed_description"
                                            class="form-control" rows="5" required
                                            placeholder="Describe what learners will learn, your teaching approach, and any prerequisites..."><?= htmlspecialchars($desc ?? '') ?></textarea>
                                        <div class="form-text">Provide a detailed description to help learners
                                            understand what they'll gain</div>
                                    </div>

                                    <div class="form-group">
                                        <label for="proposed_category" class="form-label">
                                            <i class="bi bi-tags me-2"></i>Category *
                                        </label>
                                        <input id="proposed_category" name="proposed_category" class="form-control"
                                            required placeholder="e.g., Programming, Design, Business, Arts, etc."
                                            value="<?= htmlspecialchars($category ?? '') ?>">
                                        <div class="form-text">Choose a category that best fits your skill</div>
                                    </div>

                                    <!-- IMAGE UPLOAD AREA -->
                                    <div class="form-group">
                                        <label class="form-label">
                                            <i class="bi bi-image me-2"></i>Skill Image (Optional)
                                        </label>
                                        <div class="image-upload-area" id="upload-area">
                                            <i class="bi bi-cloud-upload"
                                                style="font-size: 2rem; color: var(--secondary-accent); margin-bottom: 1rem;"></i>
                                            <p class="mb-2">
                                                <label for="skill_image" class="file-input-label">
                                                    Choose Image
                                                </label>
                                                or drag and drop
                                            </p>
                                            <input type="file" id="skill_image" name="skill_image" accept="image/*"
                                                class="file-input">
                                            <div class="form-text">Max 2MB. Supported formats: JPG, PNG, WebP, GIF</div>
                                            <div id="skill-image-preview"></div>
                                        </div>
                                    </div>

                                    <div class="d-flex gap-3 align-items-center">
                                        <button type="submit" class="btn-submit">
                                            <i class="bi bi-send me-2"></i>Submit for Review
                                        </button>
                                        <a href="<?php echo getDashboardLink(); ?>" class="btn btn-outline-secondary">
                                            <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                                        </a>
                                    </div>
                                </form>
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

        // Enhanced image preview and drag & drop functionality
        const fileInput = document.getElementById('skill_image');
        const uploadArea = document.getElementById('upload-area');
        const preview = document.getElementById('skill-image-preview');

        // File input change handler
        fileInput?.addEventListener('change', handleFileSelect);

        // Drag and drop handlers
        uploadArea.addEventListener('dragover', handleDragOver);
        uploadArea.addEventListener('dragleave', handleDragLeave);
        uploadArea.addEventListener('drop', handleDrop);

        function handleFileSelect(e) {
            const file = e.target.files[0] || e.dataTransfer?.files[0];
            if (file) {
                showPreview(file);
            }
        }

        function handleDragOver(e) {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        }

        function handleDragLeave(e) {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
        }

        function handleDrop(e) {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                showPreview(files[0]);
            }
        }

        function showPreview(file) {
            preview.innerHTML = '';
            if (!file.type.startsWith('image/')) {
                preview.innerHTML = '<div class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Please select an image file</div>';
                return;
            }

            const img = document.createElement('img');
            img.className = 'preview-image';
            img.alt = 'Preview';
            preview.appendChild(img);

            const reader = new FileReader();
            reader.onload = function (ev) {
                img.src = ev.target.result;
            };
            reader.readAsDataURL(file);

            // Add file info
            const fileInfo = document.createElement('div');
            fileInfo.className = 'mt-2 text-muted';
            fileInfo.innerHTML = `<small><i class="bi bi-file-image me-1"></i>${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)</small>`;
            preview.appendChild(fileInfo);
        }

        // Form validation feedback
        const form = document.querySelector('form');
        form.addEventListener('submit', function (e) {
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;

            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    isValid = false;
                } else {
                    field.classList.remove('is-invalid');
                }
            });

            if (!isValid) {
                e.preventDefault();
                // Scroll to first invalid field
                const firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) {
                    firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstInvalid.focus();
                }
            }
        });

        // Real-time validation
        const inputs = form.querySelectorAll('input[required], textarea[required]');
        inputs.forEach(input => {
            input.addEventListener('blur', function () {
                if (!this.value.trim()) {
                    this.classList.add('is-invalid');
                } else {
                    this.classList.remove('is-invalid');
                }
            });
        });
    </script>

    <style>
        .is-invalid {
            border-color: #dc3545 !important;
        }

        .is-invalid:focus {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
        }
    </style>
</body>

</html>