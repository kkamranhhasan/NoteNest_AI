<?php
// ============================================================
// note_download.php — NoteNest AI Platform
// Supports: ?id=<file_id>  OR  ?path=<file_path>
// Security: owner OR shared access only
// ============================================================
session_start();
require 'includes/db.php';

if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }
$user_id = $_SESSION['user_id'];

// ── Resolve by ID or path ─────────────────────────────────────
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id   = (int)$_GET['id'];
    $stmt = $conn->prepare(
        "SELECT name, file_path, mime_type FROM files
         WHERE id=?
           AND (owner_id=? OR EXISTS (
             SELECT 1 FROM shared_access
             WHERE item_type='file' AND item_id=files.id AND shared_with_user_id=?
           ))"
    );
    $stmt->bind_param('iii', $id, $user_id, $user_id);

} elseif (isset($_GET['path'])) {
    $path = $_GET['path'];
    $stmt = $conn->prepare(
        "SELECT name, file_path, mime_type FROM files
         WHERE file_path=?
           AND (owner_id=? OR EXISTS (
             SELECT 1 FROM shared_access
             WHERE item_type='file' AND item_id=files.id AND shared_with_user_id=?
           ))"
    );
    $stmt->bind_param('sii', $path, $user_id, $user_id);

} else {
    http_response_code(400); exit('Bad request');
}

$stmt->execute();
$stmt->bind_result($file_name, $file_path, $mime_type);

if ($stmt->fetch()) {
    $abs_path = __DIR__ . '/' . $file_path;
    if (file_exists($abs_path)) {
        header('Content-Type: ' . ($mime_type ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . basename($file_name) . '"');
        header('Content-Length: ' . filesize($abs_path));
        readfile($abs_path);
        exit;
    }
}

http_response_code(404);
echo 'File not found.';