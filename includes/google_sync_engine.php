<?php
// ============================================================
// includes/google_sync_engine.php
// NoteNest AI — Google Classroom Sync Engine
// Orchestrates the full sync pipeline: courses → topics → folders
//   → materials → assignments → todos → calendar → reminders
//   → AI analysis → notifications → progress logging
// ============================================================

require_once __DIR__ . '/google_classroom_service.php';

/**
 * Run a full incremental sync for a user.
 * @return array Sync result stats
 */
function gc_run_sync(mysqli $conn, int $userId, string $syncType = 'manual'): array {
    $startTime = microtime(true);
    $stats     = [
        'courses' => 0, 'topics' => 0, 'files' => 0,
        'assignments' => 0, 'errors' => 0, 'error_details' => [],
    ];

    // 1. Start sync log
    $logId = gc_start_sync_log($conn, $userId, $syncType);

    // 2. Update status to syncing
    gc_update_sync_status($conn, $userId, 'syncing');

    // 3. Get valid access token (auto-refreshes if needed)
    $accessToken = gc_get_valid_token($conn, $userId);
    if (!$accessToken) {
        $err = 'Failed to obtain valid access token. Please reconnect your Google account.';
        gc_update_sync_status($conn, $userId, 'error', $err);
        gc_finish_sync_log($conn, $logId, 'failed', $stats, $startTime, $err);
        return ['success' => false, 'error' => $err, 'stats' => $stats];
    }

    try {
        // 4. Sync Courses
        $courseResult = gc_sync_courses($conn, $userId, $accessToken);
        $stats['courses'] = $courseResult['synced'];
        $stats['errors'] += $courseResult['errors'];
        if (!empty($courseResult['error_details'])) {
            $stats['error_details'] = array_merge($stats['error_details'], $courseResult['error_details']);
        }

        // 5. Sync Topics & Folders for each course
        $gcCourses = gc_get_synced_courses($conn, $userId);
        foreach ($gcCourses as $gc) {
            // Topics
            $topicResult = gc_sync_topics($conn, $userId, $accessToken, $gc);
            $stats['topics'] += $topicResult['synced'];
            $stats['errors'] += $topicResult['errors'];

            // Materials (course work materials + coursework attachments)
            $matResult = gc_sync_materials($conn, $userId, $accessToken, $gc);
            $stats['files'] += $matResult['synced'];
            $stats['errors'] += $matResult['errors'];

            // Assignments → Todos + Calendar
            $asgResult = gc_sync_assignments($conn, $userId, $accessToken, $gc);
            $stats['assignments'] += $asgResult['synced'];
            $stats['errors'] += $asgResult['errors'];

            if (!empty($matResult['error_details'])) {
                $stats['error_details'] = array_merge($stats['error_details'], $matResult['error_details']);
            }
            if (!empty($asgResult['error_details'])) {
                $stats['error_details'] = array_merge($stats['error_details'], $asgResult['error_details']);
            }
        }

        // 6. Generate reminders for new assignments
        if (file_exists(__DIR__ . '/google_reminder_engine.php')) {
            require_once __DIR__ . '/google_reminder_engine.php';
            gc_generate_reminders($conn, $userId);
        }

        // 7. Trigger AI analysis for new assignments
        if (file_exists(__DIR__ . '/google_ai_analyzer.php')) {
            require_once __DIR__ . '/google_ai_analyzer.php';
            gc_analyze_new_assignments($conn, $userId);
        }

        // 8. Generate sync completion notification
        $msg = "✅ Google Classroom sync completed: {$stats['courses']} courses, {$stats['topics']} topics, {$stats['files']} files, {$stats['assignments']} assignments synced.";
        gc_create_notification($conn, $userId, $msg);

        // 9. Log progress event
        if (function_exists('logProgress')) {
            logProgress($conn, $userId, 'file_upload', 'Google Classroom sync: ' . $stats['files'] . ' files synced');
        }

        // 10. Update status
        gc_update_sync_status($conn, $userId, 'idle');
        gc_finish_sync_log($conn, $logId, 'completed', $stats, $startTime);

        return ['success' => true, 'stats' => $stats];

    } catch (\Throwable $e) {
        $err = 'Sync error: ' . $e->getMessage();
        gc_update_sync_status($conn, $userId, 'error', $err);
        gc_finish_sync_log($conn, $logId, 'failed', $stats, $startTime, $err);
        return ['success' => false, 'error' => $err, 'stats' => $stats];
    }
}


