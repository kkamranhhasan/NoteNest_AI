<?php
// ============================================================
// cron/google_sync.php — Background Google Classroom Sync
// Syncs all connected users. Run via cron every 15 minutes:
//   */15 * * * * php /path/to/NoteNest-main/cron/google_sync.php
// ============================================================

// CLI only — prevent web access
if (php_sapi_name() !== 'cli' && !defined('GOOGLE_SYNC_INTERNAL')) {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ai_service.php';
require_once __DIR__ . '/../includes/google_classroom_service.php';
require_once __DIR__ . '/../includes/google_sync_engine.php';

echo "[" . date('Y-m-d H:i:s') . "] Google Classroom sync cron started.\n";

// Get all users with connected Google accounts
$result = $conn->query("SELECT user_id, google_email, sync_status FROM google_accounts WHERE sync_status != 'syncing'");

$userCount   = 0;
$totalCourses = 0;
$totalFiles   = 0;

while ($row = $result->fetch_assoc()) {
    $userId = $row['user_id'];
    echo "  Syncing user #{$userId} ({$row['google_email']})... ";

    try {
        $syncResult = gc_run_sync($conn, $userId, 'cron');

        if ($syncResult['success']) {
            $s = $syncResult['stats'];
            echo "OK — Courses:{$s['courses']} Topics:{$s['topics']} Files:{$s['files']} Assignments:{$s['assignments']}\n";
            $totalCourses += $s['courses'];
            $totalFiles   += $s['files'];
        } else {
            echo "FAILED — " . ($syncResult['error'] ?? 'Unknown error') . "\n";
        }
    } catch (\Throwable $e) {
        echo "ERROR — " . $e->getMessage() . "\n";
        gc_update_sync_status($conn, $userId, 'error', $e->getMessage());
    }

    $userCount++;
}

echo "[" . date('Y-m-d H:i:s') . "] Sync completed. Users: {$userCount}, Courses: {$totalCourses}, Files: {$totalFiles}\n";
?>
