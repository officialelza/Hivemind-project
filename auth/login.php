<?php
session_start();
// 🔒 Prevent cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

$host = "localhost";
$username = "root";
$password = "";
$database = "hivemind";
$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
// 🔒 Prevent cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");
// 🚫 If already logged in, send them home
if (isset($_SESSION['user_id'])) {
    header("Location: ../homepage.php");
    exit();
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];
    $pass = $_POST['password'];

    $stmt = $conn->prepare("SELECT user_id, password, role, status FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {
        $stmt->bind_result($user_id, $hashed_password, $role, $status);
        $stmt->fetch();

        if ($status !== 'active') {
            $error = "Your account is not active. Contact admin.";
        } elseif (password_verify($pass, $hashed_password)) {
            // ✅ Set session
            $_SESSION['user_id'] = $user_id;
            $_SESSION['role'] = $role;

            //log activity
            $res = $conn->query("SELECT full_name FROM user_profiles WHERE user_id = $user_id LIMIT 1");
            $name = '';
            if ($res && $rowName = $res->fetch_assoc()) {
                $name = $rowName['full_name'];
            }

            require_once "../includes/log_activity.php";
            $details = "$role $name logged in.";
            log_activity(
                $conn,
                $user_id,
                $role,
                'login',
                $details
            );


            // Redirect based on role
            if ($role === 'admin') {
                header("Location: ../admin/a_dashboard.php");
            } elseif ($role === 'expert') {
                header("Location: ../expert/e_dashboard.php");
            } else {
                header("Location: ../learner/l_dashboard.php");
            }
            exit();
        } else {
            $error = "Invalid email or password.";
        }
    } else {
        $error = "Invalid email or password.";
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>HiveMind – Login</title>

    <link rel="icon" href="../assets/images/logo.png" type="image/x-icon" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/login.css">
</head>

<body>
    <div class="login-wrapper">
        <div class="login-left">
            <img src="../assets/images/Pizza maker-pana.png" alt="Chef Illustration" />
        </div>

        <div class="login-right">
            <div class="login-card">
                <h2>Login to your Account</h2>
                <p class="subtext">See what is going on with your skills</p>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <form action="" method="POST">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" placeholder="mail@abc.com" required />

                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="••••••••••" required />

                    <button type="submit" class="login-btn">Login</button>
                </form>

                <p class="register-text">
                    Not Registered Yet? <a href="register.php">Create an account</a>
                </p>
            </div>
        </div>
    </div>

    <script>
        // Show popup if redirected after registration
        const params = new URLSearchParams(window.location.search);
        if (params.has('registered')) {
            alert("Registration Successful! You can now log in.");
            // remove query param so popup doesn’t repeat on refresh
            window.history.replaceState({}, document.title, "login.php");
        }

        // Fix back button showing cached page after logout
        window.addEventListener("pageshow", function (event) {
            if (event.persisted) {
                window.location.reload();
            }
        });
    </script>
</body>

</html>