<?php
// ============================================================
// get_files.php — NoteNest AI API Endpoint
// Fetches study files matching folder_id, topic_id, or course_id
// Hierarchy: courses → topics → folders → files
// ============================================================
require 'includes/auth.php';
require 'config.php';
require_once 'includes/db.php';

header('Content-Type: application/json');

$user_id   = $_SESSION['user_id'];
$course_id = (int)($_REQUEST['course_id'] ?? 0);
$folder_id = (int)($_REQUEST['folder_id'] ?? 0);
$topic_id  = (int)($_REQUEST['topic_id']  ?? 0);

error_log("[NoteNest API get_files] user_id=$user_id, course_id=$course_id, topic_id=$topic_id, folder_id=$folder_id");

$files = [];

// Priority: folder_id is most specific (exact folder match)
if ($folder_id > 0) {
    $stmt = $conn->prepare(
        "SELECT DISTINCT f.id, f.name, f.mime_type, f.created_at, f.folder_id, f.course_id
         FROM files f
         WHERE f.owner_id = ? AND f.folder_id = ?
         ORDER BY f.created_at DESC, f.name ASC"
    );
    $stmt->bind_param('ii', $user_id, $folder_id);
    $stmt->execute();
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    error_log("[NoteNest API get_files] SQL: files by folder_id=$folder_id, found=" . count($files));

    // If folder has no direct files, fall through to topic-linked files
    if (empty($files) && $topic_id > 0) {
        error_log("[NoteNest API get_files] No files in folder_id=$folder_id, trying topic_id=$topic_id fallback");
        $stmt2 = $conn->prepare(
            "SELECT DISTINCT f.id, f.name, f.mime_type, f.created_at, f.folder_id, f.course_id
             FROM files f
             LEFT JOIN file_course_tags fct ON fct.file_id = f.id
             WHERE f.owner_id = ? AND (fct.topic_id = ?)
             ORDER BY f.created_at DESC, f.name ASC"
        );
        $stmt2->bind_param('ii', $user_id, $topic_id);
        $stmt2->execute();
        $files = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt2->close();
        error_log("[NoteNest API get_files] topic fallback found=" . count($files));
    }

} elseif ($topic_id > 0) {
    // No folder selected — get the folder linked to the topic from course_topics
    $tFolderId = 0;
    $tfStmt = $conn->prepare("SELECT folder_id FROM course_topics WHERE id = ?");
    $tfStmt->bind_param('i', $topic_id);
    $tfStmt->execute();
    $tfStmt->bind_result($tfRes);
    if ($tfStmt->fetch() && $tfRes) {
        $tFolderId = (int)$tfRes;
    }
    $tfStmt->close();
    error_log("[NoteNest API get_files] topic_id=$topic_id → linked folder_id=$tFolderId");

    // Get files from the topic's linked folder OR files tagged to this topic
    if ($tFolderId > 0) {
        $stmt = $conn->prepare(
            "SELECT DISTINCT f.id, f.name, f.mime_type, f.created_at, f.folder_id, f.course_id
             FROM files f
             LEFT JOIN file_course_tags fct ON fct.file_id = f.id
             WHERE f.owner_id = ? AND (f.folder_id = ? OR fct.topic_id = ?)
             ORDER BY f.created_at DESC, f.name ASC"
        );
        $stmt->bind_param('iii', $user_id, $tFolderId, $topic_id);
        $stmt->execute();
        $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        error_log("[NoteNest API get_files] SQL: files by topic folder_id=$tFolderId OR topic_id=$topic_id, found=" . count($files));
    } else {
        // No linked folder — only files tagged to topic
        $stmt = $conn->prepare(
            "SELECT DISTINCT f.id, f.name, f.mime_type, f.created_at, f.folder_id, f.course_id
             FROM files f
             LEFT JOIN file_course_tags fct ON fct.file_id = f.id
             WHERE f.owner_id = ? AND fct.topic_id = ?
             ORDER BY f.created_at DESC, f.name ASC"
        );
        $stmt->bind_param('ii', $user_id, $topic_id);
        $stmt->execute();
        $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        error_log("[NoteNest API get_files] SQL: files tagged to topic_id=$topic_id (no folder), found=" . count($files));
    }

} elseif ($course_id > 0) {
    // Course selected but no topic/folder — get all files for this course
    $stmt = $conn->prepare(
        "SELECT DISTINCT f.id, f.name, f.mime_type, f.created_at, f.folder_id, f.course_id
         FROM files f
         LEFT JOIN file_course_tags fct ON fct.file_id = f.id
         LEFT JOIN folders fo ON f.folder_id = fo.id
         WHERE f.owner_id = ? AND (f.course_id = ? OR fct.course_id = ? OR fo.course_id = ?)
         ORDER BY f.created_at DESC, f.name ASC"
    );
    $stmt->bind_param('iiii', $user_id, $course_id, $course_id, $course_id);
    $stmt->execute();
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    error_log("[NoteNest API get_files] SQL: all files for course_id=$course_id, found=" . count($files));

} else {
    // No criteria — return all user files
    $stmt = $conn->prepare(
        "SELECT DISTINCT f.id, f.name, f.mime_type, f.created_at, f.folder_id, f.course_id
         FROM files f
         WHERE f.owner_id = ?
         ORDER BY f.created_at DESC, f.name ASC"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    error_log("[NoteNest API get_files] SQL: all files for user_id=$user_id (no criteria), found=" . count($files));
}

error_log("[NoteNest API get_files] Final result: " . count($files) . " files returned for course_id=$course_id, topic_id=$topic_id, folder_id=$folder_id");

echo json_encode([
    'success'   => true,
    'course_id' => $course_id,
    'topic_id'  => $topic_id,
    'folder_id' => $folder_id,
    'files'     => $files
]);
exit;
