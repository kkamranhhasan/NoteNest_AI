<?php
// ============================================================
// includes/google_classroom_service.php
// NoteNest AI — Google Classroom API Service Layer
// Handles OAuth, token management, and all Classroom/Drive API calls
// ============================================================

/**
 * Encrypt a token string for secure storage.
 */
function gc_encrypt_token(string $token): string {
    $key    = hash('sha256', GOOGLE_CLIENT_SECRET, true);
    $iv     = openssl_random_pseudo_bytes(16);
    $cipher = openssl_encrypt($token, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($iv . '::' . $cipher);
}

/**
 * Decrypt a stored token string.
 */
function gc_decrypt_token(string $encrypted): string {
    $key  = hash('sha256', GOOGLE_CLIENT_SECRET, true);
    $data = base64_decode($encrypted);
    $parts = explode('::', $data, 2);
    if (count($parts) !== 2) return '';
    return openssl_decrypt($parts[1], 'AES-256-CBC', $key, 0, $parts[0]);
}

/**
 * Build the Google OAuth 2.0 authorization URL.
 */
function gc_get_auth_url(): string {
    $params = [
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => APP_URL . '/google_callback.php',
        'response_type' => 'code',
        'scope'         => implode(' ', [
            'openid',
            'email',
            'profile',
            'https://www.googleapis.com/auth/classroom.courses.readonly',
            'https://www.googleapis.com/auth/classroom.topics.readonly',
            'https://www.googleapis.com/auth/classroom.coursework.me.readonly',
            'https://www.googleapis.com/auth/classroom.courseworkmaterials.readonly',
            'https://www.googleapis.com/auth/classroom.announcements.readonly',
            'https://www.googleapis.com/auth/drive.readonly',
        ]),
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'state'         => session_id(),
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/**
 * Get Privacy Policy URL for Google Verification compliance.
 */
function gc_get_privacy_url(): string {
    return APP_URL . '/privacy.php';
}

/**
 * Get Terms of Service URL for Google Verification compliance.
 */
function gc_get_terms_url(): string {
    return APP_URL . '/terms.php';
}

/**
 * Exchange authorization code for access + refresh tokens.
 * @return array ['success', 'access_token', 'refresh_token', 'expires_in', 'error']
 */
function gc_exchange_code(string $code): array {
    $postFields = [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => APP_URL . '/google_callback.php',
        'grant_type'    => 'authorization_code',
    ];

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postFields),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => (defined('APP_ENV') && APP_ENV === 'local') ? false : true,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) return ['success' => false, 'error' => 'cURL error: ' . $err];

    $data = json_decode($response, true);
    if (isset($data['error'])) {
        return ['success' => false, 'error' => ($data['error_description'] ?? $data['error'])];
    }

    return [
        'success'       => true,
        'access_token'  => $data['access_token']  ?? '',
        'refresh_token' => $data['refresh_token']  ?? '',
        'expires_in'    => $data['expires_in']     ?? 3600,
        'error'         => '',
    ];
}

/**
 * Refresh an expired access token using the refresh token.
 * @return array ['success', 'access_token', 'expires_in', 'error']
 */
function gc_refresh_token(string $refreshTokenEncrypted): array {
    $refreshToken = gc_decrypt_token($refreshTokenEncrypted);
    if (!$refreshToken) return ['success' => false, 'error' => 'Failed to decrypt refresh token'];

    $postFields = [
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'refresh_token' => $refreshToken,
        'grant_type'    => 'refresh_token',
    ];

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postFields),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => (defined('APP_ENV') && APP_ENV === 'local') ? false : true,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) return ['success' => false, 'error' => 'cURL error: ' . $err];

    $data = json_decode($response, true);
    if (isset($data['error'])) {
        return ['success' => false, 'error' => ($data['error_description'] ?? $data['error'])];
    }

    return [
        'success'      => true,
        'access_token' => $data['access_token'] ?? '',
        'expires_in'   => $data['expires_in']   ?? 3600,
        'error'        => '',
    ];
}

/**
 * Get a valid access token for a user (refreshes if expired).
 * @return string|false Access token or false on failure
 */
