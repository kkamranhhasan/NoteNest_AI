<?php
/**
 * Diagnostic script — Identify why Google Classroom sync returns 0 for new content.
 * Run via CLI:  php debug_sync_issue.php
 * Or browser:   http://localhost/NoteNest-main/debug_sync_issue.php
 */
require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/google_classroom_service.php';

header('Content-Type: text/plain; charset=utf-8');
echo "===== NoteNest Google Classroom Sync Diagnostic =====\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Find user with Google account connected
$res = $conn->query("SELECT ga.user_id, ga.google_email, ga.sync_status, ga.last_sync_at, ga.sync_error, ga.token_expiry
                      FROM google_accounts ga ORDER BY ga.user_id LIMIT 5");
if ($res->num_rows === 0) {
    echo "❌ No Google accounts connected.\n";
    exit;
}

echo "== Connected Google Accounts ==\n";
while ($row = $res->fetch_assoc()) {
    echo "  User #{$row['user_id']}: {$row['google_email']} | Status: {$row['sync_status']} | Last sync: " . ($row['last_sync_at'] ?: 'Never') . " | Token expiry: {$row['token_expiry']}\n";
    if ($row['sync_error']) echo "    ⚠️  Error: {$row['sync_error']}\n";
}
echo "\n";

// Use first user for testing
$res->data_seek(0);
$account = $res->fetch_assoc();
$userId = $account['user_id'];
echo "== Testing with User #{$userId} ({$account['google_email']}) ==\n\n";

// ─── STEP 1: Token validation ─────────────────────────────────
echo "── STEP 1: Token Validation ──\n";
$token = gc_get_valid_token($conn, $userId);
if (!$token) {
    echo "❌ FAILED: Could not get valid access token!\n";
    echo "   Possible causes:\n";
    echo "   - Token expired and refresh failed\n";
    echo "   - Google account disconnected/revoked\n";
    echo "   - Encryption/decryption issue with GOOGLE_CLIENT_SECRET\n\n";

    // Try to understand why
    $tRes = $conn->query("SELECT access_token, refresh_token, token_expiry FROM google_accounts WHERE user_id = $userId");
    $tRow = $tRes->fetch_assoc();
    echo "   Token expiry in DB: {$tRow['token_expiry']}\n";
    echo "   Current time:       " . date('Y-m-d H:i:s') . "\n";
    echo "   Token expired: " . (strtotime($tRow['token_expiry']) < time() ? 'YES' : 'NO') . "\n";

    if ($tRow['refresh_token']) {
        echo "   Refresh token exists: YES\n";
        echo "   Trying manual refresh...\n";
        $refreshResult = gc_refresh_token($tRow['refresh_token']);
        echo "   Refresh result: " . json_encode($refreshResult, JSON_PRETTY_PRINT) . "\n";
    }
    exit;
}
echo "✅ Token is valid (length: " . strlen($token) . ")\n\n";

// ─── STEP 2: Fetch courses from API ──────────────────────────
echo "── STEP 2: API — Courses ──\n";
$coursesApi = gc_fetch_courses($token);
if (isset($coursesApi['error'])) {
    echo "❌ API Error: " . json_encode($coursesApi['error']) . "\n";
    exit;
}
$apiCourses = $coursesApi['courses'] ?? [];
echo "✅ API returned " . count($apiCourses) . " courses\n";
foreach ($apiCourses as $c) {
    echo "   - [{$c['id']}] {$c['name']} (state: {$c['courseState']})\n";
}
echo "\n";

// ─── STEP 3: Check what's already in DB ──────────────────────
echo "── STEP 3: Database State ──\n";
$dbCourses = $conn->query("SELECT google_course_id, course_id, course_name FROM google_courses WHERE user_id = $userId")->fetch_all(MYSQLI_ASSOC);
echo "  DB has " . count($dbCourses) . " synced courses\n";
$dbCourseIds = array_column($dbCourses, 'google_course_id');

$dbFiles = $conn->query("SELECT COUNT(*) AS cnt FROM google_files WHERE user_id = $userId")->fetch_assoc()['cnt'];
echo "  DB has $dbFiles synced files\n";

$dbAssignments = $conn->query("SELECT COUNT(*) AS cnt FROM google_assignments WHERE user_id = $userId")->fetch_assoc()['cnt'];
echo "  DB has $dbAssignments synced assignments\n";

$dbTopics = $conn->query("SELECT COUNT(*) AS cnt FROM google_topics WHERE user_id = $userId")->fetch_assoc()['cnt'];
echo "  DB has $dbTopics synced topics\n\n";

// ─── STEP 4: For each course, check for NEW materials/assignments ─
echo "── STEP 4: Checking for NEW (un-synced) content ──\n\n";

foreach ($apiCourses as $course) {
    $courseId = $course['id'];
    $courseName = $course['name'];
    echo "  📚 Course: $courseName ($courseId)\n";

    // --- Materials ---
    echo "    Materials (courseWorkMaterial):\n";
    $matApi = gc_fetch_course_materials($token, $courseId);
    $materials = $matApi['courseWorkMaterial'] ?? [];
    echo "      API returned: " . count($materials) . " materials\n";

    $newMaterialFiles = 0;
    foreach ($materials as $mat) {
        if (!isset($mat['materials'])) continue;
        foreach ($mat['materials'] as $att) {
            $fileId = null;
            if (isset($att['driveFile'])) {
                $df = $att['driveFile']['driveFile'] ?? $att['driveFile'];
                $fileId = $df['id'] ?? null;
            }
            if (!$fileId) continue;

            $chk = $conn->prepare("SELECT id FROM google_files WHERE user_id = ? AND google_file_id = ?");
            $chk->bind_param('is', $userId, $fileId);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows === 0) {
                $newMaterialFiles++;
                echo "      🆕 NEW file: " . ($df['title'] ?? $df['name'] ?? $fileId) . " (ID: $fileId)\n";
            }
            $chk->close();
        }
    }
    echo "      New unsynced material files: $newMaterialFiles\n";

    // --- Coursework (assignments) ---
    echo "    Coursework/Assignments:\n";
    $cwApi = gc_fetch_coursework($token, $courseId);
    $courseworks = $cwApi['courseWork'] ?? [];
    echo "      API returned: " . count($courseworks) . " coursework items\n";

    $newAssignments = 0;
    $newCwFiles = 0;
    foreach ($courseworks as $cw) {
        $cwId = $cw['id'];
        $chk = $conn->prepare("SELECT id FROM google_assignments WHERE user_id = ? AND google_course_id = ? AND google_coursework_id = ?");
        $chk->bind_param('iss', $userId, $courseId, $cwId);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows === 0) {
            $newAssignments++;
            echo "      🆕 NEW assignment: {$cw['title']} (ID: $cwId)\n";
        }
        $chk->close();

        // Check for new coursework attachment files
        if (isset($cw['materials'])) {
            foreach ($cw['materials'] as $att) {
                $fileId = null;
                if (isset($att['driveFile'])) {
                    $df = $att['driveFile']['driveFile'] ?? $att['driveFile'];
                    $fileId = $df['id'] ?? null;
                }
                if (!$fileId) continue;

                $chk = $conn->prepare("SELECT id FROM google_files WHERE user_id = ? AND google_file_id = ?");
                $chk->bind_param('is', $userId, $fileId);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows === 0) {
                    $newCwFiles++;
                    echo "      🆕 NEW cw-file: " . ($df['title'] ?? $df['name'] ?? $fileId) . "\n";
                }
                $chk->close();
            }
        }
    }
    echo "      New unsynced assignments: $newAssignments\n";
    echo "      New unsynced cw-files: $newCwFiles\n";

    // --- Announcements ---
    echo "    Announcements:\n";
    $annApi = gc_fetch_announcements($token, $courseId);
    $announcements = $annApi['announcements'] ?? [];
    echo "      API returned: " . count($announcements) . " announcements\n";

    $newAnnFiles = 0;
    $textOnlyAnns = 0;
    foreach ($announcements as $ann) {
        $hasAttachments = !empty($ann['materials']);
        if (!$hasAttachments) {
            $textOnlyAnns++;
            echo "      📝 TEXT-ONLY announcement (NOT synced — no file created): " . substr($ann['text'] ?? '', 0, 60) . "...\n";
            continue;
        }
        foreach ($ann['materials'] as $att) {
            $fileId = null;
            if (isset($att['driveFile'])) {
                $df = $att['driveFile']['driveFile'] ?? $att['driveFile'];
                $fileId = $df['id'] ?? null;
            }
            if (!$fileId) continue;

            $chk = $conn->prepare("SELECT id FROM google_files WHERE user_id = ? AND google_file_id = ?");
            $chk->bind_param('is', $userId, $fileId);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows === 0) {
                $newAnnFiles++;
                echo "      🆕 NEW ann-file: " . ($df['title'] ?? $df['name'] ?? $fileId) . "\n";
            }
            $chk->close();
        }
    }
    echo "      Text-only announcements (never synced): $textOnlyAnns\n";
    echo "      New unsynced announcement files: $newAnnFiles\n";

    // --- Topics ---
    echo "    Topics:\n";
    $topApi = gc_fetch_topics($token, $courseId);
    $topics = $topApi['topic'] ?? [];
    echo "      API returned: " . count($topics) . " topics\n";
    $newTopics = 0;
    foreach ($topics as $t) {
        $tid = $t['topicId'];
        $chk = $conn->prepare("SELECT id FROM google_topics WHERE user_id = ? AND google_course_id = ? AND google_topic_id = ?");
        $chk->bind_param('iss', $userId, $courseId, $tid);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows === 0) {
            $newTopics++;
            echo "      🆕 NEW topic: {$t['name']}\n";
        }
        $chk->close();
    }
    echo "      New unsynced topics: $newTopics\n\n";
}

