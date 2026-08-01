<?php
// ============================================================
// generate_exam.php — NoteNest AI API Endpoint
// Generates AI exam questions from selected study file(s)
// ============================================================
require 'includes/auth.php';
require 'config.php';
require_once 'includes/ai_service.php';
require_once 'includes/db.php';

header('Content-Type: application/json');

$user_id    = $_SESSION['user_id'];
$file_id    = (int)($_REQUEST['file_id']    ?? 0);
$course_id  = (int)($_REQUEST['course_id']  ?? 0);
$topic_id   = (int)($_REQUEST['topic_id']   ?? 0);
$folder_id  = (int)($_REQUEST['folder_id']  ?? 0);
$q_types    = trim($_REQUEST['q_types']     ?? '5 MCQ and 5 short answer');
$difficulty = trim($_REQUEST['difficulty']  ?? 'medium');

// Accept file_ids as an array or a comma-separated string
$file_ids = [];
if (isset($_REQUEST['file_ids'])) {
    if (is_array($_REQUEST['file_ids'])) {
        $file_ids = array_values(array_filter(array_map('intval', $_REQUEST['file_ids'])));
    } elseif ($_REQUEST['file_ids'] !== '') {
        $file_ids = array_values(array_filter(array_map('intval', explode(',', $_REQUEST['file_ids']))));
    }
}
// If single file_id provided, merge it in
if ($file_id > 0 && !in_array($file_id, $file_ids)) {
    $file_ids[] = $file_id;
}

error_log("[NoteNest generate_exam] ▶ START");
error_log("[NoteNest generate_exam] user_id=$user_id, file_id=$file_id");
error_log("[NoteNest generate_exam] file_ids=" . json_encode($file_ids));
error_log("[NoteNest generate_exam] course_id=$course_id, topic_id=$topic_id, folder_id=$folder_id");
error_log("[NoteNest generate_exam] q_types='$q_types', difficulty='$difficulty'");

if (empty($file_ids)) {
    error_log("[NoteNest generate_exam] ERROR: No file_ids provided");
    echo json_encode(['success' => false, 'error' => 'Please select at least one study material file.']);
    exit;
}

// ── Validate ownership and collect content ────────────────────
$allFileIds  = array_unique($file_ids);
$content     = "";
$file_names  = [];

foreach ($allFileIds as $fId) {
    // Verify file ownership
    $fq = $conn->prepare("SELECT name, file_path, mime_type FROM files WHERE id = ? AND owner_id = ?");
    $fq->bind_param('ii', $fId, $user_id);
    $fq->execute();
    $fileRow = $fq->get_result()->fetch_assoc();
    $fq->close();

    if (!$fileRow) {
        error_log("[NoteNest generate_exam] WARNING: file_id=$fId not found or not owned by user_id=$user_id — skipped");
        continue;
    }
    $file_names[] = $fileRow['name'];
    error_log("[NoteNest generate_exam] Processing file_id=$fId, name='{$fileRow['name']}'");

    // Retrieve chunk content from document_chunks
    $cstmt = $conn->prepare("SELECT content FROM document_chunks WHERE file_id = ? AND user_id = ? ORDER BY chunk_index");
    $cstmt->bind_param('ii', $fId, $user_id);
    $cstmt->execute();
    $cres        = $cstmt->get_result();
    $fileContent = "";
    while ($row = $cres->fetch_assoc()) {
        $fileContent .= $row['content'] . "\n";
    }
    $cstmt->close();
    error_log("[NoteNest generate_exam] file_id=$fId chunks content length=" . strlen($fileContent));

    // Auto-index if empty
    if (empty(trim($fileContent))) {
        error_log("[NoteNest generate_exam] Auto-indexing file_id=$fId (no chunks in DB)");
        index_file_content($conn, $fId);

        $cstmt = $conn->prepare("SELECT content FROM document_chunks WHERE file_id = ? AND user_id = ? ORDER BY chunk_index");
        $cstmt->bind_param('ii', $fId, $user_id);
        $cstmt->execute();
        $cres        = $cstmt->get_result();
        $fileContent = "";
        while ($row = $cres->fetch_assoc()) {
            $fileContent .= $row['content'] . "\n";
        }
        $cstmt->close();
        error_log("[NoteNest generate_exam] After auto-index, file_id=$fId content length=" . strlen($fileContent));
    }

    if (!empty(trim($fileContent))) {
        $content .= "=== FILE: {$fileRow['name']} ===\n" . $fileContent . "\n\n";
    } else {
        error_log("[NoteNest generate_exam] WARNING: file_id=$fId has no extractable content even after indexing");
    }
}

if (strlen(trim($content)) < 30) {
    error_log("[NoteNest generate_exam] ERROR: Insufficient extractable text — total content length=" . strlen($content));
    echo json_encode(['success' => false, 'error' => 'The selected study material file(s) do not contain enough extractable text to generate questions.']);
    exit;
}

error_log("[NoteNest generate_exam] Total content to send to AI: " . strlen($content) . " chars from " . count($file_names) . " file(s): " . implode(', ', $file_names));

// ── Generate questions using Groq AI ─────────────────────────
error_log("[NoteNest generate_exam] Calling aiGenerateQuestions...");
$result = aiGenerateQuestions($content, $q_types, $difficulty);

if (!$result['success']) {
    error_log("[NoteNest generate_exam] aiGenerateQuestions Error: " . $result['error']);
    echo json_encode(['success' => false, 'error' => $result['error']]);
    exit;
}

$qJson     = $result['questions_json'];
$primaryId = $allFileIds[0];
$fileName  = implode(', ', $file_names);

$decodedQ  = json_decode($qJson, true);
$qCount    = is_array($decodedQ) ? count($decodedQ) : 0;
error_log("[NoteNest generate_exam] AI generated $qCount questions successfully");

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

error_log("[NoteNest generate_exam] ✓ Saved eval_id=$eval_id, file='$fileName', questions=$qCount");

echo json_encode([
    'success'   => true,
    'eval_id'   => $eval_id,
    'questions' => $decodedQ,
    'file_name' => $fileName
]);
exit;
