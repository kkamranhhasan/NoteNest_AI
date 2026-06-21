<?php
// ============================================================
// ai_tutor.php — NoteNest AI Platform
// AI Tutor Chat Interface (Gemini 2.5 Flash)
// ============================================================
require 'includes/auth.php';
require 'config.php';
require 'includes/ai_service.php';

$user_id = $_SESSION['user_id'];

// ── Handle AJAX Chat Request ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // ── SEND MESSAGE ──────────────────────────────────────────
    if ($action === 'send_message') {
        $message    = trim($_POST['message']   ?? '');
        $session_id = trim($_POST['session_id'] ?? '');
        $course_id  = (int)($_POST['course_id'] ?? 0);

        if (!$message || !$session_id) {
            echo json_encode(['success' => false, 'error' => 'Message and session ID required.']);
            exit;
        }

        // Fetch course context if selected
        $course_name = '';
        if ($course_id > 0) {
            $cs = $conn->prepare("SELECT name, code FROM courses WHERE id=? AND user_id=?");
            $cs->bind_param('ii', $course_id, $user_id);
            $cs->execute();
            $cr = $cs->get_result()->fetch_assoc();
            $cs->close();
            if ($cr) $course_name = $cr['code'] . ' — ' . $cr['name'];
        }

        // Load recent conversation history for this session (last 10 turns)
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
                'role' => $row['role'] === 'assistant' ? 'model' : 'user',
                'text' => $row['message']
            ];
        }
        $hq->close();

        // Save user message to DB
        saveAiChat($conn, $user_id, $session_id, 'user', $message, 'tutor', $course_id);

        // Call Gemini AI
        $aiResult = aiChat($message, $history, $course_name);

        if (!$aiResult['success']) {
            echo json_encode(['success' => false, 'error' => $aiResult['error']]);
            exit;
        }

        $aiReply = $aiResult['text'];
        $tokens  = $aiResult['tokens'];

        // Save AI reply to DB
        saveAiChat($conn, $user_id, $session_id, 'assistant', $aiReply, 'tutor', $course_id, $tokens);

        // Log progress event
        logProgress($conn, $user_id, 'ai_chat', 'AI Tutor session', $course_id);

        echo json_encode([
            'success' => true,
            'reply'   => $aiReply,
            'tokens'  => $tokens
        ]);
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
            <label>Study Context</label>
            <select id="courseSelect">
                <option value="0">🎓 General Tutor</option>
                <?php foreach ($courses as $c): ?>
                <option value="<?php echo $c['id']; ?>">
                    <?php echo htmlspecialchars($c['code'] . ' — ' . $c['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
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

// ── Send Message ──────────────────────────────────────────────
function sendMessage() {
    const msg = $('#chatInput').val().trim();
    if (!msg || isLoading) return;

    const courseId = $('#courseSelect').val();

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
        course_id:  courseId
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
