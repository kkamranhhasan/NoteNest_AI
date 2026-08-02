<?php
// ============================================================
// google_disconnect.php — Disconnect Google Account
// ============================================================
require 'includes/auth.php';
require 'config.php';
require_once 'includes/google_classroom_service.php';

$userId = $_SESSION['user_id'];
error_log("[GC] Disconnect initiated by user_id: {$userId}");

// gc_disconnect now handles clearing Classroom sync data AND tokens
gc_disconnect($conn, $userId);

$_SESSION['gc_message']  = 'Google account disconnected. All Classroom sync data has been cleared. Your NoteNest courses remain intact.';
$_SESSION['gc_msg_type'] = 'info';

header('Location: google_classroom.php');
exit;
?>
