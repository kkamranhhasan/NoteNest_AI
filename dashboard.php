<?php
// ============================================================
// dashboard.php — NoteNest AI Platform v2.0
// Upgraded Dashboard: Analytics + Quick Stats + Todo + AI Cards
// ============================================================
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require 'config.php';
require 'includes/ai_service.php';

$user_id = $_SESSION['user_id'];

// ── Fresh user data ───────────────────────────────────────────
$stmt = $conn->prepare("SELECT name, photo, created_at FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stmt->bind_result($db_name, $db_photo, $db_created);
$stmt->fetch();
$stmt->close();
$_SESSION['user_name']  = $db_name;
$_SESSION['user_photo'] = $db_photo;

// Time-based greeting
$hour = (int)date('H');
$greeting = $hour < 12 ? "Good Morning" : ($hour < 17 ? "Good Afternoon" : "Good Evening");
$member_since = date('F Y', strtotime($db_created));

// ── Quick Stats ───────────────────────────────────────────────
$stats = [];

$r = $conn->query("SELECT COUNT(*) FROM files WHERE owner_id=$user_id");
$stats['files'] = $r->fetch_row()[0];

$r = $conn->query("SELECT COUNT(*) FROM folders WHERE owner_id=$user_id");
$stats['folders'] = $r->fetch_row()[0];

$r = $conn->query("SELECT COUNT(*) FROM ai_chat_history WHERE user_id=$user_id AND role='user'");
$stats['ai_chats'] = $r->fetch_row()[0];

$r = $conn->query("SELECT COUNT(*) FROM ai_evaluations WHERE user_id=$user_id AND status='evaluated'");
$stats['exams'] = $r->fetch_row()[0];

$r = $conn->query("SELECT COUNT(*) FROM todos WHERE user_id=$user_id AND status='pending'");
$stats['pending_tasks'] = $r->fetch_row()[0];

$r = $conn->query("SELECT COUNT(*) FROM courses WHERE user_id=$user_id");
$stats['courses'] = $r->fetch_row()[0];

// ── Analytics: Activity last 7 days ──────────────────────────
$activity_labels = [];
$activity_data   = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $label= date('D', strtotime("-$i days"));
    $activity_labels[] = $label;
    $r = $conn->query("SELECT COUNT(*) FROM user_progress WHERE user_id=$user_id AND DATE(recorded_at)='$date'");
    $activity_data[] = (int)$r->fetch_row()[0];
}

// ── Analytics: Task completion ────────────────────────────────
$r = $conn->query("SELECT COUNT(*) FROM todos WHERE user_id=$user_id AND status='done'");
$tasks_done    = (int)$r->fetch_row()[0];
$r = $conn->query("SELECT COUNT(*) FROM todos WHERE user_id=$user_id");
$tasks_total   = (int)$r->fetch_row()[0];
$tasks_pending = $tasks_total - $tasks_done;

// ── Analytics: Recent exam scores ────────────────────────────
$exam_labels = [];
$exam_scores = [];
$eq = $conn->prepare(
    "SELECT score, DATE_FORMAT(evaluated_at,'%b %d') as dt
     FROM ai_evaluations WHERE user_id=? AND status='evaluated'
     ORDER BY evaluated_at DESC LIMIT 6"
);
$eq->bind_param('i', $user_id);
$eq->execute();
$erows = $eq->get_result()->fetch_all(MYSQLI_ASSOC);
$eq->close();
foreach (array_reverse($erows) as $er) {
    $exam_labels[] = $er['dt'];
    $exam_scores[] = round((float)$er['score']);
}

// ── Upcoming Todos (next 5) ───────────────────────────────────
$tq = $conn->prepare(
    "SELECT id, title, event_datetime, priority, task_type, status
     FROM todos WHERE user_id=? AND status='pending'
     ORDER BY event_datetime ASC LIMIT 5"
);
$tq->bind_param('i', $user_id);
$tq->execute();
$upcoming_todos = $tq->get_result()->fetch_all(MYSQLI_ASSOC);
$tq->close();

// ── Recent AI activity ────────────────────────────────────────
$aq = $conn->prepare(
    "SELECT event_type, event_detail, recorded_at FROM user_progress
     WHERE user_id=? ORDER BY recorded_at DESC LIMIT 6"
);
$aq->bind_param('i', $user_id);
$aq->execute();
$recent_activity = $aq->get_result()->fetch_all(MYSQLI_ASSOC);
$aq->close();

