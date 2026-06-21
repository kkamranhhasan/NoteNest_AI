<?php
// --- UTILS ---
function folder_belongs_to_user($conn, $folder_id, $user_id) {
    $stmt = $conn->prepare("SELECT 1 FROM folders WHERE id=? AND owner_id=?");
    $stmt->bind_param('ii', $folder_id, $user_id);
    $stmt->execute(); $stmt->store_result();
    $ok = $stmt->num_rows > 0;
    $stmt->close();
    return $ok;
}
function get_folder_path($conn, $folder_id, $user_id) {
    $path = [];
    while ($folder_id) {
        $stmt = $conn->prepare("SELECT id, name, parent_folder_id FROM folders WHERE id=? AND owner_id=?");
        $stmt->bind_param('ii', $folder_id, $user_id);
        $stmt->execute();
        $stmt->bind_result($id, $name, $parent_id);
        if ($stmt->fetch()) {
            array_unshift($path, ['id' => $id, 'name' => $name]);
            $folder_id = $parent_id;
        } else {
            break;
        }
        $stmt->close();
    }
    return $path;
}
require 'includes/auth.php';
require 'includes/db.php';
$user_id = $_SESSION['user_id'];
$upload_error = $folder_error = $modal_message = '';
$current_folder_id = isset($_GET['folder']) && is_numeric($_GET['folder']) ? intval($_GET['folder']) : null;

