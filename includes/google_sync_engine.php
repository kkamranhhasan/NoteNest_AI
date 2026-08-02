<?php
// ============================================================
// includes/google_sync_engine.php
// NoteNest AI — Google Classroom Sync Engine
// Orchestrates the full sync pipeline: courses → topics → folders
//   → materials → assignments → todos → calendar → reminders
//   → AI analysis → notifications → progress logging
// ============================================================

require_once __DIR__ . '/google_classroom_service.php';

// Load AI service for RAG indexing (index_file_content)
if (file_exists(__DIR__ . '/ai_service.php')) {
    require_once __DIR__ . '/ai_service.php';
}

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

    // 3. Get current google_account_id (id from google_accounts table)
    $accountRow = gc_get_account($conn, $userId);
    if (!$accountRow) {
        $err = 'No connected Google account found.';
        gc_update_sync_status($conn, $userId, 'error', $err);
        gc_finish_sync_log($conn, $logId, 'failed', $stats, $startTime, $err);
        return ['success' => false, 'error' => $err, 'stats' => $stats];
    }
    $googleAccountId = (int)$accountRow['id'];
    $connectedEmail  = $accountRow['google_email'];
    error_log("[GC] Sync started | user_id: {$userId} | google_account_id: {$googleAccountId} | email: {$connectedEmail}");

    // 4. Get valid access token (auto-refreshes if needed)
    $accessToken = gc_get_valid_token($conn, $userId);
    if (!$accessToken) {
        $err = 'Failed to obtain valid access token. Please reconnect your Google account.';
        gc_update_sync_status($conn, $userId, 'error', $err);
        gc_finish_sync_log($conn, $logId, 'failed', $stats, $startTime, $err);
        return ['success' => false, 'error' => $err, 'stats' => $stats];
    }

    try {
        // 5. Sync Courses & repair mappings
        $courseResult = gc_sync_courses($conn, $userId, $accessToken, $googleAccountId);
        $stats['courses'] = $courseResult['synced'];
        $stats['errors'] += $courseResult['errors'];
        if (!empty($courseResult['error_details'])) {
            $stats['error_details'] = array_merge($stats['error_details'], $courseResult['error_details']);
        }

        error_log("[GC] Synced course ids: " . implode(', ', $courseResult['synced_ids'] ?? []));

        // Ensure courses & folders are linked properly (MUST run before material sync)
        gc_repair_and_link_courses($conn, $userId, $googleAccountId);

        // 6. Sync Topics & Folders for each course
        $gcCourses = gc_get_synced_courses($conn, $userId, $googleAccountId);
        foreach ($gcCourses as $gc) {
            // Topics
            $topicResult = gc_sync_topics($conn, $userId, $accessToken, $gc, $googleAccountId);
            $stats['topics'] += $topicResult['synced'];
            $stats['errors'] += $topicResult['errors'];

            // Materials (course work materials + coursework attachments)
            $matResult = gc_sync_materials($conn, $userId, $accessToken, $gc, $googleAccountId);
            $stats['files'] += $matResult['synced'];
            $stats['errors'] += $matResult['errors'];

            // Assignments → Todos + Calendar
            $asgResult = gc_sync_assignments($conn, $userId, $accessToken, $gc, $googleAccountId);
            $stats['assignments'] += $asgResult['synced'];
            $stats['errors'] += $asgResult['errors'];

            if (!empty($matResult['error_details'])) {
                $stats['error_details'] = array_merge($stats['error_details'], $matResult['error_details']);
            }
            if (!empty($asgResult['error_details'])) {
                $stats['error_details'] = array_merge($stats['error_details'], $asgResult['error_details']);
            }
        }

        // 7. Generate reminders for new assignments
        if (file_exists(__DIR__ . '/google_reminder_engine.php')) {
            require_once __DIR__ . '/google_reminder_engine.php';
            gc_generate_reminders($conn, $userId);
        }

        // 8. Trigger AI analysis for new assignments (if time permits)
        if (file_exists(__DIR__ . '/google_ai_analyzer.php') && (microtime(true) - $startTime) < 15) {
            require_once __DIR__ . '/google_ai_analyzer.php';
            @gc_analyze_new_assignments($conn, $userId);
        }

        // 9. Generate sync completion notification
        $msg = "✅ Google Classroom sync completed: {$stats['courses']} courses, {$stats['topics']} topics, {$stats['files']} files, {$stats['assignments']} assignments synced.";
        gc_create_notification($conn, $userId, $msg);

        // 10. Log progress event
        if (function_exists('logProgress')) {
            logProgress($conn, $userId, 'file_upload', 'Google Classroom sync: ' . $stats['files'] . ' files synced');
        }

        // 11. Update status
        gc_update_sync_status($conn, $userId, 'idle');
        gc_finish_sync_log($conn, $logId, 'completed', $stats, $startTime);

        error_log("[GC] Sync completed | user_id: {$userId} | google_account_id: {$googleAccountId} | courses: {$stats['courses']} | files: {$stats['files']}");
        return ['success' => true, 'stats' => $stats];

    } catch (\Throwable $e) {
        $err = 'Sync error: ' . $e->getMessage();
        gc_update_sync_status($conn, $userId, 'error', $err);
        gc_finish_sync_log($conn, $logId, 'failed', $stats, $startTime, $err);
        return ['success' => false, 'error' => $err, 'stats' => $stats];
    }
}


// ── COURSE SYNC ──────────────────────────────────────────────