// Log login progress
logProgress($conn, $user_id, 'login', 'Dashboard visit');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Dashboard — NoteNest AI</title>
    <link rel="shortcut icon" href="img/fav.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary:#0b4954; --accent:#197f8f; --bg:#f0f4f8; }
        body { font-family:'Inter',sans-serif; background:var(--bg); }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg,#0b4954 0%,#197f8f 60%,#1aacbf 100%);
            border-radius:18px; padding:32px 36px;
            display:flex; align-items:center; gap:24px;
            margin-bottom:28px;
            box-shadow:0 8px 30px rgba(11,73,84,.18);
            animation:slideDown .5s ease;
        }
        @keyframes slideDown { from{opacity:0;transform:translateY(-18px)} to{opacity:1;transform:translateY(0)} }
        .welcome-avatar { width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,.6);flex-shrink:0;transition:transform .3s; }
        .welcome-avatar:hover { transform:scale(1.06); }
        .welcome-greeting { font-size:13px;color:rgba(255,255,255,.75);margin-bottom:3px; }
        .welcome-name { font-size:24px;font-weight:700;color:#fff;margin:0 0 4px; }
        .welcome-meta { font-size:12px;color:rgba(255,255,255,.6); }
        .welcome-edit-btn { background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:8px;padding:8px 18px;font-size:13px;text-decoration:none;transition:all .2s;white-space:nowrap; }
        .welcome-edit-btn:hover { background:rgba(255,255,255,.28);color:#fff;transform:translateY(-1px); }

        /* Quick Stats */
        .stat-card {
            background:#fff;border-radius:14px;padding:20px 22px;
            box-shadow:0 2px 12px rgba(0,0,0,.05);
            display:flex;align-items:center;gap:16px;
            transition:transform .2s,box-shadow .2s;
        }
        .stat-card:hover { transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,.09); }
        .stat-icon { width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0; }
        .stat-num { font-size:1.6rem;font-weight:800;color:var(--primary);line-height:1; }
        .stat-lbl { font-size:.75rem;color:#888;font-weight:500;margin-top:2px; }

        /* Section headers */
        .section-title { font-size:1rem;font-weight:700;color:var(--primary);margin-bottom:16px;display:flex;align-items:center;gap:8px; }

        /* Chart cards */
        .chart-card { background:#fff;border-radius:16px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,.05);margin-bottom:24px; }

        /* Navigation cards */
        .nav-card {
            background:#fff;border-radius:14px;padding:22px 18px;text-align:center;
            box-shadow:0 2px 12px rgba(0,0,0,.05);
            transition:transform .2s,box-shadow .2s;
            text-decoration:none;display:block;
        }
        .nav-card:hover { transform:translateY(-4px);box-shadow:0 10px 28px rgba(0,0,0,.1); }
        .nav-card .nav-icon { font-size:2rem;margin-bottom:10px; }
        .nav-card h6 { font-weight:700;color:#2c3e50;margin-bottom:4px;font-size:.92rem; }
        .nav-card p { font-size:.78rem;color:#888;margin:0; }

        /* AI feature cards */
        .ai-feature-card {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius:14px;padding:22px;color:#fff;
            transition:transform .2s,box-shadow .2s;
            text-decoration:none;display:block;
        }
        .ai-feature-card:hover { transform:translateY(-4px);box-shadow:0 10px 30px rgba(11,73,84,.3);color:#fff; }
        .ai-feature-card .ai-icon { font-size:1.8rem;margin-bottom:10px;opacity:.9; }
        .ai-feature-card h6 { font-weight:700;margin-bottom:4px;font-size:.92rem; }
        .ai-feature-card p { font-size:.78rem;opacity:.8;margin:0; }

        /* Todo items */
        .todo-item {
            display:flex;align-items:center;gap:12px;
            padding:10px 14px;border-radius:10px;
            background:#f8fafb;margin-bottom:8px;
            transition:background .15s;
        }
        .todo-item:hover { background:#f0f7f9; }
        .priority-dot { width:10px;height:10px;border-radius:50%;flex-shrink:0; }
        .priority-dot.high   { background:#e74c3c; }
        .priority-dot.medium { background:#f39c12; }
        .priority-dot.low    { background:#27ae60; }
        .todo-title { font-size:.86rem;font-weight:600;color:#2c3e50;flex:1; }
        .todo-due   { font-size:.75rem;color:#888; }
        .todo-type-badge { font-size:.7rem;padding:2px 8px;border-radius:8px;background:#e8edf2;color:#555;font-weight:600; }

        /* Activity feed */
        .activity-item { display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid #f5f6fa; }
        .activity-item:last-child { border-bottom:none; }
        .activity-icon { width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.78rem;flex-shrink:0; }
        .activity-text { font-size:.82rem;color:#444;flex:1; }
        .activity-time { font-size:.72rem;color:#bbb; }

        @media(max-width:576px) {
            .welcome-banner { flex-wrap:wrap;padding:22px 20px; }
            .welcome-name   { font-size:20px; }
            .welcome-edit-btn { width:100%;text-align:center; }
        }
    </style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>

<div class="container py-4">

    <!-- ── Welcome Banner ── -->
    <div class="welcome-banner">
        <a href="profile.php">
            <img src="<?php echo htmlspecialchars($db_photo ?: 'img/user.png'); ?>"
                 alt="Profile" class="welcome-avatar">
        </a>
        <div style="flex:1;">
            <p class="welcome-greeting"><?php echo $greeting; ?> 👋</p>
            <h2 class="welcome-name"><?php echo htmlspecialchars($db_name); ?></h2>
            <p class="welcome-meta">
                <i class="fas fa-calendar-alt me-1"></i>Member since <?php echo $member_since; ?>
                &nbsp;·&nbsp;
                <i class="fas fa-bolt me-1"></i><?php echo $stats['ai_chats']; ?> AI interactions
            </p>
        </div>
        <a href="profile.php" class="welcome-edit-btn" style="align-self:flex-start; margin-right: 10px;"><i class="fas fa-pen me-1"></i>Edit Profile</a>
        <button onclick="syncStorage()" class="welcome-edit-btn" style="align-self:flex-start; background: #e67e22; border-color: #e67e22;" id="btnSyncStorage">
            <i class="fas fa-sync-alt me-1"></i>Sync Storage & DB
        </button>
    </div>

    <!-- ── Quick Stats ── -->
    <div class="row g-3 mb-4">
        <?php
        $stat_items = [
            ['icon'=>'fa-file','bg'=>'#e8f4fd','color'=>'#2980b9','num'=>$stats['files'],    'lbl'=>'Files'],
            ['icon'=>'fa-graduation-cap','bg'=>'#fef9e7','color'=>'#f39c12','num'=>$stats['courses'],  'lbl'=>'Courses'],
            ['icon'=>'fa-robot','bg'=>'#eafaf1','color'=>'#27ae60','num'=>$stats['ai_chats'],'lbl'=>'AI Chats'],
            ['icon'=>'fa-brain','bg'=>'#fdecea','color'=>'#e74c3c','num'=>$stats['exams'],   'lbl'=>'Exams Taken'],
            ['icon'=>'fa-tasks','bg'=>'#fef5e7','color'=>'#e67e22','num'=>$stats['pending_tasks'],'lbl'=>'Pending Tasks'],
            ['icon'=>'fa-folder','bg'=>'#f4ecf7','color'=>'#8e44ad','num'=>$stats['folders'],'lbl'=>'Folders'],
        ];
        foreach ($stat_items as $s): ?>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="stat-card">
                <div class="stat-icon" style="background:<?php echo $s['bg']; ?>;">
                    <i class="fas <?php echo $s['icon']; ?>" style="color:<?php echo $s['color']; ?>;"></i>
                </div>
                <div>
                    <div class="stat-num"><?php echo $s['num']; ?></div>
                    <div class="stat-lbl"><?php echo $s['lbl']; ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">

        <!-- ── Left Column ── -->
        <div class="col-lg-8">

            <!-- Navigation Cards -->
            <div class="section-title"><i class="fas fa-th-large"></i> Quick Access</div>
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <a href="my_note_nest.php" class="nav-card">
                        <div class="nav-icon">📁</div>
                        <h6>MyNoteNest</h6>
                        <p>Your files & folders</p>
                    </a>
                </div>
                <div class="col-6 col-md-3">
                    <a href="shared_note_nest.php" class="nav-card">
                        <div class="nav-icon">🔗</div>
                        <h6>Shared</h6>
                        <p>Files shared with you</p>
                    </a>
                </div>
                <div class="col-6 col-md-3">
                    <a href="favorites.php" class="nav-card">
                        <div class="nav-icon">⭐</div>
                        <h6>Favorites</h6>
                        <p>Quick access list</p>
                    </a>
                </div>
                <div class="col-6 col-md-3">
                    <a href="lecture_recorder.php" class="nav-card">
                        <div class="nav-icon">🎙️</div>
                        <h6>Recorder</h6>
                        <p>Record lectures</p>
                    </a>
                </div>
            </div>

            <!-- AI Feature Cards -->
            <div class="section-title"><i class="fas fa-robot"></i> AI Features</div>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <a href="ai_tutor.php" class="ai-feature-card">
                        <div class="ai-icon">🤖</div>
                        <h6>AI Tutor Chat</h6>
                        <p>Ask doubts, get explanations</p>
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="ai_exam.php" class="ai-feature-card" style="background:linear-gradient(135deg,#8e44ad,#9b59b6);">
                        <div class="ai-icon">🧠</div>
                        <h6>AI Exam</h6>
                        <p>Generate &amp; evaluate questions</p>
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="course_management.php" class="ai-feature-card" style="background:linear-gradient(135deg,#e67e22,#f39c12);">
                        <div class="ai-icon">📚</div>
                        <h6>Courses</h6>
                        <p>Manage syllabus &amp; topics</p>
                    </a>
                </div>
                <div class="col-md-6">
                    <a href="progress_analytics.php" class="ai-feature-card" style="background:linear-gradient(135deg,#6c3483,#8e44ad);">
                        <div class="ai-icon">📊</div>
                        <h6>Learning Analytics</h6>
                        <p>Track progress, scores &amp; activity heatmap</p>
                    </a>
                </div>
                <div class="col-md-6">
                    <a href="study_recommendations.php" class="ai-feature-card" style="background:linear-gradient(135deg,#1a6e32,#27ae60);">
                        <div class="ai-icon">🎯</div>
                        <h6>Study Recommendations</h6>
                        <p>AI-personalized learning plan &amp; weekly schedule</p>
                    </a>
                </div>
            </div>

            <!-- Activity Chart -->
            <div class="chart-card">
                <div class="section-title mb-3"><i class="fas fa-chart-line"></i> Study Activity (Last 7 Days)</div>
                <canvas id="activityChart" height="90"></canvas>
            </div>

            <!-- Exam Scores Chart -->
            <?php if (!empty($exam_scores)): ?>
            <div class="chart-card">
                <div class="section-title mb-3"><i class="fas fa-chart-bar"></i> Recent Exam Scores</div>
                <canvas id="scoresChart" height="90"></canvas>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Right Column ── -->
        <div class="col-lg-4">

            <!-- Task Completion Donut -->
            <?php if ($tasks_total > 0): ?>
            <div class="chart-card mb-4">
                <div class="section-title mb-3"><i class="fas fa-tasks"></i> Task Completion</div>
                <canvas id="taskDonut" height="160"></canvas>
                <div class="text-center mt-2" style="font-size:.82rem;color:#888;">
                    <span style="color:#27ae60;font-weight:700;"><?php echo $tasks_done; ?> done</span>
                    &nbsp;·&nbsp;
                    <span style="color:#e67e22;font-weight:700;"><?php echo $tasks_pending; ?> pending</span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Upcoming Todos -->
            <div class="chart-card mb-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="section-title mb-0"><i class="fas fa-clock"></i> Upcoming Tasks</div>
                    <a href="todo.php" style="font-size:.8rem;color:var(--accent);text-decoration:none;font-weight:600;">View All →</a>
                </div>
                <?php if (empty($upcoming_todos)): ?>
                <div class="text-center py-3 text-muted" style="font-size:.85rem;">
                    <i class="fas fa-check-circle fa-2x mb-2" style="color:#ddd;display:block;"></i>
                    No pending tasks!
                </div>
                <?php else: ?>
                <?php foreach ($upcoming_todos as $todo):
                    $due = new DateTime($todo['event_datetime']);
                    $now = new DateTime();
                    $diff = $now->diff($due);
                    $overdue = $due < $now;
                    $due_str = $overdue ? '⚠️ Overdue' : ($diff->days === 0 ? '⏰ Today' : 'in '.$diff->days.'d');
                ?>
                <div class="todo-item">
                    <div class="priority-dot <?php echo $todo['priority']; ?>"></div>
                    <div style="flex:1;min-width:0;">
                        <div class="todo-title"><?php echo htmlspecialchars($todo['title']); ?></div>
                        <div class="todo-due" style="<?php echo $overdue?'color:#e74c3c;':''; ?>"><?php echo $due_str; ?></div>
                    </div>
                    <span class="todo-type-badge"><?php echo $todo['task_type']; ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <!-- Quick add todo -->
                <div class="mt-3">
                    <a href="todo.php" class="btn w-100" style="background:linear-gradient(135deg,#0b4954,#197f8f);color:#fff;border:none;border-radius:10px;font-size:.85rem;font-weight:600;">
                        <i class="fas fa-plus me-1"></i> Add New Task
                    </a>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="chart-card">
                <div class="section-title mb-3"><i class="fas fa-history"></i> Recent Activity</div>
                <?php if (empty($recent_activity)): ?>
                <div class="text-center py-3 text-muted" style="font-size:.85rem;">
                    <i class="fas fa-satellite-dish fa-2x mb-2" style="color:#ddd;display:block;"></i>
                    No activity yet
                </div>
                <?php else: ?>
                <?php
                $act_icons = [
                    'file_upload' => ['fa-upload','#2980b9','#e8f4fd'],
                    'ai_chat'     => ['fa-robot', '#27ae60','#eafaf1'],
                    'exam_taken'  => ['fa-brain',  '#8e44ad','#f4ecf7'],
                    'task_done'   => ['fa-check',  '#27ae60','#eafaf1'],
                    'login'       => ['fa-sign-in-alt','#e67e22','#fef5e7'],
                    'note_view'   => ['fa-eye',    '#2980b9','#e8f4fd'],
                ];
                foreach ($recent_activity as $act):
                    $ic = $act_icons[$act['event_type']] ?? ['fa-circle','#888','#f0f0f0'];
                    $time_ago = (new DateTime($act['recorded_at']))->diff(new DateTime())->days;
                    $time_str = $time_ago === 0 ? 'Today' : $time_ago.'d ago';
                ?>
                <div class="activity-item">
                    <div class="activity-icon" style="background:<?php echo $ic[2]; ?>;">
                        <i class="fas <?php echo $ic[0]; ?>" style="color:<?php echo $ic[1]; ?>;"></i>
                    </div>
                    <div class="activity-text"><?php echo htmlspecialchars($act['event_detail'] ?: ucwords(str_replace('_',' ',$act['event_type']))); ?></div>
                    <div class="activity-time"><?php echo $time_str; ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// ── Activity Chart ────────────────────────────────────────────
new Chart(document.getElementById('activityChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode($activity_labels); ?>,
        datasets: [{
            label: 'Activities',
            data:  <?php echo json_encode($activity_data); ?>,
            borderColor: '#197f8f',
            backgroundColor: 'rgba(25,127,143,.08)',
            borderWidth: 2.5,
            pointBackgroundColor: '#0b4954',
            pointRadius: 4,
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero:true, ticks:{ stepSize:1, color:'#aaa', font:{size:11} }, grid:{ color:'#f0f2f5' } },
            x: { ticks:{ color:'#aaa', font:{size:11} }, grid:{ display:false } }
        }
    }
});

// ── Task Donut ────────────────────────────────────────────────
<?php if ($tasks_total > 0): ?>
new Chart(document.getElementById('taskDonut'), {
    type: 'doughnut',
    data: {
        labels: ['Done','Pending'],
        datasets: [{
            data: [<?php echo $tasks_done; ?>, <?php echo $tasks_pending; ?>],
            backgroundColor: ['#27ae60','#e67e22'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        cutout: '70%',
        plugins: {
            legend: { position:'bottom', labels:{ font:{size:11}, color:'#888', padding:12 } }
        }
    }
});
<?php endif; ?>

// ── Exam Scores Chart ─────────────────────────────────────────
<?php if (!empty($exam_scores)): ?>
new Chart(document.getElementById('scoresChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($exam_labels); ?>,
        datasets: [{
            label: 'Score',
            data:  <?php echo json_encode($exam_scores); ?>,
            backgroundColor: function(ctx) {
                const v = ctx.raw;
                return v >= 80 ? 'rgba(39,174,96,.7)' : v >= 60 ? 'rgba(243,156,18,.7)' : 'rgba(231,76,60,.7)';
            },
            borderRadius: 8,
            borderSkipped: false
        }]
    },
    options: {
        responsive: true,
        plugins: { legend:{ display:false } },
        scales: {
            y: { beginAtZero:true, max:100, ticks:{ color:'#aaa', font:{size:11} }, grid:{ color:'#f0f2f5' } },
            x: { ticks:{ color:'#aaa', font:{size:11} }, grid:{ display:false } }
        }
    }
});
});
<?php endif; ?>

// ── Storage Sync Function ─────────────────────────────────────
function syncStorage() {
    const btn = $('#btnSyncStorage');
    const originalText = btn.html();
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>Syncing...');
    
    $.post('sync_storage_ajax.php', { action: 'sync_storage' }, function(res) {
        if (res.success) {
            let msg = "Sync Complete!\n\n";
            msg += "Fixed Orphaned DB Rows: " + res.deleted_db_rows + "\n";
            msg += "Deleted Orphaned Files: " + res.deleted_physical_files;
            alert(msg);
        } else {
            alert('Sync failed: ' + (res.message || 'Unknown error'));
        }
    }, 'json').fail(function() {
        alert('Network error while syncing.');
    }).always(function() {
        btn.prop('disabled', false).html(originalText);
    });
}
</script>
</body>
</html>