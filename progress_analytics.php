<?php
require 'includes/auth.php';
require 'config.php';

$user_id = $_SESSION['user_id'];

// ── User info ─────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT name, photo, created_at FROM users WHERE id=?");
$stmt->bind_param('i',$user_id); $stmt->execute();
$stmt->bind_result($user_name,$user_photo,$joined_at); $stmt->fetch(); $stmt->close();
$days_active = max(1, (int)((time() - strtotime($joined_at)) / 86400));

// ── Overall Stats ─────────────────────────────────────────────
$total_files   = (int)$conn->query("SELECT COUNT(*) FROM files WHERE owner_id=$user_id")->fetch_row()[0];
$total_folders = (int)$conn->query("SELECT COUNT(*) FROM folders WHERE owner_id=$user_id")->fetch_row()[0];
$total_courses = (int)$conn->query("SELECT COUNT(*) FROM courses WHERE user_id=$user_id")->fetch_row()[0];
$total_topics  = (int)$conn->query("SELECT COUNT(*) FROM course_topics ct JOIN courses c ON ct.course_id=c.id WHERE c.user_id=$user_id")->fetch_row()[0];
$total_chats   = (int)$conn->query("SELECT COUNT(*) FROM ai_chat_history WHERE user_id=$user_id AND role='user'")->fetch_row()[0];
$total_exams   = (int)$conn->query("SELECT COUNT(*) FROM ai_evaluations WHERE user_id=$user_id AND status='evaluated'")->fetch_row()[0];
$avg_score_r   = $conn->query("SELECT AVG(score), MAX(score), MIN(score) FROM ai_evaluations WHERE user_id=$user_id AND status='evaluated'")->fetch_row();
$avg_score     = round((float)$avg_score_r[0], 1);
$max_score     = round((float)$avg_score_r[1], 1);
$min_score     = round((float)$avg_score_r[2], 1);
$tasks_done    = (int)$conn->query("SELECT COUNT(*) FROM todos WHERE user_id=$user_id AND status='done'")->fetch_row()[0];
$tasks_total   = (int)$conn->query("SELECT COUNT(*) FROM todos WHERE user_id=$user_id")->fetch_row()[0];
$tasks_pending = $tasks_total - $tasks_done;
$task_pct      = $tasks_total > 0 ? round($tasks_done/$tasks_total*100) : 0;
$recordings    = (int)$conn->query("SELECT COUNT(*) FROM lecture_recordings WHERE user_id=$user_id")->fetch_row()[0];

// ── Activity last 30 days (for heatmap) ───────────────────────
$heatmap = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $r = $conn->query("SELECT COUNT(*) FROM user_progress WHERE user_id=$user_id AND DATE(recorded_at)='$d'");
    $heatmap[] = ['date' => $d, 'count' => (int)$r->fetch_row()[0]];
}

// ── Activity last 7 days breakdown ────────────────────────────
$week_labels = []; $week_uploads = []; $week_chats = []; $week_exams = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $week_labels[] = date('D', strtotime("-$i days"));
    $week_uploads[] = (int)$conn->query("SELECT COUNT(*) FROM user_progress WHERE user_id=$user_id AND event_type='file_upload' AND DATE(recorded_at)='$d'")->fetch_row()[0];
    $week_chats[]   = (int)$conn->query("SELECT COUNT(*) FROM user_progress WHERE user_id=$user_id AND event_type='ai_chat' AND DATE(recorded_at)='$d'")->fetch_row()[0];
    $week_exams[]   = (int)$conn->query("SELECT COUNT(*) FROM user_progress WHERE user_id=$user_id AND event_type='exam_taken' AND DATE(recorded_at)='$d'")->fetch_row()[0];
}

