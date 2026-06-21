<?php
// ============================================================
// note_preview.php — NoteNest AI Platform
// Unified File Preview API
// Serves: text, image, pdf, audio, video, csv, docx-hint
// ============================================================
session_start();
require 'includes/db.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['file'])) {
    http_response_code(403); exit('Unauthorized');
}

$user_id = $_SESSION['user_id'];
$file    = $_GET['file'];

// ── Security: owner OR shared access only ─────────────────────
$stmt = $conn->prepare(
    "SELECT f.id, f.file_path, f.mime_type, f.name
     FROM files f
     WHERE f.file_path = ?
       AND (
         f.owner_id = ?
         OR EXISTS (
           SELECT 1 FROM shared_access sa
           WHERE sa.item_type='file' AND sa.item_id=f.id
             AND sa.shared_with_user_id=?
         )
       )"
);
$stmt->bind_param('sii', $file, $user_id, $user_id);
$stmt->execute();
$stmt->bind_result($file_id, $file_path, $mime_type, $file_name);

if (!$stmt->fetch()) {
    http_response_code(404); exit('File not found');
}
$stmt->close();

$abs_path = __DIR__ . '/' . $file_path;
if (!file_exists($abs_path)) {
    http_response_code(404); exit('File missing');
}

// ── Serve raw binary for browser-renderable types ────────────
$serve_raw = [
    'application/pdf', 'image/jpeg','image/png','image/gif',
    'image/webp','image/svg+xml','audio/mpeg','audio/ogg',
    'audio/wav','video/mp4','video/webm','video/ogg',
];

if (str_starts_with($mime_type, 'text/') || $mime_type === 'application/json') {
    header('Content-Type: text/plain; charset=UTF-8');
    readfile($abs_path);
    exit;
}

if (in_array($mime_type, $serve_raw) || str_starts_with($mime_type, 'image/') || str_starts_with($mime_type, 'audio/') || str_starts_with($mime_type, 'video/')) {
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: inline; filename="' . basename($file_path) . '"');
    header('Content-Length: ' . filesize($abs_path));
    readfile($abs_path);
    exit;
}

// ── For CSV: serve as plain text so JS can parse it ──────────
if ($mime_type === 'text/csv' || str_ends_with($file_path, '.csv')) {
    header('Content-Type: text/plain; charset=UTF-8');
    readfile($abs_path);
    exit;
}

// ── For everything else: return file info as JSON ─────────────
header('Content-Type: application/json');
echo json_encode([
    'name' => $file_name,
    'mime' => $mime_type,
    'size' => filesize($abs_path),
    'path' => $file_path,
]);