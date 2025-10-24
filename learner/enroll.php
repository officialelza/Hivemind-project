<?php
// enroll.php
// Rewritten to show both schedule & availability_dates slots and allow booking
// IMPORTANT: change DB credentials below to match your environment

session_start();

// --- simple auth check (expects user logged in as learner) ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'learner') {
    echo "<script>alert('Unauthorized access. Going to homepage');</script>";
    header('Location: ../homepage.php');
    exit;
}
$learner_id = (int) $_SESSION['user_id'];

// DB connection
include_once '../includes/db.php';

// Check if database connection is successful
if (!$conn) {
    die('Database connection failed. Please try again later.');
}

// --- Input: skill_id expected in GET ---
$skill_id = isset($_GET['skill_id']) ? (int) $_GET['skill_id'] : 0;
if ($skill_id <= 0) {
    header('Location: ../homepage.php?error=invalid_skill');
    exit;
}

// Booking action handling
$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book'])) {
    $slot_type = $_POST['slot_type'] ?? ''; // should be 'availability'
    $slot_id = (int) ($_POST['slot_id'] ?? 0);

    if ($slot_type !== 'availability' || $slot_id <= 0) {
        $messages[] = ['type' => 'danger', 'text' => 'Invalid slot selection.'];
    } else {
        $conn->begin_transaction();
        try {
            // Convert availability_dates -> schedule
            $stmt = $conn->prepare("SELECT expert_id, skill_id, start_date, start_time, end_time FROM availability_dates WHERE availability_id = ? LIMIT 1");
            $stmt->bind_param('i', $slot_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res->num_rows === 0)
                throw new Exception("Availability slot not found.");
            $avail = $res->fetch_assoc();
            
            // Insert into schedule table
            $stmt = $conn->prepare("INSERT INTO schedule (skill_id, date, start_time, end_time) VALUES (?, ?, ?, ?)");
            $use_skill_id = $skill_id; // use the skill requested in the page
            $stmt->bind_param('isss', $use_skill_id, $avail['start_date'], $avail['start_time'], $avail['end_time']);
            if (!$stmt->execute())
                throw new Exception("Failed to create schedule.");
            $schedule_id = $conn->insert_id;

            // check if schedule is already booked
            $chk = $conn->prepare("SELECT registration_id FROM registration WHERE schedule_id = ? AND status = 'scheduled' LIMIT 1");
            if (!$chk) {
                throw new Exception("Database error: " . $conn->error);
            }
            $chk->bind_param('i', $schedule_id);
            if (!$chk->execute()) {
                throw new Exception("Failed to check booking status.");
            }
            $rchk = $chk->get_result();
            if ($rchk->num_rows > 0)
                throw new Exception("This time slot is already booked by another learner.");

            // check if this learner already booked same schedule
            $chk2 = $conn->prepare("SELECT registration_id FROM registration WHERE schedule_id = ? AND learner_id = ? LIMIT 1");
            if (!$chk2) {
                throw new Exception("Database error: " . $conn->error);
            }
            $chk2->bind_param('ii', $schedule_id, $learner_id);
            if (!$chk2->execute()) {
                throw new Exception("Failed to check your booking status.");
            }
            $rchk2 = $chk2->get_result();
            if ($rchk2->num_rows > 0)
                throw new Exception("You have already booked this time slot.");

            // finally insert registration
            $ins = $conn->prepare("INSERT INTO registration (learner_id, skill_id, schedule_id, status, registered_at) VALUES (?, ?, ?, 'scheduled', NOW())");
            $ins->bind_param('iii', $learner_id, $skill_id, $schedule_id);
            if (!$ins->execute())
                throw new Exception("Failed to register: " . $conn->error);

            $conn->commit();
            $messages[] = ['type' => 'success', 'text' => 'Booking confirmed!'];
        } catch (Exception $e) {
            $conn->rollback();
            $messages[] = ['type' => 'danger', 'text' => 'Booking failed: ' . $e->getMessage()];
        }
    }
}

// --- Helper: fetch upcoming availability slots for this skill that are not booked ---
$today = date('Y-m-d');

