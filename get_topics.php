<?php
// ============================================================
// get_topics.php — NoteNest AI API Endpoint
// Fetches syllabus topics for a given course ID
// ============================================================
require 'includes/auth.php';
require 'config.php';
require_once 'includes/db.php';

header('Content-Type: application/json');

$user_id   = $_SESSION['user_id'];
$course_id = (int)($_REQUEST['course_id'] ?? 0);

error_log("[NoteNest API get_topics] user_id=$user_id, course_id=$course_id");

$topics = [];

if ($course_id > 0) {
    // Verify course ownership
    $cq = $conn->prepare("SELECT id FROM courses WHERE id = ? AND user_id = ?");
    $cq->bind_param('ii', $course_id, $user_id);
    $cq->execute();
    $cres = $cq->get_result();
    $cq->close();

    if ($cres->num_rows > 0) {
        $stmt = $conn->prepare(
            "SELECT ct.id, ct.title, ct.week_no, ct.folder_id
             FROM course_topics ct
             WHERE ct.course_id = ?
             ORDER BY ct.sort_order ASC, ct.week_no ASC, ct.title ASC"
        );
        $stmt->bind_param('i', $course_id);
        $stmt->execute();
        $topics = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        error_log("[NoteNest API get_topics] SQL query: SELECT from course_topics WHERE course_id=$course_id");
    } else {
        error_log("[NoteNest API get_topics] Course not found or access denied for course_id=$course_id, user_id=$user_id");
    }
} else {
    // Fetch all topics belonging to user's courses
    $stmt = $conn->prepare(
        "SELECT ct.id, ct.title, ct.week_no, ct.folder_id, ct.course_id, c.name AS course_name
         FROM course_topics ct
         JOIN courses c ON ct.course_id = c.id
         WHERE c.user_id = ?
         ORDER BY c.name ASC, ct.sort_order ASC, ct.title ASC"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $topics = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    error_log("[NoteNest API get_topics] No course_id provided — fetching all topics for user_id=$user_id");
}

error_log("[NoteNest API get_topics] SQL returned " . count($topics) . " topics for course_id=$course_id");

echo json_encode([
    'success'   => true,
    'course_id' => $course_id,
    'topics'    => $topics
]);
exit;
