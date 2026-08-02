<?php
// ============================================================
// generate_answer.php — NoteNest AI API Endpoint
// AI Tutor: Directly extracts file text and sends to Groq
// Bypasses document_chunks (RAG) which fails silently on XAMPP
// ============================================================
require 'includes/auth.php';
require 'config.php';
require_once 'includes/ai_service.php';
require_once 'includes/db.php';

header('Content-Type: application/json');

ob_start();

$user_id    = $_SESSION['user_id'];
$message    = trim($_REQUEST['message']    ?? '');
$session_id = trim($_REQUEST['session_id'] ?? '');
$course_id  = (int)($_REQUEST['course_id']  ?? 0);
$folder_id  = (int)($_REQUEST['folder_id']  ?? 0);
$topic_id   = (int)($_REQUEST['topic_id']   ?? 0);
$select_all = isset($_REQUEST['select_all']) && $_REQUEST['select_all'] == 1;

// Parse file_ids — accept array or comma-separated string
$file_ids = [];
if (isset($_REQUEST['file_ids'])) {
    if (is_array($_REQUEST['file_ids'])) {
        $file_ids = array_values(array_filter(array_map('intval', $_REQUEST['file_ids'])));
    } elseif ($_REQUEST['file_ids'] !== '') {
        $file_ids = array_values(array_filter(array_map('intval', explode(',', $_REQUEST['file_ids']))));
    }
}

// Helper to output clean JSON
function respondJson(array $data): void {
    if (ob_get_length()) ob_clean();
    echo json_encode($data);
    exit;
}

// ── DEBUG LOG: Input ──────────────────────────────────────────
error_log("==================================================");
error_log("[generate_answer] ▶ START");
error_log("[generate_answer] user_id=$user_id, session_id=$session_id");
error_log("[generate_answer] course_id=$course_id, topic_id=$topic_id, folder_id=$folder_id");
error_log("[generate_answer] select_all=" . ($select_all ? '1' : '0'));
error_log("[generate_answer] selected_file_ids=" . json_encode($file_ids));
error_log("[generate_answer] message=" . substr($message, 0, 150));

if (!$message || !$session_id) {
    error_log("[generate_answer] ERROR: Missing message or session_id");
    respondJson(['success' => false, 'error' => 'Message and session_id are required.']);
}

// ── Enforce File Selection Policy ────────────────────────────
if (!$select_all && empty($file_ids)) {
    error_log("[generate_answer] No files selected and select_all=0 — returning strict fallback");
    respondJson([
        'success' => true,
        'reply'   => "I couldn't find this information in the selected study materials.",
        'tokens'  => 0
    ]);
}

// ── Validate ownership & collect file info ────────────────────
// If select_all, fetch ALL files belonging to user (optionally scoped)
if ($select_all) {
    if ($folder_id > 0) {
        $fsql  = "SELECT id, name, file_path, mime_type FROM files WHERE owner_id = ? AND folder_id = ?";
        $fstmt = $conn->prepare($fsql);
        $fstmt->bind_param('ii', $user_id, $folder_id);
    } elseif ($course_id > 0) {
        $fsql  = "SELECT f.id, f.name, f.file_path, f.mime_type FROM files f LEFT JOIN file_course_tags fct ON fct.file_id=f.id WHERE f.owner_id=? AND (f.course_id=? OR fct.course_id=?)";
        $fstmt = $conn->prepare($fsql);
        $fstmt->bind_param('iii', $user_id, $course_id, $course_id);
    } else {
        $fsql  = "SELECT id, name, file_path, mime_type FROM files WHERE owner_id = ? ORDER BY created_at DESC LIMIT 5";
        $fstmt = $conn->prepare($fsql);
        $fstmt->bind_param('i', $user_id);
    }
    $fstmt->execute();
    $file_rows = $fstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fstmt->close();
} else {
    // Fetch only selected file_ids (verify ownership)
    $file_rows = [];
    foreach ($file_ids as $fId) {
        $fstmt = $conn->prepare("SELECT id, name, file_path, mime_type FROM files WHERE id=? AND owner_id=?");
        $fstmt->bind_param('ii', $fId, $user_id);
        $fstmt->execute();
        $row = $fstmt->get_result()->fetch_assoc();
        $fstmt->close();
        if ($row) {
            $file_rows[] = $row;
        } else {
            error_log("[generate_answer] WARNING: file_id=$fId not owned by user_id=$user_id or not found");
        }
    }
}

error_log("[generate_answer] Validated " . count($file_rows) . " file(s) to process");

if (empty($file_rows)) {
    error_log("[generate_answer] No valid files found after ownership check");
    echo json_encode([
        'success' => true,
        'reply'   => "I couldn't find this information in the selected study materials.",
        'tokens'  => 0
    ]);
    exit;
}