function gc_sync_courses(mysqli $conn, int $userId, string $accessToken, int $googleAccountId): array {
    $result = ['synced' => 0, 'errors' => 0, 'error_details' => [], 'synced_ids' => []];

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

            // Check if already synced for THIS google_account_id
            $chk = $conn->prepare("SELECT id, course_id FROM google_courses WHERE user_id = ? AND google_course_id = ? AND google_account_id = ?");
            $chk->bind_param('isi', $userId, $googleCourseId, $googleAccountId);
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
                $result['synced_ids'][] = $googleCourseId;
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

            // Insert/update google_courses mapping — always include google_account_id
            if ($exists) {
                $upd = $conn->prepare("UPDATE google_courses SET course_id=?, root_folder_id=?, course_name=?, section=?, description=?, course_code=?, course_state=?, google_account_id=?, last_synced_at=NOW() WHERE id=?");
                $upd->bind_param('iisssssii', $nnCourseId, $rootFolderId, $courseName, $section, $description, $courseCode, $courseState, $googleAccountId, $gcId);
                $upd->execute();
                $upd->close();
            } else {
                $ins = $conn->prepare(
                    "INSERT INTO google_courses (user_id, google_account_id, google_course_id, course_id, root_folder_id, course_name, section, description, course_code, course_state, last_synced_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,NOW())"
                );
                $ins->bind_param('iisisssssss', $userId, $googleAccountId, $googleCourseId, $nnCourseId, $rootFolderId, $courseName, $section, $description, $courseCode, $courseState);
                $ins->execute();
                $ins->close();
            }

            $result['synced']++;
            $result['synced_ids'][] = $googleCourseId;
        } catch (\Throwable $e) {
            $result['errors']++;
            $result['error_details'][] = 'Course sync: ' . $e->getMessage();
        }
    }

    return $result;
}


// ── TOPIC SYNC ───────────────────────────────────────────────

function gc_sync_topics(mysqli $conn, int $userId, string $accessToken, array $gc, int $googleAccountId): array {
    $result = ['synced' => 0, 'errors' => 0, 'error_details' => []];

    // Ensure root course folder and course_id exist before syncing topics
    $gc = gc_ensure_course_folder($conn, $userId, $gc);

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
            $topicName     = trim($topic['name'] ?? 'Untitled Topic');
            if ($topicName === '') $topicName = 'Untitled Topic';
            $sortOrder++;

            $topicId  = null;
            $folderId = null;
            $gtId     = null;

            // Check if google_topics row already exists
            $chk = $conn->prepare("SELECT id, topic_id, folder_id FROM google_topics WHERE user_id = ? AND google_course_id = ? AND google_topic_id = ?");
            $chk->bind_param('iss', $userId, $gc['google_course_id'], $googleTopicId);
            $chk->execute();
            $chk->bind_result($gtId, $existingTopicId, $existingFolderId);
            $exists = $chk->fetch();
            $chk->close();

            if ($exists) {
                $topicId  = $existingTopicId  ? (int)$existingTopicId  : null;
                $folderId = $existingFolderId ? (int)$existingFolderId : null;
            }

            // Ensure course_topic exists in course_topics table
            if (!empty($gc['course_id'])) {
                $courseIdInt = (int)$gc['course_id'];
                if ($topicId) {
                    // Verify course_topics record exists
                    $tCheck = $conn->prepare("SELECT id FROM course_topics WHERE id = ? AND course_id = ?");
                    $tCheck->bind_param('ii', $topicId, $courseIdInt);
                    $tCheck->execute();
                    if (!$tCheck->fetch()) {
                        $topicId = null; // Record was lost/deleted, recreate it
                    }
                    $tCheck->close();
                }

                if (!$topicId) {
                    // Check if topic exists by name for this course
                    $tNameCheck = $conn->prepare("SELECT id FROM course_topics WHERE course_id = ? AND title = ?");
                    $tNameCheck->bind_param('is', $courseIdInt, $topicName);
                    $tNameCheck->execute();
                    $tNameCheck->bind_result($foundTopicId);
                    if ($tNameCheck->fetch()) {
                        $topicId = (int)$foundTopicId;
                    }
                    $tNameCheck->close();
                }

                if (!$topicId) {
                    $tIns = $conn->prepare("INSERT INTO course_topics (course_id, title, sort_order) VALUES (?,?,?)");
                    $tIns->bind_param('isi', $courseIdInt, $topicName, $sortOrder);
                    $tIns->execute();
                    $topicId = (int)$conn->insert_id;
                    $tIns->close();
                } else {
                    $tUpd = $conn->prepare("UPDATE course_topics SET title = ?, sort_order = ? WHERE id = ?");
                    $tUpd->bind_param('sii', $topicName, $sortOrder, $topicId);
                    $tUpd->execute();
                    $tUpd->close();
                }

                // Ensure topic subfolder exists under course root folder
                if (!empty($gc['root_folder_id'])) {
                    $parentId = (int)$gc['root_folder_id'];
                    
                    if ($folderId) {
                        // Verify folder exists
                        $fCheck = $conn->prepare("SELECT id FROM folders WHERE id = ? AND owner_id = ?");
                        $fCheck->bind_param('ii', $folderId, $userId);
                        $fCheck->execute();
                        if (!$fCheck->fetch()) {
                            $folderId = null;
                        }
                        $fCheck->close();
                    }

                    if (!$folderId) {
                        // Check if a subfolder with this topic name exists under course root
                        $fNameCheck = $conn->prepare("SELECT id FROM folders WHERE owner_id = ? AND course_id = ? AND parent_folder_id = ? AND name = ?");
                        $fNameCheck->bind_param('iiis', $userId, $courseIdInt, $parentId, $topicName);
                        $fNameCheck->execute();
                        $fNameCheck->bind_result($foundFolderId);
                        if ($fNameCheck->fetch()) {
                            $folderId = (int)$foundFolderId;
                        }
                        $fNameCheck->close();
                    }

                    if (!$folderId) {
                        $fIns = $conn->prepare("INSERT INTO folders (owner_id, course_id, name, parent_folder_id) VALUES (?,?,?,?)");
                        $fIns->bind_param('iisi', $userId, $courseIdInt, $topicName, $parentId);
                        $fIns->execute();
                        $folderId = (int)$conn->insert_id;
                        $fIns->close();
                    } else {
                        $fUpd = $conn->prepare("UPDATE folders SET name = ?, parent_folder_id = ?, course_id = ? WHERE id = ? AND owner_id = ?");
                        $fUpd->bind_param('siiii', $topicName, $parentId, $courseIdInt, $folderId, $userId);
                        $fUpd->execute();
                        $fUpd->close();
                    }

                    // Link folder to course_topic
                    if ($topicId && $folderId) {
                        $upd = $conn->prepare("UPDATE course_topics SET folder_id = ? WHERE id = ?");
                        $upd->bind_param('ii', $folderId, $topicId);
                        $upd->execute();
                        $upd->close();
                    }
                }
            }

            // Save/Update google_topics mapping — always include google_account_id
            if ($exists && $gtId) {
                $gtUpd = $conn->prepare("UPDATE google_topics SET topic_id = ?, folder_id = ?, topic_name = ?, sort_order = ?, google_account_id = ? WHERE id = ?");
                $gtUpd->bind_param('iisiii', $topicId, $folderId, $topicName, $sortOrder, $googleAccountId, $gtId);
                $gtUpd->execute();
                $gtUpd->close();
            } else {
                $ins = $conn->prepare("INSERT INTO google_topics (user_id, google_account_id, google_course_id, google_topic_id, topic_id, folder_id, topic_name, sort_order) VALUES (?,?,?,?,?,?,?,?)");
                $ins->bind_param('iissiiis', $userId, $googleAccountId, $gc['google_course_id'], $googleTopicId, $topicId, $folderId, $topicName, $sortOrder);
                $ins->execute();
                $ins->close();
            }

            $result['synced']++;
        } catch (\Throwable $e) {
            $result['errors']++;
            $result['error_details'][] = 'Topic sync: ' . $e->getMessage();
        }
    }

    return $result;
}