// --- Brute-force recursive share ---
function share_folder_children($conn, $folder_id, $recipient_user_id) {
    // Share all subfolders
    $stmt = $conn->prepare("SELECT id FROM folders WHERE parent_folder_id = ?");
    $stmt->bind_param('i', $folder_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $subfolders = [];
    while ($row = $result->fetch_assoc()) $subfolders[] = $row['id'];
    $stmt->close();
    // Share all files in this folder
    $stmt = $conn->prepare("SELECT id FROM files WHERE folder_id = ?");
    $stmt->bind_param('i', $folder_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $files = [];
    while ($row = $result->fetch_assoc()) $files[] = $row['id'];
    $stmt->close();
    // Share subfolders recursively
    foreach ($subfolders as $subfolder_id) {
        $stmt = $conn->prepare("INSERT IGNORE INTO shared_access (item_type, item_id, shared_with_user_id) VALUES ('folder', ?, ?)");
        $stmt->bind_param('ii', $subfolder_id, $recipient_user_id);
        $stmt->execute(); $stmt->close();
        share_folder_children($conn, $subfolder_id, $recipient_user_id);
    }
    // Share all files
    foreach ($files as $file_id) {
        $stmt = $conn->prepare("INSERT IGNORE INTO shared_access (item_type, item_id, shared_with_user_id) VALUES ('file', ?, ?)");
        $stmt->bind_param('ii', $file_id, $recipient_user_id);
        $stmt->execute(); $stmt->close();
    }
}
// Brute-force recursive revoke
function revoke_folder_children($conn, $folder_id, $recipient_user_id) {
    $stmt = $conn->prepare("SELECT id FROM folders WHERE parent_folder_id = ?");
    $stmt->bind_param('i', $folder_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $subfolders = [];
    while ($row = $result->fetch_assoc()) $subfolders[] = $row['id'];
    $stmt->close();
    $stmt = $conn->prepare("SELECT id FROM files WHERE folder_id = ?");
    $stmt->bind_param('i', $folder_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $files = [];
    while ($row = $result->fetch_assoc()) $files[] = $row['id'];
    $stmt->close();
    foreach ($subfolders as $subfolder_id) {
        $stmt = $conn->prepare("DELETE FROM shared_access WHERE item_type='folder' AND item_id=? AND shared_with_user_id=?");
        $stmt->bind_param('ii', $subfolder_id, $recipient_user_id);
        $stmt->execute(); $stmt->close();
        revoke_folder_children($conn, $subfolder_id, $recipient_user_id);
    }
    foreach ($files as $file_id) {
        $stmt = $conn->prepare("DELETE FROM shared_access WHERE item_type='file' AND item_id=? AND shared_with_user_id=?");
        $stmt->bind_param('ii', $file_id, $recipient_user_id);
        $stmt->execute(); $stmt->close();
    }
}

// --- AJAX/POST HANDLERS (MUST BE BEFORE ANY HTML OUTPUT) ---
if (isset($_POST['share_item'])) {
    $item_type = $_POST['item_type'];
    $item_id = intval($_POST['item_id']);
    $recipient_email = trim($_POST['recipient_email']);
    if (!in_array($item_type, ['file', 'folder'])) exit('Invalid item type.');
    // Validate ownership
    if ($item_type === 'file') {
        $stmt = $conn->prepare("SELECT 1 FROM files WHERE id=? AND owner_id=?");
        $stmt->bind_param('ii', $item_id, $user_id);
    } else {
        $stmt = $conn->prepare("SELECT 1 FROM folders WHERE id=? AND owner_id=?");
        $stmt->bind_param('ii', $item_id, $user_id);
    }
    $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows === 0) exit('Invalid item or not owned by you.');
    $stmt->close();
    // Lookup recipient by email
    $stmt = $conn->prepare("SELECT id, name FROM users WHERE email=?");
    $stmt->bind_param('s', $recipient_email);
    $stmt->execute();
    $stmt->bind_result($recipient_id, $recipient_name);
    if (!$stmt->fetch()) exit('User not found.');
    $stmt->close();
    if ($recipient_id === $user_id) exit('Cannot share with yourself.');
    // Share the item
    $stmt = $conn->prepare("INSERT IGNORE INTO shared_access (item_type, item_id, shared_with_user_id) VALUES (?, ?, ?)");
    $stmt->bind_param('sii', $item_type, $item_id, $recipient_id);
    $stmt->execute(); $stmt->close();
    // If sharing a folder, recursively share its contents
    if ($item_type === 'folder') share_folder_children($conn, $item_id, $recipient_id);
    // Create notification
    $item_name = '';
    if ($item_type === 'file') {
        $stmt = $conn->prepare("SELECT name FROM files WHERE id=?");
        $stmt->bind_param('i', $item_id);
        $stmt->execute(); $stmt->bind_result($item_name); $stmt->fetch(); $stmt->close();
    } else {
        $stmt = $conn->prepare("SELECT name FROM folders WHERE id=?");
        $stmt->bind_param('i', $item_id);
        $stmt->execute(); $stmt->bind_result($item_name); $stmt->fetch(); $stmt->close();
    }
    $owner_name = $_SESSION['user_name'];
    $message = ucfirst($item_type) . ' "' . $item_name . '" has been shared with you by ' . $owner_name . '.';
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    $stmt->bind_param('is', $recipient_id, $message);
    $stmt->execute(); $stmt->close();
    exit('Shared successfully.');
}
if (isset($_POST['revoke_share'])) {
    $item_type = $_POST['item_type'];
    $item_id = intval($_POST['item_id']);
    $recipient_id = intval($_POST['recipient_id']);
    if (!in_array($item_type, ['file', 'folder'])) exit('Invalid item type.');
    // Validate ownership
    if ($item_type === 'file') {
        $stmt = $conn->prepare("SELECT 1 FROM files WHERE id=? AND owner_id=?");
        $stmt->bind_param('ii', $item_id, $user_id);
    } else {
        $stmt = $conn->prepare("SELECT 1 FROM folders WHERE id=? AND owner_id=?");
        $stmt->bind_param('ii', $item_id, $user_id);
    }
    $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows === 0) exit('Invalid item or not owned by you.');
    $stmt->close();
    if ($item_type === 'file') {
        $stmt = $conn->prepare("DELETE FROM shared_access WHERE item_type='file' AND item_id=? AND shared_with_user_id=?");
        $stmt->bind_param('ii', $item_id, $recipient_id);
        $stmt->execute(); $stmt->close();
    } else {
        // Revoke folder and all its descendants
        revoke_folder_children($conn, $item_id, $recipient_id);
        $stmt = $conn->prepare("DELETE FROM shared_access WHERE item_type='folder' AND item_id=? AND shared_with_user_id=?");
        $stmt->bind_param('ii', $item_id, $recipient_id);
        $stmt->execute(); $stmt->close();
    }
    exit('Access revoked.');
}
if (isset($_POST['favorite_item'])) {
    $item_type = $_POST['item_type'];
    $item_id = intval($_POST['item_id']);
    $is_fav = isset($_POST['is_fav']) ? 1 : 0;
    if ($is_fav) {
        $stmt = $conn->prepare('DELETE FROM favorites WHERE user_id=? AND item_type=? AND item_id=?');
        $stmt->bind_param('isi', $user_id, $item_type, $item_id);
        $stmt->execute(); $stmt->close();
    } else {
        $stmt = $conn->prepare('INSERT IGNORE INTO favorites (user_id, item_type, item_id) VALUES (?, ?, ?)');
        $stmt->bind_param('isi', $user_id, $item_type, $item_id);
        $stmt->execute(); $stmt->close();
    }
    exit('ok');
}
// --- END AJAX/POST HANDLERS ---

if ($current_folder_id !== null && !folder_belongs_to_user($conn, $current_folder_id, $user_id)) {
    header("Location: my_note_nest.php");
    exit;
}
// --- CREATE FOLDER ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_folder'])) {
    $folder_name = trim($_POST['folder_name'] ?? '');
    if ($folder_name == '') {
        $folder_error = "Folder name cannot be empty.";
    } elseif (mb_strlen($folder_name) > 100) {
        $folder_error = "Folder name too long (max 100 chars).";
    } else {
        $stmt = $conn->prepare("SELECT 1 FROM folders WHERE owner_id=? AND name=? AND ((parent_folder_id IS NULL AND ? IS NULL) OR parent_folder_id=?)");
        $stmt->bind_param('issi', $user_id, $folder_name, $current_folder_id, $current_folder_id);
        $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $folder_error = "A folder with this name already exists here.";
            $stmt->close();
        } else {
            $stmt->close();
            $stmt = $conn->prepare("INSERT INTO folders (owner_id, name, parent_folder_id) VALUES (?, ?, ?)");
            if ($current_folder_id !== null) {
                $stmt->bind_param('isi', $user_id, $folder_name, $current_folder_id);
            } else {
                $null = null;
                $stmt->bind_param('isi', $user_id, $folder_name, $null);
            }
            $stmt->execute();
            $stmt->close();
            $_SESSION['success_msg'] = "Folder created!";
            $folder_error = "";
        }
    }
    if ($folder_error == "") {
        $_SESSION['history_flatten'] = true;
        header("Location: my_note_nest.php" . ($current_folder_id ? "?folder=$current_folder_id" : ""));
        exit;
    }
}
// --- RENAME FOLDER ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['rename_folder'])) {
    $rename_folder_id = intval($_POST['rename_folder_id']);
    $rename_name = trim($_POST['rename_folder_name'] ?? '');
    if (!folder_belongs_to_user($conn, $rename_folder_id, $user_id)) {
        $_SESSION['folder_delete_error'] = "Invalid folder.";
    } elseif ($rename_name === '') {
        $_SESSION['folder_delete_error'] = "Folder name cannot be empty.";
    } elseif (mb_strlen($rename_name) > 100) {
        $_SESSION['folder_delete_error'] = "Folder name too long.";
    } else {
        $stmt = $conn->prepare("SELECT parent_folder_id FROM folders WHERE id=? AND owner_id=?");
        $stmt->bind_param('ii', $rename_folder_id, $user_id);
        $stmt->execute();
        $stmt->bind_result($parent_id); $stmt->fetch();
        $stmt->close();
        $stmt = $conn->prepare(
            "SELECT 1 FROM folders WHERE owner_id=? AND name=? AND ((parent_folder_id IS NULL AND ? IS NULL) OR parent_folder_id=?) AND id<>?"
        );
        $stmt->bind_param('issii', $user_id, $rename_name, $parent_id, $parent_id, $rename_folder_id);
        $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $_SESSION['folder_delete_error'] = "A folder with that name already exists here.";
        } else {
            $stmt->close();
            $stmt = $conn->prepare("UPDATE folders SET name=? WHERE id=? AND owner_id=?");
            $stmt->bind_param('sii', $rename_name, $rename_folder_id, $user_id);
            $stmt->execute(); $stmt->close();
            $_SESSION['success_msg'] = "Folder renamed!";
        }
    }
    $_SESSION['history_flatten'] = true;
    header("Location: my_note_nest.php" . ($current_folder_id ? "?folder=$current_folder_id" : ""));
    exit;
}
// --- RENAME FILE ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['rename_file'])) {
    $rename_file_id = intval($_POST['rename_file_id']);
    $rename_name = trim($_POST['rename_file_name'] ?? '');
    if ($rename_name === '') {
        $_SESSION['file_rename_error'] = "File name cannot be empty.";
    } elseif (mb_strlen($rename_name) > 100) {
        $_SESSION['file_rename_error'] = "File name too long.";
    } else {
        $stmt = $conn->prepare("UPDATE files SET name=? WHERE id=? AND owner_id=?");
        $stmt->bind_param('sii', $rename_name, $rename_file_id, $user_id);
        $stmt->execute(); $stmt->close();
        $_SESSION['success_msg'] = "File renamed!";
    }
    $_SESSION['history_flatten'] = true;
    $redirect_folder = $current_folder_id ? "?folder=$current_folder_id" : "";
    header("Location: my_note_nest.php$redirect_folder");
    exit;
}
// --- UPLOAD FILE ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['note_file'])) {
    $file_name = trim(htmlspecialchars($_POST['file_name'] ?? ''));
    $file = $_FILES['note_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $target_folder_id = isset($_POST['parent_folder_id']) && $_POST['parent_folder_id'] !== '' && is_numeric($_POST['parent_folder_id'])
        ? intval($_POST['parent_folder_id']) : null;
    if ($target_folder_id !== null && !folder_belongs_to_user($conn, $target_folder_id, $user_id)) {
        $upload_error = "Invalid target folder!";
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $upload_error = "No file uploaded or upload error.";
    } elseif (empty($file_name)) {
        $upload_error = "Please enter a file name.";
    } elseif ($file['size'] > 10 * 1024 * 1024) {
        $upload_error = "File must be less than 10 MB.";
    } else {
        $rand_file = uniqid("file_", true) . "." . $ext;
        $target_dir = __DIR__ . '/uploads/notes/';
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $target_path = $target_dir . $rand_file;
        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            $mime = mime_content_type($target_path);
            $db_path = 'uploads/notes/' . $rand_file;
            if ($target_folder_id !== null) {
                $stmt = $conn->prepare("INSERT INTO files (owner_id, name, file_path, mime_type, folder_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('isssi', $user_id, $file_name, $db_path, $mime, $target_folder_id);
            } else {
                $null = null;
                $stmt = $conn->prepare("INSERT INTO files (owner_id, name, file_path, mime_type, folder_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('isssi', $user_id, $file_name, $db_path, $mime, $null);
            }
            $stmt->execute();
            $stmt->close();
            $_SESSION['success_msg'] = "File uploaded!";
            $upload_error = "";
        } else {
            $upload_error = "Failed to save file.";
        }
    }
    if ($upload_error == "") {
        $_SESSION['history_flatten'] = true;
        header("Location: my_note_nest.php" . ($current_folder_id ? "?folder=$current_folder_id" : ""));
        exit;
    }
}
// --- DELETE FILE ---
if (isset($_GET['delete_file']) && is_numeric($_GET['delete_file'])) {
    $id = (int)$_GET['delete_file'];
    $stmt = $conn->prepare("SELECT file_path, folder_id FROM files WHERE id=? AND owner_id=?");
    $stmt->bind_param('ii', $id, $user_id);
    $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows == 1) {
        $stmt->bind_result($file_path, $file_folder_id); $stmt->fetch(); $stmt->close();
        $conn->query("DELETE FROM files WHERE id=$id");
        @unlink(__DIR__ . "/" . $file_path);
        $_SESSION['history_flatten'] = true;
        header("Location: my_note_nest.php" . ($file_folder_id ? "?folder=" . $file_folder_id : ""));
        exit;
    }
    $stmt->close();
}
// --- DELETE FOLDER (only if empty) ---
if (isset($_GET['delete_folder']) && is_numeric($_GET['delete_folder'])) {
    $folder_id = intval($_GET['delete_folder']);
    if (folder_belongs_to_user($conn, $folder_id, $user_id)) {
        $stmt = $conn->prepare("SELECT 1 FROM folders WHERE parent_folder_id=? AND owner_id=?");
        $stmt->bind_param('ii', $folder_id, $user_id);
        $stmt->execute(); $stmt->store_result();
        $has_subfolders = $stmt->num_rows > 0; $stmt->close();
        $stmt = $conn->prepare("SELECT 1 FROM files WHERE folder_id=? AND owner_id=?");
        $stmt->bind_param('ii', $folder_id, $user_id);
        $stmt->execute(); $stmt->store_result();
        $has_files = $stmt->num_rows > 0; $stmt->close();
        if ($has_subfolders || $has_files) {
            $_SESSION['folder_delete_error'] = "Folder is not empty.";
            $_SESSION['history_flatten'] = true;
            header("Location: my_note_nest.php" . ($current_folder_id ? "?folder=$current_folder_id" : ""));
            exit;
        } else {
            $stmt = $conn->prepare("SELECT parent_folder_id FROM folders WHERE id=?");
            $stmt->bind_param('i', $folder_id);
            $stmt->execute();
            $stmt->bind_result($redirect_parent_id);
            $stmt->fetch();
            $stmt->close();
            $conn->query("DELETE FROM folders WHERE id = $folder_id");
            $_SESSION['success_msg'] = "Folder deleted.";
            $_SESSION['history_flatten'] = true;
            header("Location: my_note_nest.php" . ($redirect_parent_id ? "?folder=$redirect_parent_id" : ""));
            exit;
        }
    }
}
// --- LOAD FOLDERS AND FILES FOR OWNER ---
$folders = [];
if ($current_folder_id === null) {
    $stmt = $conn->prepare("SELECT id, name FROM folders WHERE owner_id=? AND parent_folder_id IS NULL ORDER BY name");
    $stmt->bind_param('i', $user_id);
} else {
    $stmt = $conn->prepare("SELECT id, name FROM folders WHERE owner_id=? AND parent_folder_id=? ORDER BY name");
    $stmt->bind_param('ii', $user_id, $current_folder_id);
}
$stmt->execute();
$stmt->bind_result($fid, $fname);
while ($stmt->fetch()) $folders[] = [$fid, $fname];
$stmt->close();
$files = [];
if ($current_folder_id === null) {
    $stmt = $conn->prepare("SELECT id, name, file_path, mime_type, created_at FROM files WHERE owner_id=? AND folder_id IS NULL ORDER BY created_at DESC");
    $stmt->bind_param('i', $user_id);
} else {
    $stmt = $conn->prepare("SELECT id, name, file_path, mime_type, created_at FROM files WHERE owner_id=? AND folder_id=? ORDER BY created_at DESC");
    $stmt->bind_param('ii', $user_id, $current_folder_id);
}
$stmt->execute();
$stmt->bind_result($nid, $nname, $npath, $nmime, $ncreated);
while ($stmt->fetch()) $files[] = [$nid, $nname, $npath, $nmime, $ncreated];
$stmt->close();
// --- END LOAD FOLDERS/FILES ---
// Get favorites for this user
$fav_ids = ['file'=>[], 'folder'=>[]];
$res = $conn->query("SELECT item_type, item_id FROM favorites WHERE user_id=$user_id");
while ($row = $res->fetch_assoc()) $fav_ids[$row['item_type']][] = $row['item_id'];
// Get shared status for items
$shared_items = ['file'=>[], 'folder'=>[]];
$res = $conn->query("SELECT item_type, item_id FROM shared_access WHERE item_id IN (SELECT id FROM files WHERE owner_id=$user_id UNION SELECT id FROM folders WHERE owner_id=$user_id)");
while ($row = $res->fetch_assoc()) $shared_items[$row['item_type']][] = $row['item_id'];
// --- Breadcrumbs ---
$breadcrumbs = get_folder_path($conn, $current_folder_id, $user_id);
if (isset($_SESSION['success_msg'])) {
    $modal_message = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
} elseif (isset($_SESSION['folder_delete_error'])) {
    $modal_message = $_SESSION['folder_delete_error'];
    unset($_SESSION['folder_delete_error']);
} elseif (isset($_SESSION['file_rename_error'])) {
    $modal_message = $_SESSION['file_rename_error'];
    unset($_SESSION['file_rename_error']);
}
if ($modal_message) {
    echo "<script>if (history.replaceState) history.replaceState(null, '', window.location.pathname + window.location.search);</script>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>MyNoteNest - NoteNest</title>
  <link rel="shortcut icon" href="img/fav.ico" type="image/x-icon">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="css/my_note_nest.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <!-- DOCX preview -->
  <script src="https://cdn.jsdelivr.net/npm/mammoth@1.6.0/mammoth.browser.min.js"></script>
  <!-- XLSX/CSV preview -->
  <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
  <style>
    /* ── Enhanced Preview Modal ── */
    #previewModal .modal-dialog { max-width: 860px; }
    #previewModal .modal-content { border-radius: 16px; overflow: hidden; border: none; box-shadow: 0 20px 60px rgba(0,0,0,.18); }
    #previewModal .modal-header { background: linear-gradient(135deg,#0b4954,#197f8f); color:#fff; padding:16px 22px; }
    #previewModal .modal-title { font-weight:700; font-size:1rem; }
    #previewModal .btn-close { filter:invert(1); }
    #previewModal .modal-body { padding:0; background:#f8fafb; min-height:200px; }
    .preview-toolbar { display:flex; align-items:center; gap:10px; padding:10px 16px; background:#fff; border-bottom:1px solid #e8edf2; }
    .preview-badge { font-size:.72rem; font-weight:700; padding:3px 10px; border-radius:10px; background:#e8f4f8; color:#0b4954; }
    .preview-size { font-size:.75rem; color:#aaa; margin-left:auto; }
    /* PDF */
    #pv-pdf iframe { width:100%; height:75vh; border:none; display:block; }
    /* Image */
    #pv-image { text-align:center; padding:20px; }
    #pv-image img { max-width:100%; max-height:72vh; border-radius:8px; box-shadow:0 4px 20px rgba(0,0,0,.12); }
    /* Text / Code */
    #pv-text { max-height:72vh; overflow-y:auto; }
    #pv-text pre { margin:0; padding:20px; font-size:.82rem; font-family:'Courier New',monospace; line-height:1.6; color:#2c3e50; background:#f8fafb; white-space:pre-wrap; word-break:break-word; }
    /* DOCX */
    #pv-docx { max-height:72vh; overflow-y:auto; padding:28px 32px; background:#fff; font-size:.92rem; line-height:1.7; color:#2c3e50; }
    #pv-docx h1,#pv-docx h2,#pv-docx h3 { color:#0b4954; margin:12px 0 6px; }
    #pv-docx table { border-collapse:collapse; width:100%; margin:8px 0; }
    #pv-docx td,#pv-docx th { border:1px solid #dde2e8; padding:6px 10px; font-size:.85rem; }
    /* XLSX/CSV Table */
    #pv-sheet { max-height:72vh; overflow:auto; }
    #pv-sheet table { border-collapse:collapse; font-size:.8rem; min-width:100%; }
    #pv-sheet th { background:#0b4954; color:#fff; padding:7px 12px; position:sticky; top:0; text-align:left; font-weight:600; }
    #pv-sheet td { border:1px solid #e8edf2; padding:6px 12px; color:#333; }
    #pv-sheet tr:nth-child(even) td { background:#f5f8fa; }
    /* Audio */
    #pv-audio { padding:40px; text-align:center; }
    #pv-audio audio { width:100%; border-radius:12px; }
    #pv-audio .audio-icon { font-size:4rem; color:#197f8f; margin-bottom:16px; }
    /* Video */
    #pv-video { background:#000; text-align:center; }
    #pv-video video { max-width:100%; max-height:72vh; }
    /* Unsupported */
    #pv-unsupported { text-align:center; padding:50px 30px; }
    #pv-unsupported .pv-icon { font-size:4rem; margin-bottom:16px; }
    /* Loading */
    #pv-loading { display:flex; align-items:center; justify-content:center; min-height:300px; flex-direction:column; gap:14px; color:#888; }
    .pv-spinner { width:40px; height:40px; border:3px solid #e8edf2; border-top-color:#197f8f; border-radius:50%; animation:spin .8s linear infinite; }
    @keyframes spin { to{transform:rotate(360deg)} }
  </style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<div class="container py-4">
  <div class="row g-4">
    <div class="col-md-4">
      <!-- BREADCRUMBS -->
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb bg-white px-2 py-2 rounded shadow-sm">
          <li class="breadcrumb-item">
            <?php if ($current_folder_id !== null): ?>
              <a href="my_note_nest.php"><i class="fas fa-house"></i> All Folders</a>
            <?php else: ?>
              <span><i class="fas fa-house"></i> All Folders</span>
            <?php endif; ?>
          </li>
          <?php foreach ($breadcrumbs as $i=>$bc): ?>
              <li class="breadcrumb-item <?= $i==count($breadcrumbs)-1 ? 'active' : '' ?>">
                <?php if ($i==count($breadcrumbs)-1): ?>
                  <?= htmlspecialchars($bc['name']) ?>
                <?php else: ?>
                  <a href="my_note_nest.php?folder=<?= $bc['id'] ?>"><?= htmlspecialchars($bc['name']) ?></a>
                <?php endif; ?>
              </li>
          <?php endforeach; ?>
        </ol>
      </nav>
      <!-- CREATE FOLDER FORM -->
      <div class="card mb-3">
        <div class="card-header bg text-white">
          <i class="fas fa-folder-plus me-2"></i>Create New Folder
        </div>
        <div class="card-body">
          <?php if($folder_error): ?>
            <div class="alert alert-danger py-2"><?= $folder_error ?></div>
          <?php endif; ?>
          <form method="post" autocomplete="off">
            <div class="input-group">
              <input type="text" name="folder_name" class="form-control" maxlength="100" required placeholder="Folder Name">
              <button class="btn btn-primary-cs" type="submit" name="create_folder"><i class="fa fa-plus"></i> Add</button>
            </div>
          </form>
        </div>
      </div>
      <!-- NOTE UPLOAD FORM -->
      <div class="card">
        <div class="card-header bg text-white">
          <i class="fas fa-upload me-2"></i>Add New Note (Any Format)
        </div>
        <div class="card-body">
          <?php if($upload_error): ?>
            <div class="alert alert-danger py-2"><?= $upload_error ?></div>
          <?php endif; ?>
          <form method="post" enctype="multipart/form-data" autocomplete="off">
            <div class="mb-2">
              <label class="form-label">Note Name</label>
              <input type="text" name="file_name" class="form-control" maxlength="100" required placeholder="Enter note name">
            </div>
            <div class="mb-2">
              <label class="form-label">Select Note File</label>
              <input type="file" name="note_file" accept="*.*" class="form-control" required>
            </div>
            <input type="hidden" name="parent_folder_id" value="<?= htmlspecialchars($current_folder_id ?? '') ?>">
            <button type="submit" class="btn upload-btn mt-2 w-100 text-white">
                <i class="fas fa-upload"></i> Upload Note
            </button>
          </form>
        </div>
      </div>
    </div>
    <div class="col-md-8">
      <!-- FOLDERS HEADING -->
      <div class="section-heading mb-2">
        <i class="fas fa-folder-open"></i> Folders
      </div>
      <!-- SUBFOLDER LIST -->
      <?php if(empty($folders)): ?>
        <p class="text-muted">No subfolders here.</p>
      <?php else: ?>
        <ul class="list-group folder-list-group mb-3">
          <?php foreach($folders as $f): ?>
            <li class="list-group-item d-flex align-items-center justify-content-between">
              <div>
                <a href="my_note_nest.php?folder=<?= $f[0] ?>" class="folder-link">
                  <i class="fa fa-folder folder-icon"></i><?= htmlspecialchars($f[1]) ?>
                </a>
              </div>
              <div>
                <button type="button" 
                  class="btn btn-sm btn-outline-primary folder-action-btn me-1 rename-folder-btn"
                  data-id="<?= $f[0] ?>" data-name="<?= htmlspecialchars($f[1]) ?>" title="Rename">
                  <i class="fas fa-pen"></i>
                </button>
                <a href="my_note_nest.php?delete_folder=<?= $f[0] ?>"
                   onclick="return confirm('Delete this folder? Folder must be empty!');"
                    class="btn btn-sm btn-outline-danger folder-action-btn" title="Delete Folder">
                    <i class="fas fa-trash"></i>
                </a>
                <a href="#" class="btn btn-sm btn-outline-warning me-1 favorite-btn" data-type="folder" data-id="<?= $f[0] ?>" data-fav="<?= in_array($f[0], $fav_ids['folder']) ? 1 : 0 ?>" title="Favorite">
                  <i class="fa<?= in_array($f[0], $fav_ids['folder']) ? 's' : 'r' ?> fa-star"></i>
                </a>
                                 <?php if (in_array($f[0], $shared_items['folder'])): ?>
                   <a href="#" class="btn btn-sm btn-outline-success me-1 shared-status-btn" data-type="folder" data-id="<?= $f[0] ?>" title="Manage Sharing">
                     <i class="fas fa-users"></i>
                   </a>
                 <?php endif; ?>
                 <a href="#" class="btn btn-sm btn-outline-info share-btn" data-type="folder" data-id="<?= $f[0] ?>" title="Share"><i class="fa fa-share"></i></a>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <!-- NOTES HEADING -->
      <div class="section-heading mb-2" style="margin-top:32px;">
        <i class="fas fa-note-sticky"></i> Notes<?= ($current_folder_id !== null)? ' in "' . htmlspecialchars(end($breadcrumbs)['name']) . '"' : '' ?>
      </div>
      <div class="card">
        <div class="card-body table-responsive">
          <?php if(empty($files)): ?>
            <div class="alert alert-secondary text-muted mb-0">No notes in this folder.</div>
          <?php else: ?>
            <table class="table align-middle table-hover">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Note Name</th>
                  <th>Uploaded</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($files as $index=>$n): ?>
                <tr>
                  <th><?= $index+1 ?></th>
                  <td><?= htmlspecialchars($n[1]) ?></td>
                  <td><?= date('d M Y, H:i', strtotime($n[4])) ?></td>
                  <td class="text-end">
                    <a href="note_download.php?id=<?= $n[0] ?>" title="Download" class="btn btn-sm btn-outline-secondary me-1">
                      <i class="fas fa-download"></i>
                    </a>
                    <?php
                    // ── Show preview button for ALL file types ──
                    $mime = $n[3] ?? '';
                    $ext  = strtolower(pathinfo($n[2], PATHINFO_EXTENSION));
                    if (str_starts_with($mime,'image/'))           $pvtype = 'image';
                    elseif ($mime==='application/pdf')             $pvtype = 'pdf';
                    elseif (str_starts_with($mime,'text/') || in_array($ext,['txt','md','csv','json','html','xml','py','java','js','css','php','c','cpp','h'])) $pvtype = 'text';
                    elseif (in_array($ext,['docx','doc']))         $pvtype = 'docx';
                    elseif (in_array($ext,['xlsx','xls','csv']))   $pvtype = 'sheet';
                    elseif (str_starts_with($mime,'audio/') || in_array($ext,['mp3','wav','ogg','m4a','aac','webm'])) $pvtype = 'audio';
                    elseif (str_starts_with($mime,'video/') || in_array($ext,['mp4','webm','ogv','mov']))             $pvtype = 'video';
                    else                                           $pvtype = 'other';
                    ?>
                    <button type="button" class="btn btn-sm btn-outline-info me-1 preview-btn"
                            data-file="<?= htmlspecialchars($n[2]) ?>"
                            data-name="<?= htmlspecialchars($n[1]) ?>"
                            data-mime="<?= htmlspecialchars($mime) ?>"
                            data-ext="<?= htmlspecialchars($ext) ?>"
                            data-type="<?= $pvtype ?>"
                            title="Preview">
                      <i class="fas fa-eye"></i>
                    </button>
                    <a href="my_note_nest.php?delete_file=<?= $n[0] ?>"
                       onclick="return confirm('Are you sure to delete this file?');"
                       class="btn btn-sm btn-outline-danger" title="Delete">
                      <i class="fas fa-trash"></i>
                    </a>
                    <a href="#" class="btn btn-sm btn-outline-warning me-1 favorite-btn" data-type="file" data-id="<?= $n[0] ?>" data-fav="<?= in_array($n[0], $fav_ids['file']) ? 1 : 0 ?>" title="Favorite">
                      <i class="fa<?= in_array($n[0], $fav_ids['file']) ? 's' : 'r' ?> fa-star"></i>
                    </a>
                                         <?php if (in_array($n[0], $shared_items['file'])): ?>
                       <a href="#" class="btn btn-sm btn-outline-success me-1 shared-status-btn" data-type="file" data-id="<?= $n[0] ?>" title="Manage Sharing">
                         <i class="fas fa-users"></i>
                       </a>
                     <?php endif; ?>
                     <a href="#" class="btn btn-sm btn-outline-info share-btn" data-type="file" data-id="<?= $n[0] ?>" title="Share"><i class="fa fa-share"></i></a>
                    <button type="button" class="btn btn-sm btn-outline-primary me-1 rename-file-btn" data-id="<?= $n[0] ?>" data-name="<?= htmlspecialchars($n[1]) ?>" title="Rename"><i class="fas fa-pen"></i></button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- ── Enhanced Preview Modal ── -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <!-- Header -->
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="previewLabel">
            <i class="fas fa-eye me-2" style="opacity:.8;"></i>
            <span id="pvFileName">Preview</span>
          </h5>
          <div id="pvMeta" style="font-size:.75rem;opacity:.7;margin-top:2px;"></div>
        </div>
        <div class="d-flex align-items-center gap-2 ms-auto">
          <a id="pvDownload" href="#" class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:8px;font-size:.8rem;">
            <i class="fas fa-download me-1"></i>Download
          </a>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
      </div>

      <!-- Toolbar (file type badge) -->
      <div class="preview-toolbar" id="pvToolbar">
        <span class="preview-badge" id="pvBadge">FILE</span>
        <span id="pvTypeLabel" style="font-size:.82rem;color:#555;"></span>
        <span class="preview-size" id="pvSizeLabel"></span>
      </div>

      <!-- Body -->
      <div class="modal-body">

        <!-- Loading -->
        <div id="pv-loading">
          <div class="pv-spinner"></div>
          <span>Loading preview...</span>
        </div>

        <!-- PDF -->
        <div id="pv-pdf" style="display:none;">
          <iframe id="pvPdfFrame" src="" title="PDF Preview"></iframe>
        </div>

        <!-- Image -->
        <div id="pv-image" style="display:none;">
          <img id="pvImg" src="" alt="Preview">
        </div>

        <!-- Text / Code -->
        <div id="pv-text" style="display:none;">
          <pre id="pvTextContent"></pre>
        </div>

        <!-- DOCX -->
        <div id="pv-docx" style="display:none;">
          <div id="pvDocxContent"></div>
        </div>

        <!-- XLSX / CSV -->
        <div id="pv-sheet" style="display:none;">
          <div id="pvSheetContent"></div>
        </div>

        <!-- Audio -->
        <div id="pv-audio" style="display:none;">
          <div class="audio-icon"><i class="fas fa-music"></i></div>
          <audio id="pvAudio" controls controlsList="nodownload">
            Your browser does not support audio.
          </audio>
          <p id="pvAudioName" style="margin-top:12px;font-weight:600;color:#0b4954;"></p>
        </div>

        <!-- Video -->
        <div id="pv-video" style="display:none;">
          <video id="pvVideo" controls controlsList="nodownload" style="max-width:100%;">
            Your browser does not support video.
          </video>
        </div>

        <!-- Unsupported -->
        <div id="pv-unsupported" style="display:none;">
          <div class="pv-icon" id="pvUnsupIcon">📁</div>
          <h5 style="color:#0b4954;font-weight:700;" id="pvUnsupTitle">Preview not available</h5>
          <p style="color:#888;font-size:.88rem;" id="pvUnsupDesc">This file type cannot be previewed in the browser.</p>
          <a id="pvUnsupDownload" href="#" class="btn mt-2" style="background:linear-gradient(135deg,#0b4954,#197f8f);color:#fff;border:none;border-radius:10px;padding:10px 24px;font-weight:600;">
            <i class="fas fa-download me-2"></i>Download File
          </a>
        </div>

      </div>
    </div>
  </div>
</div>
<!-- Rename Folder Modal -->
<div class="modal fade" id="renameFolderModal" tabindex="-1" aria-labelledby="renameFolderLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="renameFolderLabel">Rename Folder</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="rename_folder_id" id="rename_folder_id">
        <div class="mb-3">
            <label for="rename_folder_name" class="form-label">New Folder Name</label>
            <input type="text" name="rename_folder_name" id="rename_folder_name" class="form-control" maxlength="100" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="rename_folder" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>
<!-- Rename File Modal -->
<div class="modal fade" id="renameFileModal" tabindex="-1" aria-labelledby="renameFileLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="renameFileLabel">Rename File</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="rename_file_id" id="rename_file_id">
        <div class="mb-3">
            <label for="rename_file_name" class="form-label">New File Name</label>
            <input type="text" name="rename_file_name" id="rename_file_name" class="form-control" maxlength="100" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" name="rename_file" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>
<!-- Feedback Modal -->
<?php if($modal_message): ?>
<div class="modal fade" id="feedbackModal" tabindex="-1" aria-labelledby="feedbackModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="feedbackModalLabel">Notice</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <?= htmlspecialchars($modal_message) ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<!-- Add Share Modal -->
<div class="modal fade" id="shareModal" tabindex="-1" aria-labelledby="shareModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form id="shareForm" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="shareModalLabel">Share Item</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="item_type" id="share_item_type">
        <input type="hidden" name="item_id" id="share_item_id">
        <div class="mb-3">
          <label class="form-label">Recipient Email</label>
          <input type="email" name="recipient_email" class="form-control" required>
        </div>
        <div class="mb-3">
          <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Note:</strong> Shared items are view-only. Recipients can preview, download, and add to favorites.
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Share</button>
      </div>
    </form>
  </div>
</div>

<!-- Share Management Modal -->
<div class="modal fade" id="shareManagementModal" tabindex="-1" aria-labelledby="shareManagementLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="shareManagementLabel">Manage Sharing</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="shareManagementContent">
          <!-- Content will be loaded dynamically -->
        </div>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ════════════════════════════════════════════
// ENHANCED FILE PREVIEW SYSTEM
// ════════════════════════════════════════════
document.querySelectorAll('.preview-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        openPreview(
            btn.getAttribute('data-file'),
            btn.getAttribute('data-name'),
            btn.getAttribute('data-type'),
            btn.getAttribute('data-mime') || '',
            btn.getAttribute('data-ext')  || ''
        );
    });
});

function openPreview(filePath, fileName, type, mime, ext) {
    // Reset all panels
    ['pdf','image','text','docx','sheet','audio','video','unsupported'].forEach(id => {
        document.getElementById('pv-' + id).style.display = 'none';
    });
    document.getElementById('pv-loading').style.display = 'flex';

    // Header info
    document.getElementById('pvFileName').textContent   = fileName;
    document.getElementById('pvDownload').href          = 'note_download.php?path=' + encodeURIComponent(filePath);
    document.getElementById('pvUnsupDownload').href     = 'note_download.php?path=' + encodeURIComponent(filePath);

    // Badge info
    const badgeMap = {
        pdf:'PDF', image:'IMAGE', text:'TEXT', docx:'WORD',
        sheet:'SHEET', audio:'AUDIO', video:'VIDEO', other:'FILE'
    };
    const typeLabels = {
        pdf:'Adobe PDF Document', image:'Image file',
        text:'Text / Code file', docx:'Word Document (DOCX)',
        sheet:'Spreadsheet / CSV', audio:'Audio recording',
        video:'Video file', other:'Binary file'
    };
    document.getElementById('pvBadge').textContent     = badgeMap[type] || ext.toUpperCase() || 'FILE';
    document.getElementById('pvTypeLabel').textContent = typeLabels[type] || mime || 'Unknown type';

    // Show modal first
    const pvModal = new bootstrap.Modal(document.getElementById('previewModal'));
    pvModal.show();

    const apiUrl = 'note_preview.php?file=' + encodeURIComponent(filePath);

    // ── Render by type ──────────────────────────────────────
    if (type === 'pdf') {
        document.getElementById('pvPdfFrame').src = apiUrl;
        document.getElementById('pvPdfFrame').onload = () => hideLoading();
        show('pv-pdf');

    } else if (type === 'image') {
        const img = document.getElementById('pvImg');
        img.onload  = () => { hideLoading(); show('pv-image'); };
        img.onerror = () => showUnsupported('🖼️', 'Cannot display image', 'The image format may not be supported.');
        img.src = apiUrl;

    } else if (type === 'audio') {
        const audio = document.getElementById('pvAudio');
        audio.src = apiUrl;
        document.getElementById('pvAudioName').textContent = fileName;
        hideLoading(); show('pv-audio');

    } else if (type === 'video') {
        const video = document.getElementById('pvVideo');
        video.src = apiUrl;
        hideLoading(); show('pv-video');

    } else if (type === 'text') {
        fetch(apiUrl)
          .then(r => r.text())
          .then(text => {
              const pre = document.getElementById('pvTextContent');
              pre.textContent = text;
              document.getElementById('pvMeta').textContent = text.split('\n').length + ' lines';
              hideLoading(); show('pv-text');
          })
          .catch(() => showUnsupported('📄', 'Cannot load file', 'Failed to fetch text content.'));

    } else if (type === 'docx') {
        fetch(apiUrl)
          .then(r => r.arrayBuffer())
          .then(buf => {
              mammoth.convertToHtml({ arrayBuffer: buf })
                .then(result => {
                    document.getElementById('pvDocxContent').innerHTML = result.value || '<p style="color:#aaa">Empty document</p>';
                    hideLoading(); show('pv-docx');
                })
                .catch(() => showUnsupported('📄', 'DOCX Render Failed', 'Download the file to view in Microsoft Word.'));
          })
          .catch(() => showUnsupported('📄', 'Cannot Load DOCX', 'Failed to fetch the document.'));

    } else if (type === 'sheet') {
        if (ext === 'csv') {
            // CSV — fetch as text, parse manually
            fetch(apiUrl)
              .then(r => r.text())
              .then(csv => {
                  renderCSV(csv);
                  hideLoading(); show('pv-sheet');
              })
              .catch(() => showUnsupported('📊', 'Cannot load CSV', ''));
        } else {
            // XLSX — fetch as arraybuffer, parse with SheetJS
            fetch(apiUrl)
              .then(r => r.arrayBuffer())
              .then(buf => {
                  try {
                      const wb   = XLSX.read(buf, { type: 'array' });
                      const ws   = wb.Sheets[wb.SheetNames[0]];
                      const html = XLSX.utils.sheet_to_html(ws, { editable: false });
                      document.getElementById('pvSheetContent').innerHTML = html;
                      hideLoading(); show('pv-sheet');
                  } catch(e) {
                      showUnsupported('📊', 'Cannot render sheet', 'Download to open in Excel.');
                  }
              })
              .catch(() => showUnsupported('📊', 'Cannot load file', ''));
        }

    } else {
        // Unsupported
        const icons = { docx:'📄', xlsx:'📊', pptx:'📑', zip:'🗜️', rar:'🗜️', exe:'⚙️' };
        showUnsupported(icons[ext] || '📁', 'Preview not available',
            'This file type cannot be previewed in the browser. Click below to download.');
    }
}

// ── CSV Renderer ──────────────────────────────────────────────
function renderCSV(csv) {
    const rows = csv.trim().split('\n').map(r => r.split(','));
    let html = '<table>';
    rows.forEach((row, i) => {
        html += i === 0 ? '<tr>' : '<tr>';
        row.forEach(cell => {
            const val = cell.replace(/"/g,'').trim();
            html += i === 0 ? `<th>${escH(val)}</th>` : `<td>${escH(val)}</td>`;
        });
        html += '</tr>';
    });
    html += '</table>';
    document.getElementById('pvSheetContent').innerHTML = html;
}

// ── Helpers ───────────────────────────────────────────────────
function hideLoading() { document.getElementById('pv-loading').style.display = 'none'; }
function show(id)      { document.getElementById(id).style.display = 'block'; }
function showUnsupported(icon, title, desc) {
    hideLoading();
    document.getElementById('pvUnsupIcon').textContent  = icon;
    document.getElementById('pvUnsupTitle').textContent = title;
    document.getElementById('pvUnsupDesc').textContent  = desc;
    show('pv-unsupported');
}
function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Clear media src when modal closes (stop audio/video playback)
document.getElementById('previewModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('pvAudio').src = '';
    document.getElementById('pvVideo').src = '';
    document.getElementById('pvPdfFrame').src = '';
    document.getElementById('pvImg').src = '';
});
document.querySelectorAll('.rename-folder-btn').forEach(function(btn) {
    btn.addEventListener('click', function () {
        document.getElementById('rename_folder_id').value = btn.getAttribute('data-id');
        document.getElementById('rename_folder_name').value = btn.getAttribute('data-name');
        let modal = new bootstrap.Modal(document.getElementById('renameFolderModal'));
        modal.show();
        setTimeout(function() {
            document.getElementById('rename_folder_name').focus();
        }, 150);
    });
});
<?php if ($modal_message): ?>
    const feedbackModal = new bootstrap.Modal(document.getElementById('feedbackModal'));
    window.addEventListener('DOMContentLoaded', function() { feedbackModal.show(); });
    document.getElementById('feedbackModal').addEventListener('hidden.bs.modal', function () {
        window.location.replace(window.location.pathname + window.location.search);
    });
    setTimeout(() => { feedbackModal.hide(); }, 2500);
<?php endif; ?>
<?php if (!empty($_SESSION['history_flatten'])): ?>
    if (window.history.replaceState) {
        window.history.replaceState(null, '', window.location.pathname + window.location.search);
    }
<?php unset($_SESSION['history_flatten']); endif; ?>
// Add favorite functionality
document.querySelectorAll('.favorite-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        let type = btn.getAttribute('data-type');
        let id = btn.getAttribute('data-id');
        let isFav = btn.getAttribute('data-fav') === '1';
        let formData = new FormData();
        formData.append('favorite_item', 1);
        formData.append('item_type', type);
        formData.append('item_id', id);
        formData.append('is_fav', isFav ? 1 : 0);
        fetch('', {method:'POST', body:formData})
          .then(r=>r.text())
          .then(() => { location.reload(); });
    });
});
// Add share functionality
document.querySelectorAll('.share-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        let type = btn.getAttribute('data-type');
        let id = btn.getAttribute('data-id');
        document.getElementById('share_item_type').value = type;
        document.getElementById('share_item_id').value = id;
        let modal = new bootstrap.Modal(document.getElementById('shareModal'));
        modal.show();
    });
});
// Handle share form submission
document.getElementById('shareForm').addEventListener('submit', function(e) {
    e.preventDefault();
    let formData = new FormData(this);
    formData.append('share_item', 1);
    fetch('', {method:'POST', body:formData})
      .then(r=>r.text())
      .then(msg => {
        alert(msg);
        location.reload();
      });
});
// Add rename file functionality
document.querySelectorAll('.rename-file-btn').forEach(function(btn) {
    btn.addEventListener('click', function () {
        document.getElementById('rename_file_id').value = btn.getAttribute('data-id');
        document.getElementById('rename_file_name').value = btn.getAttribute('data-name');
        let modal = new bootstrap.Modal(document.getElementById('renameFileModal'));
        modal.show();
        setTimeout(function() {
            document.getElementById('rename_file_name').focus();
        }, 150);
    });
});

