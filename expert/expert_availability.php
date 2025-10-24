<?php
// expert_availability.php
session_start();
require_once '../includes/db.php'; // must set $conn (mysqli)
if (empty($_SESSION['user_id'])) {
    die("You must be logged in.");
}
$expert_id = (int) $_SESSION['user_id'];
// optional: get skill_id from query to limit avail to a skill
$skill_id = isset($_GET['skill_id']) ? (int) $_GET['skill_id'] : null;

// create table if not exists
$create = "
CREATE TABLE IF NOT EXISTS availability_dates (
  availability_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  expert_id BIGINT NOT NULL,
  skill_id BIGINT NULL,
  start_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(expert_id), INDEX(skill_id), INDEX(start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
$conn->query($create);

// handle POST -> insert availability (single date)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_date'], $_POST['start_time'], $_POST['end_time'])) {
    $sd = $_POST['start_date'];          // expected YYYY-MM-DD
    $st = $_POST['start_time'];          // expected HH:MM
    $et = $_POST['end_time'];            // expected HH:MM
    $skill_id_in = isset($_POST['skill_id']) && $_POST['skill_id'] !== '' ? (int) $_POST['skill_id'] : null;

    // server-side validation: no Sundays
    $dow = date('w', strtotime($sd)); // 0=Sun .. 6=Sat
    if ($dow == 0) {
        $error = "Sundays are not allowed. Pick another date.";
    } elseif ($st >= $et) {
        $error = "Start time must be before end time.";
    } else {
        // ensure times within 09:00 - 20:00 UTC
        if ($st < '09:00' || $et > '20:00') {
            $error = "Times must be between 09:00 and 20:00 UTC.";
        } else {
            $ins = $conn->prepare("INSERT INTO availability_dates (expert_id, skill_id, start_date, start_time, end_time) VALUES (?, ?, ?, ?, ?)");
            $ins->bind_param('iisss', $expert_id, $skill_id_in, $sd, $st, $et);
            if ($ins->execute()) {
                $success = "Availability slot saved successfully!";
                // Don't redirect immediately, let user see the success message
            } else {
                $error = "DB error: " . $ins->error;
            }
            $ins->close();
        }
    }
}

// AJAX endpoint: return events for FullCalendar
if (isset($_GET['action']) && $_GET['action'] === 'events') {
    header('Content-Type: application/json');
    $from = isset($_GET['start']) ? $_GET['start'] : null; // ISO date from FullCalendar
    $to = isset($_GET['end']) ? $_GET['end'] : null;

    // build query, filter by expert and optional skill
    $q = "SELECT availability_id, start_date, start_time, end_time, skill_id FROM availability_dates WHERE expert_id = ?";
    $params = [$expert_id];
    $types = "i";
    if ($skill_id) {
        $q .= " AND skill_id = ?";
        $types .= "i";
        $params[] = $skill_id;
    }
    if ($from && $to) {
        $q .= " AND start_date BETWEEN ? AND ?";
        $types .= "ss";
        $params[] = $from;
        $params[] = $to;
    }
    $stmt = $conn->prepare($q);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $events = [];
    while ($row = $res->fetch_assoc()) {
        // FullCalendar wants ISO datetimes; we combine date + time (stored in UTC)
        $start_iso = $row['start_date'] . 'T' . substr($row['start_time'], 0, 5) . ':00Z';
        $end_iso = $row['start_date'] . 'T' . substr($row['end_time'], 0, 5) . ':00Z';
        $title = "Available";
        if ($row['skill_id'])
            $title .= " (skill #" . $row['skill_id'] . ")";
        $events[] = [
            'id' => $row['availability_id'],
            'title' => $title,
            'start' => $start_iso,
            'end' => $end_iso,
            'allDay' => false
        ];
    }
    echo json_encode($events);
    exit;
}

// fetch skills for dropdown (optional), ensure skill belongs to expert
$skills = [];
$qr = $conn->prepare("SELECT skill_id, title FROM skills WHERE expert_id = ?");
$qr->bind_param('i', $expert_id);
$qr->execute();
$qr->bind_result($sid, $stitle);
while ($qr->fetch())
    $skills[] = ['id' => $sid, 'title' => $stitle];
$qr->close();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Expert Availability — Hivemind</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="../assets/css/base.css">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/main.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/main.min.js"></script>
    <style>
        .availability-form {
            background: #fff;
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
            margin-bottom: 2rem;
        }

        .form-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            flex: 1;
        }

        .form-group.full-width {
            flex: 1 1 100%;
        }

        .calendar-container {
            background: #fff;
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
        }

        #calendar {
            max-width: 100%;
        }

        .msg {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1rem;
            border-left: 4px solid;
            font-weight: 500;
        }

        .msg.ok {
            background: #e6f5ea;
            color: #1f6f3a;
            border-left-color: #28a745;
        }

        .msg.err {
            background: #fdecea;
            color: #8a1b1b;
            border-left-color: #dc3545;
        }

        .skill-info {
            background: var(--soft-bg);
            padding: 1rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary-accent);
        }

        /* FullCalendar custom styling */
        .fc {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .fc-header-toolbar {
            margin-bottom: 1.5rem;
        }

        .fc-button {
            background: var(--deep-brown) !important;
            border-color: var(--deep-brown) !important;
            color: #fff !important;
            border-radius: 8px !important;
            font-weight: 500 !important;
        }

        .fc-button:hover {
            background: var(--secondary-accent) !important;
            border-color: var(--secondary-accent) !important;
        }

        .fc-button:focus {
            box-shadow: 0 0 0 0.2rem rgba(239, 187, 20, 0.25) !important;
        }

        .fc-today {
            background: var(--soft-bg) !important;
        }

        .fc-daygrid-day-number {
            color: var(--dark-text) !important;
            font-weight: 500;
        }

        .fc-col-header-cell {
            background: var(--soft-bg) !important;
            color: var(--dark-text) !important;
            font-weight: 600 !important;
        }

        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 0.5rem;
            }

            .availability-form {
                padding: 1rem;
            }

            .calendar-container {
                padding: 1rem;
            }

            .fc-header-toolbar {
                flex-direction: column;
                gap: 0.5rem;
            }

            .fc-toolbar-chunk {
                display: flex;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <div class="header-bar">
        <div class="container">
            <div style="display:flex;align-items:center;gap:12px">
                <div class="logo-placeholder">HM</div>
                <h5>Manage Availability</h5>
            </div>
        </div>
    </div>

    <main class="container" style="padding-top:1.25rem;padding-bottom:2rem">
        <?php if (!empty($error)): ?>
            <div class="msg err"><?= htmlspecialchars($error) ?></div>
        <?php elseif (!empty($success)): ?>
            <div class="msg ok"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($skill_id && !empty($skills)): ?>
            <?php foreach ($skills as $skill): ?>
                <?php if ($skill['id'] == $skill_id): ?>
                    <div class="skill-info">
                        <h6 style="margin:0;color:var(--dark-text)">Managing availability for:</h6>
                        <strong style="color:var(--deep-brown)"><?= htmlspecialchars($skill['title']) ?></strong>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="availability-form">
            <h6
                style="margin-bottom:1.5rem;color:var(--dark-text);border-bottom:2px solid var(--primary-accent);padding-bottom:0.5rem">
                Add New Availability Slot
            </h6>

            <form id="availForm" method="post" onsubmit="return validateForm();">
                <div class="form-row">
                    <div class="form-group">
                        <label>
                            <strong>Date (UTC)</strong><br>
                            <small class="text-muted">Sundays are blocked</small><br>
                            <input type="date" id="start_date" name="start_date" required>
                        </label>
                    </div>

                    <?php if (!$skill_id): ?>
                        <div class="form-group">
                            <label>
                                <strong>Skill (Optional)</strong><br>
                                <select name="skill_id">
                                    <option value="">General availability</option>
                                    <?php foreach ($skills as $s): ?>
                                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="skill_id" value="<?= $skill_id ?>">
                    <?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>
                            <strong>Start Time (UTC)</strong><br>
                            <select id="start_time" name="start_time" required>
                                <?php
                                // options 09:00 to 19:30 in 30-min steps (end can be up to 20:00)
                                for ($h = 9; $h <= 19; $h++) {
                                    echo '<option value="' . sprintf('%02d:00', $h) . '">' . sprintf('%02d:00', $h) . "</option>\n";
                                    echo '<option value="' . sprintf('%02d:30', $h) . '">' . sprintf('%02d:30', $h) . "</option>\n";
                                }
                                echo '<option value="20:00">20:00</option>';
                                ?>
                            </select>
                        </label>
                    </div>

                    <div class="form-group">
                        <label>
                            <strong>End Time (UTC)</strong><br>
                            <select id="end_time" name="end_time" required>
                                <?php
                                // end times from 09:30 to 20:00
                                echo '<option value="09:30">09:30</option>';
                                for ($h = 10; $h <= 19; $h++) {
                                    echo '<option value="' . sprintf('%02d:00', $h) . '">' . sprintf('%02d:00', $h) . "</option>\n";
                                    echo '<option value="' . sprintf('%02d:30', $h) . '">' . sprintf('%02d:30', $h) . "</option>\n";
                                }
                                echo '<option value="20:00">20:00</option>';
                                ?>
                            </select>
                        </label>
                    </div>
                </div>

                <div style="text-align:center;margin-top:1.5rem">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Availability Slot
                    </button>
                </div>
            </form>
        </div>
        </div>

        <div style="margin-top:2rem; text-align: center;">
            <a href="e_dashboard.php" class="btn btn-outline-secondary">
                ← Back to Dashboard
            </a>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container">
            <small class="text-muted">&copy; <?= date('Y') ?> Hivemind</small>
        </div>
    </footer>

    <script>

        // set min = today or tomorrow (UTC)
        const now = new Date();
        const utcNow = new Date(
            now.getUTCFullYear(),
            now.getUTCMonth(),
            now.getUTCDate(),
            now.getUTCHours(),
            now.getUTCMinutes()
        );

        let minDate = new Date(utcNow);

        // If current UTC hour >= 20, force tomorrow
        // (because slots are only valid 09:00–20:00 UTC)
        if (utcNow.getUTCHours() >= 20) {
            minDate.setUTCDate(minDate.getUTCDate() + 1);
        }

        // format to YYYY-MM-DD
        const yyyy = minDate.getUTCFullYear();
        const mm = String(minDate.getUTCMonth() + 1).padStart(2, '0');
        const dd = String(minDate.getUTCDate()).padStart(2, '0');
        document.getElementById('start_date').min = `${yyyy}-${mm}-${dd}`;

        // client-side: block Sundays and enforce time range approx
        function validateForm() {
            const sd = document.getElementById('start_date').value;
            if (!sd) { alert('Pick a date'); return false; }
            const dow = new Date(sd + 'T00:00:00Z').getUTCDay(); // 0=Sun
            if (dow === 0) { alert('Sundays are not allowed.'); return false; }

            const st = document.getElementById('start_time').value;
            const et = document.getElementById('end_time').value;
            if (st >= et) { alert('Start time must be before end time.'); return false; }

            // ensure within 09:00 - 20:00
            if (st < '09:00' || et > '20:00') { alert('Times must be between 09:00 and 20:00 UTC.'); return false; }
            return true;
        }

        document.addEventListener('DOMContentLoaded', function () {
            var calendarEl = document.getElementById('calendar');
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                timeZone: 'UTC',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                events: {
                    url: '<?= basename(__FILE__) ?>?action=events<?= $skill_id ? "&skill_id=$skill_id" : "" ?>',
                    method: 'GET',
                    failure: function () {
                        console.error('Failed to load calendar events');
                    }
                },
                eventColor: 'var(--primary-accent)',
                eventBackgroundColor: 'var(--primary-accent)',
                eventBorderColor: 'var(--deep-brown)',
                eventTextColor: '#fff',
                navLinks: true,
                nowIndicator: true,
                eventTimeFormat: {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false
                },
                dayMaxEvents: 3,
                moreLinkClick: 'popover',
                height: 'auto',
                eventDidMount: function (info) {
                    // Add custom styling to events
                    info.el.style.borderRadius = '6px';
                    info.el.style.fontWeight = '500';
                },
                dateClick: function (info) {
                    // Set the clicked date in the form
                    document.getElementById('start_date').value = info.dateStr;
                }
            });
            calendar.render();
        });
    </script>
</body>

</html>