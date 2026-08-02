<?php
// ============================================================
// generate_exam.php — NoteNest AI API Endpoint
// Generates AI exam questions from selected study file(s)
// Direct text extraction — bypasses document_chunks
// Context limited to 5000 chars to avoid Groq token limit
// ============================================================
require 'includes/auth.php';
require 'config.php';
require_once 'includes/ai_service.php';
require_once 'includes/db.php';

header('Content-Type: application/json');

ob_start();

$user_id    = $_SESSION['user_id'];
$file_id    = (int)($_REQUEST['file_id']    ?? 0);
$course_id  = (int)($_REQUEST['course_id']  ?? 0);
$topic_id   = (int)($_REQUEST['topic_id']   ?? 0);
$folder_id  = (int)($_REQUEST['folder_id']  ?? 0);
$q_types    = trim($_REQUEST['q_types']     ?? '5 MCQ and 5 short answer');
$difficulty = trim($_REQUEST['difficulty']  ?? 'medium');

// Accept file_ids as array or comma-separated string
$file_ids = [];
if (isset($_REQUEST['file_ids'])) {
    if (is_array($_REQUEST['file_ids'])) {
        $file_ids = array_values(array_filter(array_map('intval', $_REQUEST['file_ids'])));
    } elseif ($_REQUEST['file_ids'] !== '') {
        $file_ids = array_values(array_filter(array_map('intval', explode(',', $_REQUEST['file_ids']))));
    }
}
if ($file_id > 0 && !in_array($file_id, $file_ids)) {
    $file_ids[] = $file_id;
}

function respondJson(array $data): void {
    if (ob_get_length()) ob_clean();
    echo json_encode($data);
    exit;
}

error_log("==================================================");
error_log("[generate_exam] ▶ START");
error_log("[generate_exam] user_id=$user_id, course_id=$course_id, topic_id=$topic_id, folder_id=$folder_id");
error_log("[generate_exam] q_types='$q_types', difficulty='$difficulty'");
error_log("[generate_exam] raw file_ids=" . json_encode($file_ids));

if (empty($file_ids)) {
    error_log("[generate_exam] ❌ No file_ids provided");
    respondJson(['success' => false, 'error' => 'Please select at least one study material file.']);
}

// ── Limit to 2 files max to avoid Groq token overflow ────────
$file_ids = array_slice($file_ids, 0, 2);
error_log("[generate_exam] After slice(0,2): file_ids=" . json_encode($file_ids));

// ── Validate ownership & get file info ───────────────────────
$file_rows = [];
foreach ($file_ids as $fId) {
    $fstmt = $conn->prepare("SELECT id, name, file_path, mime_type FROM files WHERE id=? AND owner_id=?");
    $fstmt->bind_param('ii', $fId, $user_id);
    $fstmt->execute();
    $row = $fstmt->get_result()->fetch_assoc();
    $fstmt->close();
    if ($row) {
        $file_rows[] = $row;
        error_log("[generate_exam] Valid file_id=$fId, name='{$row['name']}', path='{$row['file_path']}'");
    } else {
        error_log("[generate_exam] WARNING: file_id=$fId not found or not owned by user_id=$user_id");
    }
}

if (empty($file_rows)) {
    error_log("[generate_exam] ❌ No valid files after ownership check");
    respondJson(['success' => false, 'error' => 'No valid files found. Please re-select your study materials.']);
}

// ── DIRECT FILE TEXT EXTRACTION ───────────────────────────────
$context     = "";
$totalChars  = 0;
$file_names  = [];

