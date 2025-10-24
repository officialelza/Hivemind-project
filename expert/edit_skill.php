<?php
session_start();
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['role'])) {
    header("Location: auth/login.html");
    exit();
}

$expert_id = (int) ($_SESSION['user_id'] ?? 0);
if ($expert_id <= 0) {
    die("Unauthorized");
}

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
$skill_id = (int) ($_REQUEST['id'] ?? 0);
if ($skill_id <= 0) {
    die("Invalid skill id.");
}

// Attempt to fetch existing record from skills first, fallback to expert_skill_requests
$from_table = null;
$existing = null;
$stmt = $conn->prepare("SELECT * FROM skills WHERE skill_id = ? AND expert_id = ?");
if ($stmt) {
    $stmt->bind_param('ii', $skill_id, $expert_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $existing = $res->fetch_assoc();
    $stmt->close();
}
if ($existing) {
    $from_table = 'skills';
    $current_name = $existing['title'] ?? 'Unnamed Skill';
    $current_description = $existing['description'] ?? '';
    $current_type = $existing['category'] ?? '';
    $current_image = $existing['skill_image'] ?? '';
} else {
    // fallback to requests table
    $stmt = $conn->prepare("SELECT * FROM expert_skill_requests WHERE request_id = ? AND expert_id = ?");
    if ($stmt) {
        $stmt->bind_param('ii', $skill_id, $expert_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $existing = $res->fetch_assoc();
        $stmt->close();
    }
    if ($existing) {
        $from_table = 'expert_skill_requests';
        $current_name = $existing['proposed_title'] ?? 'Unnamed Skill';
        $current_description = $existing['proposed_description'] ?? '';
        $current_type = $existing['category'] ?? '';
        $current_image = $existing['skill_image_r'] ?? '';
    }
}

if (!$from_table) {
    die("Skill not found or you don't own this skill.");
}

// Handle POST update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $desc = trim($_POST['description'] ?? '');
    $type = trim($_POST['type'] ?? '');

    if ($desc === '' || $type === '') {
        $error = 'Please fill both description and type.';
    } else {
        // Handle image upload (optional)
        $imagePath = null;
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
                    $uploadDirServer = __DIR__ . '/../expert/uploads/skills/';
                    if (!is_dir($uploadDirServer)) {
                        mkdir($uploadDirServer, 0755, true);
                    }
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $safeName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                    $dest = $uploadDirServer . $safeName;

                    if (!move_uploaded_file($file['tmp_name'], $dest)) {
                        $error = 'Failed to save uploaded image.';
                    } else {
                        $imagePath = '../expert/uploads/skills/' . $safeName;
                    }
                }
            }
        }

        if ($error === '') {
            if ($from_table === 'skills') {
                // UPDATE the existing skill instead of creating a new request
                $conn->begin_transaction();

                try {
                    // Get all enrolled learners BEFORE we start any changes
                    $learners_sql = "SELECT DISTINCT r.learner_id, up.full_name, r.registration_id, r.status
                                     FROM registration r 
                                     LEFT JOIN user_profiles up ON r.learner_id = up.user_id 
                                     WHERE r.skill_id = ?";
                    $learners_stmt = $conn->prepare($learners_sql);
                    $learners_stmt->bind_param('i', $skill_id);
                    $learners_stmt->execute();
                    $enrolled_learners = $learners_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $learners_stmt->close();

                    // Get all availability slots count
                    $slots_sql = "SELECT COUNT(*) as slot_count FROM availability_dates WHERE skill_id = ? AND expert_id = ?";
                    $slots_stmt = $conn->prepare($slots_sql);
                    $slots_stmt->bind_param('ii', $skill_id, $expert_id);
                    $slots_stmt->execute();
                    $slots_result = $slots_stmt->get_result()->fetch_assoc();
                    $removed_slots_count = $slots_result['slot_count'];
                    $slots_stmt->close();

                    // Step 1: Cancel all active registrations
                    $cancel_registrations_sql = "UPDATE registration 
                                                SET status = 'cancelled', 
                                                    completed_at = NOW() 
                                                WHERE skill_id = ? AND status IN ('scheduled', 'pending')";
                    $cancel_stmt = $conn->prepare($cancel_registrations_sql);
                    $cancel_stmt->bind_param('i', $skill_id);
                    $cancel_stmt->execute();
                    $cancelled_count = $cancel_stmt->affected_rows;
                    $cancel_stmt->close();

                    // Step 1.5: Delete all registration records for this skill (NEW STEP)
                    $delete_registrations_sql = "DELETE FROM registration WHERE skill_id = ?";
                    $delete_reg_stmt = $conn->prepare($delete_registrations_sql);
                    $delete_reg_stmt->bind_param('i', $skill_id);
                    $delete_reg_stmt->execute();
                    $delete_reg_stmt->close();

                    // Step 2: NOW we can safely remove schedule entries
                    $remove_schedules_sql = "DELETE FROM schedule WHERE skill_id = ?";
                    $schedule_stmt = $conn->prepare($remove_schedules_sql);
                    $schedule_stmt->bind_param('i', $skill_id);
                    $schedule_stmt->execute();
                    $schedule_stmt->close();


                    // Step 3: Remove all availability slots
                    $remove_availability_sql = "DELETE FROM availability_dates WHERE skill_id = ? AND expert_id = ?";
                    $remove_stmt = $conn->prepare($remove_availability_sql);
                    $remove_stmt->bind_param('ii', $skill_id, $expert_id);
                    $remove_stmt->execute();
                    $remove_stmt->close();

                    // Step 4: UPDATE the existing skill (set to unapproved and update fields)
                    $update_image = $imagePath !== null ? $imagePath : $current_image;
                    $update_sql = "UPDATE skills 
                                  SET description = ?, 
                                      category = ?, 
                                      skill_image = ?, 
                                      is_approved = 0 
                                  WHERE skill_id = ? AND expert_id = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param('sssii', $desc, $type, $update_image, $skill_id, $expert_id);
                    $update_stmt->execute();
                    $update_stmt->close();

                    // Step 5: Send notification to admin
                    $admin_notification_sql = "INSERT INTO notifications (user_id, type, message, is_read, created_at) 
                                               VALUES ((SELECT user_id FROM users WHERE role = 'admin' LIMIT 1), 'alert', ?, 0, NOW())";
                    $admin_message = "Skill '{$current_name}' has been edited and requires re-approval. {$cancelled_count} registrations cancelled, {$removed_slots_count} availability slots removed.";
                    $admin_stmt = $conn->prepare($admin_notification_sql);
                    $admin_stmt->bind_param('s', $admin_message);
                    $admin_stmt->execute();
                    $admin_stmt->close();

                    // Step 6: Send notifications to all affected learners
                    if (!empty($enrolled_learners)) {
                        $learner_notification_sql = "INSERT INTO notifications (user_id, type, message, is_read, created_at) 
                                                     VALUES (?, 'alert', ?, 0, NOW())";
                        $learner_stmt = $conn->prepare($learner_notification_sql);

                        foreach ($enrolled_learners as $learner) {
                            $learner_message = "The skill '{$current_name}' has been updated and is currently under review. Your registration has been cancelled and all availability slots removed. You will be notified when the skill is available again.";
                            $learner_stmt->bind_param('is', $learner['learner_id'], $learner_message);
                            $learner_stmt->execute();
                        }
                        $learner_stmt->close();
                    }

                    // Step 7: Activity log
                    $res = $conn->query("SELECT full_name FROM user_profiles WHERE user_id = $expert_id LIMIT 1");
                    $name = 'Expert';
                    if ($res && $row = $res->fetch_assoc()) {
                        $name = $row['full_name'];
                    }

                    require_once "../includes/log_activity.php";
                    $details = "Expert $name edited skill: $current_name";
                    log_activity(
                        $conn,
                        $_SESSION['user_id'],
                        $_SESSION['role'],
                        'edit_skill',
                        $details
                    );

                    $conn->commit();
                    $success = "Skill updated and submitted for re-approval! {$cancelled_count} registrations cancelled, {$removed_slots_count} slots removed. Notifications sent to admin and " . count($enrolled_learners) . " learners.";

                    // Update current values for display
                    $current_description = $desc;
                    $current_type = $type;
                    if ($imagePath !== null) {
                        $current_image = $imagePath;
                    }

                } catch (Exception $e) {
                    $conn->rollback();
                    $error = 'Failed to update skill: ' . $e->getMessage();
                }
            } else {
                // expert_skill_requests - just update the pending request
                if ($imagePath !== null) {
                    $sql = "UPDATE expert_skill_requests SET proposed_description = ?, category = ?, skill_image_r = ? WHERE request_id = ? AND expert_id = ?";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        $error = 'DB prepare failed.';
                    } else {
                        $stmt->bind_param('sssii', $desc, $type, $imagePath, $skill_id, $expert_id);
                        if ($stmt->execute()) {
                            $success = 'Skill request updated successfully.';
                            $current_description = $desc;
                            $current_type = $type;
                            $current_image = $imagePath;
                        } else {
                            $error = 'DB error: could not update skill request.';
                        }
                        $stmt->close();
                    }
                } else {
                    $sql = "UPDATE expert_skill_requests SET proposed_description = ?, category = ? WHERE request_id = ? AND expert_id = ?";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        $error = 'DB prepare failed.';
                    } else {
                        $stmt->bind_param('ssii', $desc, $type, $skill_id, $expert_id);
                        if ($stmt->execute()) {
                            $success = 'Skill request updated successfully.';
                            $current_description = $desc;
                            $current_type = $type;
                        } else {
                            $error = 'DB error: could not update skill request.';
                        }
                        $stmt->close();
                    }
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Edit Skill — Hivemind</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="../assets/css/base.css">
    <style>
        .edit-skill-container {
            max-width: 800px;
            margin: 0 auto;
        }

        .edit-form {
            background: #fff;
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
        }

        .preview-image {
            max-width: 200px;
            max-height: 150px;
            object-fit: cover;
            display: block;
            margin-top: 0.5rem;
            border-radius: var(--radius-lg);
            border: 2px solid var(--soft-bg);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark-text);
        }

        .form-group textarea,
        .form-group input[type="text"],
        .form-group input[type="file"] {
            width: 100%;
            padding: 0.75rem;
            border-radius: 8px;
            border: 1px solid #d8d0c0;
            background: #fff;
            color: var(--dark-text);
            font-family: inherit;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 120px;
        }

        .form-group textarea:focus,
        .form-group input[type="text"]:focus,
        .form-group input[type="file"]:focus {
            outline: none;
            border-color: var(--primary-accent);
            box-shadow: 0 0 0 3px rgba(239, 187, 20, 0.1);
        }

        .form-text {
            font-size: 0.875rem;
            color: var(--secondary-accent);
            margin-top: 0.25rem;
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            font-weight: 500;
        }

        .alert-danger {
            background: #fdecea;
            color: #8a1b1b;
            border-left-color: #dc3545;
        }

        .alert-success {
            background: #e6f5ea;
            color: #1f6f3a;
            border-left-color: #28a745;
        }

        .alert-info {
            background: #e3f2fd;
            color: #0d47a1;
            border-left-color: #2196f3;
        }

        @media (max-width: 768px) {
            .btn-group {
                flex-direction: column;
            }

            .edit-form {
                padding: 1.5rem;
            }
        }
    </style>