// ── COURSE SYNC ──────────────────────────────────────────────

function gc_sync_courses(mysqli $conn, int $userId, string $accessToken): array {
    $result = ['synced' => 0, 'errors' => 0, 'error_details' => []];

    $apiData = gc_fetch_courses($accessToken);
    if (isset($apiData['error'])) {
        $result['errors']++;
        $result['error_details'][] = 'Courses API: ' . ($apiData['error']['message'] ?? 'Unknown error');
        return $result;
    }

    $courses = $apiData['courses'] ?? [];
    foreach ($courses as $course) {
        try {
            $googleCourseId = $course['id'];
            $courseName     = $course['name'] ?? 'Untitled Course';
            $section        = $course['section'] ?? null;
            $description    = $course['descriptionHeading'] ?? ($course['description'] ?? null);
            $courseCode      = $course['enrollmentCode'] ?? strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $courseName), 0, 8));
            $courseState     = $course['courseState'] ?? 'ACTIVE';

            // Check if already synced
            $chk = $conn->prepare("SELECT id, course_id FROM google_courses WHERE user_id = ? AND google_course_id = ?");
            $chk->bind_param('is', $userId, $googleCourseId);
            $chk->execute();
            $chk->bind_result($gcId, $existingCourseId);
            $exists = $chk->fetch();
            $chk->close();

            if ($exists && $existingCourseId) {
                // Already synced — update metadata
                $upd = $conn->prepare("UPDATE google_courses SET course_name=?, section=?, description=?, course_code=?, course_state=?, last_synced_at=NOW() WHERE id=?");
                $upd->bind_param('sssssi', $courseName, $section, $description, $courseCode, $courseState, $gcId);
                $upd->execute();
                $upd->close();
                $result['synced']++;
                continue;
            }

            // Create NoteNest course (if not exists by code)
            $nnCourseId   = null;
            $rootFolderId = null;

            // Generate a unique code from course name (max 20 chars to fit courses.code varchar(20))
            $safeCode = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $courseName), 0, 15));
            if (empty($safeCode)) $safeCode = 'GC' . substr($googleCourseId, 0, 8);

            // Check if a course with this code exists for this user
            $cchk = $conn->prepare("SELECT id FROM courses WHERE user_id = ? AND code = ?");
            $cchk->bind_param('is', $userId, $safeCode);
            $cchk->execute();
            $cchk->bind_result($existingNnId);
            if ($cchk->fetch()) {
                $nnCourseId = $existingNnId;
            }
            $cchk->close();

            if (!$nnCourseId) {
                // Create the course
                $conn->begin_transaction();
                try {
                    $color = '#4285f4'; // Google blue
                    $descSafe = $description ?? '';
                    $ins = $conn->prepare("INSERT INTO courses (user_id, name, code, description, color) VALUES (?,?,?,?,?)");
                    $ins->bind_param('issss', $userId, $courseName, $safeCode, $descSafe, $color);
                    $ins->execute();
                    $nnCourseId = $conn->insert_id;
                    $ins->close();

                    // Create root folder (parent_folder_id is NULL for root)
                    $folderName = $courseName . ' (' . $safeCode . ')';
                    $fIns = $conn->prepare("INSERT INTO folders (owner_id, course_id, is_course_root, name) VALUES (?,?,1,?)");
                    $fIns->bind_param('iis', $userId, $nnCourseId, $folderName);
                    $fIns->execute();
                    $rootFolderId = $conn->insert_id;
                    $fIns->close();

                    $conn->commit();

                    // Notification
                    gc_create_notification($conn, $userId, "📚 New course synced from Google Classroom: {$courseName}");
                } catch (\Throwable $e) {
                    $conn->rollback();
                    $result['errors']++;
                    $result['error_details'][] = "Course '{$courseName}': " . $e->getMessage();
                    continue;
                }
            } else {
                // Find existing root folder
                $rfq = $conn->prepare("SELECT id FROM folders WHERE course_id = ? AND owner_id = ? AND is_course_root = 1 LIMIT 1");
                $rfq->bind_param('ii', $nnCourseId, $userId);
                $rfq->execute();
                $rfq->bind_result($rfId);
                if ($rfq->fetch()) $rootFolderId = $rfId;
                $rfq->close();
            }

            // Insert/update google_courses mapping
            if ($exists) {
                $upd = $conn->prepare("UPDATE google_courses SET course_id=?, root_folder_id=?, course_name=?, section=?, description=?, course_code=?, course_state=?, last_synced_at=NOW() WHERE id=?");
                $upd->bind_param('iisssssi', $nnCourseId, $rootFolderId, $courseName, $section, $description, $courseCode, $courseState, $gcId);
                $upd->execute();
                $upd->close();
            } else {
                $ins = $conn->prepare(
                    "INSERT INTO google_courses (user_id, google_course_id, course_id, root_folder_id, course_name, section, description, course_code, course_state, last_synced_at)
                     VALUES (?,?,?,?,?,?,?,?,?,NOW())"
                );
                $ins->bind_param('isiisssss', $userId, $googleCourseId, $nnCourseId, $rootFolderId, $courseName, $section, $description, $courseCode, $courseState);
                $ins->execute();
                $ins->close();
            }

            $result['synced']++;
        } catch (\Throwable $e) {
            $result['errors']++;
            $result['error_details'][] = 'Course sync: ' . $e->getMessage();
        }
    }

    return $result;
}


