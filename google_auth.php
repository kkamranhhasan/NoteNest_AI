<?php
// ============================================================
// google_auth.php — Initiates Google OAuth 2.0 flow
// Redirects user to Google consent screen
// ============================================================
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require 'config.php';
require_once 'includes/google_classroom_service.php';

// Check if already connected
$account = gc_get_account($conn, $_SESSION['user_id']);
if ($account) {
    $_SESSION['gc_message'] = 'Your Google account is already connected. Disconnect first to connect a different account.';
    $_SESSION['gc_msg_type'] = 'warning';
    header('Location: profile.php');
    exit;
}

// Redirect to Google OAuth
$authUrl = gc_get_auth_url();
header('Location: ' . $authUrl);
exit;
?>
