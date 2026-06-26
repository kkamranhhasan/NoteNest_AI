<?php
// ============================================================
// course_management.php — NoteNest AI Platform
// Course & Syllabus Management
// ============================================================
require 'includes/auth.php';
require 'config.php';

$user_id = $_SESSION['user_id'];
$msg = '';
$msg_type = '';

// ── Helper: recursively delete a folder tree (files on disk + DB rows) ───
function delete_folder_tree(mysqli $conn, int $folder_id, int $owner_id): void {
    // 1. Fetch and physically remove files inside this folder
    $fq = $conn->prepare("SELECT file_path FROM files WHERE folder_id=? AND owner_id=?");
    $fq->bind_param('ii', $folder_id, $owner_id); $fq->execute();
    $fq->bind_result($fp);
    $paths = [];
    while ($fq->fetch()) $paths[] = $fp;
    $fq->close();
    foreach ($paths as $path) @unlink(__DIR__ . '/' . $path);

    // 2. Delete file DB records
    $df = $conn->prepare("DELETE FROM files WHERE folder_id=? AND owner_id=?");
    $df->bind_param('ii', $folder_id, $owner_id); $df->execute(); $df->close();

    // 3. Recurse into sub-folders
    $sq = $conn->prepare("SELECT id FROM folders WHERE parent_folder_id=? AND owner_id=?");
    $sq->bind_param('ii', $folder_id, $owner_id); $sq->execute();
    $sq->bind_result($sub_id);
    $subs = [];
    while ($sq->fetch()) $subs[] = $sub_id;
    $sq->close();
    foreach ($subs as $sub) delete_folder_tree($conn, $sub, $owner_id);

    // 4. Delete this folder row
    $delf = $conn->prepare("DELETE FROM folders WHERE id=? AND owner_id=?");
    $delf->bind_param('ii', $folder_id, $owner_id); $delf->execute(); $delf->close();
}