error_log("[generate_exam] === FILE TEXT EXTRACTION START ===");

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
    $rawText  = '';

    error_log("[generate_exam] Processing file_id=$fId, name='$fName', resolved_path='$fullPath', ext='$ext', mime='$fMime'");

    if (!file_exists($fullPath)) {
        error_log("[generate_exam] ⚠️ FILE NOT FOUND on disk: $fullPath");
        continue;
    }

    $fileSize = filesize($fullPath);
    error_log("[generate_exam] File size: $fileSize bytes");

    // ── PDF ──────────────────────────────────────────────────
    if ($ext === 'pdf' || strpos($fMime, 'pdf') !== false) {
        if (function_exists('shell_exec')) {
            $escaped = escapeshellarg($fullPath);
            $pdfText = @shell_exec("pdftotext $escaped - 2>/dev/null");
            if (!empty(trim($pdfText))) {
                $rawText = $pdfText;
                error_log("[generate_exam] PDF via pdftotext: " . strlen($rawText) . " chars");
            }
        }
        if (empty(trim($rawText))) {
            $rawText = extract_text_from_pdf($fullPath);
            error_log("[generate_exam] PDF via PHP fallback: " . strlen($rawText) . " chars");
        }
    }
    // ── DOCX ─────────────────────────────────────────────────
    elseif ($ext === 'docx' || strpos($fMime, 'wordprocessingml') !== false || strpos($fMime, 'word') !== false) {
        $rawText = extract_text_from_docx($fullPath);
        error_log("[generate_exam] DOCX extracted: " . strlen($rawText) . " chars");
    }
    // ── PPTX ─────────────────────────────────────────────────
    elseif ($ext === 'pptx' || strpos($fMime, 'presentationml') !== false || strpos($fMime, 'powerpoint') !== false) {
        $rawText = extract_text_from_pptx($fullPath);
        error_log("[generate_exam] PPTX extracted: " . strlen($rawText) . " chars");
    }
    // ── Plain text / markdown / CSV / HTML ───────────────────
    elseif (in_array($ext, ['txt', 'md', 'csv', 'rtf', 'html', 'htm']) || strpos($fMime, 'text/') === 0) {
        $content = @file_get_contents($fullPath);
        if ($content !== false) {
            $rawText = strip_tags((string)$content);
        }
        error_log("[generate_exam] Text file extracted: " . strlen($rawText) . " chars");
    }
    // ── Unknown: try as plain text ───────────────────────────
    else {
        $content = @file_get_contents($fullPath);
        if ($content !== false) {
            $rawText = strip_tags((string)$content);
        }
        error_log("[generate_exam] Unknown type ($ext) tried as text: " . strlen($rawText) . " chars");
    }

    if (empty(trim($rawText))) {
        error_log("[generate_exam] ⚠️ ZERO TEXT from file_id=$fId ('$fName'). Reason: image-based PDF, corrupted, binary, or unsupported type.");
        continue;
    }

    // Sanitize
    $rawText = mb_convert_encoding($rawText, 'UTF-8', 'UTF-8');
    $rawText = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $rawText);
    $rawText = preg_replace('/\s+/', ' ', trim($rawText));

    // Per-file limit: 2000 chars
    $fileChunk = mb_substr($rawText, 0, 2000);
    $context  .= "=== FILE: {$fName} ===\n" . $fileChunk . "\n\n";
    $totalChars += strlen($fileChunk);
    $file_names[] = $fName;

    error_log("[generate_exam] '$fName': " . strlen($rawText) . " raw chars → using " . strlen($fileChunk) . " chars");
}

// ── Global context limit: 5000 chars to prevent token overflow ─
if (strlen($context) > 5000) {
    $context = substr($context, 0, 5000);
    error_log("[generate_exam] Context truncated to 5000 chars");
}

error_log("[generate_exam] === EXTRACTION COMPLETE ===");
error_log("[generate_exam] file_names=" . json_encode($file_names));
error_log("[generate_exam] Total context chars=" . strlen($context));
error_log("[generate_exam] Context preview (first 400 chars): " . substr($context, 0, 400));

if (strlen(trim($context)) < 30) {
    error_log("[generate_exam] ❌ Context too short (" . strlen($context) . " chars) — cannot generate questions");
    respondJson([
        'success' => false,
        'error'   => 'The selected file(s) do not contain enough extractable text. The file may be an image-based PDF, corrupted, or empty. Please try a different file.'
    ]);
}