// ── Exam score trend ──────────────────────────────────────────
$eq = $conn->prepare("SELECT score, max_score, DATE_FORMAT(evaluated_at,'%b %d') AS dt, weak_areas FROM ai_evaluations WHERE user_id=? AND status='evaluated' ORDER BY evaluated_at ASC LIMIT 12");
$eq->bind_param('i',$user_id); $eq->execute();
$exam_rows = $eq->get_result()->fetch_all(MYSQLI_ASSOC); $eq->close();
$exam_labels = []; $exam_pcts = [];
foreach ($exam_rows as $er) {
    $exam_labels[] = $er['dt'];
    $exam_pcts[]   = round(($er['score'] / max($er['max_score'],1)) * 100, 1);
}

// ── Score trend (linear regression slope for improvement) ─────
$improving = null;
if (count($exam_pcts) >= 2) {
    $first_half = array_slice($exam_pcts, 0, (int)(count($exam_pcts)/2));
    $last_half  = array_slice($exam_pcts, (int)(count($exam_pcts)/2));
    $improving  = array_sum($last_half)/count($last_half) > array_sum($first_half)/count($first_half);
}

// ── Course-wise performance ───────────────────────────────────
$cq = $conn->prepare(
    "SELECT c.name, c.color,
            COUNT(DISTINCT ct.id) AS topic_count,
            COUNT(DISTINCT fct.file_id) AS material_count,
            AVG(ae.score) AS avg_exam_score
     FROM courses c
     LEFT JOIN course_topics ct ON ct.course_id=c.id
     LEFT JOIN file_course_tags fct ON fct.course_id=c.id
     LEFT JOIN ai_evaluations ae ON ae.course_id=c.id AND ae.status='evaluated'
     WHERE c.user_id=?
     GROUP BY c.id ORDER BY c.created_at DESC"
);
$cq->bind_param('i',$user_id); $cq->execute();
$course_perf = $cq->get_result()->fetch_all(MYSQLI_ASSOC); $cq->close();

// ── Top weak areas ────────────────────────────────────────────
$wa_q = $conn->prepare("SELECT weak_areas FROM ai_evaluations WHERE user_id=? AND status='evaluated' AND weak_areas IS NOT NULL AND weak_areas != ''");
$wa_q->bind_param('i',$user_id); $wa_q->execute();
$wa_rows = $wa_q->get_result()->fetch_all(MYSQLI_ASSOC); $wa_q->close();
$all_weak = [];
foreach ($wa_rows as $r2) { $all_weak = array_merge($all_weak, array_map('trim', explode(',', $r2['weak_areas']))); }
$weak_freq = array_count_values($all_weak); arsort($weak_freq);
$top_weak = array_slice($weak_freq, 0, 8, true);

// ── Event type distribution ───────────────────────────────────
$ev_q = $conn->query("SELECT event_type, COUNT(*) AS cnt FROM user_progress WHERE user_id=$user_id GROUP BY event_type");
$event_dist = $ev_q->fetch_all(MYSQLI_ASSOC);

// ── Recent activity feed ──────────────────────────────────────
$af_q = $conn->prepare("SELECT event_type, event_detail, recorded_at FROM user_progress WHERE user_id=? ORDER BY recorded_at DESC LIMIT 12");
$af_q->bind_param('i',$user_id); $af_q->execute();
$activity_feed = $af_q->get_result()->fetch_all(MYSQLI_ASSOC); $af_q->close();