// ── MATERIAL SYNC (files download) ──────────────────────────

function gc_sync_materials(mysqli $conn, int $userId, string $accessToken, array $gc, int $googleAccountId): array {
    $result = ['synced' => 0, 'errors' => 0, 'error_details' => []];

    // ── CRITICAL: Ensure root folder exists before any downloads ──
    // Re-fetch fresh gc data including root_folder_id
    $gcFresh = gc_ensure_course_folder($conn, $userId, $gc);
    $gc = $gcFresh; // Use updated gc with guaranteed root_folder_id

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
        try {
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
                $fileUrl  = $att['youtubeVideo']['alternateLink'] ?? '';
                $title    = 'YouTube Video - ' . ($att['youtubeVideo']['title'] ?? 'Video');
                $fileId   = 'yt_' . md5($fileUrl);
                $mimeType = 'video/youtube';
            } elseif (isset($att['link'])) {
                $fileUrl  = $att['link']['url'] ?? '';
                $title    = 'Link - ' . ($att['link']['title'] ?? $fileUrl);
                $fileId   = 'link_' . md5($fileUrl);
                $mimeType = 'text/html';
            } elseif (isset($att['form'])) {
                $fileUrl  = $att['form']['formUrl'] ?? '';
                $title    = 'Google Form - ' . ($att['form']['title'] ?? 'Form');
                $fileId   = 'form_' . md5($fileUrl);
                $mimeType = 'text/html';
            }

            if (!$fileId) continue;

            // Resolve target folder and topic ID from google_topics
            $targetFolderId = !empty($gc['root_folder_id']) ? (int)$gc['root_folder_id'] : null;
            $nnTopicId      = null;
            $courseIdInt    = !empty($gc['course_id']) ? (int)$gc['course_id'] : null;

            if (!empty($item['topicId'])) {
                $tf = $conn->prepare("SELECT folder_id, topic_id FROM google_topics WHERE user_id = ? AND google_course_id = ? AND google_topic_id = ?");
                $tf->bind_param('iss', $userId, $gc['google_course_id'], $item['topicId']);
                $tf->execute();
                $tf->bind_result($topicFolderId, $foundTopicId);
                if ($tf->fetch()) {
                    if ($topicFolderId) $targetFolderId = (int)$topicFolderId;
                    if ($foundTopicId)  $nnTopicId      = (int)$foundTopicId;
                }
                $tf->close();
            }

            // Check if already successfully downloaded/linked
            $chk = $conn->prepare("SELECT id, file_id, folder_id, topic_id FROM google_files WHERE user_id = ? AND google_file_id = ? AND download_status IN ('downloaded', 'linked')");
            $chk->bind_param('is', $userId, $fileId);
            $chk->execute();
            $gtfRow = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($gtfRow) {
                $gfId     = (int)$gtfRow['id'];
                $nnFileId = $gtfRow['file_id'] ? (int)$gtfRow['file_id'] : null;

                // Move file to topic subfolder & update topic tag if it was previously saved without topic info
                if (($targetFolderId && $gtfRow['folder_id'] != $targetFolderId) || ($nnTopicId && $gtfRow['topic_id'] != $nnTopicId)) {
                    $updGf = $conn->prepare("UPDATE google_files SET folder_id = ?, topic_id = ?, course_id = ? WHERE id = ?");
                    $updGf->bind_param('iiii', $targetFolderId, $nnTopicId, $courseIdInt, $gfId);
                    $updGf->execute();
                    $updGf->close();

                    if ($nnFileId) {
                        $updF = $conn->prepare("UPDATE files SET folder_id = ?, course_id = ? WHERE id = ? AND owner_id = ?");
                        $updF->bind_param('iiii', $targetFolderId, $courseIdInt, $nnFileId, $userId);
                        $updF->execute();
                        $updF->close();

                        if ($nnTopicId && $courseIdInt) {
                            $tag = $conn->prepare("INSERT INTO file_course_tags (file_id, course_id, topic_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE topic_id = VALUES(topic_id)");
                            $tag->bind_param('iii', $nnFileId, $courseIdInt, $nnTopicId);
                            $tag->execute();
                            $tag->close();
                        }
                    }
                }
                continue;
            }

            // Clean up any previously failed attempts so new insert/update succeeds
            $del = $conn->prepare("DELETE FROM google_files WHERE user_id = ? AND google_file_id = ? AND download_status NOT IN ('downloaded', 'linked')");
            $del->bind_param('is', $userId, $fileId);
            $del->execute();
            $del->close();

            // For Drive files: attempt download
            $dl = ['success' => false];
            if (isset($att['driveFile'])) {
                if (!$mimeType && $fileId) {
                    $meta = gc_get_drive_file_info($accessToken, $fileId);
                    $mimeType = $meta['mimeType'] ?? '';
                    if (empty($title) || $title === 'Drive File') {
                        $title = $meta['name'] ?? $title;
                    }
                }
                $dl = gc_download_drive_file($accessToken, $fileId, $mimeType, $title);
            }

            $downloadStatus = 'pending';
            $errorMsg       = null;
            $nnFileId       = null;
            $courseIdInt    = $gc['course_id'] ? (int)$gc['course_id'] : null;

            if ($dl['success']) {
                // Save binary file to uploads/notes/
                $safeName   = preg_replace('/[^a-zA-Z0-9._-]/', '_', $dl['filename']);
                $uniqueName = time() . '_' . mt_rand(1000, 9999) . '_' . $safeName;
                $filePath   = 'uploads/notes/' . $uniqueName;
                $absPath    = __DIR__ . '/../' . $filePath;

                if (!is_dir(dirname($absPath))) {
                    mkdir(dirname($absPath), 0755, true);
                }

                if (file_put_contents($absPath, $dl['content'])) {
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

                    $downloadStatus = 'downloaded';
                    $result['synced']++;
                } else {
                    $downloadStatus = 'failed';
                    $errorMsg = 'Failed to write file to disk';
                    $result['errors']++;
                }
            } else {
                // Fallback for failed Drive download, YouTube, Forms, or external links:
                // Create a shortcut HTML link file in uploads/notes/ so item is ALWAYS available in My NoteNest!
                $linkTargetUrl = $fileUrl ?: ("https://drive.google.com/file/d/" . urlencode($fileId) . "/view");
                $safeTitle    = preg_replace('/[^a-zA-Z0-9._-]/', '_', $title);
                $uniqueName   = time() . '_' . mt_rand(1000, 9999) . '_' . $safeTitle . '.html';
                $filePath     = 'uploads/notes/' . $uniqueName;
                $absPath      = __DIR__ . '/../' . $filePath;

                if (!is_dir(dirname($absPath))) {
                    mkdir(dirname($absPath), 0755, true);
                }

                $linkContent = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($linkTargetUrl) . '"><title>' . htmlspecialchars($title) . '</title></head><body><p>Redirecting to <a href="' . htmlspecialchars($linkTargetUrl) . '">' . htmlspecialchars($title) . '</a>...</p><script>window.location.href="' . addslashes($linkTargetUrl) . '";</script></body></html>';

                if (file_put_contents($absPath, $linkContent)) {
                    $linkMime = 'text/html';
                    if ($targetFolderId !== null) {
                        $fIns = $conn->prepare("INSERT INTO files (folder_id, owner_id, course_id, name, file_path, mime_type) VALUES (?,?,?,?,?,?)");
                        $fIns->bind_param('iiisss', $targetFolderId, $userId, $courseIdInt, $title, $filePath, $linkMime);
                    } else {
                        $fIns = $conn->prepare("INSERT INTO files (owner_id, course_id, name, file_path, mime_type) VALUES (?,?,?,?,?)");
                        $fIns->bind_param('iisss', $userId, $courseIdInt, $title, $filePath, $linkMime);
                    }
                    $fIns->execute();
                    $nnFileId = $conn->insert_id;
                    $fIns->close();

                    $downloadStatus = 'downloaded';
                    $result['synced']++;
                } else {
                    $downloadStatus = 'failed';
                    $errorMsg = $dl['error'] ?? 'Could not create link file';
                    $result['errors']++;
                }
            }

            // Tag file to course and topic in file_course_tags
            if ($courseIdInt && $nnFileId) {
                if ($nnTopicId) {
                    $nnTopicIdInt = (int)$nnTopicId;
                    $tag = $conn->prepare("INSERT IGNORE INTO file_course_tags (file_id, course_id, topic_id) VALUES (?,?,?)");
                    $tag->bind_param('iii', $nnFileId, $courseIdInt, $nnTopicIdInt);
                } else {
                    $tag = $conn->prepare("INSERT IGNORE INTO file_course_tags (file_id, course_id) VALUES (?,?)");
                    $tag->bind_param('ii', $nnFileId, $courseIdInt);
                }
                $tag->execute();
                $tag->close();
            }

            if ($downloadStatus === 'downloaded') {
                gc_create_notification($conn, $userId, "📄 New material synced: {$title}");
                if (function_exists('logProgress')) {
                    logProgress($conn, $userId, 'file_upload', "Google Classroom: {$title}", $courseIdInt ?? 0);
                }
            }

            // Record in google_files — always include google_account_id
            $fileType     = pathinfo($title, PATHINFO_EXTENSION) ?: 'unknown';
            $materialId   = $item['sourceId'];
            $errorMsgSafe = $errorMsg ?? '';

            $gfSql = "INSERT INTO google_files (user_id, google_account_id, google_course_id, google_file_id, google_material_id, file_id, folder_id, course_id, topic_id, file_title, file_type, mime_type, file_url, download_status, error_message) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $gfIns = $conn->prepare($gfSql);
            $gfIns->bind_param('iisssiiiissssss',
                $userId, $googleAccountId, $gc['google_course_id'], $fileId, $materialId,
                $nnFileId, $targetFolderId, $courseIdInt, $nnTopicId,
                $title, $fileType, $mimeType, $fileUrl,
                $downloadStatus, $errorMsgSafe
            );
            $gfIns->execute();
            $gfIns->close();

        } catch (\Throwable $e) {
            $result['errors']++;
            $result['error_details'][] = 'Material sync error: ' . $e->getMessage();
        }
    }

    // After all downloads: repair any orphaned files for this course
    if (!empty($gc['course_id']) && !empty($gc['root_folder_id'])) {
        $courseIdInt = (int)$gc['course_id'];
        $rootFld     = (int)$gc['root_folder_id'];
        // Attach files without folder_id to root folder
        $upd = $conn->prepare("UPDATE files SET folder_id=? WHERE owner_id=? AND course_id=? AND (folder_id IS NULL OR folder_id=0)");
        $upd->bind_param('iii', $rootFld, $userId, $courseIdInt);
        $upd->execute();
        $upd->close();
        // Ensure file_course_tags for files that may have been missed
        $missingTags = $conn->prepare(
            "INSERT IGNORE INTO file_course_tags (file_id, course_id)
             SELECT f.id, ? FROM files f
             LEFT JOIN file_course_tags fct ON fct.file_id=f.id AND fct.course_id=?
             WHERE f.owner_id=? AND f.course_id=? AND fct.file_id IS NULL"
        );
        $missingTags->bind_param('iiii', $courseIdInt, $courseIdInt, $userId, $courseIdInt);
        $missingTags->execute();
        $missingTags->close();
    }

    return $result;
}

