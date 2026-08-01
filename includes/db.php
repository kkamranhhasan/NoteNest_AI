<?php
// ============================================================
// includes/db.php — NoteNest Database Connection
// Reads credentials from config constants (set via env vars)
// ============================================================

// Turn off automatic throwing of uncaught mysqli exceptions so db_reconnect / fallbacks can handle connection drops smoothly without Fatal Error crashes.
mysqli_report(MYSQLI_REPORT_OFF);

// config.php defines DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME, DB_PORT
// Those constants already read from getenv() so Docker/Render env vars work.
$conn = new mysqli(
    DB_SERVER,
    DB_USERNAME,
    DB_PASSWORD,
    DB_NAME,
    defined('DB_PORT') ? (int)DB_PORT : 3306  // TiDB uses port 4000, MySQL uses 3306
);

if ($conn->connect_error) {
    die("
    <div style='font-family:sans-serif;max-width:600px;margin:40px auto;padding:20px;border:2px solid #e74c3c;background:#fdf2e9;border-radius:10px;color:#c0392b;'>
        <h2 style='margin-top:0;'>⚠️ NoteNest Database Connection Failed</h2>
        <p><strong>MySQL Error:</strong> " . htmlspecialchars($conn->connect_error) . "</p>
        <p><small>Please check DB_SERVER, DB_USERNAME, DB_PASSWORD, and DB_NAME in <code>config.php</code>.</small></p>
    </div>
    ");
}

// Force UTF-8 character set for all queries
$conn->set_charset('utf8mb4');

if (!function_exists('db_reconnect')) {
    /**
     * Re-establish MySQL connection if it has dropped.
     * Call this before DB operations in long-running sync processes.
     */
    function db_reconnect(mysqli &$conn): void {
        $isAlive = false;
        try {
            if ($conn && @$conn->ping()) {
                $isAlive = true;
            }
        } catch (\Throwable $e) {
            $isAlive = false;
        }

        if (!$isAlive) {
            try {
                @$conn->close();
            } catch (\Throwable $e) {}

            try {
                $newConn = new mysqli(
                    DB_SERVER,
                    DB_USERNAME,
                    DB_PASSWORD,
                    DB_NAME,
                    defined('DB_PORT') ? (int)DB_PORT : 3306
                );
                if (!$newConn->connect_error) {
                    $newConn->set_charset('utf8mb4');
                    $conn = $newConn;
                }
            } catch (\Throwable $e) {
                // Silently ignore connection reconnect failure
            }
        }
    }
}
?>
