<?php
// ============================================================
// config.example.php — NoteNest AI Platform Configuration
// ============================================================
// ⚠️  এই ফাইলটি কপি করে নাম দাও: config.php
//     তারপর নিচের সব placeholder মান তোমার আসল মান দিয়ে পূরণ করো।
//
//     HOW TO SETUP:
//     1. Copy this file:   cp config.example.php config.php
//     2. Fill in your real values in config.php
//     3. Never commit config.php to GitHub!
// ============================================================

// ── Database ─────────────────────────────────────────────────
define('DB_SERVER',   'localhost');
define('DB_USERNAME', 'your_db_username');   // e.g. root
define('DB_PASSWORD', 'your_db_password');   // e.g. '' for XAMPP default
define('DB_NAME',     'notenest');

// ── Gmail SMTP ───────────────────────────────────────────────
// Gmail App Password পেতে: Google Account → Security → 2FA → App Passwords
define('MAIL_HOST',     'smtp.gmail.com');
define('MAIL_USERNAME', 'your_email@gmail.com');
define('MAIL_PASSWORD', 'your_gmail_app_password');   // 16-character app password
define('MAIL_PORT',     587);
define('APP_URL',       'http://localhost/NoteNest-main');   // Change for production

// ── Google Gemini AI ─────────────────────────────────────────
// API Key পেতে: https://aistudio.google.com/app/apikey
define('GEMINI_API_KEY',   'your_gemini_api_key_here');
define('GEMINI_MODEL',     'models/gemini-2.5-flash');
define('GEMINI_API_URL',   'https://generativelanguage.googleapis.com/v1beta/' . GEMINI_MODEL . ':generateContent');
define('AI_MAX_TOKENS',    2048);
define('AI_TEMPERATURE',   0.7);

require_once 'includes/db.php';
?>