// ── TOPIC SYNC ───────────────────────────────────────────────

function gc_sync_topics(mysqli $conn, int $userId, string $accessToken, array $gc): array {
    $result = ['synced' => 0, 'errors' => 0, 'error_details' => []];

    $apiData = gc_fetch_topics($accessToken, $gc['google_course_id']);
    if (isset($apiData['error'])) {
        $result['errors']++;
        return $result;
    }

    $topics = $apiData['topic'] ?? [];
    $sortOrder = 0;
    foreach ($topics as $topic) {
        try {
            $googleTopicId = $topic['topicId'];
            $topicName     = $topic['name'] ?? 'Untitled Topic';
            $sortOrder++;

            // Check if already synced
            $chk = $conn->prepare("SELECT id FROM google_topics WHERE user_id = ? AND google_course_id = ? AND google_topic_id = ?");
            $chk->bind_param('iss', $userId, $gc['google_course_id'], $googleTopicId);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $chk->close();
                $result['synced']++;
                continue;
            }
            $chk->close();

            // Create NoteNest topic
            $topicId  = null;
            $folderId = null;

            if ($gc['course_id']) {
                // Create course_topic
                $tIns = $conn->prepare("INSERT INTO course_topics (course_id, title, sort_order) VALUES (?,?,?)");
                $tIns->bind_param('isi', $gc['course_id'], $topicName, $sortOrder);
                $tIns->execute();
                $topicId = $conn->insert_id;
                $tIns->close();

                // Create folder under course root
                if ($gc['root_folder_id']) {
                    $parentId = (int)$gc['root_folder_id'];
                    $courseId = (int)$gc['course_id'];
                    $fIns = $conn->prepare("INSERT INTO folders (owner_id, course_id, name, parent_folder_id) VALUES (?,?,?,?)");
                    $fIns->bind_param('iisi', $userId, $courseId, $topicName, $parentId);
                    $fIns->execute();
                    $folderId = $conn->insert_id;
                    $fIns->close();

                    // Link folder to topic
                    $upd = $conn->prepare("UPDATE course_topics SET folder_id = ? WHERE id = ?");
                    $upd->bind_param('ii', $folderId, $topicId);
                    $upd->execute();
                    $upd->close();
                }
            }

            // Insert google_topics mapping — use dynamic SQL to handle NULLs
            $topicIdVal  = $topicId  !== null ? (int)$topicId  : null;
            $folderIdVal = $folderId !== null ? (int)$folderId : null;

            $sql = "INSERT INTO google_topics (user_id, google_course_id, google_topic_id, topic_id, folder_id, topic_name, sort_order) VALUES (?,?,?,?,?,?,?)";
            $ins = $conn->prepare($sql);
            $ins->bind_param('issiisi', $userId, $gc['google_course_id'], $googleTopicId, $topicIdVal, $folderIdVal, $topicName, $sortOrder);
            $ins->execute();
            $ins->close();

            $result['synced']++;
        } catch (\Throwable $e) {
            $result['errors']++;
            $result['error_details'][] = 'Topic sync: ' . $e->getMessage();
        }
    }

    return $result;
}