</head>

<body>
    <div class="header-bar">
        <div class="container">
            <div style="display:flex;align-items:center;gap:12px">
                <div class="logo-placeholder">HM</div>
                <h5>Edit Skill</h5>
            </div>
        </div>
    </div>

    <main class="container" style="padding-top:1.25rem;padding-bottom:2rem">
        <div class="edit-skill-container">
            <div class="edit-form">
                <h6
                    style="margin-bottom:1.5rem;color:var(--dark-text);border-bottom:2px solid var(--primary-accent);padding-bottom:0.5rem">
                    <i class="fas fa-edit"></i> Edit Skill Details
                </h6>

                <div class="skill-info"
                    style="background:var(--soft-bg);padding:1rem;border-radius:var(--radius-lg);margin-bottom:1.5rem;border-left:4px solid var(--primary-accent)">
                    <h6 style="margin:0;color:var(--dark-text)">Currently editing:</h6>
                    <strong style="color:var(--deep-brown)"><?= htmlspecialchars($current_name) ?></strong>
                </div>

                <?php if ($from_table === 'skills'): ?>
                    <div class="alert alert-info">
                        <strong><i class="fas fa-info-circle"></i> Important:</strong> Changes will set this skill to
                        unapproved status. All availability slots will be removed, active registrations cancelled, and
                        affected learners notified.
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php elseif ($success): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="skill_id" value="<?= htmlspecialchars($skill_id) ?>">

                    <div class="form-group">
                        <label>Description *</label>
                        <textarea name="description" rows="6" required
                            placeholder="Describe your skill in detail..."><?= htmlspecialchars($current_description ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Type / Category *</label>
                        <input name="type" required placeholder="e.g., Programming, Design, Music, etc."
                            value="<?= htmlspecialchars($current_type ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Replace Image (optional)</label>
                        <input type="file" name="skill_image" accept="image/*">
                        <?php if (!empty($current_image)): ?>
                            <div style="margin-top:0.5rem">
                                <strong>Current Image:</strong><br>
                                <img src="<?= htmlspecialchars($current_image) ?>" alt="current skill image"
                                    class="preview-image">
                            </div>
                        <?php endif; ?>
                        <div class="form-text">Maximum 2MB. Supported formats: JPG, PNG, WebP, GIF</div>
                    </div>

                    <div class="btn-group">
                        <button class="btn btn-primary" type="submit">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                        <a class="btn btn-outline-secondary"
                            href="<?php echo (isset($_SESSION['role']) && $_SESSION['role'] === 'expert') ? './e_dashboard.php' : './homepage.php'; ?>">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container">
            <small class="text-muted">&copy; <?= date('Y') ?> Hivemind</small>
        </div>
    </footer>
</body>

</html>