function gc_get_valid_token(mysqli $conn, int $userId) {
    $stmt = $conn->prepare("SELECT access_token, refresh_token, token_expiry FROM google_accounts WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($accessEnc, $refreshEnc, $expiry);
    if (!$stmt->fetch()) { $stmt->close(); return false; }
    $stmt->close();

    // Check if current token is still valid (with 5 min buffer)
    $expiryTime = strtotime($expiry);
    if ($expiryTime > time() + 300) {
        return gc_decrypt_token($accessEnc);
    }

    // Token expired — refresh it
    $result = gc_refresh_token($refreshEnc);
    if (!$result['success']) return false;

    $newAccessEnc = gc_encrypt_token($result['access_token']);
    $newExpiry    = date('Y-m-d H:i:s', time() + $result['expires_in']);

    $upd = $conn->prepare("UPDATE google_accounts SET access_token = ?, token_expiry = ? WHERE user_id = ?");
    $upd->bind_param('ssi', $newAccessEnc, $newExpiry, $userId);
    $upd->execute();
    $upd->close();

    return $result['access_token'];
}

/**
 * Fetch Google user info (email, name).
 */
function gc_get_user_info(string $accessToken): array {
    return gc_api_get('https://www.googleapis.com/oauth2/v2/userinfo', $accessToken);
}

/**
 * Fetch all courses for the authenticated user.
 */
function gc_fetch_courses(string $accessToken): array {
    $allCourses = [];
    $pageToken  = '';
    do {
        $url = 'https://classroom.googleapis.com/v1/courses?pageSize=100&courseStates=ACTIVE';
        if ($pageToken) $url .= '&pageToken=' . urlencode($pageToken);

        $data = gc_api_get($url, $accessToken);
        if (isset($data['error'])) return $data;

        if (isset($data['courses'])) {
            $allCourses = array_merge($allCourses, $data['courses']);
        }
        $pageToken = $data['nextPageToken'] ?? '';
    } while ($pageToken);

    return ['courses' => $allCourses];
}

/**
 * Fetch all topics for a Google Classroom course.
 */
function gc_fetch_topics(string $accessToken, string $courseId): array {
    $allTopics = [];
    $pageToken = '';
    do {
        $url = "https://classroom.googleapis.com/v1/courses/{$courseId}/topics?pageSize=100";
        if ($pageToken) $url .= '&pageToken=' . urlencode($pageToken);

        $data = gc_api_get($url, $accessToken);
        if (isset($data['error'])) {
            // Topics endpoint may 404/403 if course has no topics or insufficient perms
            $errCode = $data['code'] ?? 0;
            if ($errCode == 404 || $errCode == 403) return ['topic' => []];
            return $data;
        }

        if (isset($data['topic'])) {
            $allTopics = array_merge($allTopics, $data['topic']);
        }
        $pageToken = $data['nextPageToken'] ?? '';
    } while ($pageToken);

    return ['topic' => $allTopics];
}

/**
 * Fetch coursework (assignments) for a course.
 */
function gc_fetch_coursework(string $accessToken, string $courseId): array {
    $allWork   = [];
    $pageToken = '';
    do {
        // Note: orderBy is NOT a supported parameter for courseWork.list in Classroom API v1
        $url = "https://classroom.googleapis.com/v1/courses/{$courseId}/courseWork?pageSize=100";
        if ($pageToken) $url .= '&pageToken=' . urlencode($pageToken);

        $data = gc_api_get($url, $accessToken);
        if (isset($data['error'])) {
            $errCode = $data['code'] ?? 0;
            if ($errCode == 404 || $errCode == 403) return ['courseWork' => []];
            return $data;
        }

        if (isset($data['courseWork'])) {
            $allWork = array_merge($allWork, $data['courseWork']);
        }
        $pageToken = $data['nextPageToken'] ?? '';
    } while ($pageToken);

    return ['courseWork' => $allWork];
}

/**
 * Fetch course materials (non-assignment posts with attachments).
 */
function gc_fetch_course_materials(string $accessToken, string $courseId): array {
    $allMaterials = [];
    $pageToken    = '';
    do {
        $url = "https://classroom.googleapis.com/v1/courses/{$courseId}/courseWorkMaterials?pageSize=100";
        if ($pageToken) $url .= '&pageToken=' . urlencode($pageToken);

        $data = gc_api_get($url, $accessToken);
        if (isset($data['error'])) {
            $errCode = $data['code'] ?? 0;
            if ($errCode == 404 || $errCode == 403) return ['courseWorkMaterial' => []];
            return $data;
        }

        if (isset($data['courseWorkMaterial'])) {
            $allMaterials = array_merge($allMaterials, $data['courseWorkMaterial']);
        }
        $pageToken = $data['nextPageToken'] ?? '';
    } while ($pageToken);

    return ['courseWorkMaterial' => $allMaterials];
}

/**
 * Fetch announcements for a course (posts with optional attachments).
 */
function gc_fetch_announcements(string $accessToken, string $courseId): array {
    $allAnn    = [];
    $pageToken = '';
    do {
        $url = "https://classroom.googleapis.com/v1/courses/{$courseId}/announcements?pageSize=100";
        if ($pageToken) $url .= '&pageToken=' . urlencode($pageToken);

        $data = gc_api_get($url, $accessToken);
        if (isset($data['error'])) {
            $errCode = $data['code'] ?? 0;
            if ($errCode == 404 || $errCode == 403) return ['announcements' => []];
            return $data;
        }

        if (isset($data['announcements'])) {
            $allAnn = array_merge($allAnn, $data['announcements']);
        }
        $pageToken = $data['nextPageToken'] ?? '';
    } while ($pageToken);

    return ['announcements' => $allAnn];
}

/**
 * Download a file from Google Drive.
 * Handles both regular files and Google Docs/Slides/Sheets (exports).
 * @return array ['success', 'content', 'mime_type', 'filename', 'error']
 */
function gc_download_drive_file(string $accessToken, string $fileId, string $mimeType = '', string $title = ''): array {
    // Max download size: 50MB
    $maxSize = 50 * 1024 * 1024;

    // Google Workspace file export mappings
    $exportMap = [
        'application/vnd.google-apps.document'     => ['mime' => 'application/pdf', 'ext' => 'pdf'],
        'application/vnd.google-apps.spreadsheet'   => ['mime' => 'application/pdf', 'ext' => 'pdf'],
        'application/vnd.google-apps.presentation'  => ['mime' => 'application/pdf', 'ext' => 'pdf'],
        'application/vnd.google-apps.drawing'       => ['mime' => 'image/png',       'ext' => 'png'],
    ];

    // If mimeType is unknown, try fetching metadata from Drive API
    if (!$mimeType && $fileId) {
        $info = gc_get_drive_file_info($accessToken, $fileId);
        $mimeType = $info['mimeType'] ?? '';
        if (!$title && !empty($info['name'])) {
            $title = $info['name'];
        }
    }

    // Determine if this is a Google Workspace file that needs export
    if (isset($exportMap[$mimeType])) {
        $export   = $exportMap[$mimeType];
        $url      = "https://www.googleapis.com/drive/v3/files/{$fileId}/export?mimeType=" . urlencode($export['mime']) . "&supportsAllDrives=true";
        $dlMime   = $export['mime'];
        $filename = pathinfo($title, PATHINFO_FILENAME) . '.' . $export['ext'];
    } else {
        $url      = "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media&supportsAllDrives=true";
        $dlMime   = $mimeType;
        $filename = $title ?: $fileId;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => (defined('APP_ENV') && APP_ENV === 'local') ? false : true,
    ]);
    $content  = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    $fileSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);

    if ($err) return ['success' => false, 'error' => 'cURL: ' . $err];

    // If alt=media returned 403 or 400 (e.g. Google Docs file), attempt PDF export fallback
    if (($httpCode === 403 || $httpCode === 400) && !isset($exportMap[$mimeType])) {
        $exportUrl = "https://www.googleapis.com/drive/v3/files/{$fileId}/export?mimeType=application/pdf&supportsAllDrives=true";
        $ch2 = curl_init($exportUrl);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => (defined('APP_ENV') && APP_ENV === 'local') ? false : true,
        ]);
        $exportContent = curl_exec($ch2);
        $exportHttp    = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        if ($exportHttp === 200) {
            return [
                'success'   => true,
                'content'   => $exportContent,
                'mime_type' => 'application/pdf',
                'filename'  => pathinfo($title, PATHINFO_FILENAME) . '.pdf',
                'size'      => strlen($exportContent),
                'error'     => '',
            ];
        }
    }


    if ($httpCode !== 200) {
        $errData = json_decode($content, true);
        return ['success' => false, 'error' => 'HTTP ' . $httpCode . ': ' . ($errData['error']['message'] ?? substr($content, 0, 200))];
    }
    if ($fileSize > $maxSize) {
        return ['success' => false, 'error' => 'File too large (' . round($fileSize / 1048576, 1) . 'MB). Max: 50MB'];
    }

    return [
        'success'   => true,
        'content'   => $content,
        'mime_type' => $dlMime ?: 'application/octet-stream',
        'filename'  => $filename,
        'size'      => $fileSize,
        'error'     => '',
    ];
}

