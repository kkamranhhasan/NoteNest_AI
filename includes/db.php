<?php
// ============================================================
// includes/db.php — NoteNest Database Connection
// Reads credentials from config constants (set via env vars)
// ============================================================

// config.php defines DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME
// Those constants already read from getenv() so Docker env vars work.
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed. Please check server configuration.']));
}

// Force UTF-8 character set for all queries
$conn->set_charset('utf8mb4');
?>
