<?php
session_start();

// Database connection
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

// Check admin login
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// Check if skill_id is provided
if (!isset($_GET['id'])) {
    $_SESSION['flash_error'] = "No skill ID provided.";
    header("Location: a_dashboard.php");
    exit();
}

$skill_id = (int) $_GET['id'];

try {
    $pdo->beginTransaction();

    // Get skill details before deletion
    $skill_query = "SELECT s.title, s.expert_id, up.full_name as expert_name 
                   FROM skills s 
                   LEFT JOIN user_profiles up ON s.expert_id = up.user_id 
                   WHERE s.skill_id = ?";
    $skill_stmt = $pdo->prepare($skill_query);
    $skill_stmt->execute([$skill_id]);
    $skill_data = $skill_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$skill_data) {
        throw new Exception("Skill not found");
    }

    // Get all enrolled learners
    $enrolled_learners_query = "SELECT r.learner_id, up.full_name as learner_name 
                               FROM registration r 
                               LEFT JOIN user_profiles up ON r.learner_id = up.user_id 
                               WHERE r.skill_id = ?";
    $enrolled_learners_stmt = $pdo->prepare($enrolled_learners_query);
    $enrolled_learners_stmt->execute([$skill_id]);
    $enrolled_learners = $enrolled_learners_stmt->fetchAll(PDO::FETCH_ASSOC);

    // CORRECT DELETION ORDER (child to parent):
    // 1. Delete registrations FIRST (they reference both skill_id AND schedule_id)
    $pdo->prepare("DELETE FROM registration WHERE skill_id = ?")->execute([$skill_id]);

    // 2. Delete reviews (they reference skill_id)
    $pdo->prepare("DELETE FROM reviews WHERE skill_id = ?")->execute([$skill_id]);

    // 3. Delete availability_dates (they reference skill_id)
    $pdo->prepare("DELETE FROM availability_dates WHERE skill_id = ?")->execute([$skill_id]);

    // 4. Delete schedule entries (they reference skill_id)
    $pdo->prepare("DELETE FROM schedule WHERE skill_id = ?")->execute([$skill_id]);

    // 5. Delete the corresponding request from expert_skill_requests (if it exists)
    // This cleans up the approved request that created this skill
    $pdo->prepare("DELETE FROM expert_skill_requests WHERE expert_id = ? AND proposed_title = ? AND status = 'approved'")->execute([$skill_data['expert_id'], $skill_data['title']]);

    // 6. Finally delete the skill itself
    $pdo->prepare("DELETE FROM skills WHERE skill_id = ?")->execute([$skill_id]);

    // Send notifications AFTER successful deletion
    // Notify expert
    $expert_notification_query = "INSERT INTO notifications (user_id, type, message, is_read, created_at) 
                                 VALUES (?, 'alert', ?, 0, NOW())";
    $expert_notification_stmt = $pdo->prepare($expert_notification_query);
    $expert_message = "Your skill '{$skill_data['title']}' has been deleted by an administrator.";
    $expert_notification_stmt->execute([$skill_data['expert_id'], $expert_message]);

    // Notify enrolled learners
    if (!empty($enrolled_learners)) {
        $learner_notification_query = "INSERT INTO notifications (user_id, type, message, is_read, created_at) 
                                      VALUES (?, 'alert', ?, 0, NOW())";
        $learner_notification_stmt = $pdo->prepare($learner_notification_query);

        foreach ($enrolled_learners as $learner) {
            $learner_message = "The skill '{$skill_data['title']}' you enrolled in has been deleted by an administrator.";
            $learner_notification_stmt->execute([$learner['learner_id'], $learner_message]);
        }
    }

    $pdo->commit();
    $_SESSION['flash_success'] = "Skill '{$skill_data['title']}' deleted successfully. Notifications sent to expert and " . count($enrolled_learners) . " enrolled learners.";
} catch (Exception $e) {
    $pdo->rollback();
    $_SESSION['flash_error'] = "Error deleting skill: " . $e->getMessage();
}

header("Location: a_dashboard.php");
exit();
?>