/**
 * Get file metadata from Google Drive.
 */
function gc_get_drive_file_info(string $accessToken, string $fileId): array {
    $url = "https://www.googleapis.com/drive/v3/files/{$fileId}?fields=id,name,mimeType,size,modifiedTime&supportsAllDrives=true";
    return gc_api_get($url, $accessToken);
}


/**
 * Generic Google API GET request.
 */
function gc_api_get(string $url, string $accessToken): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => (defined('APP_ENV') && APP_ENV === 'local') ? false : true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) return ['error' => ['message' => 'cURL: ' . $err], 'code' => 0];

    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        return [
            'error' => $data['error'] ?? ['message' => 'HTTP ' . $httpCode],
            'code'  => $httpCode,
        ];
    }

    return $data ?: [];
}

/**
 * Save Google account tokens for a user.
 */
function gc_save_account(mysqli $conn, int $userId, string $email, string $accessToken, string $refreshToken, int $expiresIn): bool {
    $accessEnc  = gc_encrypt_token($accessToken);
    $refreshEnc = gc_encrypt_token($refreshToken);
    $expiry     = date('Y-m-d H:i:s', time() + $expiresIn);

    // Upsert (INSERT ... ON DUPLICATE KEY UPDATE)
    $stmt = $conn->prepare(
        "INSERT INTO google_accounts (user_id, google_email, access_token, refresh_token, token_expiry)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            google_email  = VALUES(google_email),
            access_token  = VALUES(access_token),
            refresh_token = VALUES(refresh_token),
            token_expiry  = VALUES(token_expiry),
            sync_status   = 'idle',
            sync_error    = NULL"
    );
    $stmt->bind_param('issss', $userId, $email, $accessEnc, $refreshEnc, $expiry);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Disconnect Google account — remove tokens and optionally synced data mapping.
 */
function gc_disconnect(mysqli $conn, int $userId): bool {
    $stmt = $conn->prepare("DELETE FROM google_accounts WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Check if a user has a connected Google account.
 * @return array|false  Account row or false
 */
function gc_get_account(mysqli $conn, int $userId) {
    $stmt = $conn->prepare("SELECT id, google_email, token_expiry, last_sync_at, sync_status, sync_error, connected_at FROM google_accounts WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ?: false;
}

/**
 * Update sync status for a user.
 */
function gc_update_sync_status(mysqli $conn, int $userId, string $status, ?string $error = null): void {
    // Reconnect if MySQL dropped during long sync
    if (function_exists('db_reconnect')) db_reconnect($conn);
    if (!$conn || $conn->connect_error) return;

    if ($error) {
        $stmt = $conn->prepare("UPDATE google_accounts SET sync_status = ?, sync_error = ?, last_sync_at = NOW() WHERE user_id = ?");
        if (!$stmt) return;
        $stmt->bind_param('ssi', $status, $error, $userId);
    } else {
        $stmt = $conn->prepare("UPDATE google_accounts SET sync_status = ?, sync_error = NULL, last_sync_at = NOW() WHERE user_id = ?");
        if (!$stmt) return;
        $stmt->bind_param('si', $status, $userId);
    }
    $stmt->execute();
    $stmt->close();
}
?>
