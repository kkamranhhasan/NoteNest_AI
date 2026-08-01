<?php
// ============================================================
// dashboard_gc_ajax.php — NoteNest AI Platform
// AJAX endpoint for Google Classroom dashboard interactions
// ============================================================
require 'includes/auth.php';
require 'config.php';
require_once 'includes/google_classroom_service.php';
require_once 'includes/google_sync_engine.php';

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'];
$action  = $_POST['action'] ?? $_GET['action'] ?? '';

// Only proceed if Google is connected
$account = gc_get_account($conn, $user_id);
if (!$account && $action !== 'check_connection') {
    echo json_encode(['success' => false, 'error' => 'Google account not connected.']);
    exit;
}

switch ($action) {

    // ── Sync Now ─────────────────────────────────────────────
    case 'sync_now':
        @set_time_limit(300);
        @ini_set('max_execution_time', 300);

        // Reset stuck syncing state
        if ($account['sync_status'] === 'syncing') {
            gc_update_sync_status($conn, $user_id, 'idle');
        }

        ob_start();
        $result = gc_run_sync($conn, $user_id, 'manual');
        gc_repair_and_link_courses($conn, $user_id);
        ob_end_clean();

        if ($result['success']) {
            $gcData = gc_get_dashboard_data($conn, $user_id);
            echo json_encode([
                'success' => true,
                'stats'   => $gcData['stats'],
                'message' => "Sync complete: {$result['stats']['courses']} courses, {$result['stats']['topics']} topics, {$result['stats']['files']} files, {$result['stats']['assignments']} assignments.",
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Sync failed.']);
        }
        break;

    // ── Get Fresh Stats ───────────────────────────────────────
    case 'get_gc_stats':
        $gcData = gc_get_dashboard_data($conn, $user_id);
        echo json_encode([
            'success'      => true,
            'stats'        => $gcData['stats'],
            'last_sync_at' => $account['last_sync_at'] ?? null,
        ]);
        break;

    // ── Get Courses List ──────────────────────────────────────
    case 'get_courses':
        $stmt = $conn->prepare(
            "SELECT gc.google_course_id, gc.course_name, gc.section, gc.teacher_name, gc.course_code, gc.last_synced_at,
                    (SELECT COUNT(*) FROM google_topics gt WHERE gt.google_course_id = gc.google_course_id AND gt.user_id = gc.user_id) AS topics_count,
                    (SELECT COUNT(*) FROM google_files gf WHERE gf.google_course_id = gc.google_course_id AND gf.user_id = gc.user_id) AS files_count,
                    (SELECT COUNT(*) FROM google_assignments ga WHERE ga.google_course_id = gc.google_course_id AND ga.user_id = gc.user_id) AS assignments_count
             FROM google_courses gc
             WHERE gc.user_id = ?
             ORDER BY gc.course_name ASC"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        echo json_encode(['success' => true, 'courses' => $courses]);
        break;

    // ── Get Topics for Course ─────────────────────────────────
    case 'get_topics':
        $google_course_id = $_POST['google_course_id'] ?? '';
        
        $cStmt = $conn->prepare("SELECT course_name, section, teacher_name FROM google_courses WHERE user_id = ? AND google_course_id = ?");
        $cStmt->bind_param('is', $user_id, $google_course_id);
        $cStmt->execute();
        $course_info = $cStmt->get_result()->fetch_assoc();
        $cStmt->close();

        $tStmt = $conn->prepare(
            "SELECT gt.google_topic_id, gt.topic_id, gt.topic_name,
                    (SELECT COUNT(*) FROM google_files gf WHERE gf.google_course_id = gt.google_course_id AND gf.user_id = gt.user_id AND (gf.topic_id = gt.topic_id OR (gf.topic_id IS NULL AND gt.topic_name = 'General'))) AS files_count
             FROM google_topics gt
             WHERE gt.user_id = ? AND gt.google_course_id = ?
             ORDER BY gt.sort_order ASC, gt.topic_name ASC"
        );
        $tStmt->bind_param('is', $user_id, $google_course_id);
        $tStmt->execute();
        $topics = $tStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $tStmt->close();

        // Also count files without topic
        $noTopicCountStmt = $conn->prepare(
            "SELECT COUNT(*) FROM google_files WHERE user_id = ? AND google_course_id = ? AND (topic_id IS NULL OR topic_id = 0)"
        );
        $noTopicCountStmt->bind_param('is', $user_id, $google_course_id);
        $noTopicCountStmt->execute();
        $noTopicCountStmt->bind_result($unassigned_files_count);
        $noTopicCountStmt->fetch();
        $noTopicCountStmt->close();

        echo json_encode([
            'success' => true,
            'course' => $course_info,
            'topics' => $topics,
            'unassigned_files_count' => (int)$unassigned_files_count
        ]);
        break;

    // ── Get Files and Assignments for Topic ──────────────────
    case 'get_topic_content':
        $google_course_id = $_POST['google_course_id'] ?? '';
        $topic_id_raw     = $_POST['topic_id'] ?? '';

        if ($topic_id_raw === 'unassigned') {
            $fStmt = $conn->prepare(
                "SELECT gf.id, gf.file_title, gf.file_type, gf.mime_type, gf.file_url, gf.download_status, gf.created_at, gf.file_id, f.file_path
                 FROM google_files gf
                 LEFT JOIN files f ON gf.file_id = f.id
                 WHERE gf.user_id = ? AND gf.google_course_id = ? AND (gf.topic_id IS NULL OR gf.topic_id = 0)
                 ORDER BY gf.created_at DESC"
            );
            $fStmt->bind_param('is', $user_id, $google_course_id);
        } elseif ($topic_id_raw !== '' && $topic_id_raw !== 'all') {
            $topic_id = (int)$topic_id_raw;
            $fStmt = $conn->prepare(
                "SELECT gf.id, gf.file_title, gf.file_type, gf.mime_type, gf.file_url, gf.download_status, gf.created_at, gf.file_id, f.file_path
                 FROM google_files gf
                 LEFT JOIN files f ON gf.file_id = f.id
                 WHERE gf.user_id = ? AND gf.google_course_id = ? AND gf.topic_id = ?
                 ORDER BY gf.created_at DESC"
            );
            $fStmt->bind_param('isi', $user_id, $google_course_id, $topic_id);
        } else {
            $fStmt = $conn->prepare(
                "SELECT gf.id, gf.file_title, gf.file_type, gf.mime_type, gf.file_url, gf.download_status, gf.created_at, gf.file_id, f.file_path
                 FROM google_files gf
                 LEFT JOIN files f ON gf.file_id = f.id
                 WHERE gf.user_id = ? AND gf.google_course_id = ?
                 ORDER BY gf.created_at DESC"
            );
            $fStmt->bind_param('is', $user_id, $google_course_id);
        }
        $fStmt->execute();
        $files = $fStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $fStmt->close();

        // Fetch assignments
        $aStmt = $conn->prepare(
            "SELECT ga.id, ga.title, ga.description, ga.due_date, ga.due_time, ga.max_points, ga.work_type, t.status AS todo_status
             FROM google_assignments ga
             LEFT JOIN todos t ON ga.todo_id = t.id
             WHERE ga.user_id = ? AND ga.google_course_id = ?
             ORDER BY ga.due_date ASC"
        );
        $aStmt->bind_param('is', $user_id, $google_course_id);
        $aStmt->execute();
        $assignments = $aStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $aStmt->close();

        echo json_encode([
            'success'     => true,
            'files'       => $files,
            'assignments' => $assignments
        ]);
        break;

    // ── Get Announcements ─────────────────────────────────────
    case 'get_announcements':
        $stmt = $conn->prepare(
            "SELECT id, message, is_read, created_at
             FROM notifications
             WHERE user_id = ? AND message LIKE '%Classroom%'
             ORDER BY created_at DESC LIMIT 10"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $announcements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        echo json_encode(['success' => true, 'announcements' => $announcements]);
        break;

    // ── Check connection status ───────────────────────────────
    case 'check_connection':
        echo json_encode([
            'success'   => true,
            'connected' => (bool)$account,
            'email'     => $account['google_email'] ?? null,
            'status'    => $account['sync_status']  ?? null,
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action.']);
        break;
}
