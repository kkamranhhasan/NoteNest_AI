<?php
// ============================================================
// ai_exam.php — NoteNest AI Platform
// AI Question Generation & Answer Evaluation
// 4-Step Wizard: Select → Generate → Answer → Results
// ============================================================
require 'includes/auth.php';
require 'config.php';
require 'includes/ai_service.php';

$user_id = $_SESSION['user_id'];

// ── Helper: extract readable text from a file ─────────────────
function extractFileText(string $filePath, string $mimeType): string {
    $fullPath = __DIR__ . '/' . ltrim($filePath, '/');
    if (!file_exists($fullPath)) return '';

    // Plain text files
    $textTypes = ['text/plain','text/markdown','text/html','text/csv',
                  'application/json','text/x-python','text/x-java-source'];
    if (in_array($mimeType, $textTypes) || str_ends_with($filePath, '.txt')
        || str_ends_with($filePath, '.md') || str_ends_with($filePath, '.csv')) {
        return mb_substr(file_get_contents($fullPath), 0, 6000);
    }

    // PDF — try pdftotext (available on most systems)
    if ($mimeType === 'application/pdf' || str_ends_with($filePath, '.pdf')) {
        $escaped = escapeshellarg($fullPath);
        $text = shell_exec("pdftotext $escaped - 2>/dev/null");
        if ($text) return mb_substr(trim($text), 0, 6000);
    }

    return ''; // unsupported — user must paste text
}

// ── AJAX HANDLERS ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // ── STEP 2: Generate Questions ────────────────────────────
    if ($action === 'generate_questions') {
        $file_id    = (int)($_POST['file_id']    ?? 0);
        $course_id  = (int)($_POST['course_id']  ?? 0);
        $manual_txt = trim($_POST['manual_text'] ?? '');
        $q_types    = trim($_POST['q_types']     ?? '5 MCQ and 5 short answer');
        $difficulty = $_POST['difficulty']       ?? 'medium';

        // Verify file ownership
        $content = $manual_txt;
        $file_name = 'Manual Input';

        if ($file_id > 0) {
            $fq = $conn->prepare("SELECT name, file_path, mime_type FROM files WHERE id=? AND owner_id=?");
            $fq->bind_param('ii', $file_id, $user_id);
            $fq->execute();
            $file = $fq->get_result()->fetch_assoc();
            $fq->close();

            if (!$file) { echo json_encode(['success'=>false,'error'=>'File not found.']); exit; }
            $file_name = $file['name'];

            if (empty($content)) {
                $content = extractFileText($file['file_path'], $file['mime_type'] ?? '');
            }
        }

        if (strlen($content) < 30) {
            echo json_encode(['success'=>false,'error'=>'Not enough text content to generate questions. Please paste your study material in the text box.']);
            exit;
        }

        // Call AI
        $result = aiGenerateQuestions($content, $q_types, $difficulty);

        if (!$result['success']) {
            echo json_encode(['success'=>false,'error'=>$result['error']]);
            exit;
        }

        // Save to DB
        $qJson = $result['questions_json'];
        $stmt  = $conn->prepare(
            "INSERT INTO ai_evaluations (user_id, file_id, course_id, questions_json, status)
             VALUES (?, ?, ?, ?, 'generated')"
        );
        $fileIdVal   = $file_id > 0 ? $file_id : null;
        $courseIdVal = $course_id > 0 ? $course_id : null;
        $stmt->bind_param('iiis', $user_id, $fileIdVal, $courseIdVal, $qJson);
        $stmt->execute();
        $eval_id = $conn->insert_id;
        $stmt->close();

        logProgress($conn, $user_id, 'exam_taken', 'Questions generated: '.$file_name, $course_id > 0 ? $course_id : 0);

        echo json_encode([
            'success'   => true,
            'eval_id'   => $eval_id,
            'questions' => json_decode($qJson, true),
            'file_name' => $file_name
        ]);
        exit;
    }

    // ── STEP 4: Evaluate Answers ──────────────────────────────
    if ($action === 'evaluate_answers') {
        $eval_id = (int)($_POST['eval_id'] ?? 0);
        $answers = $_POST['answers'] ?? []; // array indexed by question index

        if (!$eval_id) { echo json_encode(['success'=>false,'error'=>'Invalid evaluation ID.']); exit; }

        // Load the questions
        $eq = $conn->prepare("SELECT questions_json, course_id FROM ai_evaluations WHERE id=? AND user_id=? AND status='generated'");
        $eq->bind_param('ii', $eval_id, $user_id);
        $eq->execute();
        $evalRow = $eq->get_result()->fetch_assoc();
        $eq->close();

        if (!$evalRow) { echo json_encode(['success'=>false,'error'=>'Evaluation not found or already submitted.']); exit; }

        // Call AI Evaluator
        $result = aiEvaluateAnswers($evalRow['questions_json'], $answers);

        if (!$result['success']) {
            echo json_encode(['success'=>false,'error'=>$result['error']]);
            exit;
        }

        // Update DB
        $answersJson = json_encode($answers);
        $score       = $result['score'] ?? 0;
        $feedback    = $result['feedback_json'] ?? '';
        $weakAreas   = $result['weak_areas']    ?? '';
        $now         = date('Y-m-d H:i:s');

        $upd = $conn->prepare(
            "UPDATE ai_evaluations
             SET user_answers=?, score=?, feedback=?, weak_areas=?,
                 status='evaluated', evaluated_at=?
             WHERE id=? AND user_id=?"
        );
        $upd->bind_param('sdsssii', $answersJson, $score, $feedback, $weakAreas, $now, $eval_id, $user_id);
        $upd->execute();
        $upd->close();

        // Log score
        $courseId = (int)($evalRow['course_id'] ?? 0);
        logProgress($conn, $user_id, 'exam_taken', 'Exam evaluated, score: '.$score, $courseId, (float)$score);

        echo json_encode([
            'success'  => true,
            'feedback' => json_decode($result['feedback_json'], true)
        ]);
        exit;
    }

    // ── GET PAST EXAMS ────────────────────────────────────────
    if ($action === 'get_history') {
        $hq = $conn->prepare(
            "SELECT e.id, e.score, e.status, e.created_at, e.evaluated_at,
                    f.name AS file_name, c.name AS course_name
             FROM ai_evaluations e
             LEFT JOIN files   f ON e.file_id   = f.id
             LEFT JOIN courses c ON e.course_id  = c.id
             WHERE e.user_id=?
             ORDER BY e.created_at DESC LIMIT 10"
        );
        $hq->bind_param('i', $user_id);
        $hq->execute();
        $history = $hq->get_result()->fetch_all(MYSQLI_ASSOC);
        $hq->close();
        echo json_encode(['success'=>true,'history'=>$history]);
        exit;
    }

    echo json_encode(['success'=>false,'error'=>'Unknown action']);
    exit;
}

