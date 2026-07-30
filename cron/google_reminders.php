<?php
// ============================================================
// cron/google_reminders.php — Reminder Processing Cron
// Checks for upcoming deadlines and creates overdue notifications.
// Run via cron every 30 minutes:
//   */30 * * * * php /path/to/NoteNest-main/cron/google_reminders.php
// ============================================================

if (php_sapi_name() !== 'cli' && !defined('GOOGLE_SYNC_INTERNAL')) {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/google_classroom_service.php';
require_once __DIR__ . '/../includes/google_reminder_engine.php';

echo "[" . date('Y-m-d H:i:s') . "] Reminder cron started.\n";

// Get all users with connected Google accounts
$result = $conn->query("SELECT user_id, google_email FROM google_accounts");

$totalReminders = 0;
$totalOverdue   = 0;

while ($row = $result->fetch_assoc()) {
    $userId = $row['user_id'];

    try {
        // Generate reminders for new assignments
        $reminders = gc_generate_reminders($conn, $userId);
        $totalReminders += $reminders;

        // Check for overdue assignments
        $overdue = gc_check_overdue_assignments($conn, $userId);
        $totalOverdue += $overdue;

        if ($reminders > 0 || $overdue > 0) {
            echo "  User #{$userId}: {$reminders} reminders, {$overdue} overdue alerts\n";
        }
    } catch (\Throwable $e) {
        echo "  User #{$userId} ERROR: " . $e->getMessage() . "\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Reminders: {$totalReminders}, Overdue: {$totalOverdue}\n";
?>
