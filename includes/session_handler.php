<?php
// session_handler.php

// 1. Start or resume the session and enforce timeout
function startSession()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Timeout after 30 minutes of inactivity
    $timeout = 1800; // seconds
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        destroyUserSession();
        header("Location: /auth/login.html?error=Session expired");
        exit();
    }

    // Update last activity timestamp
    $_SESSION['last_activity'] = time();
}

// 2. Record user info in session after successful login
function setUserSession($user_id, $name, $email, $role)
{
    startSession();               // ensure session is live
    session_regenerate_id(true);  // prevent session fixation
    $_SESSION['user_id'] = $user_id;
    $_SESSION['name'] = $name;
    $_SESSION['email'] = $email;
    $_SESSION['role'] = $role;
    $_SESSION['last_activity'] = time();
}

// 3. Check if a user is logged in
function isLoggedIn()
{
    startSession();
    return isset($_SESSION['user_id']);
}

// 4. Force login for protected pages
function requireLogin()
{
    if (!isLoggedIn()) {
        header("Location: /auth/login.html?error=Please log in");
        exit();
    }
}

// 5. Force a specific role (e.g., 'expert', 'learner', 'admin')
function requireRole($role)
{
    requireLogin();
    if ($_SESSION['role'] !== $role) {
        header("Location: /homepage.php?error=Access denied");
        exit();
    }
}

// 6. Destroy session and log out
function destroyUserSession()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    session_destroy();
}
?>