// ── DIRECT FILE TEXT EXTRACTION ───────────────────────────────
// Bypass document_chunks (which requires working indexer).
// Extract text directly from files on disk.
$context    = "";
$totalChars = 0;

error_log("[generate_answer] === FILE TEXT EXTRACTION START ===");

foreach ($file_rows as $fileRow) {
    $fId      = $fileRow['id'];
    $fName    = $fileRow['name'];
    $fPath    = $fileRow['file_path'];
    $fMime    = $fileRow['mime_type'] ?? '';
    // Resolve absolute path (same logic as IndexerService.php)
    $fullPath = $fPath;
    if (!file_exists($fullPath)) {
        $fullPath = __DIR__ . '/' . ltrim($fPath, '/');
    }
    if (!file_exists($fullPath)) {
        $fullPath = __DIR__ . '/../' . ltrim($fPath, '/');
    }
    $ext      = strtolower(pathinfo($fPath, PATHINFO_EXTENSION));

    error_log("[generate_answer] Processing file_id=$fId, name='$fName', resolved_path='$fullPath', mime='$fMime', ext='$ext'");

    if (!file_exists($fullPath)) {
        error_log("[generate_answer] ⚠️ FILE NOT FOUND on disk: $fullPath");
        continue;
    }

    $rawText = '';

    // ── PDF ──────────────────────────────────────────────────
    // ── PDF ──────────────────────────────────────────────────
    if ($ext === 'pdf' || strpos($fMime, 'pdf') !== false) {
        // Try pdftotext (fastest, most reliable)
        if (function_exists('shell_exec')) {
            $escaped = escapeshellarg($fullPath);
            $pdfText = @shell_exec("pdftotext $escaped - 2>/dev/null");
            if (!empty(trim($pdfText))) {
                $rawText = $pdfText;
                error_log("[generate_answer] PDF extracted via pdftotext, chars=" . strlen($rawText));
            }
        }
        if (empty(trim($rawText))) {
            // Pure PHP fallback
            $rawText = extract_text_from_pdf($fullPath);
            error_log("[generate_answer] PDF extracted via PHP fallback, chars=" . strlen($rawText));
        }
    }
    // ── DOCX ─────────────────────────────────────────────────
    elseif ($ext === 'docx' || strpos($fMime, 'wordprocessingml') !== false || strpos($fMime, 'word') !== false) {
        $rawText = extract_text_from_docx($fullPath);
        error_log("[generate_answer] DOCX extracted, chars=" . strlen($rawText));
    }
    // ── PPTX ─────────────────────────────────────────────────
    elseif ($ext === 'pptx' || strpos($fMime, 'presentationml') !== false || strpos($fMime, 'powerpoint') !== false) {
        $rawText = extract_text_from_pptx($fullPath);
        error_log("[generate_answer] PPTX extracted, chars=" . strlen($rawText));
    }
    // ── Plain text / markdown / CSV / HTML ───────────────────
    elseif (in_array($ext, ['txt', 'md', 'csv', 'rtf', 'html', 'htm', 'json']) ||
            strpos($fMime, 'text/') === 0 || strpos($fMime, 'application/json') === 0) {
        $content = @file_get_contents($fullPath);
        if ($content !== false) {
            if ($ext === 'rtf') {
                // Strip RTF formatting
                $content = preg_replace('/\\\\\w+\s?/', ' ', $content);
                $content = preg_replace('/[{}]/', '', $content);
            }
            $rawText = strip_tags((string)$content);
        }
        error_log("[generate_answer] Text file extracted, chars=" . strlen($rawText));
    }
    // ── Unknown type: try reading as text ────────────────────
    else {
        $content = @file_get_contents($fullPath);
        if ($content !== false) {
            $rawText = strip_tags((string)$content);
        }
        error_log("[generate_answer] Unknown type ($ext/$fMime) — tried as text, chars=" . strlen($rawText));
    }

    if (empty(trim($rawText))) {
        error_log("[generate_answer] ⚠️ ZERO TEXT extracted from file_id=$fId ('$fName'). Possible reasons: binary/image file, corrupted, or wrong path.");
        continue;
    }

    // Sanitize: remove control characters, convert to UTF-8
    $rawText = mb_convert_encoding($rawText, 'UTF-8', 'UTF-8');
    $rawText = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $rawText);
    $rawText = preg_replace('/\s+/', ' ', trim($rawText));

    // Per-file limit: 2000 chars per file to avoid token overflow
    $fileChunk = mb_substr($rawText, 0, 2000);
    $context  .= "=== FILE: {$fName} ===\n" . $fileChunk . "\n\n";
    $totalChars += strlen($fileChunk);
    error_log("[generate_answer] File '$fName': extracted " . strlen($rawText) . " chars, using first " . strlen($fileChunk) . " chars");
}

