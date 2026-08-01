<?php
// ============================================================
// config.example.php — NoteNest AI Platform Configuration
// ============================================================
//
//  HOW TO SETUP:
//  1. Copy this file:   cp config.example.php config.php
//  2. Fill in your real values in config.php
//  3. NEVER commit config.php to GitHub!
//  4. For deployment: use environment variables instead.
//     See .env.example for the full list.
// ============================================================

// ── Environment ───────────────────────────────────────────────
// Set APP_ENV=production in your server/Docker env for live site.
// Keep 'local' for XAMPP development (disables SSL verification).
define('APP_ENV', getenv('APP_ENV') ?: 'local');

// ── Database ─────────────────────────────────────────────────
define('DB_SERVER',   getenv('DB_SERVER')   ?: 'localhost');
define('DB_USERNAME', getenv('DB_USERNAME') ?: 'root');
define('DB_PASSWORD', getenv('DB_PASSWORD') === false ? '' : getenv('DB_PASSWORD'));
define('DB_NAME',     getenv('DB_NAME')     ?: 'notenest');

// ── Gmail SMTP ───────────────────────────────────────────────
// Gmail App Password: Google Account → Security → 2FA → App Passwords
define('MAIL_HOST',     getenv('MAIL_HOST')     ?: 'smtp.gmail.com');
define('MAIL_USERNAME', getenv('MAIL_USERNAME') ?: 'your_email@gmail.com');
define('MAIL_PASSWORD', getenv('MAIL_PASSWORD') ?: 'your_16char_app_password');
// ── App URL (Auto-detected for local & production) ──
if (!defined('APP_URL')) {
    if (isset($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $path = ($scriptDir === '.' || $scriptDir === '/') ? '' : $scriptDir;
        define('APP_URL', $scheme . '://' . $_SERVER['HTTP_HOST'] . $path);
    } else {
        define('APP_URL', getenv('APP_URL') ?: 'http://localhost/NoteNest-main');
    }
}

// ── Groq AI API ────────────────────────────────────────────
// Get key from: https://console.groq.com/
define('GROQ_API_KEY',    getenv('GROQ_API_KEY')    ?: 'your_groq_api_key_here');
define('GROQ_MODEL',      getenv('GROQ_MODEL')      ?: 'llama-3.3-70b-versatile');
define('GROQ_MODEL_PRO',  getenv('GROQ_MODEL_PRO')  ?: 'llama-3.3-70b-versatile');
define('GROQ_MODEL_FAST', getenv('GROQ_MODEL_FAST') ?: 'llama-3.1-8b-instant');
define('GROQ_API_URL',    getenv('GROQ_API_URL')    ?: 'https://api.groq.com/openai/v1/chat/completions');
define('AI_MAX_TOKENS',   getenv('AI_MAX_TOKENS')   ?: 2048);
define('AI_TEMPERATURE',  getenv('AI_TEMPERATURE')  ?: 0.7);

// ── ChromaDB Local Vector Service ──────────────────────────
define('CHROMA_API_URL', getenv('CHROMA_API_URL') ?: 'http://127.0.0.1:8000');

// ── Jina AI Embeddings (optional — enables vector search) ───
// Get key: https://jina.ai/ (free tier available)
define('JINA_API_KEY', getenv('JINA_API_KEY') ?: 'your_jina_api_key_here');

// ── Qdrant Vector Database (optional — enables vector search) ─
// Use Qdrant Cloud free cluster: https://cloud.qdrant.io/
define('QDRANT_API_URL', getenv('QDRANT_API_URL') ?: 'http://localhost:6333');
define('QDRANT_API_KEY', getenv('QDRANT_API_KEY') ?: '');

// ── Google OAuth 2.0 (Classroom Integration) ────────────────
// Get credentials: https://console.cloud.google.com/
define('GOOGLE_CLIENT_ID',     getenv('GOOGLE_CLIENT_ID')     ?: 'your_client_id.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: 'your_client_secret');

// Legacy aliases (backward-compat)
define('GEMINI_API_KEY', GROQ_API_KEY);
define('GEMINI_MODEL',   GROQ_MODEL);
define('GROK_API_KEY',   GROQ_API_KEY);
define('GROK_MODEL',     GROQ_MODEL);
define('GROK_MODEL_PRO', GROQ_MODEL_PRO);
define('GROK_API_URL',   GROQ_API_URL);

require_once 'includes/db.php';

// Load Composer vendor autoloader
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
?>

