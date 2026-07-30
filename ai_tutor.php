<?php
// ============================================================
// ai_tutor.php — NoteNest AI Platform
// AI Tutor Chat Interface
// ============================================================
require 'includes/auth.php';
require 'config.php';
require 'includes/ai_service.php';

$user_id = $_SESSION['user_id'];

// ── Handle AJAX Chat Request ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // ── SEND MESSAGE (RAG SYSTEM) ──────────────────────────────
    if ($action === 'send_message') {
        $message    = trim($_POST['message']   ?? '');
        $session_id = trim($_POST['session_id'] ?? '');
        $course_id  = (int)($_POST['course_id'] ?? 0);
        $folder_id  = (int)($_POST['folder_id'] ?? 0);
        $topic_id   = (int)($_POST['topic_id'] ?? 0);
        $file_ids   = isset($_POST['file_ids']) ? array_map('intval', $_POST['file_ids']) : [];
        $select_all = isset($_POST['select_all']) && $_POST['select_all'] == 1;

        if (!$message || !$session_id) {
            echo json_encode(['success' => false, 'error' => 'Message and session ID required.']);
            exit;
        }

        // Enforce File Selection: user must select files or check Select All
        $search_files = null;
        if (!$select_all && !empty($file_ids)) {
            $search_files = $file_ids;
        } elseif (!$select_all && empty($file_ids)) {
            // Strict Security Rule: if no files are selected, return exact mismatch response
            echo json_encode([
                'success' => true,
                'reply'   => "I couldn't find this information in the selected study materials.",
                'tokens'  => 0
            ]);
            exit;
        }

        // Retrieve top 5 matching chunks from database (ownership strictly validated inside search_document_chunks)
        $chunks = search_document_chunks(
            $conn, 
            $user_id, 
            $message, 
            $course_id ?: null, 
            $folder_id ?: null, 
            $topic_id ?: null, 
            $search_files, 
            5
        );

        if (empty($chunks)) {
            // No matching chunks, return strict response immediately (no LLM call)
            echo json_encode([
                'success' => true,
                'reply'   => "I couldn't find this information in the selected study materials.",
                'tokens'  => 0
            ]);
            exit;
        }

        // Build context block
        $context = "";
        $idx = 1;
        foreach ($chunks as $c) {
            $context .= "[Chunk {$idx}]\n";
            $context .= "Course: " . ($c['course_code'] ?: 'N/A') . " — " . ($c['course_name'] ?: 'N/A') . "\n";
            $context .= "Folder: " . ($c['folder_name'] ?: 'N/A') . "\n";
            $context .= "Topic: " . ($c['topic_name'] ?: 'N/A') . "\n";
            $context .= "File: " . ($c['file_name'] ?: 'N/A') . "\n";
            $context .= "Page: " . ($c['page_number'] ?: '1') . "\n";
            $context .= "Confidence: " . ($c['confidence_score'] ?: '100') . "%\n";
            $context .= "Content: " . $c['content'] . "\n\n";
            $idx++;
        }

        // Strict system prompt for NoteNest AI
        $systemPrompt = "You are NoteNest AI Tutor.
Your knowledge is LIMITED ONLY to the provided context.
Never answer using your own knowledge.
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

        // Load recent conversation history (last 10 turns)
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
                'role' => $row['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $row['message']
            ];
        }
        $hq->close();

        // Save user question to DB
        saveAiChat($conn, $user_id, $session_id, 'user', $message, 'tutor', $course_id);

        // Prepare messages for Groq API
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $h) {
            $messages[] = ['role' => $h['role'], 'content' => $h['content']];
        }
        $fullUserMsg = "STUDENT QUESTION: {$message}\n\nRETRIEVED STUDY MATERIAL CONTEXT:\n{$context}";
        $messages[] = ['role' => 'user', 'content' => $fullUserMsg];

        // Call Groq (Low temperature for precise retrieval)
        $aiResult = grokRequest($messages, GROQ_MODEL, 0.1);

        if (!$aiResult['success']) {
            echo json_encode(['success' => false, 'error' => $aiResult['error']]);
            exit;
        }

        $aiReply = trim($aiResult['text']);
        $tokens  = $aiResult['tokens'];

        // Format Answer & Citation block
        if ($aiReply !== "I couldn't find this information in the selected study materials.") {
            $bestChunk = $chunks[0];
            $courseStr = ($bestChunk['course_code'] ?: 'N/A') . ' — ' . ($bestChunk['course_name'] ?: 'N/A');
            $topicStr  = $bestChunk['topic_name'] ?: 'General';
            $fileStr   = $bestChunk['file_name'] ?: 'N/A';
            $pageStr   = $bestChunk['page_number'] ?: '1';
            $confStr   = ($bestChunk['confidence_score'] ?: '100') . '%';
            
            $aiReply = "Answer\n\n" . $aiReply . "\n\nSource\nCourse: " . $courseStr . "\nTopic: " . $topicStr . "\nFile: " . $fileStr . "\nPage: " . $pageStr . "\nConfidence: " . $confStr;
        }

        // Save AI response to DB
        saveAiChat($conn, $user_id, $session_id, 'assistant', $aiReply, 'tutor', $course_id, $tokens);

        // Log progress
        logProgress($conn, $user_id, 'ai_chat', 'Private AI Tutor session', $course_id);

        echo json_encode([
            'success' => true,
            'reply'   => $aiReply,
            'tokens'  => $tokens
        ]);
        exit;
    }

    // ── GET FOLDERS ───────────────────────────────────────────
    if ($action === 'get_folders') {
        $course_id = (int)($_POST['course_id'] ?? 0);
        $topic_id  = (int)($_POST['topic_id'] ?? 0);
        
        $target_folder_id = 0;
        if ($topic_id > 0) {
            $tstmt = $conn->prepare("SELECT folder_id FROM course_topics WHERE id = ?");
            $tstmt->bind_param('i', $topic_id);
            $tstmt->execute();
            $tstmt->bind_result($fId);
            if ($tstmt->fetch()) {
                $target_folder_id = (int)$fId;
            }
            $tstmt->close();
        }

        if ($course_id > 0) {
            $stmt = $conn->prepare("SELECT id, name FROM folders WHERE course_id = ? AND owner_id = ?");
            $stmt->bind_param('ii', $course_id, $user_id);
        } else {
            $stmt = $conn->prepare("SELECT id, name FROM folders WHERE owner_id = ?");
            $stmt->bind_param('i', $user_id);
        }
        $stmt->execute();
        $folders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['success' => true, 'folders' => $folders, 'target_folder_id' => $target_folder_id]);
        exit;
    }

    // ── GET TOPICS ────────────────────────────────────────────
    if ($action === 'get_topics') {
        $course_id = (int)($_POST['course_id'] ?? 0);
        if ($course_id > 0) {
            $stmt = $conn->prepare("SELECT id, title FROM course_topics WHERE course_id = ?");
            $stmt->bind_param('i', $course_id);
        } else {
            $stmt = $conn->prepare("SELECT ct.id, ct.title FROM course_topics ct JOIN courses c ON ct.course_id = c.id WHERE c.user_id = ?");
            $stmt->bind_param('i', $user_id);
        }
        $stmt->execute();
        $topics = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['success' => true, 'topics' => $topics]);
        exit;
    }

    // ── GET FILES ─────────────────────────────────────────────
    if ($action === 'get_files') {
        $course_id = (int)($_POST['course_id'] ?? 0);
        $folder_id = (int)($_POST['folder_id'] ?? 0);
        $topic_id  = (int)($_POST['topic_id'] ?? 0);

        $sql = "SELECT DISTINCT f.id, f.name FROM files f
                LEFT JOIN file_course_tags fct ON fct.file_id = f.id
                LEFT JOIN folders fo ON f.folder_id = fo.id
                WHERE f.owner_id = ?";
        $params = [$user_id];
        $types = 'i';

        if ($course_id > 0) {
            $sql .= " AND (f.course_id = ? OR fct.course_id = ? OR fo.course_id = ?)";
            $params[] = $course_id;
            $params[] = $course_id;
            $params[] = $course_id;
            $types .= 'iii';
        }
        if ($folder_id > 0) {
            $sql .= " AND f.folder_id = ?";
            $params[] = $folder_id;
            $types .= 'i';
        }
        if ($topic_id > 0) {
            $sql .= " AND fct.topic_id = ?";
            $params[] = $topic_id;
            $types .= 'i';
        }

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['success' => true, 'files' => $files]);
        exit;
    }

    // ── NEW SESSION ───────────────────────────────────────────
    if ($action === 'new_session') {
        echo json_encode(['success' => true, 'session_id' => bin2hex(random_bytes(16))]);
        exit;
    }

    // ── LOAD SESSION HISTORY ──────────────────────────────────
    if ($action === 'load_session') {
        $session_id = trim($_POST['session_id'] ?? '');
        if (!$session_id) { echo json_encode(['success'=>false]); exit; }

        $hq = $conn->prepare(
            "SELECT role, message, created_at FROM ai_chat_history
             WHERE user_id=? AND session_id=?
             ORDER BY created_at ASC"
        );
        $hq->bind_param('is', $user_id, $session_id);
        $hq->execute();
        $msgs = $hq->get_result()->fetch_all(MYSQLI_ASSOC);
        $hq->close();
        echo json_encode(['success' => true, 'messages' => $msgs]);
        exit;
    }

    // ── GET PAST SESSIONS ─────────────────────────────────────
    if ($action === 'get_sessions') {
        $sq = $conn->prepare(
            "SELECT session_id,
                    MIN(message) AS first_msg,
                    MAX(created_at) AS last_at,
                    COUNT(*) AS msg_count
             FROM ai_chat_history
             WHERE user_id=? AND role='user'
             GROUP BY session_id
             ORDER BY last_at DESC
             LIMIT 15"
        );
        $sq->bind_param('i', $user_id);
        $sq->execute();
        $sessions = $sq->get_result()->fetch_all(MYSQLI_ASSOC);
        $sq->close();
        echo json_encode(['success' => true, 'sessions' => $sessions]);
        exit;
    }

    // ── DELETE SESSION ────────────────────────────────────────
    if ($action === 'delete_session') {
        $session_id = trim($_POST['session_id'] ?? '');
        $dq = $conn->prepare("DELETE FROM ai_chat_history WHERE user_id=? AND session_id=?");
        $dq->bind_param('is', $user_id, $session_id);
        $dq->execute();
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Load courses for selector ─────────────────────────────────
$courses = [];
$cq = $conn->prepare("SELECT id, name, code, color FROM courses WHERE user_id=? ORDER BY code ASC");
$cq->bind_param('i', $user_id);
$cq->execute();
$courses = $cq->get_result()->fetch_all(MYSQLI_ASSOC);
$cq->close();

// Generate initial session ID
$initial_session = bin2hex(random_bytes(16));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Tutor — NoteNest AI</title>
    <link rel="shortcut icon" href="img/fav.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Marked.js for Markdown rendering -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
        :root {
            --primary:   #0b4954;
            --accent:    #197f8f;
            --ai-bubble: #f0f7f9;
            --user-bubble: linear-gradient(135deg, #0b4954, #197f8f);
            --sidebar-w: 280px;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f4f8;
            margin: 0;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* ── Navbar sits at top ── */
        .navbar-wrap { flex-shrink: 0; }

        /* ── Main layout ── */
        .chat-layout {
            display: flex;
            flex: 1;
            overflow: hidden;
            height: calc(100vh - 62px);
        }

        /* ══════════════════════════
           SIDEBAR
        ══════════════════════════ */
        .sidebar {
            width: var(--sidebar-w);
            background: #fff;
            border-right: 1px solid #e8edf2;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        .sidebar-header {
            padding: 18px 16px 12px;
            border-bottom: 1px solid #f0f2f5;
        }
        .sidebar-header h6 {
            font-weight: 700;
            color: var(--primary);
            margin: 0 0 12px;
            font-size: .82rem;
            letter-spacing: .8px;
            text-transform: uppercase;
        }
        .btn-new-chat {
            width: 100%;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 9px 14px;
            font-size: .88rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity .2s, transform .15s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-new-chat:hover { opacity: .9; transform: translateY(-1px); }

        /* Course selector */
        .course-selector {
            padding: 12px 16px;
            border-bottom: 1px solid #f0f2f5;
        }
        .course-selector label {
            font-size: .75rem;
            font-weight: 600;
            color: #888;
            letter-spacing: .5px;
            text-transform: uppercase;
            display: block;
            margin-bottom: 6px;
        }
        .course-selector select {
            width: 100%;
            border: 1px solid #dde2e8;
            border-radius: 8px;
            padding: 6px 10px;
            font-size: .84rem;
            color: #333;
            background: #f8fafb;
        }
        .course-selector select:focus { outline: none; border-color: var(--accent); }

        /* Sessions list */
        .sessions-list {
            flex: 1;
            overflow-y: auto;
            padding: 10px 8px;
        }
        .sessions-label {
            font-size: .72rem;
            font-weight: 700;
            color: #aaa;
            letter-spacing: .8px;
            text-transform: uppercase;
            padding: 4px 8px 8px;
        }
        .session-item {
            padding: 10px 12px;
            border-radius: 10px;
            cursor: pointer;
            transition: background .15s;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 2px;
        }
        .session-item:hover, .session-item.active { background: #f0f7f9; }
        .session-item.active { background: #e4f2f6; }
        .session-icon {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, #0b4954, #197f8f);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .session-icon i { color: #fff; font-size: .75rem; }
        .session-info { flex: 1; min-width: 0; }
        .session-preview {
            font-size: .82rem;
            color: #333;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .session-time { font-size: .72rem; color: #aaa; margin-top: 2px; }
        .session-del {
            background: none; border: none; color: #ccc;
            padding: 0; cursor: pointer; font-size: .8rem;
            transition: color .15s;
            flex-shrink: 0;
        }
        .session-del:hover { color: #e74c3c; }
        .sessions-empty {
            text-align: center;
            padding: 30px 10px;
            color: #ccc;
            font-size: .82rem;
        }
        .sessions-empty i { font-size: 28px; display: block; margin-bottom: 8px; }

        /* ══════════════════════════
           MAIN CHAT AREA
        ══════════════════════════ */
        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Chat Header */
        .chat-header {
            background: #fff;
            border-bottom: 1px solid #e8edf2;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            gap: 14px;
            flex-shrink: 0;
        }
        .ai-avatar {
            width: 42px; height: 42px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .ai-avatar i { color: #fff; font-size: 1.1rem; }
        .ai-info h5 { margin: 0; font-weight: 700; color: var(--primary); font-size: .95rem; }
        .ai-info span { font-size: .78rem; color: #27ae60; font-weight: 500; }
        .token-badge {
            margin-left: auto;
            background: #f0f7f9;
            border-radius: 20px;
            padding: 4px 12px;
            font-size: .75rem;
            color: var(--accent);
            font-weight: 600;
        }

        /* Messages area */
        .messages-area {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        /* Welcome screen */
        .welcome-screen {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 40px;
            color: #aaa;
        }
        .welcome-screen .ai-logo {
            width: 80px; height: 80px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 8px 30px rgba(11,73,84,.2);
        }
        .welcome-screen .ai-logo i { font-size: 2.2rem; color: #fff; }
        .welcome-screen h4 { color: var(--primary); font-weight: 700; margin-bottom: 8px; }
        .welcome-screen p { color: #888; font-size: .92rem; max-width: 400px; margin: 0 auto 24px; }
        .suggestions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; max-width: 560px; }
        .suggestion-chip {
            background: #fff;
            border: 1px solid #dde2e8;
            border-radius: 20px;
            padding: 8px 16px;
            font-size: .83rem;
            color: #444;
            cursor: pointer;
            transition: all .2s;
            text-align: left;
        }
        .suggestion-chip:hover {
            border-color: var(--accent);
            background: var(--ai-bubble);
            color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(11,73,84,.1);
        }

        /* Message bubbles */
        .msg-row { display: flex; gap: 10px; align-items: flex-end; animation: msgFadeIn .3s ease; }
        @keyframes msgFadeIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
        .msg-row.user { flex-direction: row-reverse; }

        .msg-avatar {
            width: 34px; height: 34px;
            border-radius: 10px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .85rem;
        }
        .msg-avatar.ai-av { background: linear-gradient(135deg, var(--primary), var(--accent)); }
        .msg-avatar.ai-av i { color: #fff; }
        .msg-avatar.user-av {
            background: #e8edf2;
            overflow: hidden;
        }
        .msg-avatar.user-av img { width: 100%; height: 100%; object-fit: cover; border-radius: 10px; }

        .bubble {
            max-width: 72%;
            border-radius: 16px;
            padding: 12px 16px;
            font-size: .9rem;
            line-height: 1.65;
            position: relative;
        }
        .bubble.ai {
            background: #fff;
            color: #2c3e50;
            border-bottom-left-radius: 4px;
            box-shadow: 0 2px 10px rgba(0,0,0,.06);
        }
        .bubble.user {
            background: linear-gradient(135deg, #0b4954, #197f8f);
            color: #fff;
            border-bottom-right-radius: 4px;
        }
        .bubble-time {
            font-size: .68rem;
            color: #bbb;
            margin-top: 4px;
            text-align: right;
        }
        .bubble.user .bubble-time { color: rgba(255,255,255,.6); }

        /* Markdown in AI bubble */
        .bubble.ai h1,.bubble.ai h2,.bubble.ai h3 { font-size: 1rem; font-weight: 700; color: var(--primary); margin: 8px 0 4px; }
        .bubble.ai p  { margin: 0 0 8px; }
        .bubble.ai p:last-child { margin-bottom: 0; }
        .bubble.ai ul,.bubble.ai ol { padding-left: 18px; margin: 4px 0 8px; }
        .bubble.ai li { margin-bottom: 3px; }
        .bubble.ai code {
            background: #f0f4f8;
            border-radius: 4px;
            padding: 1px 5px;
            font-size: .85em;
            color: var(--primary);
            font-family: 'Courier New', monospace;
        }
        .bubble.ai pre {
            background: #1e2a35;
            border-radius: 8px;
            padding: 12px;
            overflow-x: auto;
            margin: 8px 0;
        }
        .bubble.ai pre code {
            background: none;
            color: #a8d8e8;
            font-size: .82rem;
            padding: 0;
        }
        .bubble.ai strong { color: var(--primary); }
        .bubble.ai blockquote {
            border-left: 3px solid var(--accent);
            margin: 8px 0;
            padding-left: 12px;
            color: #555;
            font-style: italic;
        }

        /* Typing indicator */
        .typing-bubble {
            background: #fff;
            border-radius: 16px 16px 16px 4px;
            padding: 14px 18px;
            box-shadow: 0 2px 10px rgba(0,0,0,.06);
        }
        .typing-dots { display: flex; gap: 4px; align-items: center; }
        .typing-dots span {
            width: 7px; height: 7px;
            background: #bbb;
            border-radius: 50%;
            animation: bounce 1.2s infinite;
        }
        .typing-dots span:nth-child(2) { animation-delay: .2s; }
        .typing-dots span:nth-child(3) { animation-delay: .4s; }
        @keyframes bounce {
            0%,80%,100% { transform: translateY(0); }
            40% { transform: translateY(-8px); background: var(--accent); }
        }

        /* ── Input Area ── */
        .input-area {
            background: #fff;
            border-top: 1px solid #e8edf2;
            padding: 16px 24px;
            flex-shrink: 0;
        }
        .input-box {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            background: #f8fafb;
            border: 1.5px solid #dde2e8;
            border-radius: 14px;
            padding: 10px 10px 10px 16px;
            transition: border-color .2s;
        }
        .input-box:focus-within { border-color: var(--accent); }
        #chatInput {
            flex: 1;
            border: none;
            background: none;
            font-size: .92rem;
            color: #333;
            resize: none;
            max-height: 120px;
            outline: none;
            font-family: 'Inter', sans-serif;
            line-height: 1.5;
        }
        #chatInput::placeholder { color: #bbb; }
        .btn-send {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border: none;
            border-radius: 10px;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform .15s, opacity .2s;
            flex-shrink: 0;
        }
        .btn-send:hover:not(:disabled) { transform: scale(1.08); }
        .btn-send:disabled { opacity: .5; cursor: default; }
        .input-hint {
            text-align: center;
            font-size: .72rem;
            color: #ccc;
            margin-top: 8px;
        }

        /* Scrollbar */
        .messages-area::-webkit-scrollbar,
        .sessions-list::-webkit-scrollbar { width: 4px; }
        .messages-area::-webkit-scrollbar-thumb,
        .sessions-list::-webkit-scrollbar-thumb { background: #ddd; border-radius: 4px; }

        @media (max-width: 768px) {
            .sidebar { display: none; }
        }
    </style>
</head>
<body>

<!-- Navbar -->
<div class="navbar-wrap">
    <?php include 'includes/navbar.php'; ?>
</div>

<div class="chat-layout">

    <!-- ══════════════ SIDEBAR ══════════════ -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h6><i class="fas fa-robot me-1"></i> AI Tutor</h6>
            <button class="btn-new-chat" id="btnNewChat">
                <i class="fas fa-plus"></i> New Conversation
            </button>
        </div>

        <!-- Course Selector -->
        <div class="course-selector">
            <label><i class="fas fa-graduation-cap me-1"></i> Course</label>
            <select id="courseSelect">
                <option value="0">🎓 Select Course...</option>
                <?php foreach ($courses as $c): ?>
                <option value="<?php echo $c['id']; ?>">
                    <?php echo htmlspecialchars($c['code'] . ' — ' . $c['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Topic Selector -->
        <div class="course-selector mt-2">
            <label><i class="fas fa-tag me-1"></i> Topic</label>
            <select id="topicSelect" disabled>
                <option value="0">🏷️ Select Topic...</option>
            </select>
        </div>

        <!-- Folder Selector -->
        <div class="course-selector mt-2">
            <label><i class="fas fa-folder me-1"></i> Folder</label>
            <select id="folderSelect" disabled>
                <option value="0">📁 Select Folder...</option>
            </select>
        </div>

        <!-- Document Search Scope -->
        <div class="course-selector mt-3 mb-2" style="border-top: 1px solid #eee; padding-top: 12px;">
            <label class="d-flex align-items-center justify-content-between mb-2" style="font-size: 0.8rem; font-weight: 600; color: var(--primary);">
                <span><i class="fas fa-file-pdf me-1"></i> Study Materials</span>
                <span class="small text-muted" id="fileCountBadge">0 selected</span>
            </label>
            
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="selectAllFiles" disabled>
                <label class="form-check-label fw-bold text-dark small" for="selectAllFiles">
                    Select All Files
                </label>
            </div>
            
            <div class="file-list-container" id="fileListContainer" style="max-height: 150px; overflow-y: auto; border: 1.5px solid #dde2e8; border-radius: 8px; padding: 8px; background: #fff; display:none;">
                <!-- Files loaded dynamically via JS -->
            </div>
            <div class="text-muted text-center py-2 small bg-white border rounded" id="noFilesMsg" style="font-size: 0.78rem;">
                Please select a course to list documents.
            </div>
        </div>

        <!-- Past Sessions -->
        <div class="sessions-list" id="sessionsList">
            <div class="sessions-label">Recent Conversations</div>
            <div class="sessions-empty" id="sessionsEmpty">
                <i class="fas fa-comments"></i>
                No conversations yet
            </div>
        </div>
    </div>

    <!-- ══════════════ MAIN CHAT ══════════════ -->
    <div class="chat-main">

        <!-- Chat Header -->
        <div class="chat-header">
            <div class="ai-avatar"><i class="fas fa-robot"></i></div>
            <div class="ai-info">
                <h5>NoteNest AI Tutor</h5>
                <span><i class="fas fa-circle" style="font-size:.6rem;"></i> Powered by Gemini 2.5 Flash</span>
            </div>
            <div class="token-badge" id="tokenBadge">
                <i class="fas fa-bolt"></i> Ready
            </div>
        </div>

        <!-- Messages -->
        <div class="messages-area" id="messagesArea">
            <!-- Welcome Screen -->
            <div class="welcome-screen" id="welcomeScreen">
                <div class="ai-logo"><i class="fas fa-robot"></i></div>
                <h4>Your AI Academic Tutor</h4>
                <p>Ask me anything about your studies. I can explain concepts, solve problems, generate examples, and help you prepare for exams.</p>
                <div class="suggestions">
                    <div class="suggestion-chip" onclick="useSuggestion('Explain polymorphism in OOP with a real-world example')">
                        💡 Explain polymorphism in OOP
                    </div>
                    <div class="suggestion-chip" onclick="useSuggestion('What is the difference between stack and queue data structures?')">
                        📚 Stack vs Queue data structures
                    </div>
                    <div class="suggestion-chip" onclick="useSuggestion('Give me a step-by-step explanation of how quicksort works')">
                        🔢 How does quicksort work?
                    </div>
                    <div class="suggestion-chip" onclick="useSuggestion('Create a 5-question quiz on database normalization')">
                        📝 Quiz me on DB normalization
                    </div>
                    <div class="suggestion-chip" onclick="useSuggestion('What are the SOLID principles in software engineering?')">
                        🏗️ SOLID principles explained
                    </div>
                    <div class="suggestion-chip" onclick="useSuggestion('Summarize the key concepts of computer networks in bullet points')">
                        🌐 Computer networks summary
                    </div>
                </div>
            </div>
        </div>

        <!-- Input Area -->
        <div class="input-area">
            <div class="input-box">
                <textarea id="chatInput" rows="1" placeholder="Ask your academic question..."></textarea>
                <button class="btn-send" id="btnSend" title="Send (Enter)">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
            <div class="input-hint">Press <kbd>Enter</kbd> to send &nbsp;·&nbsp; <kbd>Shift+Enter</kbd> for new line</div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
// ============================================================
// AI Tutor — Frontend Logic
// ============================================================

// Configure marked.js
marked.setOptions({ breaks: true, gfm: true });

let currentSession = '<?php echo $initial_session; ?>';
let isLoading = false;
let totalTokens = 0;

const userPhoto = '<?php echo htmlspecialchars($_SESSION["user_photo"] ?? "img/user.png"); ?>';

// ── Init ──────────────────────────────────────────────────────
$(document).ready(function() {
    loadSessions();
    autoResize($('#chatInput')[0]);
});

// ── Auto-resize textarea ─────────────────────────────────────
function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}
$('#chatInput').on('input', function() { autoResize(this); });

// ── Send on Enter ─────────────────────────────────────────────
$('#chatInput').on('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
});
$('#btnSend').on('click', sendMessage);

// ── RAG UI Selectors Change Handlers ───────────────────────────
$('#courseSelect').on('change', function() {
    const courseId = $(this).val();
    if (courseId == 0) {
        $('#topicSelect').val('0').html('<option value="0">🏷️ Select Topic...</option>').prop('disabled', true);
        $('#folderSelect').val('0').html('<option value="0">📁 Select Folder...</option>').prop('disabled', true);
        $('#selectAllFiles').prop('checked', false).prop('disabled', true);
        $('#fileListContainer').hide().empty();
        $('#noFilesMsg').html('Please select a course to list documents.').show();
        updateFileCount();
        return;
    }

    // Load topics
    $.post('ai_tutor.php', { action: 'get_topics', course_id: courseId }, function(res) {
        if (res.success) {
            let html = '<option value="0">🏷️ Select Topic...</option>';
            res.topics.forEach(t => {
                html += `<option value="${t.id}">${escHtml(t.title)}</option>`;
            });
            $('#topicSelect').html(html).prop('disabled', false);
        }
    }, 'json');

    // Clear folder dropdown & load files of the course
    $('#folderSelect').val('0').html('<option value="0">📁 Select Folder...</option>').prop('disabled', true);
    loadFiles();
});

$('#topicSelect').on('change', function() {
    const topicId = $(this).val();
    const courseId = $('#courseSelect').val();
    
    if (topicId == 0) {
        $('#folderSelect').val('0').html('<option value="0">📁 Select Folder...</option>').prop('disabled', true);
        loadFiles();
        return;
    }

    // Load folders corresponding to this course/topic
    $.post('ai_tutor.php', { action: 'get_folders', course_id: courseId, topic_id: topicId }, function(res) {
        if (res.success) {
            let html = '<option value="0">📁 Select Folder...</option>';
            res.folders.forEach(f => {
                html += `<option value="${f.id}">${escHtml(f.name)}</option>`;
            });
            $('#folderSelect').html(html).prop('disabled', false);
            
            // Auto-select linked folder if available
            if (res.target_folder_id > 0) {
                $('#folderSelect').val(res.target_folder_id);
            }
        }
        loadFiles();
    }, 'json');
});

$('#folderSelect').on('change', function() {
    loadFiles();
});

function loadFiles() {
    const courseId = $('#courseSelect').val();
    const topicId = $('#topicSelect').val() || 0;
    const folderId = $('#folderSelect').val() || 0;

    $.post('ai_tutor.php', {
        action: 'get_files',
        course_id: courseId,
        topic_id: topicId,
        folder_id: folderId
    }, function(res) {
        if (res.success) {
            $('#fileListContainer').empty();
            if (res.files.length > 0) {
                res.files.forEach(f => {
                    const item = $(`
                        <div class="form-check text-start mb-1" style="font-size: 0.8rem;">
                            <input class="form-check-input file-chk" type="checkbox" value="${f.id}" id="file_${f.id}">
                            <label class="form-check-label text-dark text-truncate d-inline-block w-100 mb-0" for="file_${f.id}" title="${escHtml(f.name)}" style="cursor:pointer; max-width: 90%;">
                                ${escHtml(f.name)}
                            </label>
                        </div>
                    `);
                    $('#fileListContainer').append(item);
                });
                $('#fileListContainer').show();
                $('#noFilesMsg').hide();
                $('#selectAllFiles').prop('disabled', false).prop('checked', true);
                $('.file-chk').prop('checked', true);
            } else {
                $('#fileListContainer').hide();
                $('#noFilesMsg').html('No files found for this criteria.').show();
                $('#selectAllFiles').prop('disabled', true).prop('checked', false);
            }
            updateFileCount();
        }
    }, 'json');
}

// Check/Uncheck all files
$('#selectAllFiles').on('change', function() {
    const checked = $(this).is(':checked');
    $('.file-chk').prop('checked', checked);
    updateFileCount();
});

// Individual file checkbox change
$(document).on('change', '.file-chk', function() {
    const total = $('.file-chk').length;
    const checked = $('.file-chk:checked').length;
    $('#selectAllFiles').prop('checked', total === checked);
    updateFileCount();
});

function updateFileCount() {
    const checked = $('.file-chk:checked').length;
    $('#fileCountBadge').text(`${checked} selected`);
}

// ── Send Message ──────────────────────────────────────────────
function sendMessage() {
    const msg = $('#chatInput').val().trim();
    if (!msg || isLoading) return;

    const courseId = $('#courseSelect').val();
    const topicId = $('#topicSelect').val() || 0;
    const folderId = $('#folderSelect').val() || 0;
    const selectAll = $('#selectAllFiles').is(':checked') ? 1 : 0;

    // Get checked file IDs
    const fileIds = [];
    $('.file-chk:checked').each(function() {
        fileIds.push($(this).val());
    });

    // Validation: Require file selection or Select All
    if (courseId == 0) {
        alert('Please select a course to scope the AI tutor context.');
        return;
    }
    if (!selectAll && fileIds.length === 0) {
        alert('Please select at least one study material file or check "Select All Files" before asking.');
        return;
    }

    // Hide welcome screen
    $('#welcomeScreen').hide();

    // Add user bubble
    appendBubble('user', msg);
    $('#chatInput').val('').css('height', 'auto');

    // Show typing indicator
    showTyping();
    setLoading(true);

    $.post('ai_tutor.php', {
        action:     'send_message',
        message:    msg,
        session_id: currentSession,
        course_id:  courseId,
        topic_id:   topicId,
        folder_id:  folderId,
        select_all: selectAll,
        file_ids:   fileIds
    }, function(res) {
        hideTyping();
        setLoading(false);

        if (res.success) {
            appendBubble('ai', res.reply);
            totalTokens += (res.tokens || 0);
            $('#tokenBadge').html(`<i class="fas fa-bolt"></i> ${totalTokens.toLocaleString()} tokens`);
            loadSessions(); // refresh sidebar
        } else {
            appendBubble('ai', '⚠️ **Error:** ' + (res.error || 'Something went wrong. Please try again.'));
        }
    }, 'json').fail(function() {
        hideTyping();
        setLoading(false);
        appendBubble('ai', '⚠️ **Network error.** Please check your connection and try again.');
    });
}

// ── Append bubble ─────────────────────────────────────────────
function appendBubble(role, text) {
    const isAI   = role === 'ai' || role === 'assistant';
    const time   = new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
    const avatar = isAI
        ? `<div class="msg-avatar ai-av"><i class="fas fa-robot"></i></div>`
        : `<div class="msg-avatar user-av"><img src="${userPhoto}" onerror="this.src='img/user.png'"></div>`;

    const content = isAI ? marked.parse(text) : escHtml(text).replace(/\n/g, '<br>');

    const html = `<div class="msg-row ${isAI ? 'ai' : 'user'}">
        ${avatar}
        <div>
            <div class="bubble ${isAI ? 'ai' : 'user'}">${content}</div>
            <div class="bubble-time">${time}</div>
        </div>
    </div>`;

    $('#messagesArea').append(html);
    scrollBottom();
}

// ── Typing indicator ──────────────────────────────────────────
function showTyping() {
    const html = `<div class="msg-row ai" id="typingRow">
        <div class="msg-avatar ai-av"><i class="fas fa-robot"></i></div>
        <div class="typing-bubble">
            <div class="typing-dots">
                <span></span><span></span><span></span>
            </div>
        </div>
    </div>`;
    $('#messagesArea').append(html);
    scrollBottom();
}
function hideTyping() { $('#typingRow').remove(); }

// ── Loading state ─────────────────────────────────────────────
function setLoading(state) {
    isLoading = state;
    $('#btnSend').prop('disabled', state);
    $('#chatInput').prop('disabled', state);
}

// ── Scroll to bottom ──────────────────────────────────────────
function scrollBottom() {
    const area = document.getElementById('messagesArea');
    area.scrollTop = area.scrollHeight;
}

// ── New Chat ──────────────────────────────────────────────────
$('#btnNewChat').on('click', function() {
    $.post('ai_tutor.php', { action: 'new_session' }, function(res) {
        if (res.success) {
            currentSession = res.session_id;
            totalTokens = 0;
            $('#tokenBadge').html('<i class="fas fa-bolt"></i> Ready');
            // Clear messages, show welcome
            $('#messagesArea').html(`<div class="welcome-screen" id="welcomeScreen">
                <div class="ai-logo"><i class="fas fa-robot"></i></div>
                <h4>New Conversation Started</h4>
                <p>Ask me anything about your studies!</p>
            </div>`);
            $('.session-item').removeClass('active');
        }
    }, 'json');
});

// ── Load past sessions ────────────────────────────────────────
function loadSessions() {
    $.post('ai_tutor.php', { action: 'get_sessions' }, function(res) {
        if (!res.success || !res.sessions.length) {
            $('#sessionsEmpty').show();
            return;
        }
        $('#sessionsEmpty').hide();

        // Remove old items (keep the label)
        $('#sessionsList .session-item').remove();

        res.sessions.forEach(s => {
            const preview = s.first_msg.substring(0, 40) + (s.first_msg.length > 40 ? '…' : '');
            const date = new Date(s.last_at).toLocaleDateString([], {month:'short', day:'numeric'});
            const isActive = s.session_id === currentSession ? 'active' : '';

            const el = $(`<div class="session-item ${isActive}" data-sid="${s.session_id}">
                <div class="session-icon"><i class="fas fa-comment-dots"></i></div>
                <div class="session-info">
                    <div class="session-preview">${escHtml(preview)}</div>
                    <div class="session-time">${date} · ${s.msg_count} msgs</div>
                </div>
                <button class="session-del" onclick="deleteSession(event, '${s.session_id}')">
                    <i class="fas fa-times"></i>
                </button>
            </div>`);

            el.on('click', function() { loadSessionChat(s.session_id); });
            $('#sessionsList').append(el);
        });
    }, 'json');
}

// ── Load session chat ─────────────────────────────────────────
function loadSessionChat(sessionId) {
    currentSession = sessionId;
    $('.session-item').removeClass('active');
    $(`.session-item[data-sid="${sessionId}"]`).addClass('active');

    $.post('ai_tutor.php', { action: 'load_session', session_id: sessionId }, function(res) {
        if (!res.success) return;
        $('#messagesArea').empty();

        res.messages.forEach(m => {
            appendBubble(m.role, m.message);
        });
    }, 'json');
}

// ── Delete session ────────────────────────────────────────────
function deleteSession(e, sessionId) {
    e.stopPropagation();
    if (!confirm('Delete this conversation?')) return;

    $.post('ai_tutor.php', { action:'delete_session', session_id:sessionId }, function(res) {
        if (res.success) {
            $(`.session-item[data-sid="${sessionId}"]`).fadeOut(200, function() {
                $(this).remove();
                if (sessionId === currentSession) $('#btnNewChat').trigger('click');
            });
        }
    }, 'json');
}

// ── Suggestion chips ──────────────────────────────────────────
function useSuggestion(text) {
    $('#chatInput').val(text);
    sendMessage();
}

// ── HTML escape ───────────────────────────────────────────────
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
</body>
</html>
