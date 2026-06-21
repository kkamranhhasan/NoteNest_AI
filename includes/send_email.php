<?php
// includes/send_email.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../phpmailer/Exception.php';
require_once __DIR__ . '/../phpmailer/PHPMailer.php';
require_once __DIR__ . '/../phpmailer/SMTP.php';

function sendVerificationEmail($toEmail, $toName, $token) {
    $verifyLink = APP_URL . '/verify_email.php?token=' . $token;

    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = 'UTF-8';

        // Recipients
        $mail->setFrom(MAIL_USERNAME, 'NoteNest');
        $mail->addAddress($toEmail, $toName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = '✅ Verify your NoteNest Email';
        $mail->Body    = '
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <style>
    body { font-family: Arial, sans-serif; background: #f4f6f9; margin: 0; padding: 0; }
    .wrapper { max-width: 560px; margin: 40px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
    .header { background: linear-gradient(135deg, #0b4954, #197f8f); padding: 36px 30px; text-align: center; }
    .header img { width: 50px; margin-bottom: 10px; }
    .header h1 { color: #ffffff; font-size: 24px; margin: 0; letter-spacing: 1px; }
    .body { padding: 36px 30px; color: #333; }
    .body h2 { font-size: 20px; color: #0b4954; margin-top: 0; }
    .body p { font-size: 15px; line-height: 1.7; color: #555; }
    .btn-wrap { text-align: center; margin: 30px 0; }
    .btn { display: inline-block; background: linear-gradient(135deg, #0b4954, #197f8f); color: #ffffff !important; text-decoration: none; padding: 14px 36px; border-radius: 8px; font-size: 16px; font-weight: bold; letter-spacing: 0.5px; }
    .link-box { background: #f0f4f8; border-radius: 6px; padding: 12px 16px; font-size: 13px; color: #666; word-break: break-all; margin-top: 10px; }
    .footer { background: #f8f9fa; text-align: center; padding: 20px; font-size: 12px; color: #aaa; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>📁 NoteNest</h1>
    </div>
    <div class="body">
      <h2>Hi ' . htmlspecialchars($toName) . '! 👋</h2>
      <p>NoteNest-এ সাইনআপ করার জন্য ধন্যবাদ! তোমার অ্যাকাউন্ট প্রায় রেডি।</p>
      <p>নিচের বোতামে ক্লিক করে তোমার <strong>Email Verify</strong> করো এবং অ্যাকাউন্ট activate করো:</p>
      <div class="btn-wrap">
        <a href="' . $verifyLink . '" class="btn">✅ Verify Email</a>
      </div>
      <p style="font-size:13px; color:#888;">বোতামটি কাজ না করলে নিচের লিংকটি ব্রাউজারে কপি করো:</p>
      <div class="link-box">' . $verifyLink . '</div>
      <p style="font-size:13px; color:#aaa; margin-top:20px;">⏳ এই লিংক <strong>24 ঘন্টা</strong> পর্যন্ত valid থাকবে।</p>
    </div>
    <div class="footer">
      © NoteNest &nbsp;|&nbsp; তুমি এই email পাচ্ছ কারণ কেউ এই address দিয়ে NoteNest-এ sign up করেছে।
    </div>
  </div>
</body>
</html>';

        $mail->AltBody = "Hi $toName,\n\nNoteNest-এ সাইনআপ করার জন্য ধন্যবাদ!\n\nনিচের লিংকে ক্লিক করে email verify করো:\n$verifyLink\n\nএই লিংক 24 ঘন্টা valid।\n\n— NoteNest Team";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}
?>