/**
 * Ensure a NoteNest course and root folder exist for a google_course.
 * Returns the updated $gc array with course_id and root_folder_id filled.
 */
function gc_ensure_course_folder(mysqli $conn, int $userId, array $gc): array {
    $googleCourseId = $gc['google_course_id'];
    $courseName     = $gc['course_name'] ?? 'Untitled Course';

    // Re-fetch from DB to get latest values
    $stmt = $conn->prepare("SELECT id, course_id, root_folder_id FROM google_courses WHERE user_id=? AND google_course_id=?");
    $stmt->bind_param('is', $userId, $googleCourseId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $gc['course_id']     = $row['course_id'];
        $gc['root_folder_id'] = $row['root_folder_id'];
    }

    $gcId         = $row['id'] ?? null;
    $nnCourseId   = $gc['course_id'] ? (int)$gc['course_id'] : null;
    $rootFolderId = $gc['root_folder_id'] ? (int)$gc['root_folder_id'] : null;

    // Create NoteNest course if missing
    if (!$nnCourseId) {
        $safeCode = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $courseName), 0, 15));
        if (empty($safeCode)) $safeCode = 'GC' . substr($googleCourseId, 0, 8);

        // Try to find existing
        $cchk = $conn->prepare("SELECT id FROM courses WHERE user_id=? AND (code=? OR name=?)");
        $cchk->bind_param('iss', $userId, $safeCode, $courseName);
        $cchk->execute();
        $cchk->bind_result($foundCourseId);
        if ($cchk->fetch()) $nnCourseId = (int)$foundCourseId;
        $cchk->close();

        if (!$nnCourseId) {
            $color = '#4285f4';
            $desc  = 'Synced from Google Classroom';
            $cIns  = $conn->prepare("INSERT INTO courses (user_id, name, code, description, color) VALUES (?,?,?,?,?)");
            $cIns->bind_param('issss', $userId, $courseName, $safeCode, $desc, $color);
            $cIns->execute();
            $nnCourseId = (int)$conn->insert_id;
            $cIns->close();
        }
        $gc['course_id'] = $nnCourseId;
    }

    // Create root folder if missing
    if ($nnCourseId && !$rootFolderId) {
        // Try to find existing root folder
        $rfq = $conn->prepare("SELECT id FROM folders WHERE course_id=? AND owner_id=? LIMIT 1");
        $rfq->bind_param('ii', $nnCourseId, $userId);
        $rfq->execute();
        $rfq->bind_result($foundFolderId);
        if ($rfq->fetch()) $rootFolderId = (int)$foundFolderId;
        $rfq->close();

        if (!$rootFolderId) {
            $fIns = $conn->prepare("INSERT INTO folders (owner_id, course_id, is_course_root, name) VALUES (?,?,1,?)");
            $fIns->bind_param('iis', $userId, $nnCourseId, $courseName);
            $fIns->execute();
            $rootFolderId = (int)$conn->insert_id;
            $fIns->close();
        }
        $gc['root_folder_id'] = $rootFolderId;
    }

    // Update google_courses with new ids
    if ($gcId && ($nnCourseId || $rootFolderId)) {
        $upd = $conn->prepare("UPDATE google_courses SET course_id=?, root_folder_id=? WHERE id=?");
        $upd->bind_param('iii', $nnCourseId, $rootFolderId, $gcId);
        $upd->execute();
        $upd->close();
    }

    return $gc;
}