// ── Handle AJAX requests ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $action = $_POST['action'];

    // ADD COURSE — also creates a linked root folder
    if ($action === 'add_course') {
        $name  = trim($_POST['name']  ?? '');
        $code  = strtoupper(trim($_POST['code'] ?? ''));
        $desc  = trim($_POST['description'] ?? '');
        $color = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#197f8f';

        if (!$name || !$code) {
            echo json_encode(['success' => false, 'message' => 'Course name and code are required.']);
            exit;
        }

        $conn->begin_transaction();
        try {
            // 1. Insert course
            $stmt = $conn->prepare("INSERT INTO courses (user_id, name, code, description, color) VALUES (?,?,?,?,?)");
            $stmt->bind_param('issss', $user_id, $name, $code, $desc, $color);
            $stmt->execute();
            $course_id  = $conn->insert_id;
            $stmt->close();

            // 2. Auto-create a linked root folder (name = "CourseName (CODE)")
            $folder_name = $name . ' (' . $code . ')';
            $null        = null;
            $ins = $conn->prepare(
                "INSERT INTO folders (owner_id, course_id, is_course_root, name, parent_folder_id)
                 VALUES (?, ?, 1, ?, ?)"
            );
            $ins->bind_param('iisi', $user_id, $course_id, $folder_name, $null);
            $ins->execute();
            $root_folder_id = $conn->insert_id;
            $ins->close();

            $conn->commit();
            echo json_encode([
                'success'        => true,
                'message'        => 'Course added!',
                'id'             => $course_id,
                'root_folder_id' => $root_folder_id,
            ]);
        } catch (\Throwable $e) {
            $conn->rollback();
            $err = str_contains($conn->error, 'Duplicate') ? "Course code '{$code}' already exists." : 'Database error.';
            echo json_encode(['success' => false, 'message' => $err]);
        }
        exit;
    }

    // DELETE COURSE — also recursively purges the course folder tree
    if ($action === 'delete_course') {
        $course_id = (int)($_POST['course_id'] ?? 0);

        // Verify ownership
        $chk = $conn->prepare("SELECT id FROM courses WHERE id=? AND user_id=?");
        $chk->bind_param('ii', $course_id, $user_id); $chk->execute(); $chk->store_result();
        if ($chk->num_rows === 0) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
        $chk->close();

        // Find root folder for this course
        $fq = $conn->prepare("SELECT id FROM folders WHERE course_id=? AND is_course_root=1 AND owner_id=?");
        $fq->bind_param('ii', $course_id, $user_id); $fq->execute();
        $fq->bind_result($root_folder_id); $fq->fetch(); $fq->close();

        $conn->begin_transaction();
        try {
            // Recursively delete the course folder tree (files on disk + DB rows)
            if ($root_folder_id) {
                delete_folder_tree($conn, $root_folder_id, $user_id);
            }
            // Delete the course (cascades topics, file_course_tags, evaluations etc.)
            $del = $conn->prepare("DELETE FROM courses WHERE id=? AND user_id=?");
            $del->bind_param('ii', $course_id, $user_id); $del->execute();
            $affected = $del->affected_rows; $del->close();
            $conn->commit();
            echo json_encode(['success' => $affected > 0]);
        } catch (\Throwable $e) {
            $conn->rollback();
            echo json_encode(['success'=>false,'message'=>'Delete failed: '.$e->getMessage()]);
        }
        exit;
    }

    // ADD TOPIC — also creates a linked sub-folder inside course root folder
    if ($action === 'add_topic') {
        $course_id = (int)($_POST['course_id'] ?? 0);
        $title     = trim($_POST['title'] ?? '');
        $week_no   = (int)($_POST['week_no'] ?? 0) ?: null;

        // Verify course ownership
        $chk = $conn->prepare("SELECT id FROM courses WHERE id=? AND user_id=?");
        $chk->bind_param('ii', $course_id, $user_id);
        $chk->execute(); $chk->store_result();
        if ($chk->num_rows === 0) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
        $chk->close();

        if (!$title) { echo json_encode(['success'=>false,'message'=>'Topic title required.']); exit; }

        // Find the course root folder
        $rf = $conn->prepare("SELECT id FROM folders WHERE course_id=? AND is_course_root=1 AND owner_id=?");
        $rf->bind_param('ii', $course_id, $user_id); $rf->execute();
        $rf->bind_result($root_folder_id); $rf->fetch(); $rf->close();

        if (!$root_folder_id) {
            echo json_encode(['success'=>false,'message'=>'Course root folder not found. Please recreate the course.']);
            exit;
        }

        $conn->begin_transaction();
        try {
            // 1. Get next sort order
            $ord = $conn->query("SELECT COALESCE(MAX(sort_order),0)+1 AS o FROM course_topics WHERE course_id=$course_id")->fetch_assoc()['o'];

            // 2. Insert topic
            $ins = $conn->prepare("INSERT INTO course_topics (course_id, title, week_no, sort_order) VALUES (?,?,?,?)");
            $ins->bind_param('isii', $course_id, $title, $week_no, $ord);
            $ins->execute();
            $topic_id = $conn->insert_id;
            $ins->close();

            // 3. Create sub-folder inside course root
            $sf = $conn->prepare(
                "INSERT INTO folders (owner_id, course_id, name, parent_folder_id) VALUES (?,?,?,?)"
            );
            $sf->bind_param('iisi', $user_id, $course_id, $title, $root_folder_id);
            $sf->execute();
            $topic_folder_id = $conn->insert_id;
            $sf->close();

            // 4. Link folder to topic
            $link = $conn->prepare("UPDATE course_topics SET folder_id=? WHERE id=?");
            $link->bind_param('ii', $topic_folder_id, $topic_id);
            $link->execute(); $link->close();

            $conn->commit();
            echo json_encode([
                'success'          => true,
                'id'               => $topic_id,
                'folder_id'        => $topic_folder_id,
                'root_folder_id'   => $root_folder_id,
                'message'          => 'Topic added!'
            ]);
        } catch (\Throwable $e) {
            $conn->rollback();
            echo json_encode(['success'=>false,'message'=>'Failed: '.$e->getMessage()]);
        }
        exit;
    }

    // DELETE TOPIC — also deletes topic folder tree and all files
    if ($action === 'delete_topic') {
        $topic_id = (int)($_POST['topic_id'] ?? 0);

        // Get folder_id before deleting
        $tq = $conn->prepare(
            "SELECT ct.folder_id FROM course_topics ct
             JOIN courses c ON ct.course_id = c.id
             WHERE ct.id=? AND c.user_id=?"
        );
        $tq->bind_param('ii', $topic_id, $user_id); $tq->execute();
        $tq->bind_result($topic_folder_id); $tq->fetch(); $tq->close();

        $conn->begin_transaction();
        try {
            // Delete topic folder tree (files on disk + DB rows)
            if ($topic_folder_id) {
                delete_folder_tree($conn, $topic_folder_id, $user_id);
            }
            // Delete topic row (cascades file_course_tags)
            $del = $conn->prepare(
                "DELETE ct FROM course_topics ct
                 JOIN courses c ON ct.course_id=c.id
                 WHERE ct.id=? AND c.user_id=?"
            );
            $del->bind_param('ii', $topic_id, $user_id);
            $del->execute();
            $affected = $del->affected_rows; $del->close();
            $conn->commit();
            echo json_encode(['success' => $affected > 0]);
        } catch (\Throwable $e) {
            $conn->rollback();
            echo json_encode(['success'=>false,'message'=>'Delete failed: '.$e->getMessage()]);
        }
        exit;
    }

    // GET TOPICS for a course (for dropdown in file upload)
    if ($action === 'get_topics') {
        $course_id = (int)($_POST['course_id'] ?? 0);
        $stmt = $conn->prepare("SELECT id, title, week_no FROM course_topics WHERE course_id=? ORDER BY sort_order ASC");
        $stmt->bind_param('i', $course_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $topics = $res->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['success' => true, 'topics' => $topics]);
        $stmt->close(); exit;
    }

    // TAG FILE to course/topic
    if ($action === 'tag_file') {
        $file_id   = (int)($_POST['file_id']   ?? 0);
        $course_id = (int)($_POST['course_id'] ?? 0);
        $topic_id  = (int)($_POST['topic_id']  ?? 0) ?: null;

        // Verify file ownership
        $chk = $conn->prepare("SELECT id FROM files WHERE id=? AND owner_id=?");
        $chk->bind_param('ii', $file_id, $user_id); $chk->execute(); $chk->store_result();
        if ($chk->num_rows === 0) { echo json_encode(['success'=>false,'message'=>'File not found']); exit; }
        $chk->close();
        // Verify course ownership
        $chk2 = $conn->prepare("SELECT id FROM courses WHERE id=? AND user_id=?");
        $chk2->bind_param('ii', $course_id, $user_id); $chk2->execute(); $chk2->store_result();
        if ($chk2->num_rows === 0) { echo json_encode(['success'=>false,'message'=>'Course not found']); exit; }
        $chk2->close();

        // ─ Fix: handle nullable topic_id properly ─
        if ($topic_id) {
            $stmt = $conn->prepare("INSERT IGNORE INTO file_course_tags (file_id, course_id, topic_id) VALUES (?,?,?)");
            $stmt->bind_param('iii', $file_id, $course_id, $topic_id);
        } else {
            $stmt = $conn->prepare("INSERT IGNORE INTO file_course_tags (file_id, course_id) VALUES (?,?)");
            $stmt->bind_param('ii', $file_id, $course_id);
        }
        $stmt->execute();
        $inserted = $stmt->affected_rows;
        $stmt->close();
        echo json_encode(['success' => true, 'inserted' => $inserted, 'message' => 'File attached!']);
        exit;
    }

    // UNTAG FILE from course
    if ($action === 'untag_file') {
        $file_id   = (int)($_POST['file_id']   ?? 0);
        $course_id = (int)($_POST['course_id'] ?? 0);
        $stmt = $conn->prepare(
            "DELETE fct FROM file_course_tags fct
             JOIN files f ON fct.file_id=f.id
             WHERE fct.file_id=? AND fct.course_id=? AND f.owner_id=?"
        );
        $stmt->bind_param('iii', $file_id, $course_id, $user_id);
        $stmt->execute();
        echo json_encode(['success'=> $stmt->affected_rows > 0]);
        $stmt->close(); exit;
    }

    // GET FILES for a course
    if ($action === 'get_course_files') {
        $course_id = (int)($_POST['course_id'] ?? 0);
        $stmt = $conn->prepare(
            "SELECT f.id, f.name, f.file_path, f.mime_type, t.title AS topic_title
             FROM file_course_tags fct
             JOIN files f ON fct.file_id=f.id
             LEFT JOIN course_topics t ON fct.topic_id=t.id
             WHERE fct.course_id=? AND f.owner_id=?
             ORDER BY fct.tagged_at DESC"
        );
        $stmt->bind_param('ii', $course_id, $user_id);
        $stmt->execute();
        $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['success'=>true,'files'=>$files]);
        $stmt->close(); exit;
    }

    // GET ALL USER FILES (for picker modal)
    if ($action === 'get_all_files') {
        $course_id = (int)($_POST['course_id'] ?? 0);
        $stmt = $conn->prepare(
            "SELECT f.id, f.name, f.mime_type,
                    (SELECT COUNT(*) FROM file_course_tags fct WHERE fct.file_id=f.id AND fct.course_id=?) AS tagged
             FROM files f WHERE f.owner_id=? ORDER BY f.created_at DESC"
        );
        $stmt->bind_param('ii', $course_id, $user_id);
        $stmt->execute();
        $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['success'=>true,'files'=>$files]);
        $stmt->close(); exit;
    }

    // UPLOAD FILES to a topic folder (stored in files table WITH folder_id)
    if ($action === 'upload_topic_files') {
        $course_id = (int)($_POST['course_id'] ?? 0);
        $topic_id  = (int)($_POST['topic_id']  ?? 0) ?: null;

        // Verify course ownership
        $chk = $conn->prepare("SELECT id FROM courses WHERE id=? AND user_id=?");
        $chk->bind_param('ii', $course_id, $user_id); $chk->execute(); $chk->store_result();
        if ($chk->num_rows === 0) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
        $chk->close();

        // Get the topic's folder_id (for storing in files table)
        $topic_folder_id = null;
        if ($topic_id) {
            $tfq = $conn->prepare("SELECT folder_id FROM course_topics WHERE id=? AND course_id=?");
            $tfq->bind_param('ii', $topic_id, $course_id); $tfq->execute();
            $tfq->bind_result($topic_folder_id); $tfq->fetch(); $tfq->close();
        }
        // Fallback: use course root folder
        if (!$topic_folder_id) {
            $rfq = $conn->prepare("SELECT id FROM folders WHERE course_id=? AND is_course_root=1 AND owner_id=?");
            $rfq->bind_param('ii', $course_id, $user_id); $rfq->execute();
            $rfq->bind_result($topic_folder_id); $rfq->fetch(); $rfq->close();
        }

        $uploadDir = __DIR__ . '/uploads/course_materials/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $uploaded = []; $errors = [];

        $fileCount = count($_FILES['files']['name'] ?? []);
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = $_FILES['files']['name'][$i] . ': upload error'; continue;
            }
            if ($_FILES['files']['size'][$i] > 50 * 1024 * 1024) {
                $errors[] = $_FILES['files']['name'][$i] . ': exceeds 50 MB'; continue;
            }

            $origName = basename($_FILES['files']['name'][$i]);
            $mime     = $_FILES['files']['type'][$i];
            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $safeName = uniqid('cm_', true) . '.' . $ext;
            $destPath = $uploadDir . $safeName;
            $relPath  = 'uploads/course_materials/' . $safeName;

            if (!move_uploaded_file($_FILES['files']['tmp_name'][$i], $destPath)) {
                $errors[] = $origName . ': could not save'; continue;
            }

            // Insert into files table WITH folder_id and course_id
            $ins = $conn->prepare(
                "INSERT INTO files (owner_id, folder_id, course_id, name, file_path, mime_type)
                 VALUES (?,?,?,?,?,?)"
            );
            $ins->bind_param('iiisss', $user_id, $topic_folder_id, $course_id, $origName, $relPath, $mime);
            $ins->execute();
            $file_id = $conn->insert_id;
            $ins->close();

            // Tag to course + topic in file_course_tags
            if ($topic_id) {
                $tag = $conn->prepare("INSERT IGNORE INTO file_course_tags (file_id, course_id, topic_id) VALUES (?,?,?)");
                $tag->bind_param('iii', $file_id, $course_id, $topic_id);
            } else {
                $tag = $conn->prepare("INSERT IGNORE INTO file_course_tags (file_id, course_id) VALUES (?,?)");
                $tag->bind_param('ii', $file_id, $course_id);
            }
            $tag->execute(); $tag->close();

            $uploaded[] = ['id'=>$file_id,'name'=>$origName,'file_path'=>$relPath,'mime_type'=>$mime];
        }
        echo json_encode(['success'=>true,'uploaded'=>$uploaded,'errors'=>$errors]);
        exit;
    }

    // GET FILES for a specific topic folder
    if ($action === 'get_topic_files') {
        $topic_id = (int)($_POST['topic_id'] ?? 0);
        // Get topic folder_id, verify ownership
        $tq = $conn->prepare(
            "SELECT ct.folder_id FROM course_topics ct
             JOIN courses c ON ct.course_id=c.id
             WHERE ct.id=? AND c.user_id=?"
        );
        $tq->bind_param('ii', $topic_id, $user_id); $tq->execute();
        $tq->bind_result($folder_id); $tq->fetch(); $tq->close();

        if (!$folder_id) { echo json_encode(['success'=>false,'files'=>[]]); exit; }

        $fq = $conn->prepare(
            "SELECT id, name, file_path, mime_type, created_at
             FROM files WHERE folder_id=? AND owner_id=? ORDER BY created_at DESC"
        );
        $fq->bind_param('ii', $folder_id, $user_id); $fq->execute();
        $files = $fq->get_result()->fetch_all(MYSQLI_ASSOC); $fq->close();
        echo json_encode(['success'=>true,'files'=>$files,'folder_id'=>$folder_id]);
        exit;
    }

    // DELETE a file from a topic folder
    if ($action === 'delete_topic_file') {
        $file_id = (int)($_POST['file_id'] ?? 0);
        // Verify ownership
        $fq = $conn->prepare("SELECT file_path FROM files WHERE id=? AND owner_id=?");
        $fq->bind_param('ii', $file_id, $user_id); $fq->execute();
        $fq->bind_result($file_path); $fq->fetch(); $fq->close();

        if (!$file_path) { echo json_encode(['success'=>false,'message'=>'File not found']); exit; }

        // Delete physical file
        $fullPath = __DIR__ . '/' . ltrim($file_path, '/');
        if (file_exists($fullPath)) @unlink($fullPath);

        // Delete DB row + file_course_tags (cascades via FK)
        $del = $conn->prepare("DELETE FROM files WHERE id=? AND owner_id=?");
        $del->bind_param('ii', $file_id, $user_id); $del->execute(); $del->close();
        echo json_encode(['success'=>true]);
        exit;
    }

    // RENAME a file in a topic folder
    if ($action === 'rename_topic_file') {
        $file_id  = (int)($_POST['file_id']  ?? 0);
        $new_name = trim($_POST['new_name'] ?? '');
        if (!$file_id || !$new_name) { echo json_encode(['success'=>false,'message'=>'Invalid input']); exit; }
        $upd = $conn->prepare("UPDATE files SET name=? WHERE id=? AND owner_id=?");
        $upd->bind_param('sii', $new_name, $file_id, $user_id); $upd->execute(); $upd->close();
        echo json_encode(['success'=>true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// ── Load courses + topics (with folder_id) + tagged files ─────
$courses = [];
$res = $conn->prepare("SELECT id, name, code, description, color FROM courses WHERE user_id=? ORDER BY created_at DESC");
$res->bind_param('i', $user_id);
$res->execute();
$result = $res->get_result();
while ($row = $result->fetch_assoc()) {
    // Topics — include folder_id for JS to know which folder to manage
    $tq = $conn->prepare("SELECT id, title, week_no, folder_id FROM course_topics WHERE course_id=? ORDER BY sort_order ASC");
    $tq->bind_param('i', $row['id']);
    $tq->execute();
    $row['topics'] = $tq->get_result()->fetch_all(MYSQLI_ASSOC);
    $tq->close();
    // Files tagged to this course (any topic or no topic)
    $fq = $conn->prepare(
        "SELECT f.id, f.name, f.mime_type, f.file_path, fct.topic_id,
                COALESCE(t.title,'') AS topic_title
         FROM file_course_tags fct
         JOIN files f ON fct.file_id=f.id
         LEFT JOIN course_topics t ON fct.topic_id=t.id
         WHERE fct.course_id=? AND f.owner_id=?
         ORDER BY fct.tagged_at DESC"
    );
    $fq->bind_param('ii', $row['id'], $user_id);
    $fq->execute();
    $row['files'] = $fq->get_result()->fetch_all(MYSQLI_ASSOC);
    $fq->close();
    $courses[] = $row;
}
$res->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Course Management — NoteNest AI</title>
    <link rel="shortcut icon" href="img/fav.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0b4954;
            --accent:  #197f8f;
            --light-bg: #f0f4f8;
        }
        body { font-family: 'Inter', sans-serif; background: var(--light-bg); }

        /* ── Page Header ── */
        .page-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            padding: 32px 0 28px;
            color: #fff;
            margin-bottom: 32px;
        }
        .page-header h1 { font-size: 1.8rem; font-weight: 700; }
        .page-header p  { opacity: .8; margin: 0; font-size: .95rem; }

        /* ── Add Course Card ── */
        .add-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,.06);
            padding: 28px;
            margin-bottom: 28px;
        }
        .add-card h5 { font-weight: 700; color: var(--primary); margin-bottom: 18px; }

        /* ── Course Card ── */
        .course-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,.06);
            overflow: hidden;
            transition: transform .2s, box-shadow .2s;
            margin-bottom: 24px;
        }
        .course-card:hover { transform: translateY(-3px); box-shadow: 0 8px 30px rgba(0,0,0,.1); }
        .course-header {
            padding: 18px 22px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .course-color-dot {
            width: 14px; height: 14px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .course-code {
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: 1px;
            color: #fff;
            background: rgba(0,0,0,.2);
            padding: 2px 10px;
            border-radius: 20px;
        }
        .course-name { font-weight: 700; font-size: 1.05rem; color: #fff; flex: 1; }
        .course-body { padding: 18px 22px; }
        .course-desc { font-size: .88rem; color: #666; margin-bottom: 14px; }

        /* ── Topic Items ── */
        .topic-list { list-style: none; padding: 0; margin: 0; }
        .topic-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 7px 10px;
            border-radius: 8px;
            font-size: .88rem;
            color: #444;
            transition: background .15s;
        }
        .topic-item:hover { background: #f5f7fa; }
        .topic-item .week-badge {
            font-size: .72rem;
            background: #e8f4f8;
            color: var(--accent);
            padding: 1px 7px;
            border-radius: 10px;
            font-weight: 600;
        }
        .topic-item .del-topic {
            margin-left: auto;
            color: #ccc;
            cursor: pointer;
            transition: color .15s;
            background: none; border: none; padding: 0;
        }
        .topic-item .del-topic:hover { color: #e74c3c; }

        /* ── Add Topic Form ── */
        .add-topic-form {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            flex-wrap: wrap;
        }
        .add-topic-form input {
            border-radius: 8px;
            border: 1px solid #dde2e8;
            padding: 6px 12px;
            font-size: .85rem;
            flex: 1;
            min-width: 120px;
        }
        .add-topic-form input:focus { outline: none; border-color: var(--accent); }
        .btn-add-topic {
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 6px 14px;
            font-size: .85rem;
            cursor: pointer;
            transition: background .2s;
        }
        .btn-add-topic:hover { background: var(--primary); }

        /* ── Empty State ── */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #aaa;
        }
        .empty-state i { font-size: 56px; margin-bottom: 16px; color: #d0dde3; }

        /* ── Color Swatches ── */
        .color-swatches { display: flex; gap: 8px; margin-top: 6px; flex-wrap: wrap; }
        .swatch {
            width: 28px; height: 28px;
            border-radius: 50%;
            cursor: pointer;
            border: 3px solid transparent;
            transition: border-color .15s, transform .15s;
        }
        .swatch:hover, .swatch.active { border-color: #333; transform: scale(1.15); }

        /* ── Toasts ── */
        #toastWrap { position: fixed; bottom: 24px; right: 24px; z-index: 9999; }
        .toast-item {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,.12);
            padding: 12px 18px;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: .9rem;
            animation: slideInRight .3s ease;
        }
        @keyframes slideInRight { from { opacity:0; transform:translateX(40px); } to { opacity:1; transform:translateX(0); } }
        .toast-item.success i { color: #27ae60; }
        .toast-item.error   i { color: #e74c3c; }

        /* ── Material Items ── */
        .material-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: 10px;
            border: 1px solid #e8edf2;
            margin-bottom: 7px;
            background: #fcfdff;
            transition: border-color .15s, box-shadow .15s;
        }
        .material-item:hover { border-color: #c5d8e0; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
        .mat-icon  { font-size: 1.2rem; flex-shrink: 0; }
        .mat-info  { flex: 1; min-width: 0; }
        .mat-name  { font-size: .84rem; font-weight: 600; color: #2c3e50;
                     overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .mat-topic { font-size: .72rem; color: #aaa; margin-top: 2px; }
        .mat-topic i { margin-right: 4px; font-size: .65rem; }
        .mat-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }

        /* ── Topic File Panel ── */
        .topic-file-panel { list-style:none; padding:0 0 0 22px; }
        .tfp-inner {
            background: #f8fbfc;
            border: 1px solid #e0eaee;
            border-radius: 10px;
            margin: 4px 0 6px;
            overflow: hidden;
        }
        .tfp-header {
            display: flex; align-items:center; justify-content:space-between;
            padding: 8px 12px;
            background: #eef6f8;
            font-size: .8rem; font-weight: 700; color: #4a6572;
            border-bottom: 1px solid #ddeaee;
        }
        .tfp-loading {
            text-align:center; padding:14px; font-size:.8rem; color:#aaa;
        }
        .tfp-empty {
            text-align:center; padding:14px; font-size:.8rem; color:#bbb;
        }
        .tfp-file-row {
            display:flex; align-items:center; gap:8px;
            padding:8px 12px; border-bottom:1px solid #eef1f5;
            font-size:.82rem; color:#444;
            transition: background .12s;
        }
        .tfp-file-row:last-child { border-bottom:none; }
        .tfp-file-row:hover { background:#f0f5f7; }
        .tfp-file-name { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:600; color:#2c3e50; }
        .tfp-file-actions { display:flex; gap:4px; flex-shrink:0; }
        .tfp-action-btn {
            background:none; border:none; cursor:pointer; padding:3px 5px;
            border-radius:5px; font-size:.78rem; color:#aaa; transition:color .15s, background .15s;
        }
        .tfp-action-btn:hover { color:#0b4954; background:#e0eaee; }
        .tfp-action-btn.del:hover { color:#e74c3c; background:#fde8e8; }

        /* ── Topic Upload Button ── */
        .btn-topic-upload {
            background: none;
            border: 1px dashed #c8d6de;
            color: #8aacb8;
            border-radius: 6px;
            padding: 2px 9px;
            font-size: .72rem;
            cursor: pointer;
            transition: all .18s;
            margin-left: auto;
            white-space: nowrap;
        }
        .btn-topic-upload:hover {
            background: #e8f4f8;
            border-color: var(--accent);
            color: var(--accent);
        }

        /* ── Upload Modal Styles ── */
        #topicUploadModal .modal-content { border-radius: 18px; border: none; box-shadow: 0 24px 70px rgba(0,0,0,.18); }
        #topicUploadModal .modal-header  { background: linear-gradient(135deg, #0b4954, #197f8f); color: #fff; border-radius: 18px 18px 0 0; padding: 18px 24px; }
        #topicUploadModal .btn-close     { filter: invert(1); }

        .upload-drop-zone {
            border: 2px dashed #c5d8e0;
            border-radius: 14px;
            padding: 36px 20px;
            text-align: center;
            cursor: pointer;
            transition: all .2s;
            background: #f8fbfc;
            position: relative;
        }
        .upload-drop-zone.drag-over {
            border-color: var(--accent);
            background: #e8f4f8;
        }
        .upload-drop-zone input[type=file] {
            position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
        }
        .upload-drop-zone .drop-icon {
            font-size: 2.4rem; color: #b0cdd6; margin-bottom: 10px;
        }
        .upload-drop-zone .drop-title {
            font-weight: 700; font-size: 1rem; color: #4a6572;
        }
        .upload-drop-zone .drop-hint {
            font-size: .8rem; color: #aaa; margin-top: 4px;
        }

        /* Selected file list */
        #uploadFileQueue { max-height: 220px; overflow-y: auto; margin-top: 14px; }
        #uploadFileQueue::-webkit-scrollbar { width: 4px; }
        #uploadFileQueue::-webkit-scrollbar-thumb { background: #ddd; border-radius: 4px; }
        .queued-file {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 12px; border-radius: 10px;
            border: 1px solid #e8edf2; margin-bottom: 6px;
            background: #fff; font-size: .84rem;
            animation: fadeSlideIn .2s ease;
        }
        @keyframes fadeSlideIn { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:translateY(0); } }
        .queued-file .qf-icon { font-size: 1.1rem; flex-shrink: 0; }
        .queued-file .qf-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #2c3e50; font-weight: 600; }
        .queued-file .qf-size { font-size: .72rem; color: #aaa; flex-shrink: 0; }
        .queued-file .qf-remove { background:none; border:none; color:#ddd; cursor:pointer; padding:0; font-size:.85rem; }
        .queued-file .qf-remove:hover { color: #e74c3c; }

        /* Progress bar */
        .upload-progress-wrap { margin-top: 14px; display: none; }
        .upload-progress-bar {
            height: 8px; border-radius: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            transition: width .4s ease;
        }
        #uploadProgressText { font-size: .8rem; color: #888; margin-top: 6px; text-align: center; }

        /* ── File Picker Modal ── */
        #filePickerModal .modal-content { border-radius: 16px; border: none; box-shadow: 0 20px 60px rgba(0,0,0,.15); }
        #filePickerModal .modal-header  { background: linear-gradient(135deg, #0b4954, #197f8f); color: #fff; border-radius: 16px 16px 0 0; }
        #filePickerModal .btn-close     { filter: invert(1); }
        .picker-file-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px; border-radius: 10px;
            border: 1.5px solid #e8edf2;
            margin-bottom: 8px; cursor: pointer;
            transition: all .15s;
        }
        .picker-file-item:hover    { border-color: #197f8f; background: #f0f7f9; }
        .picker-file-item.attached { border-color: #27ae60; background: #f0faf4; }
        .picker-file-item .pfi-icon { font-size: 1.3rem; flex-shrink: 0; }
        .picker-file-item .pfi-name { font-size: .86rem; font-weight: 600; color: #2c3e50; flex: 1; }
        .picker-file-item .pfi-badge { font-size: .7rem; padding: 2px 8px; border-radius: 10px;
                                       background: #d4edda; color: #155724; font-weight: 700; }
        #pickerFileList { max-height: 380px; overflow-y: auto; padding: 4px; }
        #pickerFileList::-webkit-scrollbar { width: 4px; }
        #pickerFileList::-webkit-scrollbar-thumb { background: #ddd; border-radius: 4px; }
    </style>
</head>
<body>

<?php include 'includes/navbar.php'; ?>

<!-- Page Header -->
<div class="page-header">
    <div class="container">
        <div class="d-flex align-items-center gap-3">
            <i class="fas fa-graduation-cap fa-2x opacity-75"></i>
            <div>
                <h1 class="mb-1">Course Management</h1>
                <p>Organize your courses and define syllabus topics</p>
            </div>
            <a href="dashboard.php" class="btn btn-outline-light ms-auto">
                <i class="fas fa-arrow-left me-1"></i> Dashboard
            </a>
        </div>
    </div>
</div>

<div class="container pb-5">
    <div class="row">

        <!-- ── Left: Add Course Form ── -->
        <div class="col-lg-4 mb-4">
            <div class="add-card">
                <h5><i class="fas fa-plus-circle me-2"></i>Add New Course</h5>
                <div class="mb-3">
                    <label class="form-label fw-semibold" style="font-size:.85rem;">Course Name</label>
                    <input type="text" id="inp_name" class="form-control" placeholder="e.g. Data Structures">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" style="font-size:.85rem;">Course Code</label>
                    <input type="text" id="inp_code" class="form-control" placeholder="e.g. CSE301" style="text-transform:uppercase;">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" style="font-size:.85rem;">Description <span class="text-muted">(optional)</span></label>
                    <textarea id="inp_desc" class="form-control" rows="2" placeholder="Brief course description..."></textarea>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold" style="font-size:.85rem;">Color</label>
                    <input type="hidden" id="inp_color" value="#197f8f">
                    <div class="color-swatches">
                        <?php
                        $colors = ['#197f8f','#0b4954','#8e44ad','#2980b9','#27ae60','#e67e22','#e74c3c','#2c3e50'];
                        foreach ($colors as $c):
                        ?>
                        <div class="swatch <?php echo $c==='#197f8f'?'active':''; ?>"
                             style="background:<?php echo $c; ?>"
                             data-color="<?php echo $c; ?>"
                             title="<?php echo $c; ?>"></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button class="btn btn-primary w-100" id="btnAddCourse" style="background:var(--primary);border:none;border-radius:10px;font-weight:600;">
                    <i class="fas fa-plus me-1"></i> Add Course
                </button>
            </div>

            <!-- Stats -->
            <div class="add-card">
                <h5><i class="fas fa-chart-pie me-2"></i>Overview</h5>
                <div class="d-flex justify-content-between text-center">
                    <div>
                        <div style="font-size:1.8rem;font-weight:700;color:var(--primary);" id="statCourses">
                            <?php echo count($courses); ?>
                        </div>
                        <div style="font-size:.8rem;color:#888;">Courses</div>
                    </div>
                    <div>
                        <div style="font-size:1.8rem;font-weight:700;color:var(--accent);" id="statTopics">
                            <?php echo array_sum(array_column(array_map(fn($c) => ['t'=>count($c['topics'])], $courses), 't')); ?>
                        </div>
                        <div style="font-size:.8rem;color:#888;">Topics</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Right: Course List ── -->
        <div class="col-lg-8" id="courseList">
            <?php if (empty($courses)): ?>
            <div class="empty-state">
                <i class="fas fa-book-open"></i>
                <h5>No courses yet</h5>
                <p>Add your first course using the form on the left.</p>
            </div>
            <?php else: ?>
            <?php foreach ($courses as $course): ?>
            <div class="course-card" id="course-<?php echo $course['id']; ?>">
                <!-- Course Header (colored) -->
                <div class="course-header" style="background: <?php echo htmlspecialchars($course['color']); ?>;">
                    <div class="course-name"><?php echo htmlspecialchars($course['name']); ?></div>
                    <span class="course-code"><?php echo htmlspecialchars($course['code']); ?></span>
                    <button class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:8px;"
                            onclick="deleteCourse(<?php echo $course['id']; ?>)"
                            title="Delete Course">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>

                <!-- Course Body -->
                <div class="course-body">
                    <?php if ($course['description']): ?>
                    <p class="course-desc"><?php echo htmlspecialchars($course['description']); ?></p>
                    <?php endif; ?>

                    <!-- Topics -->
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span style="font-size:.82rem;font-weight:700;color:#888;letter-spacing:.5px;">SYLLABUS TOPICS</span>
                        <span class="badge" style="background:#eef2f7;color:var(--accent);">
                            <?php echo count($course['topics']); ?> topic<?php echo count($course['topics'])!==1?'s':''; ?>
                        </span>
                    </div>

                    <ul class="topic-list" id="topics-<?php echo $course['id']; ?>">
                        <?php foreach ($course['topics'] as $t): ?>
                        <li class="topic-item" id="topic-<?php echo $t['id']; ?>" data-folder-id="<?php echo (int)($t['folder_id'] ?? 0); ?>">
                            <i class="fas fa-circle" style="font-size:5px;color:#ccc;flex-shrink:0;"></i>
                            <span class="topic-title-text"><?php echo htmlspecialchars($t['title']); ?></span>
                            <?php if ($t['week_no']): ?>
                            <span class="week-badge">Week <?php echo $t['week_no']; ?></span>
                            <?php endif; ?>
                            <!-- File panel toggle -->
                            <button class="btn-topic-upload" title="View/Upload files for this topic"
                                    onclick="toggleTopicFiles(<?php echo $t['id']; ?>, <?php echo $course['id']; ?>, '<?php echo addslashes(htmlspecialchars($t['title'])); ?>')">
                                <i class="fas fa-folder me-1"></i><span class="tf-label">Files</span>
                            </button>
                            <button class="del-topic" onclick="deleteTopic(<?php echo $t['id']; ?>, <?php echo $course['id']; ?>)" title="Remove topic">
                                <i class="fas fa-times"></i>
                            </button>
                        </li>
                        <!-- Topic File Panel (hidden by default) -->
                        <li class="topic-file-panel" id="tfp-<?php echo $t['id']; ?>" style="display:none;" data-loaded="0">
                            <div class="tfp-inner">
                                <div class="tfp-header">
                                    <span><i class="fas fa-folder-open me-1" style="color:<?php echo htmlspecialchars($course['color']); ?>;"></i>
                                        <?php echo htmlspecialchars($t['title']); ?> — Files</span>
                                    <div class="d-flex gap-2 align-items-center">
                                        <?php if (!empty($t['folder_id'])): ?>
                                        <a href="my_note_nest.php?folder=<?php echo (int)$t['folder_id']; ?>" target="_blank"
                                           class="btn btn-xs" style="font-size:.72rem;padding:2px 8px;border-radius:6px;background:#eef2f7;color:var(--accent);text-decoration:none;"
                                           title="Open in File Manager">
                                            <i class="fas fa-external-link-alt me-1"></i>NoteNest
                                        </a>
                                        <?php endif; ?>
                                        <button class="btn-topic-upload" style="margin-left:0;"
                                                onclick="openTopicUpload(<?php echo $course['id']; ?>, <?php echo $t['id']; ?>, '<?php echo addslashes(htmlspecialchars($t['title'])); ?>')">
                                            <i class="fas fa-upload me-1"></i>Upload
                                        </button>
                                    </div>
                                </div>
                                <div class="tfp-files" id="tfp-files-<?php echo $t['id']; ?>">
                                    <div class="tfp-loading"><i class="fas fa-spinner fa-spin me-1"></i>Loading...</div>
                                </div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>

                    <!-- Add Topic -->
                    <div class="add-topic-form">
                        <input type="text"   placeholder="New topic title..."  id="topic-title-<?php echo $course['id']; ?>">
                        <input type="number" placeholder="Week" min="1" max="52" id="topic-week-<?php echo $course['id']; ?>" style="max-width:80px;">
                        <button class="btn-add-topic" onclick="addTopic(<?php echo $course['id']; ?>)">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>

                    <!-- ── Course Materials Summary (course-level, no topic) ── -->
                    <div style="margin-top:20px;padding-top:16px;border-top:1px solid #f0f2f5;">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span style="font-size:.82rem;font-weight:700;color:#888;letter-spacing:.5px;">📎 COURSE MATERIALS</span>
                            <div class="d-flex gap-2">
                                <a href="my_note_nest.php?course=<?php echo $course['id']; ?>" class="btn btn-sm"
                                   style="background:#eef2f7;color:var(--accent);border:none;border-radius:8px;font-size:.76rem;font-weight:600;padding:3px 12px;">
                                    <i class="fas fa-folder me-1"></i>Open Folder
                                </a>
                                <button class="btn btn-sm" onclick="openFilePicker(<?php echo $course['id']; ?>)"
                                        style="background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:.76rem;font-weight:600;padding:3px 12px;">
                                    <i class="fas fa-paperclip me-1"></i>Attach File
                                </button>
                            </div>
                        </div>
                        <div id="materials-<?php echo $course['id']; ?>">
                        <?php if (empty($course['files'])): ?>
                            <div class="no-materials-msg" style="font-size:.82rem;color:#bbb;text-align:center;padding:12px 0;">
                                <i class="fas fa-folder-open" style="display:block;font-size:1.6rem;margin-bottom:6px;"></i>
                                No materials yet. Upload files via a topic or click "Attach File".
                            </div>
                        <?php else: ?>
                            <?php foreach ($course['files'] as $mf): ?>
                            <?php
                                $mext  = strtolower(pathinfo($mf['file_path'], PATHINFO_EXTENSION));
                                $mmime = $mf['mime_type'] ?? '';
                                if (str_starts_with($mmime,'image/'))         $mficon = 'fa-file-image text-success';
                                elseif ($mmime==='application/pdf')           $mficon = 'fa-file-pdf text-danger';
                                elseif (in_array($mext,['docx','doc']))       $mficon = 'fa-file-word text-primary';
                                elseif (in_array($mext,['xlsx','xls','csv'])) $mficon = 'fa-file-excel text-success';
                                elseif (in_array($mext,['pptx','ppt']))       $mficon = 'fa-file-powerpoint text-warning';
                                elseif (str_starts_with($mmime,'audio/'))     $mficon = 'fa-file-audio text-info';
                                elseif (str_starts_with($mmime,'video/'))     $mficon = 'fa-file-video';
                                else                                           $mficon = 'fa-file-alt text-muted';
                            ?>
                            <div class="material-item" id="mat-<?php echo $mf['id']; ?>-<?php echo $course['id']; ?>">
                                <i class="fas <?php echo $mficon; ?> mat-icon"></i>
                                <div class="mat-info">
                                    <div class="mat-name"><?php echo htmlspecialchars($mf['name']); ?></div>
                                    <?php if ($mf['topic_title']): ?>
                                    <div class="mat-topic"><i class="fas fa-tag"></i><?php echo htmlspecialchars($mf['topic_title']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="mat-actions">
                                    <button onclick="previewMaterial('<?php echo htmlspecialchars($mf['file_path']); ?>','<?php echo htmlspecialchars($mf['name']); ?>')" title="Preview"
                                            style="background:none;border:none;color:#197f8f;cursor:pointer;font-size:.85rem;">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <a href="note_download.php?path=<?php echo urlencode($mf['file_path']); ?>" title="Download"
                                       style="color:#888;font-size:.85rem;">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <button onclick="untagFile(<?php echo $mf['id']; ?>, <?php echo $course['id']; ?>)" title="Remove"
                                            style="background:none;border:none;color:#ccc;cursor:pointer;font-size:.85rem;">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Toast container -->
<div id="toastWrap"></div>

<!-- ── File Picker Modal (attach existing files) ── -->
<div class="modal fade" id="filePickerModal" tabindex="-1" aria-labelledby="filePickerLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="filePickerLabel">
          <i class="fas fa-paperclip me-2" style="opacity:.8;"></i>
          Attach Files to Course
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-3">
        <!-- Topic selector -->
        <div class="mb-3">
          <label style="font-size:.82rem;font-weight:600;color:#555;">Tag to a specific topic (optional)</label>
          <select id="pickerTopicSelect" class="form-select form-select-sm mt-1" style="border-radius:8px;">
            <option value="0">No specific topic (Course-level)</option>
          </select>
        </div>
        <!-- Search -->
        <input type="text" id="pickerSearch" class="form-control form-control-sm mb-3"
               placeholder="🔍 Search files..."
               style="border-radius:8px;"
               oninput="filterPicker(this.value)">
        <!-- File list -->
        <div id="pickerFileList">
          <div class="text-center text-muted py-4">
            <div class="spinner-border spinner-border-sm text-secondary"></div>
            <div class="mt-2" style="font-size:.85rem;">Loading your files...</div>
          </div>
        </div>
      </div>
      <div class="modal-footer" style="border-top:1px solid #f0f2f5;">
        <small class="text-muted me-auto">
          <i class="fas fa-info-circle me-1"></i>Click a file to attach / detach
        </small>
        <button class="btn btn-sm" data-bs-dismiss="modal"
                style="background:#f0f2f5;color:#555;border:none;border-radius:8px;">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Topic File Upload Modal ── -->
<div class="modal fade" id="topicUploadModal" tabindex="-1" aria-labelledby="topicUploadLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="topicUploadLabel">
          <i class="fas fa-cloud-upload-alt me-2" style="opacity:.8;"></i>
          Upload Files to Topic
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">

        <!-- Context badge -->
        <div class="mb-3 d-flex align-items-center gap-2">
          <span style="font-size:.8rem;color:#888;font-weight:600;">TOPIC:</span>
          <span id="uploadTopicName" class="badge" style="background:var(--accent);color:#fff;font-size:.82rem;padding:5px 12px;border-radius:20px;"></span>
        </div>

        <!-- Drop zone -->
        <div class="upload-drop-zone" id="uploadDropZone">
          <input type="file" id="uploadFileInput" multiple accept="*/*" onchange="onFilesSelected(this.files)">
          <div class="drop-icon"><i class="fas fa-cloud-upload-alt"></i></div>
          <div class="drop-title">Drag &amp; drop files here</div>
          <div class="drop-hint">or click to browse &nbsp;·&nbsp; Max 50 MB per file &nbsp;·&nbsp; All formats accepted</div>
        </div>

        <!-- Queued file list -->
        <div id="uploadFileQueue"></div>

        <!-- Progress -->
        <div class="upload-progress-wrap" id="uploadProgressWrap">
          <div class="progress" style="height:8px;border-radius:4px;background:#e8f0f2;">
            <div class="upload-progress-bar" id="uploadProgressBar" style="width:0%;"></div>
          </div>
          <div id="uploadProgressText">Uploading…</div>
        </div>

      </div>
      <div class="modal-footer" style="border-top:1px solid #f0f2f5; gap:10px;">
        <small class="text-muted me-auto">
          <i class="fas fa-shield-alt me-1"></i>Files are stored securely on your account
        </small>
        <button class="btn btn-sm" data-bs-dismiss="modal"
                style="background:#f0f2f5;color:#555;border:none;border-radius:8px;padding:7px 18px;">Cancel</button>
        <button class="btn btn-sm" id="btnDoUpload" onclick="doUpload()"
                style="background:linear-gradient(135deg,var(--primary),var(--accent));color:#fff;border:none;border-radius:8px;padding:7px 22px;font-weight:600;">
          <i class="fas fa-upload me-1"></i>Upload Files
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Toast helper ──────────────────────────────────────────────
function toast(msg, type='success') {
    const icon = type==='success' ? 'fa-check-circle' : 'fa-exclamation-circle';
    const el = $(`<div class="toast-item ${type}">
        <i class="fas ${icon}"></i> <span>${msg}</span>
    </div>`);
    $('#toastWrap').append(el);
    setTimeout(() => el.fadeOut(300, () => el.remove()), 3000);
}

// ── Color swatches ────────────────────────────────────────────
$('.swatch').on('click', function() {
    $('.swatch').removeClass('active');
    $(this).addClass('active');
    $('#inp_color').val($(this).data('color'));
});

// ── Add Course ────────────────────────────────────────────────
$('#btnAddCourse').on('click', function() {
    const btn = $(this);
    const name  = $('#inp_name').val().trim();
    const code  = $('#inp_code').val().trim();
    const desc  = $('#inp_desc').val().trim();
    const color = $('#inp_color').val();

    if (!name || !code) { toast('Course name and code are required.', 'error'); return; }

    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Adding...');

    $.post('course_management.php', { action:'add_course', name, code, description:desc, color }, function(res) {
        if (res.success) {
            toast('Course added successfully!');
            // Inject new course card into DOM
            const card = buildCourseCard(res.id, name, code, desc, color);
            if ($('#courseList .empty-state').length) $('#courseList').empty();
            $('#courseList').prepend(card);
            // Reset form
            $('#inp_name, #inp_code, #inp_desc').val('');
            updateStats();
        } else {
            toast(res.message, 'error');
        }
        btn.prop('disabled', false).html('<i class="fas fa-plus me-1"></i> Add Course');
    }, 'json').fail(() => { toast('Network error.', 'error'); btn.prop('disabled', false).html('<i class="fas fa-plus me-1"></i> Add Course'); });
});

// ── Build Course Card HTML ────────────────────────────────────
function buildCourseCard(id, name, code, desc, color) {
    return `<div class="course-card" id="course-${id}">
        <div class="course-header" style="background:${color};">
            <div class="course-name">${escHtml(name)}</div>
            <span class="course-code">${escHtml(code)}</span>
            <button class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:8px;"
                    onclick="deleteCourse(${id})">
                <i class="fas fa-trash-alt"></i>
            </button>
        </div>
        <div class="course-body">
            ${desc ? `<p class="course-desc">${escHtml(desc)}</p>` : ''}
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span style="font-size:.82rem;font-weight:700;color:#888;letter-spacing:.5px;">SYLLABUS TOPICS</span>
                <span class="badge" style="background:#eef2f7;color:var(--accent);" id="topic-count-${id}">0 topics</span>
            </div>
            <ul class="topic-list" id="topics-${id}"></ul>
            <div class="add-topic-form">
                <input type="text"   placeholder="New topic title..."  id="topic-title-${id}">
                <input type="number" placeholder="Week" min="1" max="52" id="topic-week-${id}" style="max-width:80px;">
                <button class="btn-add-topic" onclick="addTopic(${id})"><i class="fas fa-plus"></i> Add</button>
            </div>
        </div>
    </div>`;
}

// ── Delete Course ─────────────────────────────────────────────
function deleteCourse(id) {
    if (!confirm('Delete this course and all its topics?')) return;
    $.post('course_management.php', { action:'delete_course', course_id:id }, function(res) {
        if (res.success) {
            $(`#course-${id}`).fadeOut(300, function() { $(this).remove(); updateStats(); });
            toast('Course deleted.');
        } else { toast('Could not delete.', 'error'); }
    }, 'json');
}

// ── Add Topic ────────────────────────────────────────────────
function addTopic(courseId) {
    const title  = $(`#topic-title-${courseId}`).val().trim();
    const weekNo = $(`#topic-week-${courseId}`).val();
    if (!title) { toast('Enter a topic title.', 'error'); return; }

    $.post('course_management.php', { action:'add_topic', course_id:courseId, title, week_no:weekNo }, function(res) {
        if (res.success) {
            const weekBadge = weekNo ? `<span class="week-badge">Week ${weekNo}</span>` : '';
            const topicLi = `
            <li class="topic-item" id="topic-${res.id}" data-folder-id="${res.folder_id || 0}">
                <i class="fas fa-circle" style="font-size:5px;color:#ccc;flex-shrink:0;"></i>
                <span class="topic-title-text">${escHtml(title)}</span>
                ${weekBadge}
                <button class="btn-topic-upload" title="View/Upload files for this topic"
                        onclick="toggleTopicFiles(${res.id}, ${courseId}, '${escHtml(title).replace(/'/g,"\\'")}')">
                    <i class="fas fa-folder me-1"></i><span class="tf-label">Files</span>
                </button>
                <button class="del-topic" onclick="deleteTopic(${res.id}, ${courseId})" title="Remove topic">
                    <i class="fas fa-times"></i>
                </button>
            </li>
            <li class="topic-file-panel" id="tfp-${res.id}" style="display:none;" data-loaded="0">
                <div class="tfp-inner">
                    <div class="tfp-header">
                        <span><i class="fas fa-folder-open me-1"></i>${escHtml(title)} — Files</span>
                        <div class="d-flex gap-2 align-items-center">
                            <button class="btn-topic-upload" style="margin-left:0;"
                                    onclick="openTopicUpload(${courseId}, ${res.id}, '${escHtml(title).replace(/'/g,"\\'")}')">
                                <i class="fas fa-upload me-1"></i>Upload
                            </button>
                        </div>
                    </div>
                    <div class="tfp-files" id="tfp-files-${res.id}">
                        <div class="tfp-empty"><i class="fas fa-folder-open me-1"></i>No files yet. Click Upload.</div>
                    </div>
                </div>
            </li>`;

            // Insert before the add-topic-form div
            $(`#topics-${courseId}`).append(topicLi);
            $(`#topic-title-${courseId}, #topic-week-${courseId}`).val('');
            updateStats();
            toast('Topic added! A folder was created in your NoteNest.');
        } else { toast(res.message, 'error'); }
    }, 'json');
}

// ── Delete Topic ──────────────────────────────────────────────
function deleteTopic(topicId, courseId) {
    $.post('course_management.php', { action:'delete_topic', topic_id:topicId }, function(res) {
        if (res.success) {
            $(`#topic-${topicId}`).fadeOut(200, function() { $(this).remove(); updateStats(); });
        } else { toast('Could not delete topic.', 'error'); }
    }, 'json');
}

// ── Update stats ─────────────────────────────────────────────
function updateStats() {
    $('#statCourses').text($('.course-card').length);
    $('#statTopics').text($('.topic-item').length);
}

// ── HTML escape ───────────────────────────────────────────────
function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ══════════════════════════════════════════════════════════════
// ── Topic File Upload ─────────────────────────────────────────
// ══════════════════════════════════════════════════════════════
let _uploadCourseId = 0;
let _uploadTopicId  = 0;
let _uploadFiles    = [];   // FileList staging array

function openTopicUpload(courseId, topicId, topicTitle) {
    _uploadCourseId = courseId;
    _uploadTopicId  = topicId;
    _uploadFiles    = [];

    $('#uploadTopicName').text(topicTitle);
    $('#uploadFileQueue').empty();
    $('#uploadProgressWrap').hide();
    $('#uploadProgressBar').css('width','0%');
    $('#uploadProgressText').text('Uploading…');
    $('#btnDoUpload').prop('disabled', false).html('<i class="fas fa-upload me-1"></i>Upload Files');
    // Reset input
    const inp = document.getElementById('uploadFileInput');
    inp.value = '';

    const modal = new bootstrap.Modal(document.getElementById('topicUploadModal'));
    modal.show();
}

// Drag & drop events
const dropZone = document.getElementById('uploadDropZone');
if (dropZone) {
    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
    dropZone.addEventListener('dragleave', ()  => dropZone.classList.remove('drag-over'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('drag-over');
        onFilesSelected(e.dataTransfer.files);
    });
}

function onFilesSelected(fileList) {
    for (let f of fileList) {
        // avoid duplicates
        if (!_uploadFiles.find(x => x.name === f.name && x.size === f.size)) {
            _uploadFiles.push(f);
        }
    }
    renderQueue();
}

function renderQueue() {
    const wrap = $('#uploadFileQueue');
    wrap.empty();
    if (_uploadFiles.length === 0) return;

    _uploadFiles.forEach((f, idx) => {
        const icon = getMimeIcon(f.type, f.name);
        const size = formatBytes(f.size);
        wrap.append(`
        <div class="queued-file" id="qf-${idx}">
            <i class="fas ${icon} qf-icon"></i>
            <span class="qf-name" title="${escHtml(f.name)}">${escHtml(f.name)}</span>
            <span class="qf-size">${size}</span>
            <button class="qf-remove" onclick="removeQueued(${idx})" title="Remove"><i class="fas fa-times"></i></button>
        </div>`);
    });
}

function removeQueued(idx) {
    _uploadFiles.splice(idx, 1);
    renderQueue();
}

function getMimeIcon(mime, name) {
    if (mime.startsWith('image/'))        return 'fa-file-image text-success';
    if (mime === 'application/pdf')       return 'fa-file-pdf text-danger';
    const ext = name.split('.').pop().toLowerCase();
    if (['doc','docx'].includes(ext))     return 'fa-file-word text-primary';
    if (['xls','xlsx','csv'].includes(ext)) return 'fa-file-excel text-success';
    if (['ppt','pptx'].includes(ext))     return 'fa-file-powerpoint text-warning';
    if (mime.startsWith('audio/'))        return 'fa-file-audio text-info';
    if (mime.startsWith('video/'))        return 'fa-file-video text-purple';
    return 'fa-file-alt text-muted';
}

function formatBytes(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024*1024) return (bytes/1024).toFixed(1) + ' KB';
    return (bytes/(1024*1024)).toFixed(1) + ' MB';
}

function doUpload() {
    if (_uploadFiles.length === 0) { toast('No files selected.', 'error'); return; }

    const btn = $('#btnDoUpload');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>Uploading…');
    $('#uploadProgressWrap').show();
    $('#uploadProgressBar').css('width','0%');

    const fd = new FormData();
    fd.append('action', 'upload_topic_files');
    fd.append('course_id', _uploadCourseId);
    fd.append('topic_id',  _uploadTopicId);
    _uploadFiles.forEach(f => fd.append('files[]', f));

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'course_management.php');

    xhr.upload.onprogress = e => {
        if (e.lengthComputable) {
            const pct = Math.round(e.loaded / e.total * 100);
            $('#uploadProgressBar').css('width', pct + '%');
            $('#uploadProgressText').text(`Uploading ${pct}%… (${formatBytes(e.loaded)} / ${formatBytes(e.total)})`);
        }
    };

    xhr.onload = () => {
        let res;
        try { res = JSON.parse(xhr.responseText); } catch(e) { res = {success:false,message:'Invalid server response'}; }
        $('#uploadProgressBar').css('width','100%');
        $('#uploadProgressText').text('Done!');

        if (res.success && res.uploaded && res.uploaded.length > 0) {
            // Refresh topic file panel
            if (_uploadTopicId) {
                $(`#tfp-${_uploadTopicId}`).data('loaded', 0); // force reload
                loadTopicFiles(_uploadTopicId, _uploadCourseId);
                $(`#tfp-${_uploadTopicId}`).slideDown(200);
            }
            // Also refresh course materials section
            refreshMaterials(_uploadCourseId);

            let msg = `${res.uploaded.length} file${res.uploaded.length>1?'s':''} uploaded to topic folder!`;
            if (res.errors && res.errors.length) msg += ' (' + res.errors.length + ' failed)';
            toast(msg);

            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('topicUploadModal'))?.hide();
            }, 800);
        } else {
            const errMsg = (res.errors && res.errors.length) ? res.errors.join(', ') : (res.message || 'Upload failed.');
            toast(errMsg, 'error');
            btn.prop('disabled', false).html('<i class="fas fa-upload me-1"></i>Upload Files');
        }
    };

    xhr.onerror = () => {
        toast('Network error during upload.', 'error');
        btn.prop('disabled', false).html('<i class="fas fa-upload me-1"></i>Upload Files');
    };

    xhr.send(fd);
}

// ── File Picker (existing files) ───────────────────────────────
let _pickerCourseId = 0;
let _pickerAllFiles = [];

function openFilePicker(courseId) {
    _pickerCourseId = courseId;
    $('#pickerSearch').val('');
    $('#pickerFileList').html('<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm text-secondary"></div><div class="mt-2" style="font-size:.85rem;">Loading your files...</div></div>');

    // Load topics for selector
    $.post('course_management.php', { action:'get_topics', course_id:courseId }, function(r) {
        const sel = $('#pickerTopicSelect').empty();
        sel.append('<option value="0">No specific topic (Course-level)</option>');
        if (r.topics) r.topics.forEach(t => sel.append(`<option value="${t.id}">${escHtml(t.title)}</option>`));
    }, 'json');

    // Load files
    $.post('course_management.php', { action:'get_all_files', course_id:courseId }, function(r) {
        _pickerAllFiles = r.files || [];
        renderPicker(_pickerAllFiles);
    }, 'json');

    new bootstrap.Modal(document.getElementById('filePickerModal')).show();
}

function renderPicker(files) {
    const list = $('#pickerFileList').empty();
    if (!files.length) { list.html('<div class="text-center text-muted py-4" style="font-size:.85rem;">No files found.</div>'); return; }
    files.forEach(f => {
        const icon    = getMimeIcon(f.mime_type, f.name);
        const tagged  = parseInt(f.tagged) > 0;
        const itemCls = tagged ? 'picker-file-item attached' : 'picker-file-item';
        const badge   = tagged ? '<span class="pfi-badge">✓ Attached</span>' : '';
        list.append(`
        <div class="${itemCls}" data-id="${f.id}" onclick="toggleTag(${f.id}, this)">
            <i class="fas ${icon} pfi-icon"></i>
            <span class="pfi-name">${escHtml(f.name)}</span>
            ${badge}
        </div>`);
    });
}

function filterPicker(q) {
    const lq = q.toLowerCase();
    renderPicker(_pickerAllFiles.filter(f => f.name.toLowerCase().includes(lq)));
}

function toggleTag(fileId, el) {
    const topicId = parseInt($('#pickerTopicSelect').val()) || 0;
    const isAttached = el.classList.contains('attached');
    if (isAttached) {
        $.post('course_management.php', { action:'untag_file', file_id:fileId, course_id:_pickerCourseId }, function(r) {
            if (r.success) {
                el.classList.remove('attached');
                $(el).find('.pfi-badge').remove();
                $(`#mat-${fileId}-${_pickerCourseId}`).fadeOut(200, function(){$(this).remove();});
                toast('File detached.');
            }
        }, 'json');
    } else {
        $.post('course_management.php', { action:'tag_file', file_id:fileId, course_id:_pickerCourseId, topic_id:topicId }, function(r) {
            if (r.success) {
                el.classList.add('attached');
                if (!$(el).find('.pfi-badge').length) $(el).append('<span class="pfi-badge">✓ Attached</span>');
                toast('File attached!');
                // Refresh materials section
                refreshMaterials(_pickerCourseId);
            } else { toast(r.message || 'Failed', 'error'); }
        }, 'json');
    }
}

function refreshMaterials(courseId) {
    $.post('course_management.php', { action:'get_course_files', course_id:courseId }, function(r) {
        if (!r.success) return;
        const matDiv = $(`#materials-${courseId}`);
        matDiv.empty();
        if (!r.files.length) {
            matDiv.html('<div class="no-materials-msg" style="font-size:.82rem;color:#bbb;text-align:center;padding:12px 0;"><i class="fas fa-folder-open" style="display:block;font-size:1.6rem;margin-bottom:6px;"></i>No materials yet. Click "Attach File" to add.</div>');
            return;
        }
        r.files.forEach(f => {
            const icon = getMimeIcon(f.mime_type || '', f.name);
            const topicBadge = f.topic_title ? `<div class="mat-topic"><i class="fas fa-tag"></i>${escHtml(f.topic_title)}</div>` : '';
            matDiv.append(`
            <div class="material-item" id="mat-${f.id}-${courseId}">
                <i class="fas ${icon} mat-icon"></i>
                <div class="mat-info">
                    <div class="mat-name">${escHtml(f.name)}</div>
                    ${topicBadge}
                </div>
                <div class="mat-actions">
                    <button onclick="previewMaterial('${f.file_path}','${escHtml(f.name)}')" title="Preview"
                            style="background:none;border:none;color:#197f8f;cursor:pointer;font-size:.85rem;">
                        <i class="fas fa-eye"></i>
                    </button>
                    <a href="note_download.php?path=${encodeURIComponent(f.file_path)}" title="Download"
                       style="color:#888;font-size:.85rem;">
                        <i class="fas fa-download"></i>
                    </a>
                    <button onclick="untagFile(${f.id}, ${courseId})" title="Remove"
                            style="background:none;border:none;color:#ccc;cursor:pointer;font-size:.85rem;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>`);
        });
    }, 'json');
}

function untagFile(fileId, courseId) {
    $.post('course_management.php', { action:'untag_file', file_id:fileId, course_id:courseId }, function(r) {
        if (r.success) {
            $(`#mat-${fileId}-${courseId}`).fadeOut(200, function(){$(this).remove();});
            toast('Material removed.');
        } else { toast('Could not remove.','error'); }
    }, 'json');
}

function previewMaterial(path, name) {
    window.open('note_preview.php?path=' + encodeURIComponent(path) + '&name=' + encodeURIComponent(name), '_blank');
}
</script>
</body>
</html>
