<?php
// ============================================================
// config.php — NoteNest AI Platform Configuration
// ============================================================

// ── Database ─────────────────────────────────────────────────
define('DB_SERVER',   'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME',     'notenest');

// ── Gmail SMTP ───────────────────────────────────────────────
define('MAIL_HOST',     'smtp.gmail.com');
define('MAIL_USERNAME', 'kkamranhhasan@gmail.com');
define('MAIL_PASSWORD', 'qxaioqxwxvlvkzld');
define('MAIL_PORT',     587);
define('APP_URL',       'http://localhost/NoteNest-main');

// ── Google Gemini AI ─────────────────────────────────────────
define('GEMINI_API_KEY',   'AIzaSyDzjdx2xZrYurHgs5j5hmVyw9BphDLXNME');
define('GEMINI_MODEL',     'models/gemini-2.5-flash');
define('GEMINI_API_URL',   'https://generativelanguage.googleapis.com/v1beta/' . GEMINI_MODEL . ':generateContent');
define('AI_MAX_TOKENS',    2048);
define('AI_TEMPERATURE',   0.7);

require_once 'includes/db.php';
?>