// ── ASSIGNMENT SYNC ──────────────────────────────────────────

function gc_sync_assignments(mysqli $conn, int $userId, string $accessToken, array $gc, int $googleAccountId): array {
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

            // Insert google_assignments mapping — always include google_account_id
            $todoIdSafe     = $todoId          ? (int)$todoId          : 0;
            $calEvtIdSafe   = $calendarEventId ? (int)$calendarEventId : 0;
            $dueDateSafe    = $dueDate ?? '';
            $dueTimeSafe    = $dueTime ?? '';

            $ins = $conn->prepare(
                "INSERT INTO google_assignments (user_id, google_account_id, google_course_id, google_coursework_id, todo_id, calendar_event_id, course_id, title, description, due_date, due_time, max_points, work_type, state)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $ins->bind_param('iissiiissssdss',
                $userId, $googleAccountId, $gc['google_course_id'], $cwId, $todoIdSafe, $calEvtIdSafe, $courseIdInt,
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

function gc_get_synced_courses(mysqli $conn, int $userId, int $googleAccountId = 0): array {
    if ($googleAccountId > 0) {
        $stmt = $conn->prepare("SELECT id, google_course_id, course_id, root_folder_id, course_name FROM google_courses WHERE user_id = ? AND google_account_id = ?");
        $stmt->bind_param('ii', $userId, $googleAccountId);
    } else {
        // Fallback: no account id filter (legacy)
        $stmt = $conn->prepare("SELECT id, google_course_id, course_id, root_folder_id, course_name FROM google_courses WHERE user_id = ?");
        $stmt->bind_param('i', $userId);
    }
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
function gc_get_sync_stats(mysqli $conn, int $userId, int $googleAccountId = 0): array {
    $stats = [
        'total_courses'     => 0,
        'total_topics'      => 0,
        'total_files'       => 0,
        'total_assignments' => 0,
        'pending_assignments' => 0,
        'downloaded_files'  => 0,
    ];

    $accountFilter = ($googleAccountId > 0) ? " AND google_account_id = {$googleAccountId}" : '';

    $r = $conn->query("SELECT COUNT(*) FROM google_courses WHERE user_id = {$userId}{$accountFilter}");
    $stats['total_courses'] = (int)$r->fetch_row()[0];

    $r = $conn->query("SELECT COUNT(*) FROM google_topics WHERE user_id = {$userId}{$accountFilter}");
    $stats['total_topics'] = (int)$r->fetch_row()[0];

    $r = $conn->query("SELECT COUNT(*) FROM google_files WHERE user_id = {$userId}{$accountFilter}");
    $stats['total_files'] = (int)$r->fetch_row()[0];

    $r = $conn->query("SELECT COUNT(*) FROM google_files WHERE user_id = {$userId} AND download_status = 'downloaded'{$accountFilter}");
    $stats['downloaded_files'] = (int)$r->fetch_row()[0];

    $r = $conn->query("SELECT COUNT(*) FROM google_assignments WHERE user_id = {$userId}{$accountFilter}");
    $stats['total_assignments'] = (int)$r->fetch_row()[0];

    $r = $conn->query("SELECT COUNT(*) FROM google_assignments ga JOIN todos t ON ga.todo_id = t.id WHERE ga.user_id = {$userId} AND t.status = 'pending' AND ga.due_date >= CURDATE(){$accountFilter}");
    $stats['pending_assignments'] = (int)$r->fetch_row()[0];

    return $stats;
}

/**
 * Auto-repair & link google_courses to NoteNest courses and root folders,
 * and attach any orphaned files to course root folders.
 */
function gc_repair_and_link_courses(mysqli $conn, int $userId, int $googleAccountId = 0): void {
    try {
        if (function_exists('db_reconnect')) @db_reconnect($conn);

        $userId = (int)$userId;
        if ($userId <= 0) return;
        $accountFilter = ($googleAccountId > 0) ? " AND gc.google_account_id = {$googleAccountId}" : '';

        // 1. Create missing courses in `courses` table for synced google_courses
        $conn->query("
            INSERT INTO courses (user_id, name, code, description, color)
            SELECT gc.user_id, gc.course_name,
                   UPPER(SUBSTRING(gc.course_name, 1, 15)),
                   CONCAT('Synced from Google Classroom: ', gc.course_name), '#4285f4'
            FROM google_courses gc
            LEFT JOIN courses c ON (c.user_id = gc.user_id AND c.name = gc.course_name)
            WHERE gc.user_id = {$userId}{$accountFilter} AND c.id IS NULL
            GROUP BY gc.id
        ");

        // Link google_courses to courses table
        $conn->query("
            UPDATE google_courses gc
            JOIN courses c ON (c.user_id = gc.user_id AND c.name = gc.course_name)
            SET gc.course_id = c.id
            WHERE gc.user_id = {$userId}{$accountFilter} AND (gc.course_id IS NULL OR gc.course_id = 0)
        ");

        // 2. Create missing root folders in `folders` table for courses
        $conn->query("
            INSERT INTO folders (owner_id, course_id, is_course_root, name, parent_folder_id)
            SELECT gc.user_id, gc.course_id, 1, gc.course_name, NULL
            FROM google_courses gc
            LEFT JOIN folders f ON (f.owner_id = gc.user_id AND f.course_id = gc.course_id AND f.is_course_root = 1)
            WHERE gc.user_id = {$userId}{$accountFilter} AND gc.course_id IS NOT NULL AND f.id IS NULL
            GROUP BY gc.id
        ");

        // Link google_courses to root folders
        $conn->query("
            UPDATE google_courses gc
            JOIN folders f ON (f.owner_id = gc.user_id AND f.course_id = gc.course_id AND f.is_course_root = 1)
            SET gc.root_folder_id = f.id
            WHERE gc.user_id = {$userId}{$accountFilter} AND (gc.root_folder_id IS NULL OR gc.root_folder_id = 0)
        ");

        // 3. Repair missing course_topics and topic subfolders for google_topics
        $gtRes = $conn->query("
            SELECT gt.id, gt.google_course_id, gt.google_topic_id, gt.topic_name, gt.sort_order,
                   gc.course_id, gc.root_folder_id
            FROM google_topics gt
            JOIN google_courses gc ON (gc.google_course_id = gt.google_course_id AND gc.user_id = gt.user_id)
            WHERE gt.user_id = {$userId}
        ");

        if ($gtRes) {
            while ($gt = $gtRes->fetch_assoc()) {
                $gtId         = (int)$gt['id'];
                $courseIdInt  = $gt['course_id'] ? (int)$gt['course_id'] : 0;
                $rootFolderId = $gt['root_folder_id'] ? (int)$gt['root_folder_id'] : 0;
                $topicName    = trim($gt['topic_name'] ?: 'Untitled Topic');
                $sortOrder    = (int)($gt['sort_order'] ?: 1);

                if (!$courseIdInt) continue;

                // Check or create course_topics
                $tCheck = $conn->prepare("SELECT id FROM course_topics WHERE course_id = ? AND title = ?");
                $tCheck->bind_param('is', $courseIdInt, $topicName);
                $tCheck->execute();
                $tCheck->bind_result($foundTopicId);
                if ($tCheck->fetch()) {
                    $topicId = (int)$foundTopicId;
                } else {
                    $topicId = 0;
                }
                $tCheck->close();

                if (!$topicId) {
                    $tIns = $conn->prepare("INSERT INTO course_topics (course_id, title, sort_order) VALUES (?,?,?)");
                    $tIns->bind_param('isi', $courseIdInt, $topicName, $sortOrder);
                    $tIns->execute();
                    $topicId = (int)$conn->insert_id;
                    $tIns->close();
                }

                // Check or create folder under root_folder_id
                $folderId = 0;
                if ($rootFolderId) {
                    $fCheck = $conn->prepare("SELECT id FROM folders WHERE owner_id = ? AND course_id = ? AND parent_folder_id = ? AND name = ?");
                    $fCheck->bind_param('iiis', $userId, $courseIdInt, $rootFolderId, $topicName);
                    $fCheck->execute();
                    $fCheck->bind_result($foundFolderId);
                    if ($fCheck->fetch()) {
                        $folderId = (int)$foundFolderId;
                    }
                    $fCheck->close();

                    if (!$folderId) {
                        $fIns = $conn->prepare("INSERT INTO folders (owner_id, course_id, name, parent_folder_id) VALUES (?,?,?,?)");
                        $fIns->bind_param('iisi', $userId, $courseIdInt, $topicName, $rootFolderId);
                        $fIns->execute();
                        $folderId = (int)$conn->insert_id;
                        $fIns->close();
                    }

                    if ($topicId && $folderId) {
                        $updT = $conn->prepare("UPDATE course_topics SET folder_id = ? WHERE id = ?");
                        $updT->bind_param('ii', $folderId, $topicId);
                        $updT->execute();
                        $updT->close();
                    }
                }

                // Update google_topics with repaired topic_id and folder_id
                $updGt = $conn->prepare("UPDATE google_topics SET topic_id = ?, folder_id = ? WHERE id = ?");
                $updGt->bind_param('iii', $topicId, $folderId, $gtId);
                $updGt->execute();
                $updGt->close();
            }
        }

        // 4. Link google_files to topic_id, folder_id, and course_id
        $conn->query("
            UPDATE google_files gf
            JOIN google_courses gc ON (gc.google_course_id = gf.google_course_id AND gc.user_id = gf.user_id)
            LEFT JOIN google_topics gt ON (gt.user_id = gf.user_id AND gt.google_course_id = gf.google_course_id AND gt.topic_name != '')
            SET gf.course_id = gc.course_id,
                gf.folder_id = COALESCE(gf.folder_id, gt.folder_id, gc.root_folder_id),
                gf.topic_id  = COALESCE(gf.topic_id, gt.topic_id)
            WHERE gf.user_id = {$userId} AND gc.course_id IS NOT NULL
        ");

        // 5. Link files table to course_id and folder_id via google_files
        $conn->query("
            UPDATE files f
            JOIN google_files gf ON gf.file_id = f.id
            JOIN google_courses gc ON (gc.google_course_id = gf.google_course_id AND gc.user_id = gf.user_id)
            SET f.course_id = gc.course_id,
                f.folder_id = COALESCE(gf.folder_id, f.folder_id, gc.root_folder_id)
            WHERE gf.user_id = {$userId} AND gc.course_id IS NOT NULL
        ");

        // 6. Tag files in file_course_tags table with course_id and topic_id
        $conn->query("
            INSERT INTO file_course_tags (file_id, course_id, topic_id)
            SELECT gf.file_id, gf.course_id, gf.topic_id
            FROM google_files gf
            WHERE gf.user_id = {$userId} AND gf.file_id IS NOT NULL AND gf.course_id IS NOT NULL AND gf.course_id > 0
            ON DUPLICATE KEY UPDATE topic_id = COALESCE(VALUES(topic_id), topic_id)
        ");

    } catch (\Throwable $e) {
        error_log("gc_repair_and_link_courses error: " . $e->getMessage());
    }
}

// ── DASHBOARD DATA HELPER ─────────────────────────────────────

/**
 * Fetch all Google Classroom data needed for the main dashboard.
 * Returns a single consolidated array to keep dashboard.php clean.
 *
 * @param  mysqli $conn
 * @param  int    $userId
 * @param  string $lastVisit   ISO datetime of last dashboard visit (for "new files" badge)
 * @return array {
 *   stats, recent_courses, recent_files, upcoming_assignments,
 *   recent_announcements, new_files_count
 * }
 */
function gc_get_dashboard_data(mysqli $conn, int $userId, string $lastVisit = ''): array {

    // ── Stats ─────────────────────────────────────────────────
    $stats = gc_get_sync_stats($conn, $userId);

    // ── Recent Courses (last 5 synced) ───────────────────────
    $recent_courses = [];
    $stmt = $conn->prepare(
        "SELECT google_course_id, course_name, section, teacher_name, course_id, last_synced_at
         FROM google_courses
         WHERE user_id = ?
         ORDER BY COALESCE(last_synced_at, created_at) DESC
         LIMIT 5"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $recent_courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ── Recent Files (last 8, with course + topic names) ─────
    $recent_files = [];
    $stmt = $conn->prepare(
        "SELECT gf.id, gf.file_title, gf.file_type, gf.mime_type, gf.file_url,
                gf.download_status, gf.created_at, gf.file_id,
                gc.course_name, gc.google_course_id,
                gt.topic_name,
                f.file_path
         FROM google_files gf
         LEFT JOIN google_courses gc ON (gf.google_course_id = gc.google_course_id AND gf.user_id = gc.user_id)
         LEFT JOIN google_topics  gt ON (gf.topic_id = gt.topic_id)
         LEFT JOIN files          f  ON (gf.file_id  = f.id)
         WHERE gf.user_id = ?
         ORDER BY gf.created_at DESC
         LIMIT 8"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $recent_files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ── Upcoming Assignments (next 5 by due_date) ─────────────
    $upcoming_assignments = [];
    $stmt = $conn->prepare(
        "SELECT ga.id, ga.title, ga.due_date, ga.due_time, ga.max_points, ga.work_type,
                gc.course_name, gc.google_course_id,
                t.status AS todo_status
         FROM google_assignments ga
         JOIN  google_courses gc ON (ga.google_course_id = gc.google_course_id AND ga.user_id = gc.user_id)
         LEFT JOIN todos t ON ga.todo_id = t.id
         WHERE ga.user_id = ?
           AND (ga.due_date >= CURDATE() OR ga.due_date IS NULL)
         ORDER BY ga.due_date ASC
         LIMIT 5"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $upcoming_assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ── Recent Announcements (last 5 from notifications table) ─
    $recent_announcements = [];
    $stmt = $conn->prepare(
        "SELECT id, message, is_read, created_at
         FROM notifications
         WHERE user_id = ? AND message LIKE '%Classroom%'
         ORDER BY created_at DESC
         LIMIT 5"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $recent_announcements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // If no classroom-specific notifications, fall back to last 5 notifications
    if (empty($recent_announcements)) {
        $stmt = $conn->prepare(
            "SELECT id, message, is_read, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 5"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $recent_announcements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    // ── New Files Count (since last visit) ────────────────────
    $new_files_count = 0;
    if ($lastVisit) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM google_files WHERE user_id = ? AND created_at > ?"
        );
        $stmt->bind_param('is', $userId, $lastVisit);
        $stmt->execute();
        $stmt->bind_result($new_files_count);
        $stmt->fetch();
        $stmt->close();
        $new_files_count = (int)$new_files_count;
    }

    // ── GC Activity Table (last 15 rows: course+topic+file+assignment) ─
    $activity_rows = [];
    $stmt = $conn->prepare(
        "SELECT
            gc.course_name,
            gt.topic_name,
            gf.file_title,
            gf.created_at       AS file_upload_date,
            gf.download_status,
            ga.title            AS assignment_title,
            ga.due_date,
            COALESCE(t.status, 'N/A') AS assignment_status
         FROM google_files gf
         LEFT JOIN google_courses     gc ON (gf.google_course_id = gc.google_course_id AND gf.user_id = gc.user_id)
         LEFT JOIN google_topics      gt ON (gf.topic_id = gt.topic_id)
         LEFT JOIN google_assignments ga ON (ga.google_course_id = gf.google_course_id AND ga.user_id = gf.user_id AND ga.due_date >= CURDATE())
         LEFT JOIN todos              t  ON (ga.todo_id = t.id)
         WHERE gf.user_id = ?
         GROUP BY gf.id
         ORDER BY gf.created_at DESC
         LIMIT 15"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $activity_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return compact(
        'stats',
        'recent_courses',
        'recent_files',
        'upcoming_assignments',
        'recent_announcements',
        'new_files_count',
        'activity_rows'
    );
}
?>


