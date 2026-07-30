<?php
// Debug script — test Google Classroom API for a specific user
require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/google_classroom_service.php';

$userId = 11;

// Get token
$token = gc_get_valid_token($conn, $userId);
if (!$token) {
    echo "ERROR: No valid token\n";
    exit;
}
echo "✅ Token valid\n\n";

// Get courses
$courses = gc_fetch_courses($token);
echo "=== COURSES ===\n";
if (isset($courses['error'])) {
    echo "ERROR: " . print_r($courses['error'], true) . "\n";
} else {
    $courseList = $courses['courses'] ?? [];
    echo count($courseList) . " courses found\n";
    foreach ($courseList as $c) {
        echo "  - [{$c['id']}] {$c['name']} ({$c['courseState']})\n";
    }
}
echo "\n";

// Test first course for topics, materials, coursework
if (!empty($courseList)) {
    $courseId = $courseList[0]['id'];
    echo "=== TESTING COURSE: {$courseList[0]['name']} (ID: $courseId) ===\n\n";

    // Topics
    echo "--- Topics ---\n";
    $topics = gc_fetch_topics($token, $courseId);
    if (isset($topics['error'])) {
        echo "ERROR: " . json_encode($topics['error']) . "\n";
    } else {
        $topicList = $topics['topic'] ?? [];
        echo count($topicList) . " topics found\n";
        foreach ($topicList as $t) {
            echo "  - [{$t['topicId']}] {$t['name']}\n";
        }
    }
    echo "\n";

    // Course Materials
    echo "--- Course Materials ---\n";
    $materials = gc_fetch_course_materials($token, $courseId);
    if (isset($materials['error'])) {
        echo "ERROR: " . json_encode($materials['error']) . "\n";
    } else {
        $matList = $materials['courseWorkMaterial'] ?? [];
        echo count($matList) . " materials found\n";
        foreach ($matList as $m) {
            echo "  - {$m['title']} (attachments: " . count($m['materials'] ?? []) . ")\n";
        }
    }
    echo "\n";

    // Coursework (Assignments)
    echo "--- Coursework/Assignments ---\n";
    $cw = gc_fetch_coursework($token, $courseId);
    if (isset($cw['error'])) {
        echo "ERROR: " . json_encode($cw['error']) . "\n";
    } else {
        $cwList = $cw['courseWork'] ?? [];
        echo count($cwList) . " assignments found\n";
        foreach ($cwList as $a) {
            echo "  - {$a['title']} (type: " . ($a['workType'] ?? '?') . ", due: " . json_encode($a['dueDate'] ?? 'none') . ")\n";
        }
    }
    echo "\n";

    // Also test announcements if available
    echo "--- Announcements ---\n";
    $url = "https://classroom.googleapis.com/v1/courses/{$courseId}/announcements";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}", 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "HTTP $httpCode\n";
    $annData = json_decode($resp, true);
    if (isset($annData['error'])) {
        echo "ERROR: " . json_encode($annData['error']) . "\n";
    } else {
        $annList = $annData['announcements'] ?? [];
        echo count($annList) . " announcements found\n";
        foreach ($annList as $ann) {
            echo "  - " . substr($ann['text'] ?? 'No text', 0, 80) . " (materials: " . count($ann['materials'] ?? []) . ")\n";
        }
    }
}
?>