// Global context limit: 5000 chars
if (strlen($context) > 5000) {
    $context = substr($context, 0, 5000);
    error_log("[generate_answer] Context truncated to 5000 chars");
}

error_log("[generate_answer] === EXTRACTION COMPLETE ===");
error_log("[generate_answer] Total context length: " . strlen($context) . " chars from " . count($file_rows) . " file(s)");
error_log("[generate_answer] Context preview (first 300 chars): " . substr($context, 0, 300));

// ── No text extracted — return strict fallback ────────────────
if (strlen(trim($context)) < 10) {
    error_log("[generate_answer] ⚠️ Context is empty or too short — returning strict fallback");
    respondJson([
        'success' => true,
        'reply'   => "I couldn't find this information in the selected study materials.",
        'tokens'  => 0
    ]);
}

// ── Build system prompt ───────────────────────────────────────
$systemPrompt = "You are NoteNest AI Tutor. Answer the student's question ONLY using the provided study material context below.

RULES:
- Use ONLY the provided context. Never use outside knowledge.
- If the answer is not in the context, reply exactly: \"I couldn't find this information in the selected study materials.\"
- Be clear, helpful, and educational.
- Format answers with bullet points or numbered lists when appropriate.";

// ── Load conversation history ─────────────────────────────────
$history = [];
$hq = $conn->prepare(
    "SELECT role, message FROM ai_chat_history
     WHERE user_id=? AND session_id=?
     ORDER BY created_at ASC LIMIT 10"
);
$hq->bind_param('is', $user_id, $session_id);
$hq->execute();
$hres = $hq->get_result();
while ($row = $hres->fetch_assoc()) {
    $history[] = [
        'role'    => $row['role'] === 'assistant' ? 'assistant' : 'user',
        'content' => $row['message']
    ];
}
$hq->close();
error_log("[generate_answer] Loaded " . count($history) . " history messages");

// ── Save user question to DB ──────────────────────────────────
saveAiChat($conn, $user_id, $session_id, 'user', $message, 'tutor', $course_id);

// ── Build Groq messages ───────────────────────────────────────
$messages = [['role' => 'system', 'content' => $systemPrompt]];
foreach ($history as $h) {
    $messages[] = ['role' => $h['role'], 'content' => $h['content']];
}
$fullUserMsg  = "STUDY MATERIAL CONTEXT:\n" . $context;
$fullUserMsg .= "\n\n---\nSTUDENT QUESTION: " . $message;
$messages[] = ['role' => 'user', 'content' => $fullUserMsg];

// ── DEBUG: Log Groq request ───────────────────────────────────
error_log("[generate_answer] === GROQ REQUEST ===");
error_log("[generate_answer] Model: " . GROQ_MODEL);
error_log("[generate_answer] Messages count: " . count($messages));
error_log("[generate_answer] Total prompt length: " . strlen($fullUserMsg) . " chars");
error_log("[generate_answer] User message preview: " . substr($fullUserMsg, 0, 400));

// ── Call Groq ─────────────────────────────────────────────────
$aiResult = grokRequest($messages, GROQ_MODEL, 0.3, 1500);

// ── DEBUG: Log Groq response ──────────────────────────────────
error_log("[generate_answer] === GROQ RESPONSE ===");
if (!$aiResult['success']) {
    error_log("[generate_answer] ❌ Groq ERROR: " . $aiResult['error']);
    respondJson(['success' => false, 'error' => 'AI Error: ' . $aiResult['error']]);
}

$aiReply = trim($aiResult['text']);
$tokens  = $aiResult['tokens'];
error_log("[generate_answer] ✅ Groq response received, tokens=$tokens, reply_length=" . strlen($aiReply));
error_log("[generate_answer] Reply preview: " . substr($aiReply, 0, 300));

// ── Append source citation ────────────────────────────────────
if (stripos($aiReply, "I couldn't find") === false && !empty($file_rows)) {
    $srcNames = implode(', ', array_column($file_rows, 'name'));
    $aiReply  = "### Answer\n\n" . $aiReply . "\n\n---\n**Source:** " . $srcNames;
}

// ── Save AI response to DB ────────────────────────────────────
saveAiChat($conn, $user_id, $session_id, 'assistant', $aiReply, 'tutor', $course_id, $tokens);
logProgress($conn, $user_id, 'ai_chat', 'AI Tutor session', $course_id);

error_log("[generate_answer] ✓ Response sent successfully");

respondJson([
    'success' => true,
    'reply'   => $aiReply,
    'tokens'  => $tokens
]);