// Add share management functionality (robust revoke handler)
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.shared-status-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      let type = btn.getAttribute('data-type');
      let id = btn.getAttribute('data-id');
      fetch('share_management.php?item_type=' + type + '&item_id=' + id)
        .then(response => response.text())
        .then(html => {
          document.getElementById('shareManagementContent').innerHTML = html;
          let modal = new bootstrap.Modal(document.getElementById('shareManagementModal'));
          modal.show();
          // Attach revoke handler
          document.querySelectorAll('.revoke-btn').forEach(function(revokeBtn) {
            revokeBtn.onclick = function() {
              let type = revokeBtn.getAttribute('data-type');
              let item_id = revokeBtn.getAttribute('data-id');
              let recipient = revokeBtn.getAttribute('data-recipient');
              let name = revokeBtn.getAttribute('data-name');
              if (confirm('Are you sure you want to revoke access for ' + name + '?')) {
                let formData = new FormData();
                formData.append('revoke_share', 1);
                formData.append('item_type', type);
                formData.append('item_id', item_id);
                formData.append('recipient_id', recipient);
                fetch('my_note_nest.php', {method: 'POST', body: formData})
                  .then(response => response.text())
                  .then(msg => {
                    alert(msg);
                    location.reload();
                  });
              }
            };
          });
        });
    });
  });
});
</script>
</body>
</html>
