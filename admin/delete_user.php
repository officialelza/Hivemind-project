<?php
// admin/delete_user.php
include '../includes/db.php'; // adjust path to your DB connection

if (!isset($_GET['id'])) {
    die("No user ID provided.");
}

$user_id = intval($_GET['id']);

// Get user role
$stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    die("User not found.");
}
$user = $result->fetch_assoc();
$role = $user['role'];

// Delete role-specific dependencies
if ($role === 'learner') {
    $conn->query("DELETE FROM reviews WHERE learner_id = $user_id");
    $conn->query("DELETE FROM registration WHERE learner_id = $user_id");
} elseif ($role === 'expert') {
    // For experts, we need to delete skill-related records first
    // Get all skill IDs for this expert
    $skill_ids_result = $conn->query("SELECT skill_id FROM skills WHERE expert_id = $user_id");
    $skill_ids = [];
    while ($row = $skill_ids_result->fetch_assoc()) {
        $skill_ids[] = $row['skill_id'];
    }

    // Delete records related to each skill
    if (!empty($skill_ids)) {
        $skill_ids_list = implode(',', $skill_ids);
        $conn->query("DELETE FROM availability_dates WHERE skill_id IN ($skill_ids_list)");
        $conn->query("DELETE FROM registration WHERE skill_id IN ($skill_ids_list)");
        $conn->query("DELETE FROM reviews WHERE skill_id IN ($skill_ids_list)");
        $conn->query("DELETE FROM schedule WHERE skill_id IN ($skill_ids_list)");
    }

    // Now delete the expert's records
    $conn->query("DELETE FROM availability_dates WHERE expert_id = $user_id");
    $conn->query("DELETE FROM skills WHERE expert_id = $user_id");
    $conn->query("DELETE FROM expert_skill_requests WHERE expert_id = $user_id");
}

// Common dependencies for all roles
$conn->query("DELETE FROM notifications WHERE user_id = $user_id");

// Delete messages first, then chatrooms (due to foreign key constraint)
// Get all chatroom IDs for this user
$chatroom_ids_result = $conn->query("SELECT chat_room_id FROM chatrooms WHERE participant_one_id = $user_id OR participant_two_id = $user_id");
$chatroom_ids = [];
while ($row = $chatroom_ids_result->fetch_assoc()) {
    $chatroom_ids[] = $row['chat_room_id'];
}

// Delete messages in those chatrooms
if (!empty($chatroom_ids)) {
    $chatroom_ids_list = implode(',', $chatroom_ids);
    $conn->query("DELETE FROM messages WHERE chat_room_id IN ($chatroom_ids_list)");
}

// Also delete messages sent by this user
$conn->query("DELETE FROM messages WHERE sender_id = $user_id");

// Now delete the chatrooms
$conn->query("DELETE FROM chatrooms WHERE participant_one_id = $user_id OR participant_two_id = $user_id");

$conn->query("DELETE FROM activity_log WHERE user_id = $user_id");
$conn->query("DELETE FROM user_profiles WHERE user_id = $user_id");

// Finally, delete the user
$conn->query("DELETE FROM users WHERE user_id = $user_id");

// Redirect back with success
header("Location: a_dashboard.php?msg=User+deleted+successfully");
exit;
?>