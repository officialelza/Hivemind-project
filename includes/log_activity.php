<?php
// log_activity.php
// Call this after a successful action (add/edit skill, enroll, review, message)

function log_activity($conn, $user_id, $user_role, $action, $details)
{
    $sql = "INSERT INTO activity_log (user_id, user_role, action, details) 
            VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("isss", $user_id, $user_role, $action, $details);
    return $stmt->execute();
}
?>