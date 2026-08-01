<?php
// ============================================================
// generate_answer.php — NoteNest AI API Endpoint
// AI Tutor Answer Generation strictly restricted to selected files
// ============================================================
require 'includes/auth.php';
require 'config.php';
require_once 'includes/ai_service.php';
require_once 'includes/db.php';

header('Content-Type: application/json');

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

error_log("[NoteNest generate_answer] ▶ START");
error_log("[NoteNest generate_answer] user_id=$user_id, session_id=$session_id");
error_log("[NoteNest generate_answer] course_id=$course_id, topic_id=$topic_id, folder_id=$folder_id");
error_log("[NoteNest generate_answer] file_ids=" . json_encode($file_ids) . ", select_all=" . ($select_all ? '1' : '0'));
error_log("[NoteNest generate_answer] message=" . substr($message, 0, 100));

if (!$message || !$session_id) {
    error_log("[NoteNest generate_answer] ERROR: Missing message or session_id");
    echo json_encode(['success' => false, 'error' => 'Message and session_id are required.']);
    exit;
}

// ── Enforce File Selection Policy ────────────────────────────
$search_files = null;

if ($select_all) {
    // select_all=1 means search all user files under course/topic/folder context
    $search_files = null;
    error_log("[NoteNest generate_answer] Mode: select_all=1 — no file filter applied");
} elseif (!empty($file_ids)) {
    $search_files = $file_ids;
    error_log("[NoteNest generate_answer] Mode: specific file_ids=" . implode(',', $file_ids));
} else {
    // No files selected and select_all is false — strict fallback
    error_log("[NoteNest generate_answer] No files selected and select_all=0 — returning strict fallback");
    echo json_encode([
        'success' => true,
        'reply'   => "I couldn't find this information in the selected study materials.",
        'tokens'  => 0
    ]);
    exit;
}

// ── Validate selected file_ids belong to this user ───────────
if (!empty($search_files)) {
    $validatedIds = [];
    foreach ($search_files as $fId) {
        $fcheck = $conn->prepare("SELECT id FROM files WHERE id = ? AND owner_id = ?");
        $fcheck->bind_param('ii', $fId, $user_id);
        $fcheck->execute();
        $fcheck->bind_result($validId);
        if ($fcheck->fetch()) {
            $validatedIds[] = $validId;
        } else {
            error_log("[NoteNest generate_answer] WARNING: file_id=$fId not owned by user_id=$user_id — excluded");
        }
        $fcheck->close();
    }
    $search_files = !empty($validatedIds) ? $validatedIds : null;
    if (empty($search_files)) {
        error_log("[NoteNest generate_answer] All file_ids failed ownership check — returning strict fallback");
        echo json_encode([
            'success' => true,
            'reply'   => "I couldn't find this information in the selected study materials.",
            'tokens'  => 0
        ]);
        exit;
    }
    error_log("[NoteNest generate_answer] Validated file_ids=" . json_encode($search_files));
}

// ── Auto-index any files not yet in document_chunks ──────────
if (!empty($search_files)) {
    foreach ($search_files as $fId) {
        $chkStmt = $conn->prepare("SELECT COUNT(*) FROM document_chunks WHERE file_id = ? AND user_id = ?");
        $chkStmt->bind_param('ii', $fId, $user_id);
        $chkStmt->execute();
        $chkStmt->bind_result($chunkCount);
        $chkStmt->fetch();
        $chkStmt->close();

        if ($chunkCount === 0) {
            error_log("[NoteNest generate_answer] Auto-indexing file_id=$fId for user_id=$user_id");
            index_file_content($conn, $fId);
        } else {
            error_log("[NoteNest generate_answer] file_id=$fId already indexed with $chunkCount chunks");
        }
    }
}

// ── Search document chunks (strictly scoped to selected files) ─
error_log("[NoteNest generate_answer] Calling search_document_chunks: course_id=$course_id, folder_id=$folder_id, topic_id=$topic_id, file_ids=" . json_encode($search_files));

$chunks = search_document_chunks(
    $conn,
    $user_id,
    $message,
    $course_id ?: null,
    $folder_id ?: null,
    $topic_id  ?: null,
    $search_files,
    5
);

error_log("[NoteNest generate_answer] Retrieved " . count($chunks) . " document chunks from search");

if (empty($chunks)) {
    error_log("[NoteNest generate_answer] No chunks found — returning strict fallback");
    echo json_encode([
        'success' => true,
        'reply'   => "I couldn't find this information in the selected study materials.",
        'tokens'  => 0
    ]);
    exit;
}

