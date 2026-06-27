<?php
// verify_email.php — handles verification link clicks
require 'config.php';

$message = '';
$success = false;
$showConfirm = false;
$token = '';

function findUserByToken($conn, $token) {
    $stmt = $conn->prepare(
        "SELECT id, name, is_verified, token_created_at FROM users WHERE verification_token = ?"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows !== 1) {
        $stmt->close();
        return null;
    }
    $stmt->bind_result($user_id, $user_name, $is_verified, $token_created_at);
    $stmt->fetch();
    $stmt->close();
    return compact('user_id', 'user_name', 'is_verified', 'token_created_at');
}

function tokenExpired($token_created_at) {
    return $token_created_at && strtotime($token_created_at) < strtotime('-24 hours');
}

function activateUser($conn, $user_id, $user_name) {
    $update = $conn->prepare(
        "UPDATE users SET is_verified = 1 WHERE id = ? AND (is_verified = 0 OR is_verified IS NULL)"
    );
    if (!$update) {
        return [false, 'Database error. Please try again.'];
    }
    $update->bind_param('i', $user_id);
    $ok = $update->execute();
    $affected = $update->affected_rows;
    $update->close();

    if ($ok && $affected > 0) {
        return [true, "Hi $user_name, তোমার email সফলভাবে verify হয়েছে! 🎉"];
    }
    if ($ok) {
        return [true, "Hi $user_name, তোমার email আগেই verify করা ছিল। 🎉"];
    }
    return [false, 'কিছু একটা ভুল হয়েছে। আবার চেষ্টা করো।'];
}

$rawToken = $_GET['token'] ?? $_POST['token'] ?? '';
$justVerified = isset($_GET['done']) && $_GET['done'] === '1';

if (!empty($rawToken)) {
    $token = preg_replace('/[^a-fA-F0-9]/', '', trim($rawToken));

    if (empty($token)) {
        $message = 'Invalid verification link.';
    } else {
        $user = findUserByToken($conn, $token);

        if (!$user) {
            $message = 'এই verification link টি invalid অথবা expired। নতুন link পেতে resend page ব্যবহার করো।';
        } elseif ($justVerified && (int) $user['is_verified'] === 1) {
            $success = true;
            $message = "Hi {$user['user_name']}, তোমার email সফলভাবে verify হয়েছে! 🎉";
        } elseif ((int) $user['is_verified'] === 1) {
            $success = true;
            $message = "Hi {$user['user_name']}, তোমার email আগেই verify করা আছে! 🎉";
        } elseif (tokenExpired($user['token_created_at'])) {
            $message = 'এই verification link-এর মেয়াদ শেষ হয়ে গেছে। নতুন link পেতে resend page ব্যবহার করো।';
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            [$success, $message] = activateUser($conn, $user['user_id'], $user['user_name']);
            if ($success) {
                header('Location: verify_email.php?token=' . urlencode($token) . '&done=1');
                exit;
            }
        } else {
            $showConfirm = true;
            $message = "Hi {$user['user_name']}, নিচের বোতামে ক্লিক করে email verify করো।";
        }
    }
} else {
    $message = 'Invalid verification link.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Email Verification - NoteNest</title>
    <link rel="shortcut icon" href="img/fav.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0b4954 0%, #197f8f 50%, #1a9aad 100%);
            font-family: 'Segoe UI', Arial, sans-serif;
        }
        .card {
            background: #fff;
            border-radius: 20px;
            padding: 56px 48px;
            text-align: center;
            max-width: 480px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            animation: popIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        @keyframes popIn {
            from { opacity: 0; transform: scale(0.85) translateY(20px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
        .icon {
            font-size: 72px;
            margin-bottom: 20px;
            animation: bounceIn 0.6s 0.2s both;
        }
        @keyframes bounceIn {
            from { transform: scale(0); }
            to   { transform: scale(1); }
        }
        h2 { color: #0b4954; font-size: 26px; margin-bottom: 14px; }
        p  { color: #666; font-size: 15px; line-height: 1.7; margin-bottom: 30px; }
        .btn {
            display: inline-block;
            background: linear-gradient(135deg, #0b4954, #197f8f);
            color: #fff;
            text-decoration: none;
            padding: 14px 36px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: bold;
            border: none;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 4px 15px rgba(11,73,84,0.3);
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(11,73,84,0.4);
        }
        .btn-outline {
            display: inline-block;
            margin-top: 12px;
            color: #197f8f;
            text-decoration: none;
            font-size: 14px;
        }
        .brand { font-size: 13px; color: #bbb; margin-top: 30px; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($showConfirm): ?>
            <div class="icon">📧</div>
            <h2>Confirm Your Email</h2>
            <p><?php echo htmlspecialchars($message); ?></p>
            <form method="POST" action="verify_email.php">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <button type="submit" class="btn" id="verifyBtn"><i class="fas fa-check"></i> &nbsp;Verify Email</button>
            </form>
            <script>
                document.querySelector('form').addEventListener('submit', function () {
                    var btn = document.getElementById('verifyBtn');
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> &nbsp;Verifying...';
                });
            </script>
        <?php elseif ($success): ?>
            <div class="icon">✅</div>
            <h2>Email Verified!</h2>
            <p><?php echo htmlspecialchars($message); ?><br>এখন তুমি NoteNest-এ Login করতে পারবে।</p>
            <a href="login.php" class="btn"><i class="fas fa-arrow-right"></i> &nbsp;Login করো</a>
        <?php else: ?>
            <div class="icon">❌</div>
            <h2>Verification Failed</h2>
            <p><?php echo htmlspecialchars($message); ?></p>
            <a href="resend_verification.php" class="btn"><i class="fas fa-envelope"></i> &nbsp;Resend Verification Email</a>
            <br>
            <a href="login.php" class="btn-outline">Login Page</a>
            <a href="register.php" class="btn-outline">নতুন Account করো</a>
        <?php endif; ?>
        <p class="brand">📁 NoteNest</p>
    </div>
</body>
</html>