// ── Generate questions using Groq ─────────────────────────────
$systemPrompt = "You are an expert exam question generator. Generate exam questions ONLY from the study material provided. Output as valid JSON array.";

$userPrompt = "Generate {$q_types} exam questions at {$difficulty} difficulty from this study material.

STUDY MATERIAL:
{$context}

IMPORTANT: Return ONLY a JSON array (no markdown, no extra text) like:
[
  {\"type\":\"mcq\",\"question\":\"...\",\"options\":[\"A\",\"B\",\"C\",\"D\"],\"answer\":\"A\",\"explanation\":\"...\"},
  {\"type\":\"short\",\"question\":\"...\",\"answer\":\"...\"}
]";

error_log("[generate_exam] === GROQ REQUEST ===");
error_log("[generate_exam] Prompt length: " . strlen($userPrompt) . " chars");
error_log("[generate_exam] Model: " . GROQ_MODEL);

$messages = [
    ['role' => 'system', 'content' => $systemPrompt],
    ['role' => 'user',   'content' => $userPrompt]
];

$result = grokRequest($messages, GROQ_MODEL, 0.4, 2000);

error_log("[generate_exam] === GROQ RESPONSE ===");

if (!$result['success']) {
    error_log("[generate_exam] ❌ Groq ERROR: " . $result['error']);
    respondJson(['success' => false, 'error' => 'AI Error: ' . $result['error']]);
}

$rawJson = trim($result['text']);
$tokens  = $result['tokens'];
error_log("[generate_exam] ✅ Groq response received, tokens=$tokens, response_length=" . strlen($rawJson));
error_log("[generate_exam] Raw response preview: " . substr($rawJson, 0, 400));

// ── Parse JSON response ───────────────────────────────────────
// Strip markdown code fences if present
$rawJson  = preg_replace('/^```(?:json)?\s*/i', '', $rawJson);
$rawJson  = preg_replace('/\s*```$/i', '', $rawJson);
$rawJson  = trim($rawJson);

$questions = json_decode($rawJson, true);
if (!is_array($questions)) {
    // Try to extract JSON array from response
    preg_match('/\[.*\]/s', $rawJson, $jsonMatch);
    $questions = isset($jsonMatch[0]) ? json_decode($jsonMatch[0], true) : null;
}

if (!is_array($questions) || empty($questions)) {
    error_log("[generate_exam] ❌ Failed to parse JSON questions from Groq response");
    error_log("[generate_exam] Raw response: " . $rawJson);
    respondJson(['success' => false, 'error' => 'Could not parse questions from AI response. Please try again.']);
}

$qCount    = count($questions);
$qJson     = json_encode($questions);
$primaryId = $file_rows[0]['id'];
$fileName  = implode(', ', $file_names);

error_log("[generate_exam] Parsed $qCount questions for file(s): $fileName");

// ── Save to DB ────────────────────────────────────────────────
$stmt = $conn->prepare(
    "INSERT INTO ai_evaluations (user_id, file_id, course_id, questions_json, status)
     VALUES (?, ?, ?, ?, 'generated')"
);
$courseIdVal = $course_id > 0 ? $course_id : null;
$stmt->bind_param('iiis', $user_id, $primaryId, $courseIdVal, $qJson);
$stmt->execute();
$eval_id = $conn->insert_id;
$stmt->close();

logProgress($conn, $user_id, 'exam_taken', 'Questions generated: ' . $fileName, $course_id > 0 ? $course_id : 0);
error_log("[generate_exam] ✓ Saved eval_id=$eval_id, questions=$qCount, file='$fileName'");

respondJson([
    'success'   => true,
    'eval_id'   => $eval_id,
    'questions' => $questions,
    'file_name' => $fileName
]);