// ── MATERIAL SYNC (files download) ──────────────────────────

function gc_sync_materials(mysqli $conn, int $userId, string $accessToken, array $gc): array {
    $result = ['synced' => 0, 'errors' => 0, 'error_details' => []];

    // Fetch course work materials
    $apiData = gc_fetch_course_materials($accessToken, $gc['google_course_id']);
    $materials = $apiData['courseWorkMaterial'] ?? [];

    // Also get attachments from coursework (assignments)
    $cwData = gc_fetch_coursework($accessToken, $gc['google_course_id']);
    $coursework = $cwData['courseWork'] ?? [];

    // Collect all attachments
    $allAttachments = [];

    foreach ($materials as $mat) {
        $topicId = $mat['topicId'] ?? null;
        if (isset($mat['materials'])) {
            foreach ($mat['materials'] as $attachment) {
                $allAttachments[] = [
                    'attachment' => $attachment,
                    'topicId'    => $topicId,
                    'sourceType' => 'material',
                    'sourceId'   => $mat['id'] ?? '',
                ];
            }
        }
    }

    foreach ($coursework as $cw) {
        $topicId = $cw['topicId'] ?? null;
        if (isset($cw['materials'])) {
            foreach ($cw['materials'] as $attachment) {
                $allAttachments[] = [
                    'attachment' => $attachment,
                    'topicId'    => $topicId,
                    'sourceType' => 'coursework',
                    'sourceId'   => $cw['id'] ?? '',
                ];
            }
        }
    }

    // Also get attachments from announcements (posts)
    $annData = gc_fetch_announcements($accessToken, $gc['google_course_id']);
    $announcements = $annData['announcements'] ?? [];

    foreach ($announcements as $ann) {
        if (isset($ann['materials'])) {
            foreach ($ann['materials'] as $attachment) {
                $allAttachments[] = [
                    'attachment' => $attachment,
                    'topicId'    => null,
                    'sourceType' => 'announcement',
                    'sourceId'   => $ann['id'] ?? '',
                ];
            }
        }
    }

    foreach ($allAttachments as $item) {
        $att = $item['attachment'];

        // Determine file info from attachment type
        $fileId   = null;
        $title    = 'Untitled';
        $mimeType = '';
        $fileUrl  = '';

        if (isset($att['driveFile'])) {
            $df       = $att['driveFile']['driveFile'] ?? $att['driveFile'];
            $fileId   = $df['id'] ?? null;
            $title    = $df['title'] ?? ($df['name'] ?? 'Drive File');
            $mimeType = $df['mimeType'] ?? '';
            $fileUrl  = $df['alternateLink'] ?? '';
        } elseif (isset($att['youtubeVideo'])) {
            continue;  // Skip YouTube
        } elseif (isset($att['link'])) {
            continue;  // Skip external links
        } elseif (isset($att['form'])) {
            continue;  // Skip Google Forms
        }

        if (!$fileId) continue;

        // Check if already synced
        $chk = $conn->prepare("SELECT id FROM google_files WHERE user_id = ? AND google_file_id = ?");
        $chk->bind_param('is', $userId, $fileId);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $chk->close();
            continue;
        }
        $chk->close();

        // Resolve target folder
        $targetFolderId = $gc['root_folder_id'] ? (int)$gc['root_folder_id'] : null;
        if ($item['topicId']) {
            $tf = $conn->prepare("SELECT folder_id FROM google_topics WHERE user_id = ? AND google_course_id = ? AND google_topic_id = ?");
            $tf->bind_param('iss', $userId, $gc['google_course_id'], $item['topicId']);
            $tf->execute();
            $tf->bind_result($topicFolderId);
            if ($tf->fetch() && $topicFolderId) {
                $targetFolderId = (int)$topicFolderId;
            }
            $tf->close();
        }

        // Resolve topic_id for NoteNest
        $nnTopicId = null;
        if ($item['topicId']) {
            $tq = $conn->prepare("SELECT topic_id FROM google_topics WHERE user_id = ? AND google_course_id = ? AND google_topic_id = ?");
            $tq->bind_param('iss', $userId, $gc['google_course_id'], $item['topicId']);
            $tq->execute();
            $tq->bind_result($nnTopicId);
            $tq->fetch();
            $tq->close();
        }

        // Get file metadata from Drive if mimeType not known
        if (!$mimeType && $fileId) {
            $meta = gc_get_drive_file_info($accessToken, $fileId);
            $mimeType = $meta['mimeType'] ?? '';
            if (empty($title) || $title === 'Drive File') {
                $title = $meta['name'] ?? $title;
            }
        }

        // Download the file
        $dl = gc_download_drive_file($accessToken, $fileId, $mimeType, $title);

        $downloadStatus = 'pending';
        $errorMsg       = null;
        $nnFileId       = null;
        $courseIdInt     = $gc['course_id'] ? (int)$gc['course_id'] : null;

        if ($dl['success']) {
            // Save to uploads/notes/
            $safeName   = preg_replace('/[^a-zA-Z0-9._-]/', '_', $dl['filename']);
            $uniqueName = time() . '_' . mt_rand(1000, 9999) . '_' . $safeName;
            $filePath   = 'uploads/notes/' . $uniqueName;
            $absPath    = __DIR__ . '/../' . $filePath;

            // Ensure directory exists
            if (!is_dir(dirname($absPath))) {
                mkdir(dirname($absPath), 0755, true);
            }

            if (file_put_contents($absPath, $dl['content'])) {
                // Insert into files table — build query dynamically for nullable folder_id
                if ($targetFolderId !== null) {
                    $fIns = $conn->prepare("INSERT INTO files (folder_id, owner_id, course_id, name, file_path, mime_type) VALUES (?,?,?,?,?,?)");
                    $fIns->bind_param('iiisss', $targetFolderId, $userId, $courseIdInt, $title, $filePath, $dl['mime_type']);
                } else {
                    $fIns = $conn->prepare("INSERT INTO files (owner_id, course_id, name, file_path, mime_type) VALUES (?,?,?,?,?)");
                    $fIns->bind_param('iisss', $userId, $courseIdInt, $title, $filePath, $dl['mime_type']);
                }
                $fIns->execute();
                $nnFileId = $conn->insert_id;
                $fIns->close();

                // Tag file with course/topic
                if ($courseIdInt) {
                    $tag = $conn->prepare("INSERT IGNORE INTO file_course_tags (file_id, course_id, topic_id) VALUES (?,?,?)");
                    $nnTopicIdInt = $nnTopicId ? (int)$nnTopicId : null;
                    $tag->bind_param('iii', $nnFileId, $courseIdInt, $nnTopicIdInt);
                    $tag->execute();
                    $tag->close();
                }

                // Index RAG content
                index_file_content($conn, $nnFileId);

                $downloadStatus = 'downloaded';
                $result['synced']++;

                // Notification
                gc_create_notification($conn, $userId, "📄 New material downloaded: {$title}");

                // Log progress
                if (function_exists('logProgress')) {
                    logProgress($conn, $userId, 'file_upload', "Google Classroom: {$title}", $courseIdInt ?? 0);
                }
            } else {
                $downloadStatus = 'failed';
                $errorMsg = 'Failed to write file to disk';
                $result['errors']++;
            }
        } else {
            if (strpos($dl['error'], 'File too large') !== false) {
                $downloadStatus = 'skipped';
                $errorMsg = $dl['error'];
            } else {
                $downloadStatus = 'failed';
                $errorMsg = $dl['error'];
                $result['errors']++;
            }
            $result['error_details'][] = "File '{$title}': " . $dl['error'];
        }

        // Record in google_files — use direct SQL for nullable columns
        $fileType   = pathinfo($title, PATHINFO_EXTENSION) ?: 'unknown';
        $materialId = $item['sourceId'];
        $errorMsgSafe = $errorMsg ?? '';

        $gfSql = "INSERT INTO google_files (user_id, google_course_id, google_file_id, google_material_id, file_id, folder_id, course_id, topic_id, file_title, file_type, mime_type, file_url, download_status, error_message) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $gfIns = $conn->prepare($gfSql);
        $gfIns->bind_param('isssiiiissssss',
            $userId, $gc['google_course_id'], $fileId, $materialId,
            $nnFileId, $targetFolderId, $courseIdInt, $nnTopicId,
            $title, $fileType, $mimeType, $fileUrl,
            $downloadStatus, $errorMsgSafe
        );
        $gfIns->execute();
        $gfIns->close();
    }

    return $result;
}


