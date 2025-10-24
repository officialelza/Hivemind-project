<?php
// hivemind/pages/profile.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /hivemindf/auth/login.html');
    exit();
}

require __DIR__ . '/../includes/db.php'; // mysqli $conn

$user_id = (int) $_SESSION['user_id'];
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bio = trim($_POST['bio'] ?? '');
    $phone = trim($_POST['phone_number'] ?? '');

    // --- Paths ---
    // Absolute filesystem dir (for move_uploaded_file)
    $uploadDirFS = dirname(__DIR__) . '/uploads/';          // C:/xampp/htdocs/hivemindf/uploads/
    // Public web path to store in DB / use in <img>
    $uploadDirURL = '/hivemindf/uploads/';

    if (!is_dir($uploadDirFS)) {
        @mkdir($uploadDirFS, 0777, true);
    }

    $profilePicURL = null;

    // --- File upload (optional) ---
    if (!empty($_FILES['profile_picture']['name'])) {
        $allowed = ['jpg', 'jpeg', 'png'];
        $name = basename($_FILES['profile_picture']['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $size = (int) $_FILES['profile_picture']['size'];

        if (!in_array($ext, $allowed)) {
            $error = 'Invalid file type. Only JPG, JPEG, PNG allowed.';
        } elseif ($size > 2 * 1024 * 1024) {
            $error = 'File too large. Max 2MB.';
        } else {
            $newName = 'profile_' . $user_id . '_' . time() . '.' . $ext;
            $destFS = $uploadDirFS . $newName;   // absolute path on disk
            $destURL = $uploadDirURL . $newName;  // path stored in DB

            if (!move_uploaded_file($_FILES['profile_picture']['tmp_name'], $destFS)) {
                $error = 'Could not move uploaded file.';
            } else {
                $profilePicURL = $destURL;
            }
        }
    }

    // --- Update DB (only path, never blob) ---
    if (!$error) {
        if ($profilePicURL) {
            $sql = "UPDATE user_profiles
                       SET bio=?, phone_number=?, profile_picture=?
                     WHERE user_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sssi', $bio, $phone, $profilePicURL, $user_id);
        } else {
            $sql = "UPDATE user_profiles
                       SET bio=?, phone_number=?
                     WHERE user_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssi', $bio, $phone, $user_id);
        }
        if ($stmt->execute()) {
            $_SESSION['flash_success'] = "Profile updated successfully!";
            header("Location: ../homepage.php");
            exit();
        } else {
            $error = 'Database update failed: ' . $conn->error;
        }

    }
}

// --- Fetch for display ---
$sql = "SELECT u.email, up.full_name, up.bio, up.phone_number, up.profile_picture
          FROM users u
          JOIN user_profiles up ON u.user_id = up.user_id
         WHERE u.user_id=?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stmt->bind_result($email, $full_name, $bio, $phone_number, $profile_picture);
$stmt->fetch();
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Your Profile</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="/hivemindf/dashboard.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .profile-avatar {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #eee;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.07);
            background: #fff;
        }
    </style>
</head>

<body>
    <div class="container mt-5">
        <div class="card mx-auto" style="max-width: 560px;">
            <div class="card-header text-center">
                <h3>Your Profile</h3>
            </div>
            <div class="card-body">
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <div class="form-group text-center">
                        <?php if ($profile_picture): ?>
                            <img src="<?= htmlspecialchars($profile_picture) ?>" class="profile-avatar mb-3"
                                alt="Profile Picture">
                        <?php else: ?>
                            <img src="/hivemindf/assets/default-user.png" class="profile-avatar mb-3" alt="No Image">
                        <?php endif; ?>
                        <input type="file" name="profile_picture" class="form-control-file mt-2"
                            accept=".jpg,.jpeg,.png">
                        <small class="text-muted d-block">JPG/PNG, max 2MB.</small>
                    </div>

                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($full_name ?? '') ?>"
                            readonly>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($email ?? '') ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="bio">Bio</label>
                        <textarea name="bio" id="bio" class="form-control" maxlength="500"
                            rows="3"><?= htmlspecialchars($bio ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="phone_number">Phone Number</label>
                        <input type="tel" name="phone_number" id="phone_number" maxlength="15" class="form-control"
                            value="<?= htmlspecialchars($phone_number ?? '') ?>">
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Update Profile</button>
                </form>
            </div>
        </div>
    </div>
</body>

</html>