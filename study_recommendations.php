<?php
require 'includes/auth.php';
require 'config.php';
require 'includes/ai_service.php';

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if ($_POST['action'] === 'generate_recommendations') {
        $weak_q = $conn->prepare("SELECT weak_areas, score, max_score FROM ai_evaluations WHERE user_id=? AND status='evaluated' ORDER BY evaluated_at DESC LIMIT 10");
        $weak_q->bind_param('i', $user_id); $weak_q->execute();
        $evals = $weak_q->get_result()->fetch_all(MYSQLI_ASSOC); $weak_q->close();

        $cq = $conn->prepare("SELECT c.name AS course, c.code, GROUP_CONCAT(t.title ORDER BY t.sort_order SEPARATOR ' | ') AS topics FROM courses c LEFT JOIN course_topics t ON t.course_id = c.id WHERE c.user_id=? GROUP BY c.id");
        $cq->bind_param('i', $user_id); $cq->execute();
        $courses = $cq->get_result()->fetch_all(MYSQLI_ASSOC); $cq->close();

        $aq = $conn->prepare("SELECT message FROM ai_chat_history WHERE user_id=? AND role='user' ORDER BY created_at DESC LIMIT 15");
        $aq->bind_param('i', $user_id); $aq->execute();
        $chats = array_column($aq->get_result()->fetch_all(MYSQLI_ASSOC), 'message'); $aq->close();

        $avg_q = $conn->prepare("SELECT AVG(score) as avg_score, COUNT(*) as total FROM ai_evaluations WHERE user_id=? AND status='evaluated'");
        $avg_q->bind_param('i', $user_id); $avg_q->execute();
        $avg_row = $avg_q->get_result()->fetch_assoc(); $avg_q->close();

        $weak_areas_all = []; $scores_list = [];
        foreach ($evals as $e) {
            if ($e['weak_areas']) { $weak_areas_all = array_merge($weak_areas_all, array_map('trim', explode(',', $e['weak_areas']))); }
            $scores_list[] = round(($e['score'] / max($e['max_score'],1))*100,1).'%';
        }
        $weak_freq = array_count_values($weak_areas_all); arsort($weak_freq);
        $top_weak = array_slice(array_keys($weak_freq), 0, 8);
        $courses_summary = implode("\n", array_map(fn($c) => "- {$c['course']} ({$c['code']})" . ($c['topics'] ? ": {$c['topics']}" : ''), $courses));
        $avg_score = $avg_row['avg_score'] ? round($avg_row['avg_score'],1) : 'N/A';
        $total_exams = $avg_row['total'] ?? 0;
        $chats_summary = implode("\n", array_slice($chats, 0, 8));

        $prompt = "You are an expert academic advisor for a university student using the NoteNest AI platform.

STUDENT PROFILE:
- Courses enrolled: " . (empty($courses) ? "None registered" : "\n{$courses_summary}") . "
- Average exam score: {$avg_score}% (across {$total_exams} exams)
- Recent exam scores: " . (empty($scores_list) ? "No exams yet" : implode(', ', $scores_list)) . "
- Identified weak areas: " . (empty($top_weak) ? "None identified yet" : implode(', ', $top_weak)) . "
- Recent AI tutor questions: " . (empty($chats_summary) ? "No chats yet" : "\n{$chats_summary}") . "

Generate a comprehensive personalized study recommendation plan. Return ONLY valid JSON (NO markdown):
{
  \"overall_assessment\": \"2-3 sentence assessment of student's current learning status\",
  \"study_score\": 72,
  \"recommendations\": [
    {
      \"priority\": \"high\",
      \"topic\": \"Topic name\",
      \"reason\": \"Why this needs attention\",
      \"study_tips\": [\"Tip 1\", \"Tip 2\", \"Tip 3\"],
      \"estimated_hours\": 3,
      \"resources\": [\"Resource type 1\", \"Resource type 2\"]
    }
  ],
  \"weekly_plan\": {
    \"monday\": \"Focus for Monday\",
    \"tuesday\": \"Focus for Tuesday\",
    \"wednesday\": \"Focus for Wednesday\",
    \"thursday\": \"Focus for Thursday\",
    \"friday\": \"Focus for Friday\",
    \"saturday\": \"Focus for Saturday\",
    \"sunday\": \"Rest and review plan\"
  },
  \"motivational_message\": \"Short encouraging message for student\",
  \"focus_areas\": [\"Area 1\", \"Area 2\", \"Area 3\"]
}