// ── ASSIGNMENT SYNC ──────────────────────────────────────────

function gc_sync_assignments(mysqli $conn, int $userId, string $accessToken, array $gc): array {
    $result = ['synced' => 0, 'errors' => 0, 'error_details' => []];

    $apiData = gc_fetch_coursework($accessToken, $gc['google_course_id']);
    if (isset($apiData['error'])) {
        $result['errors']++;
        return $result;
    }

    $courseIdInt = $gc['course_id'] ? (int)$gc['course_id'] : 0;

    $courseworks = $apiData['courseWork'] ?? [];
    foreach ($courseworks as $cw) {
        try {
            $cwId        = $cw['id'];
            $title       = $cw['title'] ?? 'Untitled Assignment';
            $description = $cw['description'] ?? '';
            $workType    = $cw['workType'] ?? 'ASSIGNMENT';
            $state       = $cw['state'] ?? 'PUBLISHED';
            $maxPoints   = isset($cw['maxPoints']) ? (float)$cw['maxPoints'] : 0.0;

            // Parse due date
            $dueDate = null;
            $dueTime = null;
            if (isset($cw['dueDate'])) {
                $dd = $cw['dueDate'];
                $dueDate = sprintf('%04d-%02d-%02d', $dd['year'], $dd['month'], $dd['day']);
                if (isset($cw['dueTime'])) {
                    $dt = $cw['dueTime'];
                    $dueTime = sprintf('%02d:%02d:00', $dt['hours'] ?? 23, $dt['minutes'] ?? 59);
                } else {
                    $dueTime = '23:59:00';
                }
            }

            // Check if already synced
            $chk = $conn->prepare("SELECT id FROM google_assignments WHERE user_id = ? AND google_course_id = ? AND google_coursework_id = ?");
            $chk->bind_param('iss', $userId, $gc['google_course_id'], $cwId);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $chk->close();
                $result['synced']++;
                continue;
            }
            $chk->close();

            // Create Todo
            $todoId = null;
            $priority = 'medium';
            if ($dueDate) {
                $eventDatetime = $dueDate . ' ' . ($dueTime ?: '23:59:00');

                // Auto-detect priority based on due date proximity
                $daysUntilDue = (strtotime($dueDate) - time()) / 86400;
                if ($daysUntilDue <= 2) $priority = 'high';
                elseif ($daysUntilDue <= 7) $priority = 'medium';
                else $priority = 'low';

                $taskType = 'assignment';
                $status   = 'pending';
                $tIns = $conn->prepare(
                    "INSERT INTO todos (user_id, title, event_datetime, details, status, priority, task_type, course_id)
                     VALUES (?,?,?,?,?,?,?,?)"
                );
                $tIns->bind_param('issssssi', $userId, $title, $eventDatetime, $description, $status, $priority, $taskType, $courseIdInt);
                $tIns->execute();
                $todoId = $conn->insert_id;
                $tIns->close();
            }

            // Create Calendar Event
            $calendarEventId = null;
            if ($dueDate) {
                $color = $priority === 'high' ? '#e74c3c' : ($priority === 'medium' ? '#f39c12' : '#27ae60');
                $cIns = $conn->prepare(
                    "INSERT INTO calendar_events (user_id, todo_id, course_id, title, description, event_date, event_time, priority, event_type, color, source)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)"
                );
                $evtType = 'assignment';
                $source  = 'google_classroom';
                $todoIdInt = $todoId ? (int)$todoId : 0;
                $cIns->bind_param('iiissssssss', $userId, $todoIdInt, $courseIdInt, $title, $description, $dueDate, $dueTime, $priority, $evtType, $color, $source);
                $cIns->execute();
                $calendarEventId = $conn->insert_id;
                $cIns->close();
            }

            // Insert google_assignments mapping
            $todoIdSafe     = $todoId          ? (int)$todoId          : 0;
            $calEvtIdSafe   = $calendarEventId ? (int)$calendarEventId : 0;
            $dueDateSafe    = $dueDate ?? '';
            $dueTimeSafe    = $dueTime ?? '';

            $ins = $conn->prepare(
                "INSERT INTO google_assignments (user_id, google_course_id, google_coursework_id, todo_id, calendar_event_id, course_id, title, description, due_date, due_time, max_points, work_type, state)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $ins->bind_param('issiiissssdss',
                $userId, $gc['google_course_id'], $cwId, $todoIdSafe, $calEvtIdSafe, $courseIdInt,
                $title, $description, $dueDateSafe, $dueTimeSafe, $maxPoints, $workType, $state
            );
            $ins->execute();
            $ins->close();

            // Notification
            gc_create_notification($conn, $userId, "📝 New assignment synced: {$title}" . ($dueDate ? " (Due: {$dueDate})" : ''));

            $result['synced']++;
        } catch (\Throwable $e) {
            $result['errors']++;
            $result['error_details'][] = "Assignment '{$title}': " . $e->getMessage();
        }
    }

    return $result;
}