// ── Load data for page ────────────────────────────────────────
// User's files
$files = [];
$fq = $conn->prepare(
    "SELECT id, name, mime_type, file_path, created_at FROM files WHERE owner_id=? ORDER BY created_at DESC"
);
$fq->bind_param('i', $user_id);
$fq->execute();
$files = $fq->get_result()->fetch_all(MYSQLI_ASSOC);
$fq->close();

// Courses
$courses = [];
$cq = $conn->prepare("SELECT id, name, code, color FROM courses WHERE user_id=? ORDER BY code");
$cq->bind_param('i', $user_id);
$cq->execute();
$courses = $cq->get_result()->fetch_all(MYSQLI_ASSOC);
$cq->close();

// File icon helper
function fileIcon(string $mime): string {
    if (str_contains($mime,'pdf'))   return 'fa-file-pdf text-danger';
    if (str_contains($mime,'word'))  return 'fa-file-word text-primary';
    if (str_contains($mime,'image')) return 'fa-file-image text-success';
    if (str_contains($mime,'text'))  return 'fa-file-alt text-secondary';
    if (str_contains($mime,'sheet') || str_contains($mime,'excel')) return 'fa-file-excel text-success';
    if (str_contains($mime,'presentation') || str_contains($mime,'powerpoint')) return 'fa-file-powerpoint text-warning';
    return 'fa-file text-muted';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Exam — NoteNest AI</title>
    <link rel="shortcut icon" href="img/fav.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
        :root { --primary:#0b4954; --accent:#197f8f; --bg:#f0f4f8; }
        body { font-family:'Inter',sans-serif; background:var(--bg); }

        /* ── Page Header ── */
        .page-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            padding: 28px 0 24px; color: #fff; margin-bottom: 32px;
        }
        .page-header h1 { font-size:1.7rem; font-weight:700; }

        /* ── Wizard Steps ── */
        .wizard-steps {
            display: flex; gap: 0; margin-bottom: 32px;
            background: #fff; border-radius: 14px;
            overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,.06);
        }
        .step-item {
            flex: 1; padding: 16px 12px; text-align: center;
            font-size: .82rem; font-weight: 600; color: #bbb;
            border-right: 1px solid #f0f2f5;
            transition: all .3s; position: relative;
        }
        .step-item:last-child { border-right: none; }
        .step-item.active  { color: var(--primary); background: #f0f7f9; }
        .step-item.done    { color: #27ae60; background: #f0faf4; }
        .step-item .step-num {
            display: inline-flex; align-items: center; justify-content: center;
            width: 26px; height: 26px; border-radius: 50%;
            background: #eee; color: #999; font-size: .78rem; font-weight:700;
            margin-bottom: 5px;
        }
        .step-item.active .step-num { background: var(--accent); color: #fff; }
        .step-item.done   .step-num { background: #27ae60; color: #fff; }

        /* ── Cards ── */
        .card-panel {
            background: #fff; border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,.06); padding: 28px;
            margin-bottom: 24px;
        }
        .card-panel h5 { font-weight:700; color:var(--primary); margin-bottom:18px; }

        /* ── File Grid ── */
        .file-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 12px; max-height: 320px; overflow-y: auto;
        }
        .file-card {
            border: 2px solid #e8edf2; border-radius: 12px;
            padding: 14px 12px; cursor: pointer; transition: all .2s;
            text-align: center;
        }
        .file-card:hover { border-color: var(--accent); background: #f0f7f9; transform: translateY(-2px); }
        .file-card.selected { border-color: var(--accent); background: #e4f2f6; }
        .file-card .ficon { font-size: 1.8rem; margin-bottom: 8px; }
        .file-card .fname {
            font-size: .78rem; font-weight:600; color:#333;
            overflow: hidden; text-overflow: ellipsis;
            display: -webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
        }
        .file-card .fdate { font-size: .7rem; color: #aaa; margin-top: 4px; }

        /* ── Option Buttons (difficulty / q-type) ── */
        .opt-btn {
            border: 2px solid #e8edf2; border-radius: 10px;
            padding: 8px 16px; cursor: pointer; font-size:.84rem;
            font-weight:600; color:#555; background:#fff;
            transition: all .2s; display: inline-flex; align-items:center; gap:6px;
        }
        .opt-btn:hover { border-color: var(--accent); color: var(--accent); }
        .opt-btn.selected { border-color: var(--accent); background: var(--accent); color: #fff; }

        /* ── Question Cards ── */
        .question-card {
            background: #fff; border-radius: 14px;
            border-left: 4px solid var(--accent);
            padding: 20px 22px; margin-bottom: 18px;
            box-shadow: 0 2px 10px rgba(0,0,0,.05);
            animation: slideUp .35s ease;
        }
        @keyframes slideUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
        .q-label {
            font-size: .72rem; font-weight:700; letter-spacing:.8px;
            text-transform: uppercase; color: var(--accent); margin-bottom:8px;
        }
        .q-text { font-weight:600; color:#2c3e50; font-size:.95rem; margin-bottom:14px; line-height:1.5; }

        /* MCQ options */
        .mcq-option {
            display: flex; align-items:center; gap:10px;
            padding: 10px 14px; border-radius: 10px;
            border: 1.5px solid #e8edf2; margin-bottom: 8px;
            cursor: pointer; transition: all .15s; font-size:.88rem;
        }
        .mcq-option:hover { border-color: var(--accent); background: #f0f7f9; }
        .mcq-option input[type=radio] { accent-color: var(--accent); width:16px; height:16px; }
        .mcq-option.correct-ans { border-color: #27ae60; background: #f0faf4; }
        .mcq-option.wrong-ans   { border-color: #e74c3c; background: #fdf4f4; }

        /* Short answer */
        .short-answer-input {
            width: 100%; border: 1.5px solid #e8edf2; border-radius: 10px;
            padding: 10px 14px; font-size:.88rem; font-family:'Inter',sans-serif;
            resize: vertical; min-height: 80px; transition: border-color .2s;
        }
        .short-answer-input:focus { outline: none; border-color: var(--accent); }

        /* ── Results ── */
        .result-hero {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: 16px; padding: 36px; text-align:center; color:#fff;
            margin-bottom: 24px;
        }
        .score-circle {
            width: 120px; height: 120px; border-radius: 50%;
            background: rgba(255,255,255,.15);
            border: 4px solid rgba(255,255,255,.4);
            display: inline-flex; flex-direction:column;
            align-items:center; justify-content:center;
            margin-bottom: 16px;
        }
        .score-num { font-size: 2.4rem; font-weight:800; line-height:1; }
        .score-lbl { font-size: .8rem; opacity:.8; }
        .grade-badge {
            display: inline-block; background: rgba(255,255,255,.2);
            border: 1px solid rgba(255,255,255,.4);
            border-radius: 20px; padding: 4px 18px; font-size:.9rem; font-weight:700;
        }

        .result-q-card {
            background: #fff; border-radius: 12px; padding: 18px 20px;
            margin-bottom: 14px; box-shadow: 0 2px 8px rgba(0,0,0,.05);
        }
        .result-q-card.correct { border-left: 4px solid #27ae60; }
        .result-q-card.wrong   { border-left: 4px solid #e74c3c; }
        .result-q-card.partial { border-left: 4px solid #f39c12; }
        .marks-chip {
            font-size: .75rem; font-weight:700; padding: 2px 10px;
            border-radius: 20px; display:inline-block;
        }
        .marks-chip.full   { background:#d4edda; color:#155724; }
        .marks-chip.partial{ background:#fff3cd; color:#856404; }
        .marks-chip.zero   { background:#f8d7da; color:#721c24; }

        .weak-area-badge {
            background: #fff3cd; color: #856404; border-radius:20px;
            padding: 5px 14px; font-size:.82rem; font-weight:600;
            display:inline-block; margin: 4px;
        }
        .suggestion-item {
            padding: 8px 14px; background:#f8f9fa; border-radius:8px;
            font-size:.85rem; color:#555; margin-bottom:6px;
            display:flex; align-items:center; gap:8px;
        }

        /* ── Progress bar ── */
        .exam-progress {
            background: #e8edf2; border-radius:10px; height:6px; margin-bottom:20px;
        }
        .exam-progress-fill {
            height:100%; border-radius:10px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            transition: width .3s ease;
        }

        /* ── Loading overlay ── */
        #loadingOverlay {
            display:none; position:fixed; inset:0; z-index:9999;
            background:rgba(0,0,0,.5); backdrop-filter:blur(4px);
            align-items:center; justify-content:center; flex-direction:column;
        }
        .loading-box {
            background:#fff; border-radius:20px; padding:40px 48px;
            text-align:center; box-shadow:0 20px 60px rgba(0,0,0,.2);
        }
        .loading-spinner {
            width:56px; height:56px; border:4px solid #e8edf2;
            border-top-color: var(--accent); border-radius:50%;
            animation: spin .9s linear infinite; margin:0 auto 16px;
        }
        @keyframes spin { to { transform:rotate(360deg); } }

        /* Scrollbar */
        .file-grid::-webkit-scrollbar { width:4px; }
        .file-grid::-webkit-scrollbar-thumb { background:#ddd; border-radius:4px; }

        /* History table */
        .history-badge {
            font-size:.75rem; padding:3px 10px; border-radius:10px;
            font-weight:600;
        }
        .history-badge.evaluated { background:#d4edda; color:#155724; }
        .history-badge.generated { background:#fff3cd; color:#856404; }
    </style>
</head>
<body>

<?php include 'includes/navbar.php'; ?>

<!-- Loading overlay -->
<div id="loadingOverlay" style="display:flex">
    <div class="loading-box">
        <div class="loading-spinner"></div>
        <h5 style="color:var(--primary);font-weight:700;margin:0 0 6px;" id="loadingTitle">Generating Questions...</h5>
        <p style="color:#888;font-size:.88rem;margin:0;" id="loadingSubtitle">AI is analyzing your study material</p>
    </div>
</div>

<!-- Page Header -->
<div class="page-header">
    <div class="container">
        <div class="d-flex align-items-center gap-3">
            <i class="fas fa-brain fa-2x opacity-75"></i>
            <div>
                <h1 class="mb-1">AI Exam</h1>
                <p class="mb-0" style="opacity:.8">Generate personalized questions & get AI evaluation</p>
            </div>
            <a href="dashboard.php" class="btn btn-outline-light ms-auto">
                <i class="fas fa-arrow-left me-1"></i> Dashboard
            </a>
        </div>
    </div>
</div>

<div class="container pb-5">

    <!-- Wizard Steps -->
    <div class="wizard-steps" id="wizardSteps">
        <div class="step-item active" id="step-ind-1">
            <div><div class="step-num">1</div></div>
            <div>Select Material</div>
        </div>
        <div class="step-item" id="step-ind-2">
            <div><div class="step-num">2</div></div>
            <div>Generate Questions</div>
        </div>
        <div class="step-item" id="step-ind-3">
            <div><div class="step-num">3</div></div>
            <div>Answer</div>
        </div>
        <div class="step-item" id="step-ind-4">
            <div><div class="step-num">4</div></div>
            <div>Results</div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8 mx-auto">

            <!-- ═══════════════════════════════
                 STEP 1 — Select Material
            ═══════════════════════════════ -->
            <div id="step1">
                <!-- File Picker -->
                <div class="card-panel">
                    <h5><i class="fas fa-folder-open me-2"></i>Select Study Material</h5>

                    <?php if (empty($files)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-cloud-upload-alt fa-3x mb-3" style="color:#ddd;"></i>
                        <p>No files uploaded yet. <a href="my_note_nest.php">Upload files first.</a></p>
                    </div>
                    <?php else: ?>
                    <div class="file-grid" id="fileGrid">
                        <?php foreach ($files as $f): ?>
                        <div class="file-card" data-id="<?php echo $f['id']; ?>"
                             data-name="<?php echo htmlspecialchars($f['name']); ?>"
                             data-mime="<?php echo htmlspecialchars($f['mime_type'] ?? ''); ?>"
                             onclick="selectFile(this)">
                            <div class="ficon"><i class="fas <?php echo fileIcon($f['mime_type'] ?? ''); ?>"></i></div>
                            <div class="fname"><?php echo htmlspecialchars($f['name']); ?></div>
                            <div class="fdate"><?php echo date('M d', strtotime($f['created_at'])); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Manual Text Input -->
                <div class="card-panel">
                    <h5><i class="fas fa-paste me-2"></i>Or Paste Study Material</h5>
                    <p style="font-size:.85rem;color:#888;margin-bottom:12px;">
                        Paste lecture notes, book excerpts, or any text content here. Works for all file types (PDF, DOCX, etc.)
                    </p>
                    <textarea id="manualText" class="short-answer-input"
                              style="min-height:140px;"
                              placeholder="Paste your study material here... (minimum 30 characters)"></textarea>
                </div>

                <!-- Course & Config -->
                <div class="card-panel">
                    <h5><i class="fas fa-sliders-h me-2"></i>Exam Configuration</h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:.84rem;">Course (optional)</label>
                            <select id="examCourse" class="form-select" style="border-radius:10px;">
                                <option value="0">No specific course</option>
                                <?php foreach ($courses as $c): ?>
                                <option value="<?php echo $c['id']; ?>">
                                    <?php echo htmlspecialchars($c['code'].' — '.$c['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size:.84rem;">Difficulty</label>
                            <div class="d-flex gap-2 flex-wrap">
                                <button class="opt-btn selected" data-val="easy" onclick="selectDifficulty(this)">🟢 Easy</button>
                                <button class="opt-btn" data-val="medium" onclick="selectDifficulty(this)">🟡 Medium</button>
                                <button class="opt-btn" data-val="hard" onclick="selectDifficulty(this)">🔴 Hard</button>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" style="font-size:.84rem;">Question Types</label>
                            <div class="d-flex gap-2 flex-wrap">
                                <button class="opt-btn selected" data-val="5 MCQ and 5 short answer" onclick="selectQType(this)">📋 Mixed (5 MCQ + 5 Short)</button>
                                <button class="opt-btn" data-val="10 MCQ" onclick="selectQType(this)">☑️ 10 MCQ Only</button>
                                <button class="opt-btn" data-val="5 short answer and 2 essay" onclick="selectQType(this)">✍️ Short + Essay</button>
                                <button class="opt-btn" data-val="3 MCQ, 3 short answer, and 1 essay" onclick="selectQType(this)">📝 Full Exam</button>
                            </div>
                        </div>
                    </div>

                    <button class="btn mt-4 w-100 py-3 fw-bold" id="btnGenerate"
                            style="background:linear-gradient(135deg,#0b4954,#197f8f);color:#fff;border:none;border-radius:12px;font-size:1rem;">
                        <i class="fas fa-magic me-2"></i> Generate AI Questions
                    </button>
                </div>

                <!-- Past Exam History -->
                <div class="card-panel" id="historyPanel" style="display:none;">
                    <h5><i class="fas fa-history me-2"></i>Past Exams</h5>
                    <div id="historyList"></div>
                </div>
            </div>

            <!-- ═══════════════════════════════
                 STEP 3 — Answer Questions
            ═══════════════════════════════ -->
            <div id="step3" style="display:none;">
                <div class="card-panel">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h5 class="mb-0"><i class="fas fa-pencil-alt me-2"></i>Answer the Questions</h5>
                        <span id="examFilename" class="badge" style="background:#f0f7f9;color:var(--accent);font-size:.8rem;padding:6px 12px;border-radius:10px;"></span>
                    </div>
                    <div class="exam-progress"><div class="exam-progress-fill" id="progressFill" style="width:0%"></div></div>
                    <p style="font-size:.82rem;color:#888;margin-bottom:20px;">
                        <span id="answeredCount">0</span> / <span id="totalCount">0</span> answered
                    </p>
                    <div id="questionsContainer"></div>

                    <button class="btn mt-3 w-100 py-3 fw-bold" id="btnSubmit"
                            style="background:linear-gradient(135deg,#27ae60,#2ecc71);color:#fff;border:none;border-radius:12px;font-size:1rem;">
                        <i class="fas fa-paper-plane me-2"></i> Submit for AI Evaluation
                    </button>
                </div>
            </div>

            <!-- ═══════════════════════════════
                 STEP 4 — Results
            ═══════════════════════════════ -->
            <div id="step4" style="display:none;">

                <!-- Score Hero -->
                <div class="result-hero" id="resultHero">
                    <div class="score-circle">
                        <div class="score-num" id="finalScore">--</div>
                        <div class="score-lbl">/ 100</div>
                    </div>
                    <h3 id="finalGrade" style="font-weight:800;margin:8px 0 4px;"></h3>
                    <p id="overallFeedback" style="opacity:.85;font-size:.92rem;max-width:500px;margin:0 auto 16px;"></p>
                </div>

                <!-- Weak Areas -->
                <div class="card-panel" id="weakAreasPanel" style="display:none;">
                    <h5><i class="fas fa-exclamation-triangle me-2 text-warning"></i>Areas to Improve</h5>
                    <div id="weakAreasList"></div>
                </div>

                <!-- Study Suggestions -->
                <div class="card-panel" id="suggestionsPanel" style="display:none;">
                    <h5><i class="fas fa-lightbulb me-2 text-warning"></i>Study Suggestions</h5>
                    <div id="suggestionsList"></div>
                </div>

                <!-- Per-question Results -->
                <div class="card-panel">
                    <h5><i class="fas fa-list-check me-2"></i>Detailed Results</h5>
                    <div id="detailedResults"></div>
                </div>

                <!-- Action buttons -->
                <div class="d-flex gap-3">
                    <button class="btn flex-fill py-3" onclick="restartExam()"
                            style="background:linear-gradient(135deg,#0b4954,#197f8f);color:#fff;border:none;border-radius:12px;font-weight:600;">
                        <i class="fas fa-redo me-2"></i> New Exam
                    </button>
                    <a href="ai_tutor.php" class="btn flex-fill py-3"
                       style="background:#fff;color:var(--primary);border:2px solid #e8edf2;border-radius:12px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:8px;">
                        <i class="fas fa-robot"></i> Ask AI Tutor
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
// ============================================================
// AI Exam — Frontend Logic
// ============================================================
marked.setOptions({ breaks:true, gfm:true });

let selectedFileId   = 0;
let selectedDiff     = 'easy';
let selectedQType    = '5 MCQ and 5 short answer';
let currentEvalId    = 0;
let currentQuestions = [];
let totalQuestions   = 0;

// ── Init ──────────────────────────────────────────────────────
$(document).ready(function() {
    loadHistory();
});

// ── File selection ────────────────────────────────────────────
function selectFile(el) {
    $('.file-card').removeClass('selected');
    $(el).addClass('selected');
    selectedFileId = parseInt($(el).data('id'));
}

// ── Difficulty ────────────────────────────────────────────────
function selectDifficulty(el) {
    $('.opt-btn[data-val="easy"],.opt-btn[data-val="medium"],.opt-btn[data-val="hard"]').removeClass('selected');
    $(el).addClass('selected');
    selectedDiff = $(el).data('val');
}

// ── Question type ─────────────────────────────────────────────
function selectQType(el) {
    // Only deselect q-type buttons (not difficulty)
    $('[onclick="selectQType(this)"]').removeClass('selected');
    $(el).addClass('selected');
    selectedQType = $(el).data('val');
}

// ── STEP 1 → 2: Generate questions ───────────────────────────
$('#btnGenerate').on('click', function() {
    const manualText = $('#manualText').val().trim();
    if (selectedFileId === 0 && manualText.length < 30) {
        alert('Please select a file OR paste at least 30 characters of study material.');
        return;
    }

    showLoading('Generating Questions...', 'AI is reading your study material...');

    $.post('ai_exam.php', {
        action:      'generate_questions',
        file_id:     selectedFileId,
        course_id:   $('#examCourse').val(),
        manual_text: manualText,
        q_types:     selectedQType,
        difficulty:  selectedDiff
    }, function(res) {
        hideLoading();
        if (res.success) {
            currentEvalId    = res.eval_id;
            currentQuestions = res.questions;
            totalQuestions   = res.questions.length;
            renderQuestions(res.questions, res.file_name);
            goToStep(3);
        } else {
            alert('❌ ' + (res.error || 'Could not generate questions.'));
        }
    }, 'json').fail(function() {
        hideLoading();
        alert('Network error. Please try again.');
    });
});

// ── Render questions ──────────────────────────────────────────
function renderQuestions(questions, fileName) {
    $('#examFilename').text('📄 ' + fileName);
    $('#totalCount').text(questions.length);
    $('#answeredCount').text('0');
    $('#progressFill').css('width', '0%');
    const container = $('#questionsContainer');
    container.empty();

    questions.forEach((q, idx) => {
        const num  = idx + 1;
        const type = (q.type || 'short_answer').toLowerCase();
        let inner  = '';

        if (type === 'mcq') {
            const opts = q.options || [];
            inner = opts.map((opt, oi) => `
                <label class="mcq-option" id="opt-${idx}-${oi}">
                    <input type="radio" name="mcq_${idx}" value="${escHtml(opt[0])}"
                           onchange="trackAnswer(${idx})">
                    <span>${escHtml(opt)}</span>
                </label>`).join('');
        } else {
            // Short answer or essay
            inner = `<textarea class="short-answer-input" id="sa-${idx}"
                         placeholder="Write your answer here..."
                         rows="${type==='essay'?5:3}"
                         oninput="trackAnswer(${idx})"></textarea>`;
        }

        const typeLabel = type === 'mcq' ? '🔵 Multiple Choice' :
                         type === 'essay' ? '📝 Essay' : '✏️ Short Answer';

        container.append(`
            <div class="question-card" id="qcard-${idx}">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="q-label">Question ${num}</div>
                    <span style="font-size:.72rem;background:#f0f7f9;color:var(--accent);
                                 padding:2px 10px;border-radius:10px;font-weight:600;">
                        ${typeLabel}
                    </span>
                </div>
                <div class="q-text">${escHtml(q.question)}</div>
                ${inner}
            </div>`);
    });
}

// ── Track answered count ──────────────────────────────────────
let answeredSet = new Set();
function trackAnswer(idx) {
    answeredSet.add(idx);
    const cnt = answeredSet.size;
    $('#answeredCount').text(cnt);
    $('#progressFill').css('width', (cnt / totalQuestions * 100) + '%');
}

// ── Submit answers ────────────────────────────────────────────
$('#btnSubmit').on('click', function() {
    const answers = {};
    currentQuestions.forEach((q, idx) => {
        const type = (q.type || 'short_answer').toLowerCase();
        if (type === 'mcq') {
            const val = $(`input[name="mcq_${idx}"]:checked`).val();
            answers[idx] = val || '';
        } else {
            answers[idx] = $(`#sa-${idx}`).val().trim();
        }
    });

    const unanswered = Object.values(answers).filter(a => !a).length;
    if (unanswered > 0) {
        if (!confirm(`You have ${unanswered} unanswered question(s). Submit anyway?`)) return;
    }

    showLoading('Evaluating Answers...', 'AI is grading your exam...');

    const postData = { action: 'evaluate_answers', eval_id: currentEvalId };
    Object.keys(answers).forEach(k => postData[`answers[${k}]`] = answers[k]);

    $.post('ai_exam.php', postData, function(res) {
        hideLoading();
        if (res.success) {
            renderResults(res.feedback, answers);
            goToStep(4);
        } else {
            alert('❌ ' + (res.error || 'Evaluation failed.'));
        }
    }, 'json').fail(function() {
        hideLoading();
        alert('Network error.');
    });
});

// ── Render results ────────────────────────────────────────────
function renderResults(fb, answers) {
    const score = fb.total_score ?? 0;
    const max   = fb.max_score   ?? 100;
    const grade = fb.grade       ?? 'N/A';

    $('#finalScore').text(score);
    $('#finalGrade').text('Grade: ' + grade);
    $('#overallFeedback').text(fb.overall_feedback ?? '');

    // Score circle color
    const color = score >= 80 ? '#27ae60' : score >= 60 ? '#f39c12' : '#e74c3c';
    $('.result-hero').css('background', `linear-gradient(135deg, ${color}, ${adjustColor(color)})`);

    // Weak areas
    const weak = fb.weak_areas || [];
    if (weak.length) {
        const html = weak.map(w => `<span class="weak-area-badge"><i class="fas fa-exclamation-circle me-1"></i>${escHtml(w)}</span>`).join('');
        $('#weakAreasList').html(html);
        $('#weakAreasPanel').show();
    }

    // Suggestions
    const sugs = fb.study_suggestions || [];
    if (sugs.length) {
        const html = sugs.map(s => `<div class="suggestion-item"><i class="fas fa-arrow-right" style="color:var(--accent);flex-shrink:0;"></i>${escHtml(s)}</div>`).join('');
        $('#suggestionsList').html(html);
        $('#suggestionsPanel').show();
    }

    // Per-question
    const qResults = fb.question_results || [];
    const det = $('#detailedResults');
    det.empty();
    qResults.forEach((r, i) => {
        const earned  = r.marks_earned   ?? 0;
        const possible= r.marks_possible ?? 10;
        const isCorrect = r.is_correct;
        const cls   = earned >= possible ? 'correct' : earned > 0 ? 'partial' : 'wrong';
        const chip  = earned >= possible ? 'full' : earned > 0 ? 'partial' : 'zero';
        const icon  = earned >= possible ? '✅' : earned > 0 ? '⚠️' : '❌';

        const q = currentQuestions[i] || {};
        const userAns = answers[i] || '(no answer)';

        det.append(`
            <div class="result-q-card ${cls}">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div style="font-weight:700;color:#2c3e50;font-size:.9rem;">
                        ${icon} Q${i+1}: ${escHtml((q.question||'').substring(0,80))}${(q.question||'').length>80?'…':''}
                    </div>
                    <span class="marks-chip ${chip} ms-2">${earned}/${possible}</span>
                </div>
                <div style="font-size:.82rem;color:#666;margin-bottom:6px;">
                    <strong>Your answer:</strong> ${escHtml(String(userAns))}
                </div>
                ${q.correct_answer ? `<div style="font-size:.82rem;color:#27ae60;margin-bottom:6px;">
                    <strong>Correct:</strong> ${escHtml(q.correct_answer)}
                </div>` : ''}
                <div style="font-size:.82rem;color:#555;background:#f8f9fa;border-radius:8px;padding:8px 12px;">
                    <i class="fas fa-comment-alt me-1" style="color:var(--accent);"></i>
                    ${escHtml(r.feedback || '')}
                </div>
            </div>`);
    });
}

// ── History ───────────────────────────────────────────────────
function loadHistory() {
    $.post('ai_exam.php', { action: 'get_history' }, function(res) {
        if (!res.success || !res.history.length) return;
        $('#historyPanel').show();
        const list = $('#historyList');
        list.empty();
        res.history.forEach(h => {
            const score    = h.score != null ? parseFloat(h.score).toFixed(0)+'%' : '—';
            const badgeCls = h.status === 'evaluated' ? 'evaluated' : 'generated';
            const date     = new Date(h.created_at).toLocaleDateString([], {month:'short', day:'numeric'});
            list.append(`
                <div class="d-flex align-items-center gap-3 py-2" style="border-bottom:1px solid #f0f2f5;">
                    <i class="fas fa-brain" style="color:var(--accent);font-size:1.1rem;"></i>
                    <div style="flex:1;font-size:.84rem;color:#333;">
                        ${escHtml(h.file_name || h.course_name || 'Manual input')}
                    </div>
                    <span style="font-size:.82rem;color:#888;">${date}</span>
                    ${h.score != null ? `<span style="font-weight:700;color:var(--accent);">${score}</span>` : ''}
                    <span class="history-badge ${badgeCls}">${h.status}</span>
                </div>`);
        });
    }, 'json');
}

// ── Wizard navigation ─────────────────────────────────────────
function goToStep(step) {
    ['step1','step3','step4'].forEach(s => $(`#${s}`).hide());
    $(`#step${step}`).show();
    [1,2,3,4].forEach(i => {
        $(`#step-ind-${i}`).removeClass('active done');
        if (i < step)  $(`#step-ind-${i}`).addClass('done');
        if (i === step) $(`#step-ind-${i}`).addClass('active');
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function restartExam() {
    selectedFileId = 0; currentEvalId = 0; currentQuestions = [];
    answeredSet.clear(); totalQuestions = 0;
    $('.file-card').removeClass('selected');
    $('#manualText').val('');
    $('#weakAreasPanel, #suggestionsPanel').hide();
    loadHistory();
    goToStep(1);
}

// ── Helpers ───────────────────────────────────────────────────
function showLoading(title, sub) {
    $('#loadingTitle').text(title);
    $('#loadingSubtitle').text(sub);
    $('#loadingOverlay').css('display','flex');
}
function hideLoading() { $('#loadingOverlay').hide(); }

function adjustColor(hex) {
    // Just darken slightly for gradient
    return hex.replace('#27ae60','#1e8449').replace('#f39c12','#d68910').replace('#e74c3c','#c0392b');
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Hide loading overlay initially
$(document).ready(function() { hideLoading(); });
</script>
</body>
</html>
