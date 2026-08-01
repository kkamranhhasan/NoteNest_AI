<?php
// ============================================================
// get_folders.php — NoteNest AI API Endpoint
// Fetches folders for a given topic_id or course_id
// Flow: Course → Topic → Folder
// ============================================================
require 'includes/auth.php';
require 'config.php';
require_once 'includes/db.php';

header('Content-Type: application/json');

$user_id   = $_SESSION['user_id'];
$course_id = (int)($_REQUEST['course_id'] ?? 0);
$topic_id  = (int)($_REQUEST['topic_id']  ?? 0);

error_log("[NoteNest API get_folders] user_id=$user_id, course_id=$course_id, topic_id=$topic_id");

$target_folder_id = 0;
$folders = [];

// Step 1: If topic_id given, resolve the linked folder and course_id from course_topics
if ($topic_id > 0) {
    $tstmt = $conn->prepare("SELECT folder_id, course_id FROM course_topics WHERE id = ?");
    $tstmt->bind_param('i', $topic_id);
    $tstmt->execute();
    $tstmt->bind_result($fId, $cId);
    if ($tstmt->fetch()) {
        $target_folder_id = (int)$fId;
        if ($course_id === 0 && $cId) {
            $course_id = (int)$cId;
        }
    }
    $tstmt->close();
    error_log("[NoteNest API get_folders] topic_id=$topic_id resolved: target_folder_id=$target_folder_id, course_id=$course_id");
}

// Step 2: Fetch folders from folders table by course or by target folder
if ($topic_id > 0 && $target_folder_id > 0) {
    // Fetch the specific linked folder and its subfolders
    $stmt = $conn->prepare(
        "SELECT f.id, f.name, f.parent_folder_id, f.course_id
         FROM folders f
         WHERE f.owner_id = ? AND (f.id = ? OR f.parent_folder_id = ?)
         ORDER BY f.name ASC"
    );
    $stmt->bind_param('iii', $user_id, $target_folder_id, $target_folder_id);
    $stmt->execute();
    $folders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    error_log("[NoteNest API get_folders] SQL: folders for topic_id=$topic_id via folder_id=$target_folder_id, found=" . count($folders));
} elseif ($course_id > 0) {
    // Fetch all folders linked to this course
    $stmt = $conn->prepare(
        "SELECT f.id, f.name, f.parent_folder_id, f.course_id
         FROM folders f
         WHERE f.owner_id = ? AND (f.course_id = ?
               OR f.id = ?)
         ORDER BY f.name ASC"
    );
    $stmt->bind_param('iii', $user_id, $course_id, $target_folder_id);
    $stmt->execute();
    $folders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    error_log("[NoteNest API get_folders] SQL: folders for course_id=$course_id, found=" . count($folders));
} else {
    // No course or topic: return all folders for this user
    $stmt = $conn->prepare(
        "SELECT f.id, f.name, f.parent_folder_id, f.course_id
         FROM folders f
         WHERE f.owner_id = ?
         ORDER BY f.name ASC"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $folders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    error_log("[NoteNest API get_folders] SQL: all folders for user_id=$user_id, found=" . count($folders));
}

error_log("[NoteNest API get_folders] Returning " . count($folders) . " folders, target_folder_id=$target_folder_id");

echo json_encode([
    'success'          => true,
    'course_id'        => $course_id,
    'topic_id'         => $topic_id,
    'target_folder_id' => $target_folder_id,
    'folders'          => $folders
]);
exit;