Generate 4-6 recommendations. Priority: 'high', 'medium', or 'low'.";

        $result = geminiRequest([['role'=>'user','text'=>$prompt]], 'You are an expert academic advisor. Respond only in valid JSON.', 0.5);
        if ($result['success']) {
            $json_text = trim(preg_replace('/```json\s*|\s*```/', '', $result['text']));
            $decoded = json_decode($json_text, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                echo json_encode(['success'=>true,'data'=>$decoded]);
            } else {
                echo json_encode(['success'=>false,'message'=>'AI returned invalid JSON. Please try again.']);
            }
        } else {
            echo json_encode(['success'=>false,'message'=>$result['error']]);
        }
        exit;
    }
    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

$stmt = $conn->prepare("SELECT name, photo FROM users WHERE id=?");
$stmt->bind_param('i', $user_id); $stmt->execute();
$stmt->bind_result($user_name, $user_photo); $stmt->fetch(); $stmt->close();

$total_exams = (int)$conn->query("SELECT COUNT(*) FROM ai_evaluations WHERE user_id=$user_id AND status='evaluated'")->fetch_row()[0];
$avg_score   = round((float)$conn->query("SELECT AVG(score) FROM ai_evaluations WHERE user_id=$user_id AND status='evaluated'")->fetch_row()[0], 1);
$total_courses = (int)$conn->query("SELECT COUNT(*) FROM courses WHERE user_id=$user_id")->fetch_row()[0];
$total_chats   = (int)$conn->query("SELECT COUNT(*) FROM ai_chat_history WHERE user_id=$user_id AND role='user'")->fetch_row()[0];

