<?php
session_start();

require_once "../includes/log_activity.php";
require_once "../includes/db.php";

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// fetch full_name
$name = '';
$res = $conn->query("SELECT full_name FROM user_profiles WHERE user_id = $user_id LIMIT 1");
if ($res && $row = $res->fetch_assoc()) {
    $name = $row['full_name'];
}

$details = "$role $name logged out.";
log_activity($conn, $user_id, $role, 'logout', $details);


// Unset all session variables
$_SESSION = [];

// Destroy session cookie too
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the session data on server
session_destroy();

// 🔒 Prevent cached pages being re-shown
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out - HiveMind</title>
    <link rel="icon" type="image/x-icon" href="../assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-accent: #EFBB14;
            --deep-brown: #985D09;
            --secondary-accent: #CE933A;
            --bright-gold: #EFA800;
            --dark-text: #392105;
            --soft-bg: #E1D9C1;
        }

        body {
            background: linear-gradient(135deg, var(--soft-bg), #f8f9fa);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logout-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            padding: 3rem;
            text-align: center;
            max-width: 500px;
            width: 90%;
            position: relative;
            overflow: hidden;
        }

        .logout-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-accent), var(--bright-gold));
        }

        .logo-space {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary-accent), var(--bright-gold));
            border-radius: 20px;
            margin: 0 auto 2rem;
            border: 3px solid var(--deep-brown);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--dark-text);
            font-weight: bold;
            font-size: 1.2rem;
        }

        .logo-space img {
            height: 50px;
            width: auto;
        }

        .logout-icon {
            font-size: 4rem;
            color: var(--secondary-accent);
            margin-bottom: 1.5rem;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }

            100% {
                transform: scale(1);
            }
        }

        .logout-title {
            color: var(--dark-text);
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .logout-message {
            color: #666;
            font-size: 1.1rem;
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .progress-container {
            margin: 2rem 0;
        }

        .progress {
            height: 8px;
            border-radius: 10px;
            background-color: #e9ecef;
            overflow: hidden;
        }

        .progress-bar {
            background: linear-gradient(90deg, var(--primary-accent), var(--bright-gold));
            border-radius: 10px;
            animation: loading 3s ease-in-out;
        }

        @keyframes loading {
            0% {
                width: 0%;
            }

            100% {
                width: 100%;
            }
        }

        .redirect-info {
            color: #888;
            font-size: 0.9rem;
            margin-top: 1rem;
        }

        .spinner-container {
            margin: 1rem 0;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--primary-accent);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .success-checkmark {
            display: none;
            color: #28a745;
            font-size: 3rem;
            margin: 1rem 0;
            animation: checkmark 0.5s ease-in-out;
        }

        @keyframes checkmark {
            0% {
                transform: scale(0);
            }

            50% {
                transform: scale(1.2);
            }

            100% {
                transform: scale(1);
            }
        }

        .manual-redirect {
            margin-top: 2rem;
            display: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--deep-brown), var(--secondary-accent));
            border: none;
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--secondary-accent), var(--primary-accent));
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .security-note {
            background: #f8f9fa;
            border-left: 4px solid var(--primary-accent);
            padding: 1rem;
            margin-top: 2rem;
            border-radius: 0 8px 8px 0;
            font-size: 0.9rem;
            color: #666;
        }
    </style>
</head>

<body>
    <div class="logout-container">
        <!-- Logo Space -->
        <div class="logo-space">
            <img src="../assets/images/logo.png" alt="Logo" class="logo">
        </div>

        <!-- Logout Icon -->
        <div class="logout-icon">
            <i class="fas fa-sign-out-alt"></i>
        </div>

        <!-- Title -->
        <h1 class="logout-title">Logging You Out</h1>

        <!-- Message -->
        <p class="logout-message">
            Thank you for using HiveMind! You have been successfully logged out of your account.
        </p>

        <!-- Progress Bar -->
        <div class="progress-container">
            <div class="progress">
                <div class="progress-bar" role="progressbar" style="width: 0%"></div>
            </div>
        </div>

        <!-- Spinner -->
        <div class="spinner-container">
            <div class="spinner" id="loadingSpinner"></div>
        </div>

        <!-- Success Checkmark -->
        <div class="success-checkmark" id="successCheck">
            <i class="fas fa-check-circle"></i>
        </div>

        <!-- Redirect Info -->
        <p class="redirect-info" id="redirectText">
            Redirecting you to the homepage in <span id="countdown">3</span> seconds...
        </p>

        <!-- Manual Redirect Button -->
        <div class="manual-redirect" id="manualRedirect">
            <p>If you're not redirected automatically:</p>
            <a href="../index.html" class="btn btn-primary">
                <i class="fas fa-home me-2"></i>Go to Homepage
            </a>
        </div>

        <!-- Security Note -->
        <div class="security-note">
            <i class="fas fa-shield-alt me-2"></i>
            <strong>Security Tip:</strong> For your security, please close your browser window if you're using a shared
            computer.
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            let countdown = 3;
            const countdownElement = document.getElementById('countdown');
            const loadingSpinner = document.getElementById('loadingSpinner');
            const successCheck = document.getElementById('successCheck');
            const redirectText = document.getElementById('redirectText');
            const manualRedirect = document.getElementById('manualRedirect');

            const countdownInterval = setInterval(function () {
                countdown--;
                countdownElement.textContent = countdown;

                if (countdown <= 0) {
                    clearInterval(countdownInterval);
                    loadingSpinner.style.display = 'none';
                    successCheck.style.display = 'block';
                    redirectText.textContent = 'Logout successful! Redirecting now...';
                    setTimeout(function () {
                        window.location.href = '../index.html'; // ✅ redirect to homepage
                    }, 1000);
                }
            }, 1000);

            setTimeout(function () {
                manualRedirect.style.display = 'block';
            }, 8000);

            // Prevent back button caching
            history.pushState(null, null, location.href);
            window.onpopstate = function () {
                history.go(1);
            };
        });
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