// ── Overall readiness score ───────────────────────────────────
$readiness = 0;
if ($total_files > 0)    $readiness += 15;
if ($total_courses > 0)  $readiness += 15;
if ($total_topics > 5)   $readiness += 10;
if ($total_chats > 5)    $readiness += 15;
if ($total_exams > 0)    $readiness += 20;
if ($avg_score >= 70)    $readiness += 15;
if ($task_pct >= 50)     $readiness += 10;
$readiness = min(100, $readiness);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Learning Analytics — NoteNest AI</title>
    <meta name="description" content="Track your academic progress, learning patterns, exam performance, and overall study analytics.">
    <link rel="shortcut icon" href="img/fav.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { --primary:#0b4954; --accent:#197f8f; --bg:#f0f4f8; }
        body { font-family:'Inter',sans-serif; background:var(--bg); }
        .page-header { background:linear-gradient(135deg,#0b4954 0%,#197f8f 60%,#1aacbf 100%); padding:36px 0 30px; color:#fff; margin-bottom:32px; }
        .page-header h1 { font-size:2rem; font-weight:800; }
        .page-header p { opacity:.82; font-size:.95rem; margin:0; }
        .card-g { background:#fff; border-radius:18px; box-shadow:0 4px 24px rgba(0,0,0,.06); padding:24px; margin-bottom:24px; }
        .card-g:hover { box-shadow:0 8px 32px rgba(0,0,0,.09); }
        .section-title { font-size:1rem; font-weight:700; color:var(--primary); margin-bottom:16px; display:flex; align-items:center; gap:8px; }

        /* Readiness Gauge */
        .readiness-wrap { text-align:center; padding:10px 0; }
        .gauge-outer { width:160px; height:160px; border-radius:50%; background:conic-gradient(var(--accent) <?php echo $readiness * 3.6; ?>deg, #e8f4f8 0deg); display:flex; align-items:center; justify-content:center; margin:0 auto 12px; box-shadow:0 4px 20px rgba(25,127,143,.2); }
        .gauge-inner { width:124px; height:124px; border-radius:50%; background:#fff; display:flex; flex-direction:column; align-items:center; justify-content:center; }
        .gauge-num { font-size:2.2rem; font-weight:800; color:var(--primary); line-height:1; }
        .gauge-lbl { font-size:.72rem; color:#888; font-weight:600; }

        /* Big Stat Cards */
        .big-stat { border-radius:16px; padding:20px 18px; text-align:center; height:100%; }
        .big-stat .bs-icon { font-size:1.6rem; margin-bottom:8px; }
        .big-stat .bs-num { font-size:2rem; font-weight:800; line-height:1; color:#2c3e50; }
        .big-stat .bs-lbl { font-size:.76rem; color:#888; font-weight:600; margin-top:4px; }

        /* Score Summary Row */
        .score-pill { display:inline-flex; align-items:center; gap:6px; padding:8px 18px; border-radius:30px; font-size:.9rem; font-weight:700; }

        /* Heatmap */
        .heatmap-grid { display:grid; grid-template-columns:repeat(10, 1fr); gap:5px; }
        .hm-cell { width:100%; aspect-ratio:1; border-radius:4px; cursor:default; transition:transform .1s; }
        .hm-cell:hover { transform:scale(1.3); }
        .hm-0 { background:#e8f4f8; }
        .hm-1 { background:#b0d8e4; }
        .hm-2 { background:#7abfcf; }
        .hm-3 { background:#197f8f; }
        .hm-4 { background:#0b4954; }

        /* Course Performance Table */
        .course-row { display:flex; align-items:center; gap:14px; padding:12px 0; border-bottom:1px solid #f5f6fa; }
        .course-row:last-child { border-bottom:none; }
        .course-color { width:12px; height:12px; border-radius:50%; flex-shrink:0; }
        .course-name { font-weight:600; font-size:.88rem; color:#2c3e50; flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .course-bar-wrap { width:120px; height:8px; background:#e8f4f8; border-radius:4px; overflow:hidden; flex-shrink:0; }
        .course-bar { height:100%; border-radius:4px; background:linear-gradient(90deg,var(--primary),var(--accent)); transition:width 1s ease; }

        /* Weak areas */
        .weak-row { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #f5f7fa; }
        .weak-row:last-child { border-bottom:none; }
        .weak-name { flex:1; font-size:.84rem; color:#444; }
        .weak-count-bar { width:80px; height:6px; background:#fdecea; border-radius:3px; overflow:hidden; }
        .weak-count-fill { height:100%; background:#e74c3c; border-radius:3px; }
        .weak-badge { font-size:.72rem; color:#e74c3c; font-weight:700; min-width:20px; text-align:right; }

        /* Activity feed */
        .af-item { display:flex; align-items:center; gap:12px; padding:8px 0; border-bottom:1px solid #f5f6fa; }
        .af-item:last-child { border-bottom:none; }
        .af-dot { width:34px; height:34px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:.78rem; flex-shrink:0; }
        .af-text { font-size:.82rem; color:#444; flex:1; }
        .af-time { font-size:.72rem; color:#bbb; white-space:nowrap; }

        /* Improvement badge */
        .improve-badge { display:inline-flex; align-items:center; gap:6px; padding:6px 14px; border-radius:20px; font-size:.82rem; font-weight:700; }
        .improve-badge.up   { background:#eafaf1; color:#27ae60; }
        .improve-badge.down { background:#fdecea; color:#e74c3c; }
        .improve-badge.na   { background:#f0f2f5; color:#888; }

        #toastWrap { position:fixed; bottom:24px; right:24px; z-index:9999; }
        .toast-item { background:#fff; border-radius:10px; box-shadow:0 4px 20px rgba(0,0,0,.12); padding:12px 18px; margin-top:8px; display:flex; align-items:center; gap:10px; font-size:.88rem; }
    </style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>

<div class="page-header">
    <div class="container">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <div class="p-3 rounded-3" style="background:rgba(255,255,255,.15);"><i class="fas fa-chart-line fa-2x"></i></div>
            <div>
                <h1 class="mb-1">Learning Analytics</h1>
                <p>Detailed progress tracking, performance analysis, and academic insights</p>
            </div>
            <div class="ms-auto d-flex gap-2 flex-wrap">
                <a href="study_recommendations.php" class="btn btn-light" style="border-radius:10px;font-weight:600;font-size:.86rem;">
                    <i class="fas fa-lightbulb me-1"></i>Get Recommendations
                </a>
                <a href="dashboard.php" class="btn btn-outline-light" style="border-radius:10px;font-size:.86rem;">
                    <i class="fas fa-arrow-left me-1"></i>Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">

    <!-- ── Row 1: Big Stats ── -->
    <div class="row g-3 mb-4">
        <?php
        $big_stats = [
            ['icon'=>'📁','num'=>$total_files,    'lbl'=>'Files Uploaded',    'bg'=>'#e8f4fd'],
            ['icon'=>'📚','num'=>$total_courses,  'lbl'=>'Courses Enrolled',  'bg'=>'#fef9e7'],
            ['icon'=>'🧠','num'=>$total_exams,    'lbl'=>'Exams Completed',   'bg'=>'#f4ecf7'],
            ['icon'=>'🤖','num'=>$total_chats,    'lbl'=>'AI Tutor Sessions', 'bg'=>'#eafaf1'],
            ['icon'=>'✅','num'=>$tasks_done,     'lbl'=>'Tasks Completed',   'bg'=>'#e8f8f5'],
            ['icon'=>'🎙️','num'=>$recordings,    'lbl'=>'Lectures Recorded', 'bg'=>'#fdecea'],
        ];
        foreach ($big_stats as $s): ?>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card-g big-stat" style="background:<?php echo $s['bg']; ?>;">
                <div class="bs-icon"><?php echo $s['icon']; ?></div>
                <div class="bs-num"><?php echo $s['num']; ?></div>
                <div class="bs-lbl"><?php echo $s['lbl']; ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">

        <!-- ── Left Column ── -->
        <div class="col-lg-4">

            <!-- Academic Readiness Gauge -->
            <div class="card-g">
                <div class="section-title"><i class="fas fa-tachometer-alt"></i> Academic Readiness</div>
                <div class="readiness-wrap">
                    <div class="gauge-outer">
                        <div class="gauge-inner">
                            <div class="gauge-num"><?php echo $readiness; ?></div>
                            <div class="gauge-lbl">/ 100</div>
                        </div>
                    </div>
                    <?php
                    if ($readiness >= 80)      echo '<span class="badge" style="background:#27ae60;color:#fff;border-radius:20px;padding:6px 16px;font-size:.82rem;">🏆 Excellent</span>';
                    elseif ($readiness >= 60)  echo '<span class="badge" style="background:#f39c12;color:#fff;border-radius:20px;padding:6px 16px;font-size:.82rem;">📈 Good Progress</span>';
                    elseif ($readiness >= 40)  echo '<span class="badge" style="background:#e67e22;color:#fff;border-radius:20px;padding:6px 16px;font-size:.82rem;">💪 Keep Going</span>';
                    else                       echo '<span class="badge" style="background:#e74c3c;color:#fff;border-radius:20px;padding:6px 16px;font-size:.82rem;">🚀 Just Starting</span>';
                    ?>
                    <div class="mt-3" style="font-size:.78rem;color:#888;">Based on files, exams, tasks, and AI usage</div>
                </div>

                <div class="mt-4">
                    <div style="font-size:.8rem;font-weight:600;color:#888;margin-bottom:10px;">BREAKDOWN</div>
                    <?php
                    $checks = [
                        ['Files uploaded',         $total_files > 0,   'Upload study materials'],
                        ['Courses registered',      $total_courses > 0, 'Register a course'],
                        ['Topics added (5+)',       $total_topics > 5,  'Add syllabus topics'],
                        ['AI chats (5+)',           $total_chats > 5,   'Chat with AI tutor'],
                        ['Exam taken',              $total_exams > 0,   'Take an AI exam'],
                        ['Score ≥ 70%',             $avg_score >= 70,   'Improve exam score'],
                        ['Task completion ≥ 50%',   $task_pct >= 50,    'Complete more tasks'],
                    ];
                    foreach ($checks as $c):
                        $done = $c[1];
                    ?>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="fas <?php echo $done ? 'fa-check-circle text-success' : 'fa-circle' ; ?>" style="font-size:.85rem;<?php echo $done ? '' : 'color:#ddd;'; ?>"></i>
                        <div style="flex:1;font-size:.82rem;color:<?php echo $done ? '#2c3e50' : '#aaa'; ?>;"><?php echo $c[0]; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Task Completion -->
            <div class="card-g">
                <div class="section-title"><i class="fas fa-tasks"></i> Task Completion</div>
                <?php if ($tasks_total > 0): ?>
                <canvas id="taskDonut" height="160"></canvas>
                <div class="text-center mt-2">
                    <span style="color:#27ae60;font-weight:700;font-size:.88rem;"><?php echo $tasks_done; ?> done</span>
                    &nbsp;·&nbsp;
                    <span style="color:#e67e22;font-weight:700;font-size:.88rem;"><?php echo $tasks_pending; ?> pending</span>
                    &nbsp;·&nbsp;
                    <span style="color:var(--accent);font-weight:700;font-size:.88rem;"><?php echo $task_pct; ?>% rate</span>
                </div>
                <?php else: ?>
                <div class="text-center py-3 text-muted" style="font-size:.85rem;"><i class="fas fa-tasks fa-2x mb-2" style="color:#ddd;display:block;"></i>No tasks yet. <a href="todo.php">Add tasks →</a></div>
                <?php endif; ?>
            </div>

            <!-- Activity Feed -->
            <div class="card-g">
                <div class="section-title"><i class="fas fa-history"></i> Recent Activity</div>
                <?php
                $act_icons = [
                    'file_upload' => ['fa-upload','#2980b9','#e8f4fd'],
                    'ai_chat'     => ['fa-robot', '#27ae60','#eafaf1'],
                    'exam_taken'  => ['fa-brain',  '#8e44ad','#f4ecf7'],
                    'task_done'   => ['fa-check',  '#27ae60','#eafaf1'],
                    'login'       => ['fa-sign-in-alt','#e67e22','#fef5e7'],
                    'note_view'   => ['fa-eye',    '#2980b9','#e8f4fd'],
                ];
                if (empty($activity_feed)): ?>
                <div class="text-center py-3 text-muted" style="font-size:.85rem;"><i class="fas fa-satellite-dish fa-2x mb-2" style="color:#ddd;display:block;"></i>No activity yet</div>
                <?php else: foreach ($activity_feed as $act):
                    $ic = $act_icons[$act['event_type']] ?? ['fa-circle','#888','#f0f0f0'];
                    $dt = new DateTime($act['recorded_at']);
                    $now = new DateTime();
                    $diff = $now->diff($dt);
                    $ts = $diff->days > 0 ? $diff->days.'d ago' : ($diff->h > 0 ? $diff->h.'h ago' : 'Just now');
                ?>
                <div class="af-item">
                    <div class="af-dot" style="background:<?php echo $ic[2]; ?>;"><i class="fas <?php echo $ic[0]; ?>" style="color:<?php echo $ic[1]; ?>;"></i></div>
                    <div class="af-text"><?php echo htmlspecialchars($act['event_detail'] ?: ucwords(str_replace('_',' ',$act['event_type']))); ?></div>
                    <div class="af-time"><?php echo $ts; ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- ── Right Column ── -->
        <div class="col-lg-8">

            <!-- Exam Score Performance -->
            <div class="card-g">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="section-title mb-0"><i class="fas fa-chart-line"></i> Exam Score Trend</div>
                    <div class="d-flex align-items-center gap-10 flex-wrap gap-2">
                        <?php if ($total_exams > 0): ?>
                        <span class="score-pill" style="background:#e8f4f8;color:var(--primary);font-size:.8rem;">Avg <?php echo $avg_score; ?>%</span>
                        <span class="score-pill" style="background:#eafaf1;color:#27ae60;font-size:.8rem;">Best <?php echo $max_score; ?>%</span>
                        <?php if ($improving !== null): ?>
                        <span class="improve-badge <?php echo $improving ? 'up' : 'down'; ?>">
                            <i class="fas fa-arrow-<?php echo $improving ? 'up' : 'down'; ?>"></i>
                            <?php echo $improving ? 'Improving' : 'Declining'; ?>
                        </span>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($exam_pcts)): ?>
                <canvas id="examTrendChart" height="90"></canvas>
                <?php else: ?>
                <div class="text-center py-4 text-muted" style="font-size:.88rem;">
                    <i class="fas fa-brain fa-2x mb-2" style="color:#ddd;display:block;"></i>
                    No exam data yet. <a href="ai_exam.php" style="color:var(--accent);font-weight:600;">Take an AI exam →</a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Weekly Activity Breakdown -->
            <div class="card-g">
                <div class="section-title"><i class="fas fa-chart-bar"></i> Weekly Activity Breakdown</div>
                <canvas id="weeklyChart" height="90"></canvas>
            </div>

            <!-- 30-Day Activity Heatmap -->
            <div class="card-g">
                <div class="section-title"><i class="fas fa-th"></i> 30-Day Activity Heatmap</div>
                <div class="heatmap-grid mb-2">
                    <?php foreach ($heatmap as $h):
                        $lvl = $h['count'] === 0 ? 0 : ($h['count'] <= 2 ? 1 : ($h['count'] <= 4 ? 2 : ($h['count'] <= 7 ? 3 : 4)));
                        $label = $h['date'].': '.$h['count'].' activities';
                    ?>
                    <div class="hm-cell hm-<?php echo $lvl; ?>" title="<?php echo $label; ?>"></div>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex align-items-center gap-2 mt-1" style="font-size:.73rem;color:#aaa;">
                    <span>Less</span>
                    <div class="hm-cell hm-0" style="width:14px;height:14px;display:inline-block;border-radius:3px;"></div>
                    <div class="hm-cell hm-1" style="width:14px;height:14px;display:inline-block;border-radius:3px;"></div>
                    <div class="hm-cell hm-2" style="width:14px;height:14px;display:inline-block;border-radius:3px;"></div>
                    <div class="hm-cell hm-3" style="width:14px;height:14px;display:inline-block;border-radius:3px;"></div>
                    <div class="hm-cell hm-4" style="width:14px;height:14px;display:inline-block;border-radius:3px;"></div>
                    <span>More</span>
                </div>
            </div>

            <!-- Course Performance -->
            <?php if (!empty($course_perf)): ?>
            <div class="card-g">
                <div class="section-title"><i class="fas fa-graduation-cap"></i> Course-wise Overview</div>
                <?php foreach ($course_perf as $cp):
                    $avg_e = $cp['avg_exam_score'] ? round($cp['avg_exam_score'],1) : null;
                    $bar_w = $avg_e ? min(100, $avg_e) : 0;
                ?>
                <div class="course-row">
                    <div class="course-color" style="background:<?php echo htmlspecialchars($cp['color']); ?>;"></div>
                    <div class="course-name"><?php echo htmlspecialchars($cp['name']); ?></div>
                    <div style="font-size:.75rem;color:#aaa;flex-shrink:0;"><?php echo $cp['topic_count']; ?> topics</div>
                    <div style="font-size:.75rem;color:#aaa;flex-shrink:0;"><?php echo $cp['material_count']; ?> files</div>
                    <?php if ($avg_e !== null): ?>
                    <div class="course-bar-wrap">
                        <div class="course-bar" style="width:<?php echo $bar_w; ?>%;"></div>
                    </div>
                    <div style="font-size:.8rem;font-weight:700;color:var(--primary);min-width:42px;text-align:right;"><?php echo $avg_e; ?>%</div>
                    <?php else: ?>
                    <div style="font-size:.74rem;color:#bbb;min-width:80px;text-align:right;">No exam yet</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Weak Areas Analysis -->
            <?php if (!empty($top_weak)): ?>
            <div class="card-g">
                <div class="section-title"><i class="fas fa-exclamation-triangle"></i> Weak Areas Analysis
                    <a href="study_recommendations.php" class="ms-auto" style="font-size:.78rem;color:var(--accent);font-weight:600;text-decoration:none;">Get Study Plan →</a>
                </div>
                <?php $max_cnt = max(array_values($top_weak)); foreach ($top_weak as $topic => $cnt): ?>
                <div class="weak-row">
                    <div class="weak-name"><?php echo htmlspecialchars($topic); ?></div>
                    <div class="weak-count-bar"><div class="weak-count-fill" style="width:<?php echo round($cnt/$max_cnt*100); ?>%;"></div></div>
                    <div class="weak-badge"><?php echo $cnt; ?>×</div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Activity Distribution -->
            <?php if (!empty($event_dist)): ?>
            <div class="card-g">
                <div class="section-title"><i class="fas fa-chart-pie"></i> Activity Distribution</div>
                <div class="row align-items-center">
                    <div class="col-md-5"><canvas id="actDistChart" height="180"></canvas></div>
                    <div class="col-md-7">
                        <?php
                        $ev_labels = ['file_upload'=>'File Uploads','ai_chat'=>'AI Chat Sessions','exam_taken'=>'Exams Taken','task_done'=>'Tasks Done','login'=>'Logins','note_view'=>'File Views'];
                        $ev_colors = ['file_upload'=>'#2980b9','ai_chat'=>'#27ae60','exam_taken'=>'#8e44ad','task_done'=>'#27ae60','login'=>'#e67e22','note_view'=>'#3498db'];
                        foreach ($event_dist as $ed):
                            $lbl = $ev_labels[$ed['event_type']] ?? ucwords(str_replace('_',' ',$ed['event_type']));
                            $col = $ev_colors[$ed['event_type']] ?? '#888';
                        ?>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div style="width:10px;height:10px;border-radius:50%;background:<?php echo $col; ?>;flex-shrink:0;"></div>
                            <div style="flex:1;font-size:.82rem;color:#555;"><?php echo $lbl; ?></div>
                            <div style="font-size:.82rem;font-weight:700;color:#2c3e50;"><?php echo $ed['cnt']; ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<div id="toastWrap"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Task Donut ────────────────────────────────────────────────
<?php if ($tasks_total > 0): ?>
new Chart(document.getElementById('taskDonut'), {
    type:'doughnut',
    data:{ labels:['Done','Pending'], datasets:[{ data:[<?php echo $tasks_done; ?>,<?php echo $tasks_pending; ?>], backgroundColor:['#27ae60','#e67e22'], borderWidth:0 }] },
    options:{ responsive:true, cutout:'70%', plugins:{ legend:{ position:'bottom', labels:{ font:{size:11}, color:'#888', padding:12 } } } }
});
<?php endif; ?>

// ── Exam Trend Chart ──────────────────────────────────────────
<?php if (!empty($exam_pcts)): ?>
new Chart(document.getElementById('examTrendChart'), {
    type:'line',
    data:{
        labels: <?php echo json_encode($exam_labels); ?>,
        datasets:[{
            label:'Score %',
            data: <?php echo json_encode($exam_pcts); ?>,
            borderColor:'#197f8f', backgroundColor:'rgba(25,127,143,.08)',
            borderWidth:2.5, pointBackgroundColor:'#0b4954', pointRadius:5, tension:0.4, fill:true
        }]
    },
    options:{
        responsive:true,
        plugins:{ legend:{display:false}, tooltip:{ callbacks:{ label: ctx => ` ${ctx.raw}%` } } },
        scales:{
            y:{ beginAtZero:true, max:100, ticks:{ color:'#aaa', font:{size:11}, callback: v => v+'%' }, grid:{ color:'#f0f2f5' } },
            x:{ ticks:{ color:'#aaa', font:{size:11} }, grid:{ display:false } }
        }
    }
});
<?php endif; ?>

// ── Weekly Activity Chart ─────────────────────────────────────
new Chart(document.getElementById('weeklyChart'), {
    type:'bar',
    data:{
        labels: <?php echo json_encode($week_labels); ?>,
        datasets:[
            { label:'Uploads', data:<?php echo json_encode($week_uploads); ?>, backgroundColor:'rgba(41,128,185,.65)', borderRadius:5 },
            { label:'AI Chats', data:<?php echo json_encode($week_chats); ?>,  backgroundColor:'rgba(39,174,96,.65)',  borderRadius:5 },
            { label:'Exams',   data:<?php echo json_encode($week_exams); ?>,   backgroundColor:'rgba(142,68,173,.65)', borderRadius:5 }
        ]
    },
    options:{
        responsive:true,
        plugins:{ legend:{ position:'top', labels:{ font:{size:11}, color:'#888', padding:14 } } },
        scales:{
            y:{ beginAtZero:true, ticks:{ stepSize:1, color:'#aaa', font:{size:11} }, grid:{ color:'#f0f2f5' }, stacked:false },
            x:{ ticks:{ color:'#aaa', font:{size:11} }, grid:{ display:false } }
        }
    }
});

// ── Activity Distribution Pie ─────────────────────────────────
<?php if (!empty($event_dist)): ?>
new Chart(document.getElementById('actDistChart'), {
    type:'doughnut',
    data:{
        labels: <?php echo json_encode(array_map(fn($e) => ucwords(str_replace('_',' ',$e['event_type'])), $event_dist)); ?>,
        datasets:[{
            data: <?php echo json_encode(array_column($event_dist,'cnt')); ?>,
            backgroundColor:['#2980b9','#27ae60','#8e44ad','#e67e22','#e74c3c','#3498db','#f39c12'],
            borderWidth:2, borderColor:'#fff'
        }]
    },
    options:{ responsive:true, cutout:'60%', plugins:{ legend:{display:false} } }
});
<?php endif; ?>
</script>
</body>
</html>