// ─── STEP 5: Check sync logs ────────────────────────────────
echo "── STEP 5: Recent Sync Logs ──\n";
$logs = $conn->query("SELECT * FROM google_sync_logs WHERE user_id = $userId ORDER BY started_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
if (empty($logs)) {
    echo "  No sync logs found\n";
} else {
    foreach ($logs as $log) {
        echo "  [{$log['started_at']}] {$log['sync_type']} → {$log['status']} | C:{$log['courses_synced']} T:{$log['topics_synced']} F:{$log['files_synced']} A:{$log['assignments_synced']} E:{$log['errors_count']} | {$log['duration_sec']}s\n";
        if ($log['error_details'] && $log['error_details'] !== '[]') {
            echo "    Errors: {$log['error_details']}\n";
        }
    }
}
echo "\n";

// ─── STEP 6: Check NoteNest files table ────────────────────
echo "── STEP 6: NoteNest Files from Google Classroom ──\n";
$nnFiles = $conn->query("SELECT f.id, f.name, f.file_path, f.folder_id, f.created_at
                          FROM files f
                          JOIN google_files gf ON gf.file_id = f.id
                          WHERE gf.user_id = $userId
                          ORDER BY f.created_at DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
echo "  Last 10 synced files in NoteNest:\n";
foreach ($nnFiles as $nf) {
    $exists = file_exists(__DIR__ . '/' . $nf['file_path']) ? '✅' : '❌ MISSING';
    echo "    [{$nf['id']}] {$nf['name']} → {$nf['file_path']} $exists (folder:{$nf['folder_id']}, {$nf['created_at']})\n";
}

// ─── STEP 7: Check upload directory ─────────────────────────
echo "\n── STEP 7: Upload Directory ──\n";
$uploadDir = __DIR__ . '/uploads/notes/';
if (!is_dir($uploadDir)) {
    echo "  ❌ Directory does NOT exist: $uploadDir\n";
    echo "  This means files can't be saved!\n";
} else {
    echo "  ✅ Directory exists: $uploadDir\n";
    echo "  Writable: " . (is_writable($uploadDir) ? '✅ YES' : '❌ NO') . "\n";
    $fileCount = count(glob($uploadDir . '*'));
    echo "  Files in directory: $fileCount\n";
}

echo "\n===== DIAGNOSIS COMPLETE =====\n";
echo "\nSUMMARY:\n";
echo "If all API calls return data but DB shows 0 new items → sync engine skip-logic issue\n";
echo "If API returns 0 items → Google API scope/permission issue\n";
echo "If token fails → reconnect Google account\n";
echo "If text-only announcements → need new feature to save announcement text as notes\n";
?>