// ── HELPER FUNCTIONS ─────────────────────────────────────────

function gc_get_synced_courses(mysqli $conn, int $userId): array {
    $stmt = $conn->prepare("SELECT id, google_course_id, course_id, root_folder_id, course_name FROM google_courses WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function gc_create_notification(mysqli $conn, int $userId, string $message): void {
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    $stmt->bind_param('is', $userId, $message);
    $stmt->execute();
    $stmt->close();
}

function gc_start_sync_log(mysqli $conn, int $userId, string $syncType): int {
    $stmt = $conn->prepare("INSERT INTO google_sync_logs (user_id, sync_type, status) VALUES (?, ?, 'started')");
    $stmt->bind_param('is', $userId, $syncType);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}

function gc_finish_sync_log(mysqli $conn, int $logId, string $status, array $stats, float $startTime, string $errMsg = ''): void {
    $duration = round(microtime(true) - $startTime, 2);
    $errors   = json_encode($stats['error_details'] ?? []);
    $stmt = $conn->prepare(
        "UPDATE google_sync_logs SET
            status = ?, courses_synced = ?, topics_synced = ?, files_synced = ?,
            assignments_synced = ?, errors_count = ?, error_details = ?,
            duration_sec = ?, completed_at = NOW()
         WHERE id = ?"
    );
    $stmt->bind_param('siiiissdi',
        $status, $stats['courses'], $stats['topics'], $stats['files'],
        $stats['assignments'], $stats['errors'], $errors,
        $duration, $logId
    );
    $stmt->execute();
    $stmt->close();
}

/**
 * Get sync statistics for dashboard display.
 */
function gc_get_sync_stats(mysqli $conn, int $userId): array {
    $stats = [
        'total_courses'     => 0,
        'total_topics'      => 0,
        'total_files'       => 0,
        'total_assignments' => 0,
        'pending_assignments' => 0,
        'downloaded_files'  => 0,
    ];

    $stmt = $conn->prepare("SELECT COUNT(*) FROM google_courses WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stats['total_courses'] = (int)$count;
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM google_topics WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stats['total_topics'] = (int)$count;
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM google_files WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stats['total_files'] = (int)$count;
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM google_files WHERE user_id = ? AND download_status = 'downloaded'");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stats['downloaded_files'] = (int)$count;
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM google_assignments WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stats['total_assignments'] = (int)$count;
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM google_assignments ga JOIN todos t ON ga.todo_id = t.id WHERE ga.user_id = ? AND t.status = 'pending' AND ga.due_date >= CURDATE()");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stats['pending_assignments'] = (int)$count;
    $stmt->close();

    return $stats;
}
?>
