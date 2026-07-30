<?php
// ============================================================
// includes/google_reminder_engine.php
// NoteNest AI — Automatic Reminder Generator
// Creates tiered reminders for Google Classroom assignments
// ============================================================

/**
 * Reminder offsets in seconds with their message templates.
 */
function gc_get_reminder_offsets(): array {
    return [
        ['offset' => 7 * 86400,     'label' => '7 days',      'icon' => '📚'],
        ['offset' => 3 * 86400,     'label' => '3 days',      'icon' => '⚠️'],
        ['offset' => 1 * 86400,     'label' => 'tomorrow',    'icon' => '🔴'],
        ['offset' => 6 * 3600,      'label' => '6 hours',     'icon' => '⏰'],
        ['offset' => 1 * 3600,      'label' => '1 hour',      'icon' => '🚨'],
        ['offset' => 15 * 60,       'label' => '15 minutes',  'icon' => '❗'],
    ];
}

/**
 * Generate reminders for all unprocessed assignments.
 * @return int Number of reminders created
 */
function gc_generate_reminders(mysqli $conn, int $userId): int {
    $created = 0;

    // Get assignments that need reminders
    $stmt = $conn->prepare(
        "SELECT ga.id, ga.title, ga.due_date, ga.due_time, ga.todo_id, gc.course_name
         FROM google_assignments ga
         JOIN google_courses gc ON ga.google_course_id = gc.google_course_id AND ga.user_id = gc.user_id
         WHERE ga.user_id = ? AND ga.reminders_created = 0 AND ga.due_date IS NOT NULL AND ga.due_date >= CURDATE()"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $offsets = gc_get_reminder_offsets();

    foreach ($assignments as $asg) {
        $dueDateTime = $asg['due_date'] . ' ' . ($asg['due_time'] ?: '23:59:00');
        $dueTimestamp = strtotime($dueDateTime);

        if (!$dueTimestamp) continue;

        foreach ($offsets as $rem) {
            $reminderTimestamp = $dueTimestamp - $rem['offset'];

            // Skip reminders in the past
            if ($reminderTimestamp <= time()) continue;

            $reminderDatetime = date('Y-m-d H:i:s', $reminderTimestamp);
            $msg = "{$rem['icon']} Assignment \"{$asg['title']}\" ({$asg['course_name']}) is due in {$rem['label']}!";

            // Insert notification scheduled for reminder time
            $ins = $conn->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, ?)");
            $ins->bind_param('iss', $userId, $msg, $reminderDatetime);
            $ins->execute();
            $ins->close();

            // Log in todo_notifications if todo exists
            if ($asg['todo_id']) {
                $tn = $conn->prepare("INSERT INTO todo_notifications (todo_id, notified_at) VALUES (?, ?)");
                $tn->bind_param('is', $asg['todo_id'], $reminderDatetime);
                $tn->execute();
                $tn->close();
            }

            $created++;
        }

        // Mark reminders as created
        $upd = $conn->prepare("UPDATE google_assignments SET reminders_created = 1 WHERE id = ?");
        $upd->bind_param('i', $asg['id']);
        $upd->execute();
        $upd->close();
    }

    return $created;
}

/**
 * Check for overdue assignments and generate overdue notifications.
 */
function gc_check_overdue_assignments(mysqli $conn, int $userId): int {
    $count = 0;

    $stmt = $conn->prepare(
        "SELECT ga.title, ga.due_date, gc.course_name, t.status AS todo_status
         FROM google_assignments ga
         JOIN google_courses gc ON ga.google_course_id = gc.google_course_id AND ga.user_id = gc.user_id
         LEFT JOIN todos t ON ga.todo_id = t.id
         WHERE ga.user_id = ? AND ga.due_date < CURDATE() AND (t.status IS NULL OR t.status = 'pending')"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $overdue = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($overdue as $asg) {
        // Check if we already sent an overdue notification today
        $chk = $conn->prepare(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND message LIKE ? AND DATE(created_at) = CURDATE()"
        );
        $pattern = "%overdue%{$asg['title']}%";
        $chk->bind_param('is', $userId, $pattern);
        $chk->execute();
        $chk->bind_result($existing);
        $chk->fetch();
        $chk->close();

        if ($existing == 0) {
            $msg = "🚫 OVERDUE: Assignment \"{$asg['title']}\" ({$asg['course_name']}) was due on {$asg['due_date']}!";
            $ins = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $ins->bind_param('is', $userId, $msg);
            $ins->execute();
            $ins->close();
            $count++;
        }
    }

    return $count;
}
?>