$wa_q = $conn->prepare("SELECT weak_areas FROM ai_evaluations WHERE user_id=? AND status='evaluated' AND weak_areas IS NOT NULL AND weak_areas != '' ORDER BY evaluated_at DESC LIMIT 5");
$wa_q->bind_param('i', $user_id); $wa_q->execute();
$wa_rows = $wa_q->get_result()->fetch_all(MYSQLI_ASSOC); $wa_q->close();
$all_weak = [];
foreach ($wa_rows as $row) { $all_weak = array_merge($all_weak, array_map('trim', explode(',', $row['weak_areas']))); }
$weak_freq = array_count_values($all_weak); arsort($weak_freq);
$top_weak_areas = array_slice(array_keys($weak_freq), 0, 6);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Study Recommendations — NoteNest AI</title>
    <meta name="description" content="Personalized AI-powered study recommendations based on your learning performance.">
    <link rel="shortcut icon" href="img/fav.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary:#0b4954; --accent:#197f8f; --bg:#f0f4f8; --high:#e74c3c; --medium:#f39c12; --low:#27ae60; }
        body { font-family:'Inter',sans-serif; background:var(--bg); }
        .page-header { background:linear-gradient(135deg,#0b4954 0%,#197f8f 60%,#1aacbf 100%); padding:36px 0 30px; color:#fff; margin-bottom:32px; }
        .page-header h1 { font-size:2rem; font-weight:800; }
        .page-header p { opacity:.82; font-size:.95rem; margin:0; }
        .glass-card { background:#fff; border-radius:18px; box-shadow:0 4px 24px rgba(0,0,0,.06); padding:26px; margin-bottom:24px; transition:transform .2s,box-shadow .2s; }
        .glass-card:hover { transform:translateY(-2px); box-shadow:0 8px 32px rgba(0,0,0,.09); }
        .btn-generate { background:linear-gradient(135deg,#0b4954,#197f8f); color:#fff; border:none; border-radius:14px; padding:16px 32px; font-size:1rem; font-weight:700; cursor:pointer; transition:all .2s; display:flex; align-items:center; gap:10px; width:100%; justify-content:center; }
        .btn-generate:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(25,127,143,.35); }
        .btn-generate:disabled { opacity:.6; cursor:not-allowed; transform:none; }
        .stat-chip { background:linear-gradient(135deg,#f0f7f9,#e8f4f8); border-radius:12px; padding:14px 16px; text-align:center; margin-bottom:10px; }
        .stat-chip .chip-num { font-size:1.7rem; font-weight:800; color:var(--primary); line-height:1; }
        .stat-chip .chip-lbl { font-size:.73rem; color:#888; font-weight:600; margin-top:3px; }
        .weak-tag { display:inline-block; background:#fdecea; color:#c0392b; border-radius:20px; padding:4px 12px; font-size:.76rem; font-weight:600; margin:3px; }
        .assessment-banner { background:linear-gradient(135deg,#0b4954,#197f8f); border-radius:16px; padding:24px 28px; color:#fff; margin-bottom:24px; display:flex; align-items:center; gap:20px; }
        .study-score-ring { width:90px; height:90px; border-radius:50%; background:rgba(255,255,255,.15); display:flex; flex-direction:column; align-items:center; justify-content:center; flex-shrink:0; border:3px solid rgba(255,255,255,.3); }
        .study-score-ring .score-num { font-size:1.6rem; font-weight:800; line-height:1; }
        .study-score-ring .score-lbl { font-size:.65rem; opacity:.8; }
        .rec-card { border-radius:14px; border-left:5px solid; padding:18px 20px; margin-bottom:14px; background:#fff; box-shadow:0 2px 10px rgba(0,0,0,.05); transition:transform .2s,box-shadow .2s; animation:fadeUp .35s ease both; }
        .rec-card:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(0,0,0,.08); }
        @keyframes fadeUp { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
        .rec-card.priority-high { border-color:var(--high); }
        .rec-card.priority-medium { border-color:var(--medium); }
        .rec-card.priority-low { border-color:var(--low); }
        .priority-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; }
        .priority-badge.high { background:#fdecea; color:var(--high); }
        .priority-badge.medium { background:#fef9e7; color:var(--medium); }
        .priority-badge.low { background:#eafaf1; color:var(--low); }
        .rec-topic { font-size:1.05rem; font-weight:700; color:#2c3e50; margin:8px 0 6px; }
        .rec-reason { font-size:.84rem; color:#666; margin-bottom:12px; }
        .rec-tips { list-style:none; padding:0; margin:0; }
        .rec-tips li { font-size:.82rem; color:#555; padding:3px 0 3px 18px; position:relative; }
        .rec-tips li::before { content:'✓'; position:absolute; left:0; color:var(--accent); font-weight:700; }
        .rec-meta { display:flex; gap:14px; margin-top:10px; flex-wrap:wrap; }
        .rec-meta-item { font-size:.75rem; color:#999; display:flex; align-items:center; gap:4px; }
        .day-card { background:#f8fbfc; border-radius:12px; padding:14px 16px; margin-bottom:8px; display:flex; gap:14px; align-items:flex-start; transition:background .15s; }
        .day-card:hover { background:#e8f4f8; }
        .day-name { font-size:.72rem; font-weight:800; color:var(--accent); text-transform:uppercase; letter-spacing:.8px; min-width:70px; padding-top:2px; }
        .day-plan { font-size:.85rem; color:#444; }
        .ai-loading { text-align:center; padding:50px 20px; }
        .ai-loading .pulse-ring { width:80px; height:80px; border-radius:50%; border:4px solid #e8f4f8; border-top-color:var(--accent); animation:spin 1s linear infinite; margin:0 auto 20px; }
        @keyframes spin { to{transform:rotate(360deg)} }
        .ai-loading .load-steps li { font-size:.84rem; color:#888; padding:4px 0; list-style:none; opacity:0; animation:fadeIn .4s ease forwards; }
        .ai-loading .load-steps li:nth-child(1){animation-delay:.4s}
        .ai-loading .load-steps li:nth-child(2){animation-delay:1.2s}
        .ai-loading .load-steps li:nth-child(3){animation-delay:2.0s}
        .ai-loading .load-steps li:nth-child(4){animation-delay:2.8s}
        @keyframes fadeIn { to{opacity:1} }
        .focus-chip { display:inline-flex; align-items:center; gap:6px; background:linear-gradient(135deg,#e8f4f8,#d0eaf0); color:var(--primary); border-radius:20px; padding:7px 16px; font-size:.82rem; font-weight:600; margin:4px; }
        .moti-banner { background:linear-gradient(135deg,#f39c12,#e67e22); border-radius:14px; padding:20px 24px; color:#fff; font-size:.92rem; font-weight:500; display:flex; align-items:center; gap:14px; margin-top:20px; }
        .moti-banner i { font-size:1.8rem; opacity:.9; flex-shrink:0; }
        .initial-state { text-align:center; padding:48px 24px; }
        .initial-state .big-icon { font-size:4rem; margin-bottom:16px; }
        .initial-state h5 { font-weight:700; color:var(--primary); }
        .initial-state p { font-size:.9rem; color:#888; }
        #toastWrap { position:fixed; bottom:24px; right:24px; z-index:9999; }
        .toast-item { background:#fff; border-radius:10px; box-shadow:0 4px 20px rgba(0,0,0,.12); padding:12px 18px; margin-top:8px; display:flex; align-items:center; gap:10px; font-size:.88rem; animation:slideRight .3s ease; }
        @keyframes slideRight { from{opacity:0;transform:translateX(40px)} to{opacity:1;transform:translateX(0)} }
        .toast-item.error i { color:#e74c3c; }
        .toast-item.success i { color:#27ae60; }
    </style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>

<div class="page-header">
    <div class="container">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <div class="p-3 rounded-3" style="background:rgba(255,255,255,.15);"><i class="fas fa-lightbulb fa-2x"></i></div>
            <div>
                <h1 class="mb-1">Study Recommendations</h1>
                <p>AI-powered personalized learning plan based on your performance</p>
            </div>
            <a href="dashboard.php" class="btn btn-outline-light ms-auto"><i class="fas fa-arrow-left me-1"></i> Dashboard</a>
        </div>
    </div>
</div>

<div class="container pb-5">
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="glass-card">
                <h6 class="fw-bold mb-3" style="color:var(--primary);"><i class="fas fa-user-graduate me-2"></i>Your Learning Profile</h6>
                <div class="row g-2">
                    <div class="col-6"><div class="stat-chip"><div class="chip-num"><?php echo $total_exams; ?></div><div class="chip-lbl">Exams Taken</div></div></div>
                    <div class="col-6"><div class="stat-chip"><div class="chip-num"><?php echo $avg_score ?: '—'; ?><?php echo $avg_score ? '%' : ''; ?></div><div class="chip-lbl">Avg Score</div></div></div>
                    <div class="col-6"><div class="stat-chip"><div class="chip-num"><?php echo $total_courses; ?></div><div class="chip-lbl">Courses</div></div></div>
                    <div class="col-6"><div class="stat-chip"><div class="chip-num"><?php echo $total_chats; ?></div><div class="chip-lbl">AI Chats</div></div></div>
                </div>
                <?php if (!empty($top_weak_areas)): ?>
                <div class="mt-3">
                    <div class="fw-semibold mb-2" style="font-size:.8rem;color:#888;letter-spacing:.5px;">IDENTIFIED WEAK AREAS</div>
                    <?php foreach ($top_weak_areas as $w): ?><span class="weak-tag"><i class="fas fa-exclamation-triangle me-1" style="font-size:.6rem;"></i><?php echo htmlspecialchars($w); ?></span><?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="glass-card">
                <h6 class="fw-bold mb-2" style="color:var(--primary);"><i class="fas fa-magic me-2"></i>Generate AI Plan</h6>
                <p style="font-size:.82rem;color:#888;margin-bottom:16px;">Our AI will analyze your exam results, weak areas, and study patterns to create a personalized learning plan.</p>
                <button class="btn-generate" id="btnGenerate" onclick="generateRecommendations()">
                    <i class="fas fa-robot"></i><span>Generate My Plan</span>
                </button>
                <div class="mt-2 text-center" style="font-size:.74rem;color:#bbb;"><i class="fas fa-lock me-1"></i>Powered by Google Gemini AI</div>
            </div>

            <div class="glass-card">
                <h6 class="fw-bold mb-3" style="color:var(--primary);"><i class="fas fa-info-circle me-2"></i>How It Works</h6>
                <div class="d-flex gap-3 mb-3"><div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#0b4954,#197f8f);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;flex-shrink:0;">1</div><div><div style="font-size:.84rem;font-weight:600;color:#333;">Analyzes Your Data</div><div style="font-size:.76rem;color:#888;">Exam scores, weak areas, chat history</div></div></div>
                <div class="d-flex gap-3 mb-3"><div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#8e44ad,#9b59b6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;flex-shrink:0;">2</div><div><div style="font-size:.84rem;font-weight:600;color:#333;">AI Generates Plan</div><div style="font-size:.76rem;color:#888;">Prioritized topics with study tips</div></div></div>
                <div class="d-flex gap-3"><div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#27ae60,#2ecc71);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;flex-shrink:0;">3</div><div><div style="font-size:.84rem;font-weight:600;color:#333;">Follow Weekly Plan</div><div style="font-size:.76rem;color:#888;">Day-by-day structured schedule</div></div></div>
            </div>
        </div>

        <div class="col-lg-8" id="mainContent">
            <div class="glass-card initial-state" id="initialState">
                <div class="big-icon">🎯</div>
                <h5>Ready to Get Your Personalized Plan?</h5>
                <p>Click "Generate My Plan" and our AI will analyze your learning data to create a customized study roadmap just for you.</p>
                <?php if ($total_exams === 0): ?>
                <div class="alert mt-3" style="background:#fff8e1;border:1px solid #ffe082;border-radius:12px;font-size:.84rem;color:#795548;">
                    <i class="fas fa-lightbulb me-2"></i><strong>Tip:</strong> Take at least one AI Exam for more accurate recommendations. <a href="ai_exam.php" style="color:var(--accent);font-weight:600;">Take an exam →</a>
                </div>
                <?php endif; ?>
            </div>

            <div class="glass-card ai-loading" id="loadingState" style="display:none;">
                <div class="pulse-ring"></div>
                <h6 class="fw-bold mb-3" style="color:var(--primary);">AI is analyzing your learning profile...</h6>
                <ul class="load-steps">
                    <li><i class="fas fa-check-circle me-2" style="color:var(--accent);"></i>Gathering your exam history</li>
                    <li><i class="fas fa-check-circle me-2" style="color:var(--accent);"></i>Identifying weak areas & patterns</li>
                    <li><i class="fas fa-check-circle me-2" style="color:var(--accent);"></i>Building personalized recommendations</li>
                    <li><i class="fas fa-check-circle me-2" style="color:var(--accent);"></i>Creating your weekly study plan</li>
                </ul>
            </div>

            <div id="resultsArea" style="display:none;"></div>
        </div>
    </div>
</div>

<div id="toastWrap"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toast(msg, type='success') {
    const icon = type==='success'?'fa-check-circle':'fa-exclamation-circle';
    const el = document.createElement('div');
    el.className = `toast-item ${type}`;
    el.innerHTML = `<i class="fas ${icon}"></i> <span>${msg}</span>`;
    document.getElementById('toastWrap').appendChild(el);
    setTimeout(() => el.remove(), 4500);
}
function generateRecommendations() {
    const btn = document.getElementById('btnGenerate');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Analyzing...</span>';
    document.getElementById('initialState').style.display = 'none';
    document.getElementById('loadingState').style.display = 'block';
    document.getElementById('resultsArea').style.display  = 'none';
    fetch('study_recommendations.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=generate_recommendations' })
    .then(r => r.json())
    .then(res => {
        document.getElementById('loadingState').style.display = 'none';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync-alt"></i> <span>Regenerate Plan</span>';
        if (res.success) { renderResults(res.data); toast('Study plan generated!', 'success'); }
        else { document.getElementById('initialState').style.display = 'block'; toast(res.message || 'Failed. Try again.', 'error'); }
    })
    .catch(() => {
        document.getElementById('loadingState').style.display = 'none';
        document.getElementById('initialState').style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-robot"></i> <span>Generate My Plan</span>';
        toast('Network error. Please try again.', 'error');
    });
}
function renderResults(data) {
    const area = document.getElementById('resultsArea');
    area.style.display = 'block';
    const sc = data.study_score >= 75 ? '#27ae60' : data.study_score >= 50 ? '#f39c12' : '#e74c3c';
    let html = `<div class="assessment-banner"><div class="study-score-ring"><div class="score-num" style="color:${sc};">${data.study_score||'—'}</div><div class="score-lbl">Study Score</div></div><div><div style="font-size:.8rem;opacity:.75;margin-bottom:4px;font-weight:600;">AI ASSESSMENT</div><div style="font-size:.95rem;line-height:1.6;">${esc(data.overall_assessment||'')}</div></div></div>`;
    if (data.focus_areas && data.focus_areas.length) {
        html += `<div class="glass-card"><h6 class="fw-bold mb-3" style="color:var(--primary);"><i class="fas fa-crosshairs me-2"></i>Top Focus Areas</h6><div>${data.focus_areas.map(a=>`<span class="focus-chip"><i class="fas fa-bullseye" style="font-size:.7rem;"></i>${esc(a)}</span>`).join('')}</div></div>`;
    }
    if (data.recommendations && data.recommendations.length) {
        html += `<div class="glass-card"><h6 class="fw-bold mb-3" style="color:var(--primary);"><i class="fas fa-list-check me-2"></i>Personalized Recommendations <span class="badge ms-2" style="background:#eef2f7;color:#666;font-size:.72rem;">${data.recommendations.length} topics</span></h6>`;
        data.recommendations.forEach((rec, i) => {
            const p = (rec.priority||'medium').toLowerCase();
            html += `<div class="rec-card priority-${p}" style="animation-delay:${i*80}ms;"><div class="d-flex align-items-center justify-content-between"><span class="priority-badge ${p}">${p} priority</span>${rec.estimated_hours?`<span style="font-size:.76rem;color:#aaa;"><i class="fas fa-clock me-1"></i>${rec.estimated_hours}h needed</span>`:''}</div><div class="rec-topic">${esc(rec.topic||'')}</div><div class="rec-reason">${esc(rec.reason||'')}</div>${rec.study_tips&&rec.study_tips.length?`<ul class="rec-tips">${rec.study_tips.map(t=>`<li>${esc(t)}</li>`).join('')}</ul>`:''}</div>`;
        });
        html += `</div>`;
    }
    if (data.weekly_plan) {
        const days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
        const labels = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        html += `<div class="glass-card"><h6 class="fw-bold mb-3" style="color:var(--primary);"><i class="fas fa-calendar-week me-2"></i>Weekly Study Plan</h6>`;
        days.forEach((d,i) => { if(data.weekly_plan[d]) html += `<div class="day-card"><div class="day-name">${labels[i]}</div><div class="day-plan">${esc(data.weekly_plan[d])}</div></div>`; });
        html += `</div>`;
    }
    if (data.motivational_message) {
        html += `<div class="moti-banner"><i class="fas fa-fire"></i><div>${esc(data.motivational_message)}</div></div>`;
    }
    area.innerHTML = html;
    area.scrollIntoView({ behavior:'smooth', block:'start' });
}
function esc(s) { const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
</script>
</body>
</html>
