<?php
function sanitize_input($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}
function is_image($mime) {
    return strpos($mime, 'image/') === 0;
}
function is_text($mime) {
    return strpos($mime, 'text/') === 0;
}

function is_pdf($mime) {
    return strpos($mime, 'application/pdf') === 0;
}

/**
 * Sync Database and Filesystem
 * Scans the database and physical storage to remove orphaned files and DB entries.
 * Resolves manual phpMyAdmin deletions and accidental file removals.
 */
function sync_system_storage($conn) {
    $db_files = [];
    $res = $conn->query("SELECT id, file_path FROM files");
    while($row = $res->fetch_assoc()) {
        $db_files[$row['id']] = $row['file_path'];
    }
    
    $deleted_db_rows = 0;
    $deleted_physical_files = 0;

    // 1. Check for missing physical files (delete DB row if physical file is gone)
    foreach ($db_files as $id => $path) {
        $absolute_path = realpath(__DIR__ . '/../' . ltrim($path, '/'));
        if (!$absolute_path || !file_exists($absolute_path)) {
            $conn->query("DELETE FROM files WHERE id = $id");
            $deleted_db_rows++;
        }
    }
    
    // 2. Check for orphaned physical files (delete from disk if not in DB)
    $upload_dir = realpath(__DIR__ . '/../uploads/notes');
    if ($upload_dir && is_dir($upload_dir)) {
        $physical_files = glob($upload_dir . '/*');
        
        // Refresh DB paths in case some were deleted in step 1
        $current_db_paths = [];
        $res = $conn->query("SELECT file_path FROM files");
        while($row = $res->fetch_assoc()) {
            $abs = realpath(__DIR__ . '/../' . ltrim($row['file_path'], '/'));
            if ($abs) {
                $current_db_paths[] = $abs;
            }
        }
        
        foreach ($physical_files as $pfile) {
            if (basename($pfile) === '.gitkeep') continue;
            
            if (!in_array(realpath($pfile), $current_db_paths)) {
                @unlink($pfile);
                $deleted_physical_files++;
            }
        }
    }
    
    return [
        'success' => true,
        'deleted_db_rows' => $deleted_db_rows,
        'deleted_physical_files' => $deleted_physical_files
    ];
}
?>
