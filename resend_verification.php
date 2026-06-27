<?php
require 'config.php';
require 'includes/send_email.php';

$email = '';
$message = '';
$success = false;
$error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = htmlspecialchars(trim($_POST['email'] ?? ''));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = true;
        $message = 'Valid email address দাও।';
    } else {
        $stmt = $conn->prepare("SELECT id, name, is_verified FROM users WHERE email = ?");
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 1) {
                $stmt->bind_result($user_id, $user_name, $is_verified);
                $stmt->fetch();
                $stmt->close();

                if ($is_verified) {
                    $success = true;
                    $message = 'এই email ইতিমধ্যে verify করা আছে। Login করতে পারো।';
                } else {
                    $verification_token = bin2hex(random_bytes(32));
                    $update = $conn->prepare(
                        "UPDATE users SET verification_token = ?, token_created_at = NOW() WHERE id = ?"
                    );
                    if ($update) {
                        $update->bind_param('si', $verification_token, $user_id);
                        if ($update->execute()) {
                            if (sendVerificationEmail($email, $user_name, $verification_token)) {
                                $success = true;
                                $message = 'নতুন verification email পাঠানো হয়েছে। Inbox ও spam folder চেক করো।';
                            } else {
                                $error = true;
                                $message = 'Email পাঠানো যায়নি। পরে আবার চেষ্টা করো।';
                            }
                        } else {
                            $error = true;
                            $message = 'Database error. Please try again.';
                        }
                        $update->close();
                    } else {
                        $error = true;
                        $message = 'Database error. Please try again.';
                    }
                }
            } else {
                $stmt->close();
                $success = true;
                $message = 'যদি এই email দিয়ে unverified account থাকে, verification email পাঠানো হবে।';
            }
        } else {
            $error = true;
            $message = 'Database error. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Resend Verification - NoteNest</title>
    <link rel="shortcut icon" href="img/fav.ico" type="image/x-icon">
    <link rel="stylesheet" href="css/login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .notice {
            margin: 12px 0;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 14px;
            line-height: 1.6;
        }
        .notice.success { background: #e8f8ef; color: #1e7e34; }
        .notice.error   { background: #fdecea; color: #c0392b; }
        .back-link {
            display: inline-block;
            margin-top: 14px;
            color: #197f8f;
            text-decoration: none;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container" id="container">
        <div class="form-container sign-in-container">
            <form action="resend_verification.php" method="POST">
                <h1>Resend Verification Email</h1>
                <span>Sign up করেছ কিন্তু verify email পাওনি?</span>
                <input type="email" name="email" required placeholder="Email"
                       value="<?php echo htmlspecialchars($email); ?>">

                <?php if ($message): ?>
                    <div class="notice <?php echo $success ? 'success' : 'error'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-color">Send Verification Email</button>
                <a href="login.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
            </form>
        </div>
        <div class="overlay-container">
            <div class="overlay">
                <div class="overlay-panel overlay-right">
                    <h1>Verify Your Account</h1>
                    <p>Email verify না করলে login করা যাবে না।</p>
                    <button class="ghost"><a href="register.php">Create Account</a></button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
