<?php
// ============================================================
// google_disconnect.php — Disconnect Google Account
// ============================================================
require 'includes/auth.php';
require 'config.php';
require_once 'includes/google_classroom_service.php';

gc_disconnect($conn, $_SESSION['user_id']);

$_SESSION['gc_message']  = 'Google account disconnected successfully.';
$_SESSION['gc_msg_type'] = 'info';

header('Location: profile.php');
exit;
?>
