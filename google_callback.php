<?php
// ============================================================
// google_callback.php — Google OAuth 2.0 Callback Handler
// Exchanges auth code for tokens, stores encrypted in DB
// ============================================================
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require 'config.php';
require_once 'includes/google_classroom_service.php';

$userId = $_SESSION['user_id'];

// Check for errors from Google
if (isset($_GET['error'])) {
    $_SESSION['gc_message'] = 'Google authorization failed: ' . htmlspecialchars($_GET['error']);
    $_SESSION['gc_msg_type'] = 'danger';
    header('Location: profile.php');
    exit;
}

// Must have authorization code
$code = $_GET['code'] ?? '';
if (empty($code)) {
    $_SESSION['gc_message'] = 'No authorization code received from Google.';
    $_SESSION['gc_msg_type'] = 'danger';
    header('Location: profile.php');
    exit;
}

// Exchange code for tokens
$tokenResult = gc_exchange_code($code);
if (!$tokenResult['success']) {
    $_SESSION['gc_message'] = 'Failed to exchange code: ' . htmlspecialchars($tokenResult['error']);
    $_SESSION['gc_msg_type'] = 'danger';
    header('Location: profile.php');
    exit;
}

$accessToken  = $tokenResult['access_token'];
$refreshToken = $tokenResult['refresh_token'];
$expiresIn    = $tokenResult['expires_in'];

// If no refresh token received, prompt re-consent
if (empty($refreshToken)) {
    $_SESSION['gc_message'] = 'Google did not provide a refresh token. Please try connecting again.';
    $_SESSION['gc_msg_type'] = 'warning';
    header('Location: profile.php');
    exit;
}

// Get Google user info
$userInfo = gc_get_user_info($accessToken);
$email    = $userInfo['email'] ?? 'unknown@gmail.com';

// Save to database — returns google_account_id (int > 0 on success)
$savedAccountId = gc_save_account($conn, $userId, $email, $accessToken, $refreshToken, $expiresIn);

error_log("[GC] Callback | user_id: {$userId} | email: {$email} | google_account_id: {$savedAccountId}");

if ($savedAccountId > 0) {
    $_SESSION['gc_message'] = "✅ Google account ({$email}) connected successfully! Click 'Sync Now' to import your courses.";
    $_SESSION['gc_msg_type'] = 'success';

    // Create notification
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    $msg = "🔗 Google Classroom connected: {$email}";
    $stmt->bind_param('is', $userId, $msg);
    $stmt->execute();
    $stmt->close();
} else {
    $_SESSION['gc_message'] = 'Failed to save Google account. Please try again.';
    $_SESSION['gc_msg_type'] = 'danger';
}

header('Location: profile.php');
exit;
?>