// ── Build context string from retrieved chunks ────────────────
$context = "";
$idx = 1;
foreach ($chunks as $c) {
    $context .= "[Chunk {$idx}]\n";
    $context .= "Course: "  . ($c['course_code'] ?: 'N/A') . " — " . ($c['course_name'] ?: 'N/A') . "\n";
    $context .= "Folder: "  . ($c['folder_name'] ?: 'N/A') . "\n";
    $context .= "Topic: "   . ($c['topic_name']  ?: 'N/A') . "\n";
    $context .= "File: "    . ($c['file_name']   ?: 'N/A') . "\n";
    $context .= "Page: "    . ($c['page_number'] ?: '1')   . "\n";
    $context .= "Confidence: " . ($c['confidence_score'] ?: '100') . "%\n";
    $context .= "Content: " . $c['content'] . "\n\n";
    $idx++;
}
error_log("[NoteNest generate_answer] Context built from " . ($idx - 1) . " chunks, total chars=" . strlen($context));

// ── System prompt — strictly prohibit outside knowledge ───────
$systemPrompt = "You are NoteNest AI Tutor.
Your knowledge is LIMITED ONLY to the provided context.
Never answer using your own outside knowledge.
Never answer from the internet.
Never guess.
Never fabricate.
Answer ONLY using the retrieved context.

Additional features instructions:
- AI SUMMARY: If the user requests a summary, summarize ONLY the provided context.
- FLASHCARDS: If the user requests flashcards, generate them ONLY from the provided context.
- VIVA: If the user requests a viva/oral exam, generate questions ONE-BY-ONE based ONLY on the provided context.

If the answer or topic is unavailable in the provided context, reply exactly:
\"I couldn't find this information in the selected study materials.\"";

// ── Load recent conversation history ─────────────────────────
$history = [];
$hq = $conn->prepare(
    "SELECT role, message FROM ai_chat_history
     WHERE user_id=? AND session_id=?
     ORDER BY created_at ASC LIMIT 20"
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
error_log("[NoteNest generate_answer] Loaded " . count($history) . " history messages for session_id=$session_id");

// ── Save user question to DB ──────────────────────────────────
saveAiChat($conn, $user_id, $session_id, 'user', $message, 'tutor', $course_id);

// ── Build API messages array ──────────────────────────────────
$messages   = [['role' => 'system', 'content' => $systemPrompt]];
foreach ($history as $h) {
    $messages[] = ['role' => $h['role'], 'content' => $h['content']];
}
$fullUserMsg = "STUDENT QUESTION: {$message}\n\nRETRIEVED STUDY MATERIAL CONTEXT:\n{$context}";
$messages[]  = ['role' => 'user', 'content' => $fullUserMsg];

// ── Call Groq AI ──────────────────────────────────────────────
error_log("[NoteNest generate_answer] Sending request to Groq AI...");
$aiResult = grokRequest($messages, GROQ_MODEL, 0.1);

if (!$aiResult['success']) {
    error_log("[NoteNest generate_answer] Groq AI Error: " . $aiResult['error']);
    echo json_encode(['success' => false, 'error' => $aiResult['error']]);
    exit;
}

$aiReply = trim($aiResult['text']);
$tokens  = $aiResult['tokens'];
error_log("[NoteNest generate_answer] AI response received, length=" . strlen($aiReply) . ", tokens=$tokens");

// ── Append source citation if answer found ────────────────────
if ($aiReply !== "I couldn't find this information in the selected study materials.") {
    $bestChunk = $chunks[0];
    $courseStr = ($bestChunk['course_code'] ?: 'N/A') . ' — ' . ($bestChunk['course_name'] ?: 'N/A');
    $topicStr  = $bestChunk['topic_name'] ?: 'General';
    $fileStr   = $bestChunk['file_name']  ?: 'N/A';
    $pageStr   = $bestChunk['page_number'] ?: '1';
    $confStr   = ($bestChunk['confidence_score'] ?: '100') . '%';

    $aiReply = "### Answer\n\n" . $aiReply . "\n\n---\n**Source:**\n- **Course:** " . $courseStr . "\n- **Topic:** " . $topicStr . "\n- **File:** " . $fileStr . "\n- **Page:** " . $pageStr . " (Confidence: " . $confStr . ")";
}

// ── Save AI response to DB ────────────────────────────────────
saveAiChat($conn, $user_id, $session_id, 'assistant', $aiReply, 'tutor', $course_id, $tokens);
logProgress($conn, $user_id, 'ai_chat', 'Private AI Tutor session', $course_id);

error_log("[NoteNest generate_answer] ✓ Response sent successfully, tokens=$tokens");

echo json_encode([
    'success' => true,
    'reply'   => $aiReply,
    'tokens'  => $tokens
]);
exit;
