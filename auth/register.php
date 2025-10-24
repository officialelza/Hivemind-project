<?php
session_start();

// 🔒 Prevent cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

// 🚫 If logged in, kick out
if (isset($_SESSION['user_id'])) {
    header("Location: homepage.php");
    exit();
}

$host = "localhost";
$username = "root";
$password = "";
$database = "hivemind";
$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle registration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = $_POST['full_name'];
    $dob = $_POST['dob'];
    $phone_number = $_POST['phone_number'];
    $gender = $_POST['gender'];
    $role = $_POST['role'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_BCRYPT);

    // Insert into users
    $stmt = $conn->prepare("INSERT INTO users (email, password, role, status) VALUES (?, ?, ?, 'active')");
    $stmt->bind_param("sss", $email, $password, $role);
    $stmt->execute();
    $user_id = $stmt->insert_id;



    require_once "../includes/log_activity.php";
    $details = "New $role registered: $full_name";
    log_activity(
        $conn,
        $new_user_id,
        $role,
        'register',
        $details
    );
    $stmt->close();

    // Insert into user_profiles
    $stmt = $conn->prepare("INSERT INTO user_profiles (user_id, full_name, dob, phone_number, gender) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $user_id, $full_name, $dob, $phone_number, $gender);
    $stmt->execute();
    $stmt->close();

    // ✅ Redirect to login with success
    header("Location: login.php?registered=1");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Sign Up | HiveMind</title>
    <link rel="icon" href="../assets/images/logo.png" type="image/x-icon" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .select-card {
            cursor: pointer;
            transition: 0.3s;
            text-align: center;
        }

        .select-card:hover {
            border-color: #ffc107;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }

        input[type="radio"]:checked+.select-card {
            background-color: #ffc107;
            color: #fff;
            border-color: #e0a800;
        }

        input[type="radio"] {
            display: none;
        }

        .select-card i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            display: block;
        }

        .rules-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 15px;
            font-size: 0.85rem;
            /* smaller font */
            margin-top: 8px;
        }

        .valid {
            color: green;
        }

        .invalid {
            color: red;
        }
    </style>
</head>

<body class="bg-light d-flex align-items-center justify-content-center min-vh-100">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h1 class="h4 text-center mb-4">Create Your Account</h1>

                        <form action="" method="POST" name="signup_form">
                            <div class="mb-3">
                                <label for="full_name" class="form-label">Name</label>
                                <input type="text" id="full_name" name="full_name" class="form-control"
                                    placeholder="Your full name" required minlength="2">
                            </div>

                            <div class="mb-3">
                                <label for="dob" class="form-label">Date of Birth</label>
                                <input type="date" id="dob" name="dob" class="form-control" required
                                    max="<?php echo date('Y-m-d', strtotime('-16 years')); ?>"
                                    onchange="validateAge(this)">
                                <small class="text-muted">You must be at least 16 years old</small>
                            </div>

                            <div class="mb-3">
                                <label for="phone_number" class="form-label">Phone Number</label>
                                <input type="tel" id="phone_number" name="phone_number" class="form-control"
                                    placeholder="+1234567890" pattern="^\+?\d{7,15}$"
                                    title="Include country code, digits only" required>
                            </div>

                            <div class="mb-4">
                                <p class="fw-bold">Select Gender</p>
                                <div class="row g-3">
                                    <div class="col-6">
                                        <input type="radio" id="gender-male" name="gender" value="Male" required>
                                        <label for="gender-male" class="card border select-card p-3">
                                            <i class="bi bi-person-fill"></i> Male
                                        </label>
                                    </div>
                                    <div class="col-6">
                                        <input type="radio" id="gender-female" name="gender" value="Female">
                                        <label for="gender-female" class="card border select-card p-3">
                                            <i class="bi bi-person-fill"></i> Female
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <p class="fw-bold">Select Role</p>
                                <div class="row g-3">
                                    <div class="col-6">
                                        <input type="radio" id="role-expert" name="role" value="expert" required>
                                        <label for="role-expert" class="card border select-card p-3">
                                            <i class="bi bi-mortarboard-fill"></i> Expert
                                        </label>
                                    </div>
                                    <div class="col-6">
                                        <input type="radio" id="role-learner" name="role" value="learner">
                                        <label for="role-learner" class="card border select-card p-3">
                                            <i class="bi bi-book-fill"></i> Learner
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" id="email" name="email" class="form-control"
                                    placeholder="you@example.com" required>
                            </div>

                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" id="password" name="password" class="form-control"
                                    placeholder="Minimum 8 characters" minlength="8" required>
                                <div id="rules" class="rules-grid">
                                    <p id="length" class="invalid">At least 8 characters</p>
                                    <p id="lower" class="invalid">At least one lowercase letter</p>
                                    <p id="upper" class="invalid">At least one uppercase letter</p>
                                    <p id="number" class="invalid">At least one number</p>
                                    <p id="symbol" class="invalid">At least one symbol</p>
                                </div>

                            </div>

                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm Password</label>
                                <input type="password" id="confirm_password" name="confirm_password"
                                    class="form-control" placeholder="Re-enter your password" minlength="8" required>
                            </div>

                            <div class="mb-3 form-check">
                                <input class="form-check-input" type="checkbox" name="terms" id="terms" required>
                                <label class="form-check-label" for="terms">
                                    I agree to the <a href="../info/terms.php">terms and conditions</a>
                                </label>
                            </div>

                            <div class="d-flex justify-content-between">
                                <button type="submit" class="btn btn-warning">Sign Up</button>
                                <button type="reset" class="btn btn-secondary"
                                    onclick="window.location.href='../index.html'">Cancel</button>
                            </div>
                        </form>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>

        const password = document.getElementById("password");
        const rules = {
            length: document.getElementById("length"),
            lower: document.getElementById("lower"),
            upper: document.getElementById("upper"),
            number: document.getElementById("number"),
            symbol: document.getElementById("symbol")
        };

        password.addEventListener("input", () => {
            const val = password.value;
            rules.length.className = val.length >= 8 ? "valid" : "invalid";
            rules.lower.className = /[a-z]/.test(val) ? "valid" : "invalid";
            rules.upper.className = /[A-Z]/.test(val) ? "valid" : "invalid";
            rules.number.className = /\d/.test(val) ? "valid" : "invalid";
            rules.symbol.className = /[@$!%*?&]/.test(val) ? "valid" : "invalid";
        });
        document.querySelector('form').addEventListener('submit', e => {
            const pw = document.getElementById('password').value;
            const cpw = document.getElementById('confirm_password').value;
            if (pw !== cpw) {
                e.preventDefault();
                alert('Passwords do not match!');
            }
        });


    </script>

</body>

</html>