// --- Helper: fetch availability_dates slots for this skill (upcoming) that are not booked ---
$availability_slots = [];
$aq = $conn->prepare("
    SELECT a.availability_id, a.start_date, a.start_time, a.end_time, a.expert_id,
           up.full_name AS expert_name
    FROM availability_dates a
    LEFT JOIN user_profiles up ON up.user_id = a.expert_id
    WHERE a.skill_id = ? AND a.start_date >= ?
    AND a.availability_id NOT IN (
        SELECT DISTINCT availability_id 
        FROM registration r 
        JOIN schedule s ON r.schedule_id = s.schedule_id
        WHERE r.status = 'scheduled' 
        AND s.date = a.start_date 
        AND s.start_time = a.start_time 
        AND s.end_time = a.end_time
    )
    ORDER BY a.start_date, a.start_time
");
$aq->bind_param('is', $skill_id, $today);
$aq->execute();
$ra = $aq->get_result();
while ($row = $ra->fetch_assoc())
    $availability_slots[] = $row;

// --- Get skill title for header ---
$s_name = "Skill";
$stmt = $conn->prepare("SELECT title FROM skills WHERE skill_id = ? LIMIT 1");
$stmt->bind_param('i', $skill_id);
$stmt->execute();
$sr = $stmt->get_result();
if ($sr->num_rows) {
    $s_name = $sr->fetch_assoc()['title'];
}

// --- HTML output ---
?><!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Enroll — <?php echo htmlspecialchars($s_name); ?></title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="../assets/css/base.css">
    <style>
        .slot-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.6rem;
            border-radius: var(--radius-lg);
            border: 1px solid var(--soft-bg);
            margin-bottom: 0.6rem;
            background: #fff;
            transition: all var(--transition);
        }

        .slot-row:hover {
            border-color: var(--primary-accent);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .slot-meta {
            font-size: 0.95rem;
            color: var(--dark-text);
            font-weight: 500;
            line-height: 1.4;
        }

        .slot-info {
            flex: 1;
        }

        .expert-name {
            font-style: italic;
            color: var(--secondary-accent);
        }

        .msg {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-weight: 500;
        }

        .msg.success {
            background: #e6f5ea;
            color: #1f6f3a;
            border-left-color: #28a745;
        }

        .msg.danger {
            background: #fdecea;
            color: #8a1b1b;
            border-left-color: #dc3545;
        }

        .enrollment-container {
            max-width: 800px;
            margin: 0 auto;
        }

        .slot-card {
            background: #fff;
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
            margin-bottom: 1rem;
        }

        .slot-card h6 {
            color: var(--dark-text);
            font-weight: 600;
            margin-bottom: 1rem;
            border-bottom: 2px solid var(--primary-accent);
            padding-bottom: 0.5rem;
            font-size: 1.1rem;
        }

        .slots-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        @media (max-width: 768px) {
            .slot-row {
                flex-direction: column;
                align-items: stretch;
                gap: 0.5rem;
            }
            
            .slot-row form {
                align-self: stretch;
            }
            
            .slot-row button {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="header-bar">
        <div class="container">
            <div style="display:flex;align-items:center;gap:12px">
                <div class="logo-placeholder">HM</div>
                <h5>Enroll — <?php echo htmlspecialchars($s_name); ?></h5>
            </div>
        </div>
    </div>

    <main class="container" style="padding-top:1.25rem;padding-bottom:2rem">
        <div class="enrollment-container">
            <?php if (!empty($messages)): ?>
                <section class="messages" role="alert" aria-live="polite">
                    <?php foreach ($messages as $m): ?>
                        <div class="msg <?php echo $m['type'] == 'success' ? 'success' : 'danger'; ?>" role="alert">
                            <?php echo htmlspecialchars($m['text']); ?>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <div class="row">
                <div class="col">
                    <div class="slot-card">
                        <h6>Available Time Slots</h6>
                        <p class="text-muted">These time slots come from expert availability. Click "Book Slot" to reserve your session.</p>
                        
                        <?php if (count($availability_slots) === 0): ?>
                            <p class="text-muted">No availability slots found for this skill.</p>
                        <?php else: ?>
                            <div class="slots-list" role="list">
                                <?php foreach ($availability_slots as $slot): ?>
                                    <div class="slot-row" role="listitem">
                                        <div class="slot-info">
                                            <div class="slot-meta">
                                                <strong><?php echo date('l, F j, Y', strtotime($slot['start_date'])); ?></strong><br>
                                                <time datetime="<?php echo $slot['start_date'] . 'T' . $slot['start_time']; ?>">
                                                    <?php echo date('g:i A', strtotime($slot['start_time'])); ?> - 
                                                    <?php echo date('g:i A', strtotime($slot['end_time'])); ?>
                                                </time>
                                            </div>
                                            <div class="text-muted" style="font-size:0.85rem">
                                                <span class="expert-name"><?php echo htmlspecialchars($slot['expert_name'] ?? 'Expert'); ?></span>
                                            </div>
                                        </div>
                                        <form method="post" style="margin:0">
                                            <input type="hidden" name="slot_type" value="availability">
                                            <input type="hidden" name="slot_id" value="<?php echo (int) $slot['availability_id']; ?>">
                                            <button class="btn btn-primary" name="book" type="submit" aria-label="Book this time slot">
                                                Book Slot
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                </div>
            </div>
        </div>

            <div style="margin-top:2rem; text-align: center;">
                <a href="../homepage.php" class="btn btn-outline-secondary">
                    ← Back to Homepage
                </a>
            </div>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container">
            <small class="text-muted">&copy; <?php echo date('Y'); ?> Hivemind</small>
        </div>
    </footer>
</body>

</html>