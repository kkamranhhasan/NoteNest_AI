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
require_once 'includes/google_classroom_service.php';
require_once 'includes/google_sync_engine.php';

$user_id = $_SESSION['user_id'];

// ── Fresh user data ───────────────────────────────────────────
$stmt = $conn->prepare("SELECT name, email, photo, created_at FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stmt->bind_result($db_name, $db_email, $db_photo, $db_created);
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

// ── Google Classroom Integration ──────────────────────────────
$gc_account  = gc_get_account($conn, $user_id);
$gc_data     = null;
$gc_last_visit = $_SESSION['gc_last_dashboard_visit'] ?? '';

if ($gc_account) {
    // Auto-reset stuck sync status
    if ($gc_account['sync_status'] === 'syncing') {
        gc_update_sync_status($conn, $user_id, 'idle');
        $gc_account['sync_status'] = 'idle';
    }
    // Load all dashboard GC data
    $gc_data = gc_get_dashboard_data($conn, $user_id, $gc_last_visit);
}

// Record this visit timestamp for "new files" detection on next load
$_SESSION['gc_last_dashboard_visit'] = date('Y-m-d H:i:s');

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

        /* ── Google Classroom Section ──────────────────────────── */
        .gc-section {
            background:#fff;
            border-radius:18px;
            border-left:5px solid #4285f4;
            box-shadow:0 2px 16px rgba(0,0,0,.06);
            padding:26px 28px;
            margin-bottom:28px;
        }
        .gc-section-header {
            display:flex;align-items:center;justify-content:space-between;
            margin-bottom:20px;flex-wrap:wrap;gap:10px;
        }
        .gc-section-title {
            display:flex;align-items:center;gap:10px;
            font-size:1.05rem;font-weight:700;color:#0b4954;
        }
        .gc-google-badge {
            background:linear-gradient(135deg,#4285f4,#34a853);
            color:#fff;border-radius:8px;padding:3px 10px;
            font-size:.72rem;font-weight:700;letter-spacing:.5px;
        }
        .gc-last-sync { font-size:.75rem;color:#aaa; }
        .gc-sync-btn {
            background:linear-gradient(135deg,#4285f4,#1a73e8);
            color:#fff;border:none;border-radius:10px;
            padding:8px 18px;font-size:.82rem;font-weight:600;
            cursor:pointer;transition:all .2s;white-space:nowrap;
            display:inline-flex;align-items:center;gap:6px;
        }
        .gc-sync-btn:hover { transform:translateY(-1px);box-shadow:0 6px 18px rgba(66,133,244,.3); }
        .gc-sync-btn:disabled { opacity:.6;cursor:not-allowed;transform:none; }
        /* GC Stat cards */
        .gc-stat-card {
            border-radius:14px;padding:18px 16px;
            display:flex;align-items:center;gap:14px;
            transition:transform .2s,box-shadow .2s;
        }
        .gc-stat-card:hover { transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,.1); }
        .gc-stat-icon {
            width:44px;height:44px;border-radius:11px;
            display:flex;align-items:center;justify-content:center;
            font-size:1.1rem;flex-shrink:0;
        }
        .gc-stat-num { font-size:1.5rem;font-weight:800;line-height:1; }
        .gc-stat-lbl { font-size:.72rem;font-weight:500;margin-top:2px;opacity:.75; }
        /* GC Course cards */
        .gc-course-card {
            background:#f8fafb;border-radius:12px;padding:14px 16px;
            border:1px solid #e8ecf0;cursor:pointer;
            transition:all .2s;text-decoration:none;display:block;
        }
        .gc-course-card:hover {
            background:#e8f0fe;border-color:#4285f4;
            transform:translateY(-2px);box-shadow:0 6px 18px rgba(66,133,244,.12);
        }
        .gc-course-name {
            font-size:.88rem;font-weight:700;color:#0b4954;
            margin:0 0 3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
        }
        .gc-course-meta { font-size:.72rem;color:#888; }
        .gc-course-icon {
            width:36px;height:36px;border-radius:9px;
            background:linear-gradient(135deg,#4285f4,#34a853);
            display:flex;align-items:center;justify-content:center;
            color:#fff;font-size:.9rem;flex-shrink:0;margin-bottom:8px;
        }
        /* GC file rows */
        .gc-file-table { width:100%;border-collapse:separate;border-spacing:0 4px; }
        .gc-file-table th { font-size:.72rem;color:#999;font-weight:600;padding:6px 10px;text-transform:uppercase;letter-spacing:.5px; }
        .gc-file-table td { font-size:.82rem;color:#444;padding:8px 10px;background:#f8fafb;vertical-align:middle; }
        .gc-file-table tr td:first-child { border-radius:8px 0 0 8px; }
        .gc-file-table tr td:last-child  { border-radius:0 8px 8px 0; }
        .gc-file-icon { width:28px;height:28px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;flex-shrink:0; }
        /* GC assignment items */
        .gc-assign-item {
            display:flex;align-items:flex-start;gap:12px;
            padding:10px 12px;border-radius:10px;background:#f8fafb;
            margin-bottom:7px;border:1px solid transparent;
            transition:border-color .15s,background .15s;
        }
        .gc-assign-item:hover { background:#e8f0fe;border-color:#4285f4; }
        .gc-assign-due {
            font-size:.7rem;font-weight:700;padding:3px 8px;
            border-radius:6px;white-space:nowrap;flex-shrink:0;
        }
        .gc-assign-due.overdue { background:#fdecea;color:#c0392b; }
        .gc-assign-due.today   { background:#fef9e7;color:#e67e22; }
        .gc-assign-due.soon    { background:#e8f4fd;color:#2980b9; }
        .gc-assign-due.nodate  { background:#f4f4f4;color:#999; }
        /* GC announcement items */
        .gc-announce-item {
            padding:10px 12px;border-radius:10px;background:#f8fafb;
            margin-bottom:7px;border-left:3px solid #34a853;
        }
        .gc-announce-msg { font-size:.82rem;color:#333;margin:0 0 4px; }
        .gc-announce-time { font-size:.7rem;color:#aaa; }
        /* Quick action buttons */
        .gc-quick-btn {
            display:flex;align-items:center;gap:8px;
            padding:10px 14px;border-radius:10px;border:1.5px solid;
            font-size:.82rem;font-weight:600;text-decoration:none;
            transition:all .2s;cursor:pointer;background:#fff;
            width:100%;margin-bottom:8px;
        }
        .gc-quick-btn:hover { transform:translateX(3px); }
        .gc-quick-btn-course { border-color:#4285f4;color:#4285f4; }
        .gc-quick-btn-course:hover { background:#e8f0fe;color:#1a73e8; }
        .gc-quick-btn-file   { border-color:#34a853;color:#34a853; }
        .gc-quick-btn-file:hover   { background:#e8f8ed;color:#27ae60; }
        .gc-quick-btn-assign { border-color:#fbbc04;color:#e67e22; }
        .gc-quick-btn-assign:hover { background:#fef9e7;color:#d35400; }
        .gc-quick-btn-sync   { border-color:#ea4335;color:#ea4335; }
        .gc-quick-btn-sync:hover   { background:#fdecea;color:#c0392b; }
        /* GC Activity Table */
        .gc-activity-table { width:100%;border-collapse:collapse; }
        .gc-activity-table th {
            background:#f0f4f8;font-size:.72rem;color:#666;
            font-weight:700;padding:10px 14px;text-align:left;
            text-transform:uppercase;letter-spacing:.5px;
        }
        .gc-activity-table th:first-child { border-radius:8px 0 0 8px; }
        .gc-activity-table th:last-child  { border-radius:0 8px 8px 0; }
        .gc-activity-table td { font-size:.82rem;color:#444;padding:10px 14px;border-bottom:1px solid #f5f6fa;vertical-align:middle; }
        .gc-activity-table tr:hover td { background:#f8fafb; }
        .gc-activity-table tr:last-child td { border-bottom:none; }
        /* Status badges */
        .gc-status { font-size:.7rem;padding:3px 9px;border-radius:8px;font-weight:600;white-space:nowrap; }
        .gc-status.downloaded { background:#eafaf1;color:#27ae60; }
        .gc-status.pending    { background:#fef9e7;color:#e67e22; }
        .gc-status.failed     { background:#fdecea;color:#c0392b; }
        .gc-status.skipped    { background:#f4f4f4;color:#999; }
        /* New badge */
        .gc-new-badge {
            display:inline-flex;align-items:center;gap:4px;
            background:#ea4335;color:#fff;border-radius:8px;
            padding:3px 10px;font-size:.72rem;font-weight:700;
            animation:gcPulse 1.5s ease-in-out infinite;
        }
        @keyframes gcPulse {
            0%,100%{box-shadow:0 0 0 0 rgba(234,67,53,.4)}
            50%{box-shadow:0 0 0 6px rgba(234,67,53,0)}
        }
        /* GC connect prompt */
        .gc-connect-prompt {
            background:linear-gradient(135deg,#e8f0fe,#e8f8ed);
            border-radius:16px;padding:28px;text-align:center;
            border:1.5px dashed #4285f4;margin-bottom:28px;
        }
        .gc-connect-icon { font-size:2.5rem;margin-bottom:12px; }
        .gc-connect-title { font-size:1rem;font-weight:700;color:#0b4954;margin-bottom:6px; }
        .gc-connect-text  { font-size:.85rem;color:#666;margin-bottom:16px; }
        .gc-connect-cta {
            background:linear-gradient(135deg,#4285f4,#1a73e8);
            color:#fff;border:none;border-radius:10px;
            padding:10px 24px;font-size:.88rem;font-weight:600;
            text-decoration:none;display:inline-flex;align-items:center;gap:8px;
            transition:all .2s;
        }
        .gc-connect-cta:hover { transform:translateY(-2px);box-shadow:0 8px 20px rgba(66,133,244,.3);color:#fff; }
        /* Empty state */
        .gc-empty { text-align:center;padding:24px;color:#bbb;font-size:.85rem; }
        .gc-empty i { font-size:2rem;display:block;margin-bottom:8px; }
        /* Responsive */
        @media(max-width:768px) {
            .gc-section { padding:18px 16px; }
            .gc-file-table thead { display:none; }
            .gc-file-table td { display:block;padding:4px 8px; }
            .gc-file-table tr td:first-child,
            .gc-file-table tr td:last-child { border-radius:0; }
        }

        /* Custom Logout Modal Styles */
        .custom-modal-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(11, 73, 84, 0.4);
            backdrop-filter: blur(8px);
            display: flex; align-items: center; justify-content: center;
            z-index: 9999;
            opacity: 0; pointer-events: none;
            transition: opacity 0.3s ease;
        }
        .custom-modal-overlay.active {
            opacity: 1; pointer-events: all;
        }
        .custom-modal-card {
            background: #fff;
            border-radius: 16px;
            padding: 32px;
            width: 90%; max-width: 400px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
            transform: scale(0.9);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            text-align: center;
        }
        .custom-modal-overlay.active .custom-modal-card {
            transform: scale(1);
        }
        .custom-modal-icon {
            font-size: 3rem;
            color: #e74c3c;
            margin-bottom: 16px;
        }
        .custom-modal-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #0b4954;
            margin-bottom: 8px;
        }
        .custom-modal-text {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 24px;
        }
        .custom-modal-btn-confirm {
            background: #e74c3c;
            color: #fff;
            border: none;
            padding: 10px 24px;
            border-radius: 8px;
            font-weight: 600;
            margin-right: 12px;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .custom-modal-btn-confirm:hover {
            background: #c0392b;
            color: #fff;
            transform: translateY(-1px);
        }
        .custom-modal-btn-cancel {
            background: #f1f2f6;
            color: #555;
            border: none;
            padding: 10px 24px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.2s;
            display: inline-block;
        }
        .custom-modal-btn-cancel:hover {
            background: #dfe4ea;
            transform: translateY(-1px);
        }

        /* Profile Sidebar Widget hover states */
        .profile-sidebar-card .list-group-item:hover {
            background-color: #f8fafb !important;
            color: #0b4954 !important;
        }

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

    <!-- ══════════════════════════════════════════════════════════ -->
    <!-- ── Single Google Classroom Summary Card ───────────────── -->
    <!-- ══════════════════════════════════════════════════════════ -->
    <div class="card gc-dashboard-card mb-4" style="background: linear-gradient(135deg, #ffffff 0%, #f4f8ff 100%); border: 1.5px solid #e1ecfe; border-radius: 16px; box-shadow: 0 4px 20px rgba(66, 133, 244, 0.08); transition: transform 0.2s, box-shadow 0.2s; cursor: pointer;" onclick="openGcModal()">
        <div class="card-body p-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div style="width: 54px; height: 54px; border-radius: 14px; background: linear-gradient(135deg, #4285f4, #34a853); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.5rem; box-shadow: 0 6px 16px rgba(66, 133, 244, 0.25); flex-shrink:0;">
                    <i class="fab fa-google"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <h5 class="mb-0 fw-bold" style="color: #0b4954;">Google Classroom</h5>
                        <?php if ($gc_account): ?>
                            <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2 py-1" style="font-size: 0.72rem; border: 1px solid rgba(39, 174, 96, 0.3);">
                                <i class="fas fa-check-circle me-1"></i>Connected
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-2 py-1" style="font-size: 0.72rem;">
                                <i class="fas fa-plug me-1"></i>Not Connected
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted" style="font-size: 0.83rem;">
                        <?php if ($gc_account && $gc_data): ?>
                            <span class="fw-semibold text-primary"><i class="fas fa-graduation-cap me-1"></i><span id="mainGcCourseCount"><?php echo $gc_data['stats']['total_courses']; ?></span> Connected Courses</span>
                            &nbsp;·&nbsp;
                            <span><i class="fas fa-sync-alt me-1"></i>Last Synced: <span id="mainGcLastSync"><?php echo $gc_account['last_sync_at'] ? date('M j, g:ia', strtotime($gc_account['last_sync_at'])) : 'Never'; ?></span></span>
                        <?php else: ?>
                            Connect your Google Classroom account to sync courses, topics, materials & assignments.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div>
                <?php if ($gc_account): ?>
                    <button class="btn btn-primary px-4 py-2 rounded-3 fw-semibold shadow-sm" style="background: linear-gradient(135deg, #4285f4, #1a73e8); border: none;" onclick="event.stopPropagation(); openGcModal();">
                        <i class="fas fa-door-open me-2"></i>Open Classroom
                    </button>
                <?php else: ?>
                    <a href="google_classroom.php" class="btn btn-outline-primary px-4 py-2 rounded-3 fw-semibold" onclick="event.stopPropagation();">
                        <i class="fab fa-google me-2"></i>Connect Now
                    </a>
                <?php endif; ?>
            </div>
        </div>
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



            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- ── Google Classroom Interactive Explorer Modal ─────────── -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <div class="modal fade" id="googleClassroomModal" tabindex="-1" aria-labelledby="gcModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
                        <div class="modal-header border-bottom-0 pb-0 pt-4 px-4 d-flex align-items-center justify-content-between">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <div style="width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#4285f4,#34a853);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1rem;">
                                        <i class="fab fa-google"></i>
                                    </div>
                                    <h5 class="modal-title fw-bold mb-0" id="gcModalLabel" style="color: #0b4954;">Google Classroom Explorer</h5>
                                </div>
                                <nav id="gcBreadcrumbNav" class="small text-muted py-1" style="font-size:0.85rem;"></nav>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="gcModalSyncNow()" id="gcModalSyncBtn">
                                    <i class="fas fa-sync-alt me-1"></i>Sync Now
                                </button>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                        </div>
                        <div class="modal-body p-4" id="gcModalBodyContent" style="min-height: 420px;">
                            <!-- Dynamically loaded via AJAX -->
                        </div>
                        <div class="modal-footer border-top-0 pt-0 pb-3 px-4 d-flex align-items-center justify-content-between text-muted small">
                            <span><i class="fas fa-info-circle me-1"></i>Click any course to view topics, or click a topic to explore materials & assignments.</span>
                            <a href="google_classroom.php" class="text-primary text-decoration-none fw-semibold">Manage Connection & Sync Settings →</a>
                        </div>
                    </div>
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

// ── Google Classroom Modal Explorer & Drill-Down ─────────────
let gcCurrentCourseId = null;
let gcCurrentCourseName = '';
let gcCurrentTopicId = null;
let gcCurrentTopicName = '';

function openGcModal() {
    var modalEl = document.getElementById('googleClassroomModal');
    if (!modalEl) {
        console.error('googleClassroomModal element not found');
        return;
    }
    
    try {
        if (window.bootstrap && bootstrap.Modal) {
            var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        } else if (typeof $(modalEl).modal === 'function') {
            $(modalEl).modal('show');
        } else {
            $(modalEl).addClass('show').css('display', 'block');
            $('body').addClass('modal-open');
        }
    } catch (e) {
        console.warn('Modal open error, using jQuery fallback:', e);
        if (typeof $(modalEl).modal === 'function') {
            $(modalEl).modal('show');
        } else {
            $(modalEl).addClass('show').css('display', 'block');
            $('body').addClass('modal-open');
        }
    }
    
    loadGcCourses();
}

function loadGcCourses() {
    gcCurrentCourseId = null;
    gcCurrentCourseName = '';
    gcCurrentTopicId = null;
    gcCurrentTopicName = '';
    
    updateGcBreadcrumb();
    
    $('#gcModalBodyContent').html('<div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-2x text-primary mb-2"></i><p class="text-muted">Loading Courses...</p></div>');
    
    $.post('dashboard_gc_ajax.php', { action: 'get_courses' }, function(res) {
        if (!res.success) {
            $('#gcModalBodyContent').html('<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i> ' + (res.error || 'Failed to load courses') + '</div>');
            return;
        }
        
        let html = '';
        if (!res.courses || res.courses.length === 0) {
            html = '<div class="text-center py-5"><i class="fas fa-graduation-cap fa-3x text-muted mb-3"></i><h5>No Google Classroom Courses Found</h5><p class="text-muted mb-3">Click "Sync Now" to import your courses from Google Classroom.</p><button class="btn btn-primary rounded-pill px-4" onclick="gcModalSyncNow()"><i class="fas fa-sync-alt me-1"></i>Sync Now</button></div>';
        } else {
            html += '<div class="row g-3">';
            res.courses.forEach(function(c) {
                html += `
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 border-0 shadow-sm gc-course-tile" style="border-radius:14px; background:#f8fafb; cursor:pointer; transition:all .2s; border: 1px solid #e8ecf0;" onclick="loadGcTopics('${c.google_course_id}', '${escapeJsStr(c.course_name)}')">
                        <div class="card-body p-3 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#4285f4,#34a853);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1rem;flex-shrink:0;">
                                        <i class="fas fa-graduation-cap"></i>
                                    </div>
                                    <h6 class="mb-0 fw-bold text-dark text-truncate" title="${escapeHtml(c.course_name)}">${escapeHtml(c.course_name)}</h6>
                                </div>
                                ${c.section ? `<div class="text-muted small mb-1"><i class="fas fa-users me-1"></i>${escapeHtml(c.section)}</div>` : ''}
                                ${c.teacher_name ? `<div class="text-muted small mb-2"><i class="fas fa-chalkboard-teacher me-1"></i>${escapeHtml(c.teacher_name)}</div>` : ''}
                            </div>
                            <div class="d-flex align-items-center justify-content-between pt-2 border-top border-light mt-2" style="font-size:0.75rem; color:#666;">
                                <span><i class="fas fa-layer-group me-1 text-primary"></i>${c.topics_count || 0} Topics</span>
                                <span><i class="fas fa-file-alt me-1 text-warning"></i>${c.files_count || 0} Files</span>
                                <span><i class="fas fa-tasks me-1 text-danger"></i>${c.assignments_count || 0} Tasks</span>
                            </div>
                        </div>
                    </div>
                </div>`;
            });
            html += '</div>';
        }
        $('#gcModalBodyContent').html(html);
    }, 'json').fail(function() {
        $('#gcModalBodyContent').html('<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i> Network error loading courses.</div>');
    });
}

function loadGcTopics(googleCourseId, courseName) {
    gcCurrentCourseId = googleCourseId;
    gcCurrentCourseName = courseName;
    gcCurrentTopicId = null;
    gcCurrentTopicName = '';
    
    updateGcBreadcrumb();
    
    $('#gcModalBodyContent').html('<div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-2x text-primary mb-2"></i><p class="text-muted">Loading Topics...</p></div>');
    
    $.post('dashboard_gc_ajax.php', { action: 'get_topics', google_course_id: googleCourseId }, function(res) {
        if (!res.success) {
            $('#gcModalBodyContent').html('<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i> ' + (res.error || 'Failed to load topics') + '</div>');
            return;
        }
        
        let html = `
        <div class="d-flex align-items-center justify-content-between mb-3 bg-light p-3 rounded-3 flex-wrap gap-2">
            <div>
                <h6 class="fw-bold mb-0 text-primary"><i class="fas fa-graduation-cap me-2"></i>${escapeHtml(courseName)}</h6>
                <small class="text-muted">${res.course.section ? escapeHtml(res.course.section) + ' · ' : ''}${res.course.teacher_name ? escapeHtml(res.course.teacher_name) : ''}</small>
            </div>
            <button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="loadGcTopicContent('${googleCourseId}', 'all', 'All Topics & Materials', '${escapeJsStr(courseName)}')">
                <i class="fas fa-th-list me-1"></i>View All Files & Assignments
            </button>
        </div>
        <div class="row g-3">`;

        // "All files" topic tile
        html += `
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 border-primary border-opacity-25 shadow-sm gc-topic-tile" style="border-radius:12px; background:#e8f0fe; cursor:pointer;" onclick="loadGcTopicContent('${googleCourseId}', 'all', 'All Topics & Materials', '${escapeJsStr(courseName)}')">
                <div class="card-body p-3 d-flex align-items-center gap-3">
                    <div style="width:40px;height:40px;border-radius:10px;background:#4285f4;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;">
                        <i class="fas fa-folder-open"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-0 text-primary">All Materials & Assignments</h6>
                        <small class="text-muted">Browse complete course content</small>
                    </div>
                </div>
            </div>
        </div>`;

        if ((!res.topics || res.topics.length === 0) && !res.unassigned_files_count) {
            html += `<div class="col-12"><div class="text-center py-4 text-muted">No specific topics found for this course. Click "All Materials & Assignments" above.</div></div>`;
        } else {
            if (res.topics) {
                res.topics.forEach(function(t) {
                    html += `
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 border-0 shadow-sm gc-topic-tile" style="border-radius:12px; background:#f8fafb; border: 1px solid #e8ecf0; cursor:pointer;" onclick="loadGcTopicContent('${googleCourseId}', ${t.topic_id ? t.topic_id : '\''+t.google_topic_id+'\''}, '${escapeJsStr(t.topic_name)}', '${escapeJsStr(courseName)}')">
                            <div class="card-body p-3 d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center gap-3">
                                    <div style="width:38px;height:38px;border-radius:9px;background:#34a853;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;">
                                        <i class="fas fa-layer-group"></i>
                                    </div>
                                    <div>
                                        <h6 class="fw-bold mb-0 text-dark">${escapeHtml(t.topic_name)}</h6>
                                        <small class="text-muted">${t.files_count || 0} items</small>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-right text-muted" style="font-size:0.8rem;"></i>
                            </div>
                        </div>
                    </div>`;
                });
            }
            
            if (res.unassigned_files_count > 0) {
                html += `
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 border-0 shadow-sm gc-topic-tile" style="border-radius:12px; background:#f8fafb; border: 1px solid #e8ecf0; cursor:pointer;" onclick="loadGcTopicContent('${googleCourseId}', 'unassigned', 'General / Unassigned', '${escapeJsStr(courseName)}')">
                        <div class="card-body p-3 d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width:38px;height:38px;border-radius:9px;background:#fbbc04;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;">
                                    <i class="fas fa-tags"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-0 text-dark">General / Unassigned</h6>
                                    <small class="text-muted">${res.unassigned_files_count} items</small>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right text-muted" style="font-size:0.8rem;"></i>
                        </div>
                    </div>
                </div>`;
            }
        }
        
        html += '</div>';
        $('#gcModalBodyContent').html(html);
    }, 'json').fail(function() {
        $('#gcModalBodyContent').html('<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i> Network error loading topics.</div>');
    });
}

function loadGcTopicContent(googleCourseId, topicId, topicName, courseName) {
    if (courseName) gcCurrentCourseName = courseName;
    gcCurrentCourseId = googleCourseId;
    gcCurrentTopicId = topicId;
    gcCurrentTopicName = topicName;
    
    updateGcBreadcrumb();
    
    $('#gcModalBodyContent').html('<div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-2x text-primary mb-2"></i><p class="text-muted">Loading Files and Assignments...</p></div>');
    
    $.post('dashboard_gc_ajax.php', { action: 'get_topic_content', google_course_id: googleCourseId, topic_id: topicId }, function(res) {
        if (!res.success) {
            $('#gcModalBodyContent').html('<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i> ' + (res.error || 'Failed to load content') + '</div>');
            return;
        }
        
        let html = `
        <div class="d-flex align-items-center justify-content-between mb-3 bg-light p-3 rounded-3 flex-wrap gap-2">
            <div>
                <div class="text-muted small">${escapeHtml(gcCurrentCourseName)}</div>
                <h6 class="fw-bold mb-0 text-primary"><i class="fas fa-layer-group me-2"></i>${escapeHtml(topicName)}</h6>
            </div>
            <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="loadGcTopics('${googleCourseId}', '${escapeJsStr(gcCurrentCourseName)}')">
                <i class="fas fa-arrow-left me-1"></i>Back to Topics
            </button>
        </div>
        
        <ul class="nav nav-tabs nav-fill mb-3" id="gcContentTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-bold" id="files-tab" data-bs-toggle="tab" data-bs-target="#gcFilesTab" type="button" role="tab">
                    <i class="fas fa-file-alt me-2 text-warning"></i>Files & Study Materials (${res.files ? res.files.length : 0})
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-bold" id="assignments-tab" data-bs-toggle="tab" data-bs-target="#gcAssignmentsTab" type="button" role="tab">
                    <i class="fas fa-tasks me-2 text-danger"></i>Assignments & Deadlines (${res.assignments ? res.assignments.length : 0})
                </button>
            </li>
        </ul>
        
        <div class="tab-content" id="gcContentTabsContent">
            <!-- Files Tab -->
            <div class="tab-pane fade show active" id="gcFilesTab" role="tabpanel">`;
            
        if (!res.files || res.files.length === 0) {
            html += `<div class="text-center py-5 text-muted"><i class="fas fa-folder-open fa-2x mb-2 d-block text-secondary"></i>No study materials or files uploaded under this topic.</div>`;
        } else {
            html += `<div class="table-responsive"><table class="table table-hover align-middle" style="font-size:0.85rem;">
                <thead class="table-light">
                    <tr><th>File Title</th><th>Date Uploaded</th><th>Download Status</th><th>Action</th></tr>
                </thead><tbody>`;
            res.files.forEach(function(f) {
                let iconClass = 'fa-file-alt';
                let iconColor = '#4285f4';
                let ftype = (f.file_type || '').toLowerCase();
                let fmime = (f.mime_type || '').toLowerCase();
                if (fmime.includes('pdf') || ftype === 'pdf') { iconClass = 'fa-file-pdf'; iconColor = '#e74c3c'; }
                else if (fmime.includes('sheet') || ftype === 'xlsx') { iconClass = 'fa-file-excel'; iconColor = '#27ae60'; }
                else if (fmime.includes('presentation') || ftype === 'pptx') { iconClass = 'fa-file-powerpoint'; iconColor = '#e67e22'; }
                else if (fmime.includes('image')) { iconClass = 'fa-file-image'; iconColor = '#8e44ad'; }
                
                let link = 'google_classroom.php';
                if (f.file_id && f.file_path) {
                    link = 'note_preview.php?id=' + f.file_id;
                } else if (f.file_url) {
                    link = f.file_url;
                }
                
                html += `
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <i class="fas ${iconClass} fa-lg" style="color:${iconColor}"></i>
                            <span class="fw-semibold text-dark">${escapeHtml(f.file_title)}</span>
                        </div>
                    </td>
                    <td class="text-muted">${f.created_at ? f.created_at.substring(0, 10) : '—'}</td>
                    <td><span class="badge bg-${f.download_status === 'downloaded' ? 'success' : 'warning'} bg-opacity-10 text-${f.download_status === 'downloaded' ? 'success' : 'warning'} rounded-pill px-2 py-1">${f.download_status || 'pending'}</span></td>
                    <td><a href="${link}" target="${f.file_id ? '_self' : '_blank'}" class="btn btn-sm btn-outline-primary rounded-pill px-3"><i class="fas fa-external-link-alt me-1"></i>Open File</a></td>
                </tr>`;
            });
            html += `</tbody></table></div>`;
        }
        
        html += `</div>
            <!-- Assignments Tab -->
            <div class="tab-pane fade" id="gcAssignmentsTab" role="tabpanel">`;
            
        if (!res.assignments || res.assignments.length === 0) {
            html += `<div class="text-center py-5 text-muted"><i class="fas fa-check-circle fa-2x mb-2 d-block text-success"></i>No active assignments under this course/topic.</div>`;
        } else {
            html += `<div class="list-group list-group-flush">`;
            res.assignments.forEach(function(a) {
                let dueBadge = '<span class="badge bg-secondary">No Due Date</span>';
                if (a.due_date) {
                    dueBadge = `<span class="badge bg-danger"><i class="fas fa-clock me-1"></i>Due: ${escapeHtml(a.due_date)} ${a.due_time ? escapeHtml(a.due_time) : ''}</span>`;
                }
                let statusBadge = `<span class="badge bg-${a.todo_status === 'done' ? 'success' : 'warning'}">${a.todo_status === 'done' ? 'Completed' : 'Pending'}</span>`;
                
                html += `
                <div class="list-group-item p-3 rounded-3 mb-2 border">
                    <div class="d-flex align-items-center justify-content-between mb-1 flex-wrap gap-2">
                        <h6 class="fw-bold mb-0 text-dark">${escapeHtml(a.title)}</h6>
                        <div class="d-flex align-items-center gap-2">
                            ${dueBadge}
                            ${statusBadge}
                        </div>
                    </div>
                    ${a.description ? `<p class="small text-muted mb-2 text-truncate">${escapeHtml(a.description)}</p>` : ''}
                    <div class="d-flex align-items-center justify-content-between small text-muted">
                        <span><i class="fas fa-award me-1"></i>Max Points: ${a.max_points ? a.max_points : 'N/A'}</span>
                        <span><i class="fas fa-tasks me-1"></i>Type: ${escapeHtml(a.work_type || 'ASSIGNMENT')}</span>
                    </div>
                </div>`;
            });
            html += `</div>`;
        }
        
        html += `</div></div>`;
        $('#gcModalBodyContent').html(html);
    }, 'json').fail(function() {
        $('#gcModalBodyContent').html('<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i> Network error loading content.</div>');
    });
}

function updateGcBreadcrumb() {
    let html = '<a href="javascript:void(0)" onclick="loadGcCourses()" class="text-decoration-none fw-semibold"><i class="fas fa-home me-1"></i>Courses</a>';
    if (gcCurrentCourseId) {
        html += ` <i class="fas fa-chevron-right mx-1 text-muted" style="font-size:0.7rem;"></i> <a href="javascript:void(0)" onclick="loadGcTopics('${gcCurrentCourseId}', '${escapeJsStr(gcCurrentCourseName)}')" class="text-decoration-none fw-semibold">${escapeHtml(gcCurrentCourseName)}</a>`;
    }
    if (gcCurrentTopicId) {
        html += ` <i class="fas fa-chevron-right mx-1 text-muted" style="font-size:0.7rem;"></i> <span class="text-dark fw-bold">${escapeHtml(gcCurrentTopicName)}</span>`;
    }
    $('#gcBreadcrumbNav').html(html);
}

function gcModalSyncNow() {
    const btn = $('#gcModalSyncBtn');
    const origHtml = btn.html();
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>Syncing...');
    
    $.post('dashboard_gc_ajax.php', { action: 'sync_now' }, function(res) {
        if (res.success) {
            gcToast('✅ ' + (res.message || 'Sync completed successfully'), 'success');
            if (res.stats) {
                $('#mainGcCourseCount').text(res.stats.total_courses || 0);
                $('#mainGcLastSync').text('Just now');
            }
            if (gcCurrentTopicId) {
                loadGcTopicContent(gcCurrentCourseId, gcCurrentTopicId, gcCurrentTopicName, gcCurrentCourseName);
            } else if (gcCurrentCourseId) {
                loadGcTopics(gcCurrentCourseId, gcCurrentCourseName);
            } else {
                loadGcCourses();
            }
        } else {
            gcToast('❌ ' + (res.error || 'Sync failed'), 'error');
        }
    }, 'json').fail(function() {
        gcToast('❌ Network error during sync', 'error');
    }).always(function() {
        btn.prop('disabled', false).html(origHtml);
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function escapeJsStr(str) {
    if (!str) return '';
    return String(str).replace(/'/g, "\\'").replace(/"/g, '\\"');
}


// ── GC Toast Notification ─────────────────────────────────────
function gcToast(message, type) {
    const toast = document.createElement('div');
    toast.style.cssText = [
        'position:fixed;top:80px;right:20px;z-index:99999;',
        'background:' + (type === 'success' ? 'linear-gradient(135deg,#34a853,#27ae60)' : 'linear-gradient(135deg,#ea4335,#c0392b)') + ';',
        'color:#fff;padding:14px 20px;border-radius:12px;font-size:.88rem;font-weight:600;',
        'box-shadow:0 8px 24px rgba(0,0,0,.18);max-width:340px;',
        'animation:slideInRight .3s ease;'
    ].join('');
    toast.innerHTML = message;
    document.body.appendChild(toast);
    setTimeout(function() {
        toast.style.opacity = '0'; toast.style.transition = 'opacity .4s';
        setTimeout(function() { document.body.removeChild(toast); }, 400);
    }, 3000);
}

// ── Dismiss new-files badge on click ─────────────────────────
$(function() {
    $('#gcNewBadge').on('click', function() { $(this).fadeOut(300); });
});

// Add slideInRight keyframe
const gcStyle = document.createElement('style');
gcStyle.innerHTML = '@keyframes slideInRight{from{opacity:0;transform:translateX(40px)}to{opacity:1;transform:translateX(0)}}';
document.head.appendChild(gcStyle);

// ── Logout Confirmation Script ──
$(function() {
    $(document).on('click', '.logout-btn-trigger', function(e) {
        e.preventDefault();
        $('#customLogoutModal').addClass('active');
    });
    
    $(document).on('click', '.custom-modal-btn-cancel, .custom-modal-overlay', function(e) {
        if (e.target === this || $(e.target).hasClass('custom-modal-btn-cancel') || $(e.target).closest('.custom-modal-btn-cancel').length) {
            $('#customLogoutModal').removeClass('active');
        }
    });
});
</script>

<!-- Custom Logout Confirmation Modal -->
<div class="custom-modal-overlay" id="customLogoutModal">
    <div class="custom-modal-card">
        <div class="custom-modal-icon">
            <i class="fas fa-sign-out-alt"></i>
        </div>
        <div class="custom-modal-title">Confirm Logout</div>
        <div class="custom-modal-text">Are you sure you want to log out of NoteNest? Your current session will end.</div>
        </div>
    </div>
</div>
<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>