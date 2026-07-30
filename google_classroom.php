<?php
// ============================================================
// google_classroom.php — NoteNest AI Platform
// Google Classroom Settings, Sync Dashboard & Calendar
// ============================================================
require 'includes/auth.php';
require 'config.php';
require_once 'includes/google_classroom_service.php';
require_once 'includes/google_sync_engine.php';

$user_id = $_SESSION['user_id'];

// ── Handle AJAX actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // SYNC NOW
    if ($_POST['action'] === 'sync_now') {
        $result = gc_run_sync($conn, $user_id, 'manual');
        echo json_encode($result);
        exit;
    }

    // DISCONNECT
    if ($_POST['action'] === 'disconnect') {
        gc_disconnect($conn, $user_id);
        echo json_encode(['success' => true, 'message' => 'Google account disconnected.']);
        exit;
    }

    // GET SYNC STATUS
    if ($_POST['action'] === 'get_sync_status') {
        $account = gc_get_account($conn, $user_id);
        $stats   = $account ? gc_get_sync_stats($conn, $user_id) : [];
        echo json_encode(['account' => $account, 'stats' => $stats]);
        exit;
    }

    // GET CALENDAR EVENTS
    if ($_POST['action'] === 'get_calendar') {
        $month = intval($_POST['month'] ?? date('m'));
        $year  = intval($_POST['year']  ?? date('Y'));
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = date('Y-m-t', strtotime($start));

        $stmt = $conn->prepare(
            "SELECT ce.*, c.name AS course_name
             FROM calendar_events ce
             LEFT JOIN courses c ON ce.course_id = c.id
             WHERE ce.user_id = ? AND ce.event_date BETWEEN ? AND ?
             ORDER BY ce.event_date ASC, ce.event_time ASC"
        );
        $stmt->bind_param('iss', $user_id, $start, $end);
        $stmt->execute();
        $events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo json_encode(['events' => $events, 'month' => $month, 'year' => $year]);
        exit;
    }

    // GET SYNC LOGS
    if ($_POST['action'] === 'get_sync_logs') {
        $stmt = $conn->prepare(
            "SELECT * FROM google_sync_logs WHERE user_id = ? ORDER BY started_at DESC LIMIT 10"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['logs' => $logs]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// ── AUTO-SYNC on page load ───────────────────────────────────
$account   = gc_get_account($conn, $user_id);
$autoSyncResult = null;

if ($account && $account['sync_status'] !== 'syncing') {
    // Auto-sync if never synced OR last sync was >5 minutes ago
    $shouldSync = false;
    if (!$account['last_sync_at']) {
        $shouldSync = true;
    } else {
        $lastSyncTime = strtotime($account['last_sync_at']);
        $shouldSync   = (time() - $lastSyncTime) > 300; // 5 minutes
    }

    if ($shouldSync) {
        require_once 'includes/ai_service.php';
        $autoSyncResult = gc_run_sync($conn, $user_id, 'auto');
        // Refresh account data after sync
        $account = gc_get_account($conn, $user_id);
    }
}

$syncStats = $account ? gc_get_sync_stats($conn, $user_id) : null;

// Flash messages from OAuth callback
$flashMsg  = $_SESSION['gc_message']  ?? '';
$flashType = $_SESSION['gc_msg_type'] ?? 'info';
unset($_SESSION['gc_message'], $_SESSION['gc_msg_type']);

// Recent sync logs
$syncLogs = [];
if ($account) {
    $lq = $conn->prepare("SELECT * FROM google_sync_logs WHERE user_id = ? ORDER BY started_at DESC LIMIT 5");
    $lq->bind_param('i', $user_id);
    $lq->execute();
    $syncLogs = $lq->get_result()->fetch_all(MYSQLI_ASSOC);
    $lq->close();
}

// Synced courses
$syncedCourses = [];
if ($account) {
    $cq = $conn->prepare(
        "SELECT gc.*, c.name AS nn_course_name, c.code AS nn_course_code
         FROM google_courses gc
         LEFT JOIN courses c ON gc.course_id = c.id
         WHERE gc.user_id = ? ORDER BY gc.course_name ASC"
    );
    $cq->bind_param('i', $user_id);
    $cq->execute();
    $syncedCourses = $cq->get_result()->fetch_all(MYSQLI_ASSOC);
    $cq->close();
}

// Upcoming assignments
$upcomingAssignments = [];
if ($account) {
    $aq = $conn->prepare(
        "SELECT ga.*, gc.course_name, t.status AS todo_status
         FROM google_assignments ga
         JOIN google_courses gc ON ga.google_course_id = gc.google_course_id AND ga.user_id = gc.user_id
         LEFT JOIN todos t ON ga.todo_id = t.id
         WHERE ga.user_id = ? AND (ga.due_date >= CURDATE() OR ga.due_date IS NULL)
         ORDER BY ga.due_date ASC LIMIT 20"
    );
    $aq->bind_param('i', $user_id);
    $aq->execute();
    $upcomingAssignments = $aq->get_result()->fetch_all(MYSQLI_ASSOC);
    $aq->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Google Classroom — NoteNest AI</title>
    <meta name="description" content="Connect and sync your Google Classroom courses, assignments, and materials with NoteNest AI.">
    <link rel="shortcut icon" href="img/fav.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/google_classroom.css">
</head>
<body>
<?php include 'includes/navbar.php'; ?>

<div class="container py-4">

    <!-- Flash Message -->
    <?php if ($flashMsg): ?>
    <div class="alert alert-<?= htmlspecialchars($flashType) ?> alert-dismissible fade show gc-alert" role="alert">
        <?= htmlspecialchars($flashMsg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Auto-Sync Result -->
    <?php if ($autoSyncResult): ?>
    <div class="alert alert-<?= $autoSyncResult['success'] ? 'info' : 'warning' ?> alert-dismissible fade show gc-alert" role="alert">
        <?php if ($autoSyncResult['success']): ?>
            <i class="fas fa-sync-alt me-1"></i> Auto-synced:
            <?= $autoSyncResult['stats']['courses'] ?> courses,
            <?= $autoSyncResult['stats']['topics'] ?> topics,
            <?= $autoSyncResult['stats']['files'] ?> files,
            <?= $autoSyncResult['stats']['assignments'] ?> assignments
            <?php if ($autoSyncResult['stats']['errors'] > 0): ?>
                <span class="text-warning">(<?= $autoSyncResult['stats']['errors'] ?> errors)</span>
            <?php endif; ?>
        <?php else: ?>
            <i class="fas fa-exclamation-triangle me-1"></i> Auto-sync failed: <?= htmlspecialchars($autoSyncResult['error'] ?? 'Unknown error') ?>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="gc-header">
        <div class="gc-header-content">
            <div class="gc-header-icon">
                <i class="fab fa-google"></i>
            </div>
            <div>
                <h1 class="gc-header-title">Google Classroom</h1>
                <p class="gc-header-subtitle">Sync your courses, materials, and assignments automatically</p>
            </div>
        </div>
        <?php if ($account): ?>
        <div class="gc-header-actions">
            <button class="btn gc-btn-sync" onclick="syncNow()" id="btnSync">
                <i class="fas fa-sync-alt me-2"></i>Sync Now
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Connection Status Card -->
    <div class="row g-4 mb-4">
        <div class="col-lg-4">
            <div class="gc-card gc-connection-card">
                <div class="gc-card-header">
                    <i class="fas fa-link"></i> Connection
                </div>
                <div class="gc-card-body text-center">
                    <?php if ($account): ?>
                        <div class="gc-connected-badge">
                            <i class="fas fa-check-circle"></i> Connected
                        </div>
                        <div class="gc-email mt-2">
                            <i class="fab fa-google me-1"></i>
                            <?= htmlspecialchars($account['google_email']) ?>
                        </div>
                        <div class="gc-meta mt-2">
                            <small>Connected <?= date('M d, Y', strtotime($account['connected_at'])) ?></small>
                        </div>
                        <button class="btn gc-btn-disconnect mt-3" onclick="disconnectGoogle()">
                            <i class="fas fa-unlink me-1"></i> Disconnect
                        </button>
                    <?php else: ?>
                        <div class="gc-disconnected-icon">
                            <i class="fab fa-google"></i>
                        </div>
                        <p class="gc-disconnected-text">Connect your Google account to sync courses, materials, and assignments.</p>
                        <a href="google_auth.php" class="btn gc-btn-connect">
                            <img src="https://developers.google.com/identity/images/g-logo.png" alt="G" class="gc-google-icon">
                            Connect Google Account
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sync Status -->
        <div class="col-lg-4">
            <div class="gc-card">
                <div class="gc-card-header">
                    <i class="fas fa-cloud-download-alt"></i> Sync Status
                </div>
                <div class="gc-card-body">
                    <?php if ($account): ?>
                        <div class="gc-sync-status-badge gc-sync-<?= $account['sync_status'] ?>">
                            <?php if ($account['sync_status'] === 'syncing'): ?>
                                <i class="fas fa-spinner fa-spin me-1"></i> Syncing...
                            <?php elseif ($account['sync_status'] === 'error'): ?>
                                <i class="fas fa-exclamation-triangle me-1"></i> Error
                            <?php else: ?>
                                <i class="fas fa-check me-1"></i> Ready
                            <?php endif; ?>
                        </div>
                        <div class="gc-sync-detail mt-3">
                            <div class="gc-detail-row">
                                <span class="gc-detail-label">Last Sync</span>
                                <span class="gc-detail-value" id="lastSyncTime">
                                    <?= $account['last_sync_at'] ? date('M d, g:i A', strtotime($account['last_sync_at'])) : 'Never' ?>
                                </span>
                            </div>
                            <div class="gc-detail-row">
                                <span class="gc-detail-label">Status</span>
                                <span class="gc-detail-value gc-status-text"><?= ucfirst($account['sync_status']) ?></span>
                            </div>
                            <?php if ($account['sync_error']): ?>
                            <div class="gc-error-box mt-2">
                                <small><i class="fas fa-exclamation-circle me-1"></i><?= htmlspecialchars(substr($account['sync_error'], 0, 150)) ?></small>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-plug fa-2x mb-2 d-block" style="opacity:.3;"></i>
                            Connect Google account first
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="col-lg-4">
            <div class="gc-card">
                <div class="gc-card-header">
                    <i class="fas fa-chart-pie"></i> Sync Summary
                </div>
                <div class="gc-card-body">
                    <?php if ($syncStats): ?>
                    <div class="gc-stats-grid">
                        <div class="gc-stat-item">
                            <div class="gc-stat-num"><?= $syncStats['total_courses'] ?></div>
                            <div class="gc-stat-label">Courses</div>
                        </div>
                        <div class="gc-stat-item">
                            <div class="gc-stat-num"><?= $syncStats['total_topics'] ?></div>
                            <div class="gc-stat-label">Topics</div>
                        </div>
                        <div class="gc-stat-item">
                            <div class="gc-stat-num"><?= $syncStats['downloaded_files'] ?></div>
                            <div class="gc-stat-label">Files</div>
                        </div>
                        <div class="gc-stat-item">
                            <div class="gc-stat-num"><?= $syncStats['total_assignments'] ?></div>
                            <div class="gc-stat-label">Assignments</div>
                        </div>
                        <div class="gc-stat-item">
                            <div class="gc-stat-num gc-pending"><?= $syncStats['pending_assignments'] ?></div>
                            <div class="gc-stat-label">Pending</div>
                        </div>
                        <div class="gc-stat-item">
                            <div class="gc-stat-num gc-files"><?= $syncStats['total_files'] ?></div>
                            <div class="gc-stat-label">Total Items</div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-database fa-2x mb-2 d-block" style="opacity:.3;"></i>
                        No sync data yet
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($account): ?>
    <!-- Tabs Navigation -->
    <ul class="nav nav-pills gc-tabs mb-4" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-courses">
                <i class="fas fa-graduation-cap me-1"></i> Courses
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-assignments">
                <i class="fas fa-tasks me-1"></i> Assignments
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-calendar">
                <i class="fas fa-calendar-alt me-1"></i> Calendar
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-logs">
                <i class="fas fa-history me-1"></i> Sync Logs
            </button>
        </li>
    </ul>

    <div class="tab-content">

        <!-- COURSES TAB -->
        <div class="tab-pane fade show active" id="tab-courses">
            <?php if (empty($syncedCourses)): ?>
            <div class="gc-card">
                <div class="gc-card-body text-center py-5">
                    <i class="fas fa-graduation-cap fa-3x mb-3" style="color:#ccc;"></i>
                    <h5 class="text-muted">No courses synced yet</h5>
                    <p class="text-muted">Click "Sync Now" to import your Google Classroom courses.</p>
                </div>
            </div>
            <?php else: ?>
            <div class="row g-3">
                <?php foreach ($syncedCourses as $gc): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="gc-course-card">
                        <div class="gc-course-header">
                            <div class="gc-course-icon">
                                <i class="fas fa-book"></i>
                            </div>
                            <div class="gc-course-badge"><?= htmlspecialchars($gc['course_state']) ?></div>
                        </div>
                        <h6 class="gc-course-title"><?= htmlspecialchars($gc['course_name']) ?></h6>
                        <?php if ($gc['section']): ?>
                            <div class="gc-course-section"><?= htmlspecialchars($gc['section']) ?></div>
                        <?php endif; ?>
                        <?php if ($gc['course_code']): ?>
                            <div class="gc-course-code">
                                <i class="fas fa-code me-1"></i><?= htmlspecialchars($gc['course_code']) ?>
                            </div>
                        <?php endif; ?>
                        <div class="gc-course-meta">
                            <span title="NoteNest Course">
                                <i class="fas fa-link me-1"></i>
                                <?= $gc['nn_course_name'] ? htmlspecialchars($gc['nn_course_name']) : 'Not linked' ?>
                            </span>
                        </div>
                        <?php if ($gc['last_synced_at']): ?>
                        <div class="gc-course-sync-time">
                            <i class="fas fa-clock me-1"></i>
                            Synced <?= date('M d, g:i A', strtotime($gc['last_synced_at'])) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ASSIGNMENTS TAB -->
        <div class="tab-pane fade" id="tab-assignments">
            <?php if (empty($upcomingAssignments)): ?>
            <div class="gc-card">
                <div class="gc-card-body text-center py-5">
                    <i class="fas fa-clipboard-check fa-3x mb-3" style="color:#ccc;"></i>
                    <h5 class="text-muted">No assignments synced yet</h5>
                </div>
            </div>
            <?php else: ?>
            <div class="gc-card">
                <div class="gc-card-body p-0">
                    <div class="table-responsive">
                        <table class="table gc-assignments-table mb-0">
                            <thead>
                                <tr>
                                    <th>Assignment</th>
                                    <th>Course</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <th>AI Analysis</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($upcomingAssignments as $asg):
                                $overdue = $asg['due_date'] && strtotime($asg['due_date']) < strtotime('today');
                                $dueStr  = $asg['due_date'] ? date('M d, Y', strtotime($asg['due_date'])) : 'No due date';
                                $statusClass = ($asg['todo_status'] === 'done') ? 'gc-badge-done' :
                                              ($overdue ? 'gc-badge-overdue' : 'gc-badge-pending');
                                $statusText  = ($asg['todo_status'] === 'done') ? 'Done' :
                                              ($overdue ? 'Overdue' : 'Pending');

                                // Parse AI analysis
                                $aiData = $asg['ai_analysis'] ? json_decode($asg['ai_analysis'], true) : null;
                            ?>
                                <tr>
                                    <td>
                                        <div class="gc-asg-title"><?= htmlspecialchars($asg['title']) ?></div>
                                        <?php if ($asg['description']): ?>
                                        <div class="gc-asg-desc"><?= htmlspecialchars(substr($asg['description'], 0, 80)) ?>...</div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="gc-asg-course"><?= htmlspecialchars($asg['course_name']) ?></span></td>
                                    <td>
                                        <span class="<?= $overdue ? 'text-danger fw-bold' : '' ?>"><?= $dueStr ?></span>
                                        <?php if ($asg['due_time']): ?>
                                        <br><small class="text-muted"><?= date('g:i A', strtotime($asg['due_time'])) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="gc-status-badge <?= $statusClass ?>"><?= $statusText ?></span></td>
                                    <td>
                                        <?php if ($aiData): ?>
                                        <button class="btn btn-sm gc-btn-ai-detail" data-bs-toggle="modal" data-bs-target="#aiModal"
                                                data-title="<?= htmlspecialchars($asg['title']) ?>"
                                                data-analysis='<?= htmlspecialchars(json_encode($aiData)) ?>'>
                                            <i class="fas fa-robot me-1"></i>
                                            <?= htmlspecialchars($aiData['estimated_difficulty'] ?? 'View') ?>
                                        </button>
                                        <?php else: ?>
                                        <span class="text-muted"><i class="fas fa-hourglass-half me-1"></i>Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- CALENDAR TAB -->
        <div class="tab-pane fade" id="tab-calendar">
            <div class="gc-card">
                <div class="gc-card-header d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fas fa-calendar-alt me-1"></i>
                        <span id="calendarMonthLabel"><?= date('F Y') ?></span>
                    </div>
                    <div>
                        <button class="btn btn-sm gc-btn-cal-nav" onclick="changeMonth(-1)"><i class="fas fa-chevron-left"></i></button>
                        <button class="btn btn-sm gc-btn-cal-nav" onclick="changeMonth(0)">Today</button>
                        <button class="btn btn-sm gc-btn-cal-nav" onclick="changeMonth(1)"><i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
                <div class="gc-card-body p-0">
                    <div class="gc-calendar-grid" id="calendarGrid">
                        <!-- Rendered by JS -->
                    </div>
                </div>
            </div>
            <div class="gc-card mt-3">
                <div class="gc-card-header">
                    <i class="fas fa-list me-1"></i> Legend
                </div>
                <div class="gc-card-body">
                    <div class="gc-legend">
                        <span class="gc-legend-item"><span class="gc-legend-dot" style="background:#e74c3c;"></span> High Priority</span>
                        <span class="gc-legend-item"><span class="gc-legend-dot" style="background:#f39c12;"></span> Medium Priority</span>
                        <span class="gc-legend-item"><span class="gc-legend-dot" style="background:#27ae60;"></span> Low Priority</span>
                        <span class="gc-legend-item"><span class="gc-legend-dot" style="background:#4285f4;"></span> General</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- SYNC LOGS TAB -->
        <div class="tab-pane fade" id="tab-logs">
            <?php if (empty($syncLogs)): ?>
            <div class="gc-card">
                <div class="gc-card-body text-center py-5">
                    <i class="fas fa-history fa-3x mb-3" style="color:#ccc;"></i>
                    <h5 class="text-muted">No sync history</h5>
                </div>
            </div>
            <?php else: ?>
            <div class="gc-card">
                <div class="gc-card-body p-0">
                    <div class="table-responsive">
                        <table class="table gc-logs-table mb-0">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Courses</th>
                                    <th>Topics</th>
                                    <th>Files</th>
                                    <th>Assignments</th>
                                    <th>Errors</th>
                                    <th>Duration</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($syncLogs as $log): ?>
                                <tr>
                                    <td><?= date('M d, g:i A', strtotime($log['started_at'])) ?></td>
                                    <td><span class="gc-log-type"><?= ucfirst($log['sync_type']) ?></span></td>
                                    <td>
                                        <span class="gc-log-status gc-log-<?= $log['status'] ?>">
                                            <?= ucfirst($log['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= $log['courses_synced'] ?></td>
                                    <td><?= $log['topics_synced'] ?></td>
                                    <td><?= $log['files_synced'] ?></td>
                                    <td><?= $log['assignments_synced'] ?></td>
                                    <td><?= $log['errors_count'] ?></td>
                                    <td><?= $log['duration_sec'] ? $log['duration_sec'] . 's' : '-' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- AI Analysis Modal -->
<div class="modal fade" id="aiModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content gc-ai-modal">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-robot me-2"></i>AI Assignment Analysis</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="aiModalBody">
                <!-- Rendered by JS -->
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Sync Now ─────────────────────────────────────────────────
function syncNow() {
    const btn = document.getElementById('btnSync');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Syncing...';

    $.post('google_classroom.php', { action: 'sync_now' }, function(res) {
        if (res.success) {
            const s = res.stats;
            let msg = `Sync complete!\n\nCourses: ${s.courses}\nTopics: ${s.topics}\nFiles: ${s.files}\nAssignments: ${s.assignments}`;
            if (s.errors > 0) msg += `\n\n⚠️ ${s.errors} error(s) occurred.`;
            alert(msg);
            location.reload();
        } else {
            alert('Sync failed: ' + (res.error || 'Unknown error'));
        }
    }, 'json').fail(function(xhr) {
        alert('Network error during sync. Check console for details.');
        console.error(xhr);
    }).always(function() {
        btn.disabled = false;
        btn.innerHTML = orig;
    });
}

// ── Disconnect ───────────────────────────────────────────────
function disconnectGoogle() {
    if (!confirm('Are you sure you want to disconnect your Google account? Synced data will remain but no new sync will occur.')) return;

    $.post('google_classroom.php', { action: 'disconnect' }, function(res) {
        if (res.success) {
            alert('Google account disconnected.');
            location.reload();
        }
    }, 'json');
}

// ── Calendar ─────────────────────────────────────────────────
let calMonth = <?= (int)date('m') ?>;
let calYear  = <?= (int)date('Y') ?>;
const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

function changeMonth(delta) {
    if (delta === 0) {
        calMonth = new Date().getMonth() + 1;
        calYear = new Date().getFullYear();
    } else {
        calMonth += delta;
        if (calMonth < 1) { calMonth = 12; calYear--; }
        if (calMonth > 12) { calMonth = 1; calYear++; }
    }
    loadCalendar();
}

function loadCalendar() {
    document.getElementById('calendarMonthLabel').textContent = monthNames[calMonth-1] + ' ' + calYear;

    $.post('google_classroom.php', { action: 'get_calendar', month: calMonth, year: calYear }, function(res) {
        renderCalendar(res.events || [], calMonth, calYear);
    }, 'json');
}

function renderCalendar(events, month, year) {
    const grid = document.getElementById('calendarGrid');
    if (!grid) return;

    const firstDay = new Date(year, month-1, 1).getDay();
    const daysInMonth = new Date(year, month, 0).getDate();
    const today = new Date();

    let html = '<div class="gc-cal-header-row">';
    dayNames.forEach(d => html += `<div class="gc-cal-header-cell">${d}</div>`);
    html += '</div>';

    // Map events by date
    const eventMap = {};
    events.forEach(e => {
        const d = parseInt(e.event_date.split('-')[2]);
        if (!eventMap[d]) eventMap[d] = [];
        eventMap[d].push(e);
    });

    html += '<div class="gc-cal-body">';
    let dayCount = 1;
    for (let row = 0; row < 6; row++) {
        if (dayCount > daysInMonth) break;
        html += '<div class="gc-cal-row">';
        for (let col = 0; col < 7; col++) {
            if ((row === 0 && col < firstDay) || dayCount > daysInMonth) {
                html += '<div class="gc-cal-cell gc-cal-empty"></div>';
            } else {
                const isToday = (dayCount === today.getDate() && month === today.getMonth()+1 && year === today.getFullYear());
                const dayEvents = eventMap[dayCount] || [];
                html += `<div class="gc-cal-cell ${isToday ? 'gc-cal-today' : ''}">`;
                html += `<div class="gc-cal-day-num">${dayCount}</div>`;
                dayEvents.forEach(evt => {
                    html += `<div class="gc-cal-event" style="border-left:3px solid ${evt.color || '#4285f4'};" title="${evt.title}">`;
                    html += `<span class="gc-cal-event-text">${evt.title.substring(0,20)}${evt.title.length>20?'...':''}</span>`;
                    if (evt.event_time) html += `<span class="gc-cal-event-time">${evt.event_time.substring(0,5)}</span>`;
                    html += '</div>';
                });
                html += '</div>';
                dayCount++;
            }
        }
        html += '</div>';
    }
    html += '</div>';
    grid.innerHTML = html;
}

// ── AI Analysis Modal ────────────────────────────────────────
document.querySelectorAll('.gc-btn-ai-detail').forEach(btn => {
    btn.addEventListener('click', function() {
        const title = this.dataset.title;
        let analysis;
        try { analysis = JSON.parse(this.dataset.analysis); } catch(e) { return; }

        let html = `<h5 class="mb-3">${title}</h5>`;

        if (analysis.summary) {
            html += `<div class="gc-ai-section"><h6><i class="fas fa-file-alt me-2"></i>Summary</h6><p>${analysis.summary}</p></div>`;
        }
        if (analysis.estimated_difficulty) {
            const diffColors = {Easy:'#27ae60', Medium:'#f39c12', Hard:'#e74c3c'};
            html += `<div class="gc-ai-section"><h6><i class="fas fa-signal me-2"></i>Difficulty</h6>
                <span class="badge" style="background:${diffColors[analysis.estimated_difficulty]||'#888'};font-size:.9rem;padding:6px 14px;">${analysis.estimated_difficulty}</span>`;
            if (analysis.estimated_hours) html += ` <span class="text-muted ms-2">~${analysis.estimated_hours} hours</span>`;
            html += '</div>';
        }
        if (analysis.important_concepts && analysis.important_concepts.length) {
            html += '<div class="gc-ai-section"><h6><i class="fas fa-lightbulb me-2"></i>Key Concepts</h6><div class="gc-ai-tags">';
            analysis.important_concepts.forEach(c => html += `<span class="gc-ai-tag">${c}</span>`);
            html += '</div></div>';
        }
        if (analysis.study_tips && analysis.study_tips.length) {
            html += '<div class="gc-ai-section"><h6><i class="fas fa-graduation-cap me-2"></i>Study Tips</h6><ul class="gc-ai-list">';
            analysis.study_tips.forEach(t => html += `<li>${t}</li>`);
            html += '</ul></div>';
        }
        if (analysis.recommended_approach) {
            html += `<div class="gc-ai-section"><h6><i class="fas fa-route me-2"></i>Recommended Approach</h6><p>${analysis.recommended_approach}</p></div>`;
        }

        document.getElementById('aiModalBody').innerHTML = html;
    });
});

// Load calendar on tab show
document.querySelector('[data-bs-target="#tab-calendar"]')?.addEventListener('shown.bs.tab', function() {
    loadCalendar();
});

// Auto-load calendar if it's visible
if (document.querySelector('#tab-calendar.show')) loadCalendar();
</script>
</body>
</html>
