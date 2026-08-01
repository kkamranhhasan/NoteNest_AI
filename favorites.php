<?php
require 'includes/auth.php';
require_once 'config.php';
require 'includes/db.php';
require_once 'includes/functions.php';
$user_id = $_SESSION['user_id'];
$modal_message = '';

// Auto-migrate schema to ensure VARCHAR(50) and unique key constraint
@$conn->query("ALTER TABLE favorites MODIFY COLUMN item_type VARCHAR(50) NOT NULL");
@$conn->query("ALTER TABLE favorites ADD UNIQUE KEY unique_user_item (user_id, item_type, item_id)");

// Handle favorite/unfavorite
if (isset($_POST['favorite_item'])) {
    $item_type = trim($_POST['item_type'] ?? '');
    $item_id   = intval($_POST['item_id'] ?? 0);
    $is_fav    = isset($_POST['is_fav']) && $_POST['is_fav'] == 1;

    if (in_array($item_type, ['file', 'folder', 'course', 'topic']) && $item_id > 0) {
        if ($is_fav) {
            $stmt = $conn->prepare('DELETE FROM favorites WHERE user_id=? AND item_type=? AND item_id=?');
            $stmt->bind_param('isi', $user_id, $item_type, $item_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare('INSERT INTO favorites (user_id, item_type, item_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE created_at = CURRENT_TIMESTAMP');
            $stmt->bind_param('isi', $user_id, $item_type, $item_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    exit('ok');
}

// Get favorites for this user
$fav_ids = ['file' => [], 'folder' => [], 'course' => [], 'topic' => []];
$res = $conn->query("SELECT item_type, item_id FROM favorites WHERE user_id=$user_id");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $t = $row['item_type'];
        if (!isset($fav_ids[$t])) $fav_ids[$t] = [];
        $fav_ids[$t][] = (int)$row['item_id'];
    }
}

// Collect all target folder IDs (direct folders, course root folders, topic subfolders)
$target_folder_ids = [];
if (!empty($fav_ids['folder'])) {
    foreach ($fav_ids['folder'] as $id) $target_folder_ids[] = $id;
}
if (!empty($fav_ids['course'])) {
    $cIds = implode(',', array_map('intval', $fav_ids['course']));
    $cRes = $conn->query("SELECT id FROM folders WHERE id IN ($cIds) UNION SELECT id FROM folders WHERE course_id IN ($cIds) AND is_course_root = 1");
    if ($cRes) {
        while ($r = $cRes->fetch_assoc()) $target_folder_ids[] = (int)$r['id'];
    }
}
if (!empty($fav_ids['topic'])) {
    $tIds = implode(',', array_map('intval', $fav_ids['topic']));
    $tRes = $conn->query("SELECT id FROM folders WHERE id IN ($tIds) UNION SELECT folder_id FROM course_topics WHERE id IN ($tIds) AND folder_id IS NOT NULL");
    if ($tRes) {
        while ($r = $tRes->fetch_assoc()) $target_folder_ids[] = (int)$r['id'];
    }
}
$target_folder_ids = array_unique(array_filter($target_folder_ids));

// Get favorite folders with owner info & course metadata
$favorite_folders = [];
if (!empty($target_folder_ids)) {
    $folder_ids_str = implode(',', $target_folder_ids);
    $stmt = $conn->prepare("
        SELECT f.id, f.name, f.created_at, u.name as owner_name, 
               CASE WHEN f.owner_id = ? THEN 'own' ELSE 'shared' END as access_type,
               COALESCE(f.is_course_root, 0) as is_course_root,
               c.code as course_code, c.color as course_color
        FROM folders f 
        JOIN users u ON f.owner_id = u.id
        LEFT JOIN courses c ON f.course_id = c.id
        WHERE f.id IN ($folder_ids_str)
        AND (f.owner_id = ? OR EXISTS (
            SELECT 1 FROM shared_access sa 
            WHERE sa.item_type='folder' AND sa.item_id=f.id AND sa.shared_with_user_id=?
        ))
        ORDER BY f.created_at DESC
    ");
    if ($stmt) {
        $stmt->bind_param('iii', $user_id, $user_id, $user_id);
        $stmt->execute();
        $resF = $stmt->get_result();
        while ($row = $resF->fetch_assoc()) {
            $favorite_folders[] = [
                'id'           => (int)$row['id'],
                'name'         => $row['name'],
                'created_at'   => $row['created_at'],
                'owner_name'   => $row['owner_name'],
                'access_type'  => $row['access_type'],
                'is_course_root'=> (int)$row['is_course_root'],
                'course_code'  => $row['course_code'],
                'course_color' => $row['course_color'] ?: '#197f8f',
            ];
        }
        $stmt->close();
    }
}

// Collect all target file IDs
$target_file_ids = [];
if (!empty($fav_ids['file'])) {
    foreach ($fav_ids['file'] as $id) $target_file_ids[] = $id;
}
$target_file_ids = array_unique(array_filter($target_file_ids));

// Get favorite files with owner info
$favorite_files = [];
if (!empty($target_file_ids)) {
    $file_ids_str = implode(',', $target_file_ids);
    $stmt = $conn->prepare("
        SELECT f.id, f.name, f.file_path, f.mime_type, f.created_at, u.name as owner_name,
               CASE WHEN f.owner_id = ? THEN 'own' ELSE 'shared' END as access_type
        FROM files f 
        JOIN users u ON f.owner_id = u.id
        WHERE f.id IN ($file_ids_str)
        AND (f.owner_id = ? OR EXISTS (
            SELECT 1 FROM shared_access sa 
            WHERE sa.item_type='file' AND sa.item_id=f.id AND sa.shared_with_user_id=?
        ))
        ORDER BY f.created_at DESC
    ");
    if ($stmt) {
        $stmt->bind_param('iii', $user_id, $user_id, $user_id);
        $stmt->execute();
        $resFile = $stmt->get_result();
        while ($row = $resFile->fetch_assoc()) {
            $favorite_files[] = [
                'id'          => (int)$row['id'],
                'name'        => $row['name'],
                'file_path'   => $row['file_path'],
                'mime_type'   => $row['mime_type'],
                'created_at'  => $row['created_at'],
                'owner_name'  => $row['owner_name'],
                'access_type' => $row['access_type'],
            ];
        }
        $stmt->close();
    }
}

if (isset($_SESSION['success_msg'])) {
    $modal_message = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}

if ($modal_message) {
    echo "<script>if (history.replaceState) history.replaceState(null, '', window.location.pathname);</script>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>My Favorites - NoteNest</title>
  <link rel="shortcut icon" href="img/fav.ico" type="image/x-icon">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="css/favorites.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<div class="container py-4">
  <div class="row g-4">
    <div class="col-md-4">
      <!-- INFO CARD -->
      <div class="card mb-3">
        <div class="card-header">
          <i class="fas fa-star me-2"></i>My Favorites
        </div>
        <div class="card-body">
          <p class="mb-2"><strong>Favorite Folders:</strong> <?= count($favorite_folders) ?></p>
          <p class="mb-0"><strong>Favorite Files:</strong> <?= count($favorite_files) ?></p>
        </div>
      </div>
    </div>
    
    <div class="col-md-8">
      <!-- FAVORITE FOLDERS HEADING -->
      <div class="section-heading mb-2">
        <i class="fas fa-folder-open"></i> Favorite Folders &amp; Courses
      </div>
      
      <!-- FAVORITE FOLDER LIST -->
      <?php if(empty($favorite_folders)): ?>
        <p class="text-muted">No favorite folders yet.</p>
      <?php else: ?>
        <ul class="list-group folder-list-group mb-3">
          <?php foreach($favorite_folders as $f): ?>
            <li class="list-group-item d-flex align-items-center justify-content-between">
              <div>
                <?php if ($f['access_type'] === 'own'): ?>
                  <a href="my_note_nest.php?folder=<?= $f['id'] ?>" class="folder-link text-decoration-none fw-semibold">
                    <i class="fa fa-folder folder-icon me-1" style="color:<?= htmlspecialchars($f['course_color']) ?>"></i><?= htmlspecialchars($f['name']) ?>
                  </a>
                <?php else: ?>
                  <a href="shared_note_nest.php?folder=<?= $f['id'] ?>" class="folder-link text-decoration-none fw-semibold">
                    <i class="fa fa-folder folder-icon me-1"></i><?= htmlspecialchars($f['name']) ?>
                  </a>
                <?php endif; ?>
                <?php if (!empty($f['course_code'])): ?>
                  <span class="badge ms-1" style="background:<?= htmlspecialchars($f['course_color']) ?>;color:#fff;font-size:.68rem;">
                    <?= htmlspecialchars($f['course_code']) ?>
                  </span>
                <?php endif; ?>
                <small class="text-muted d-block mt-1">
                  <?= $f['access_type'] === 'own' ? 'My folder' : 'Shared by: ' . htmlspecialchars($f['owner_name']) ?>
                  <span class="badge bg-<?= $f['access_type'] === 'own' ? 'primary' : 'info' ?>">
                    <?= $f['access_type'] === 'own' ? 'Owner' : 'View Only' ?>
                  </span>
                </small>
                <small class="text-muted">Created: <?= date('d M Y, H:i', strtotime($f['created_at'])) ?></small>
              </div>
              <div>
                <a href="#" class="btn btn-sm btn-outline-warning me-1 favorite-btn" data-type="folder" data-id="<?= $f['id'] ?>" data-fav="1" title="Remove from Favorites">
                  <i class="fas fa-star"></i>
                </a>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      
      <!-- FAVORITE FILES HEADING -->
      <div class="section-heading mb-2" style="margin-top:32px;">
        <i class="fas fa-note-sticky"></i> Favorite Files
      </div>
      
      <div class="card">
        <div class="card-body table-responsive">
          <?php if(empty($favorite_files)): ?>
            <div class="alert alert-secondary text-muted mb-0">No favorite files yet.</div>
          <?php else: ?>
            <table class="table align-middle table-hover">
              <thead>
                <tr>
                  <th>#</th>
                  <th>File Name</th>
                  <th>Type</th>
                  <th>Owner / Status</th>
                  <th>Created Date</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($favorite_files as $index=>$n): 
                  $mime = $n['mime_type'] ?? '';
                  $is_own = ($n['access_type'] ?? '') === 'own';
                ?>
                  <tr>
                    <th><?= $index+1 ?></th>
                    <td><?= htmlspecialchars($n['name']) ?></td>
                    <td>
                      <?php if (strpos($mime, 'image/') === 0): ?>
                        <span class="badge bg-success">Image</span>
                      <?php elseif (strpos($mime, 'text/') === 0): ?>
                        <span class="badge bg-info">Text</span>
                      <?php elseif (strpos($mime, 'application/pdf') === 0): ?>
                        <span class="badge bg-danger">PDF</span>
                      <?php else: ?>
                        <span class="badge bg-secondary">File</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($is_own): ?>
                        <span class="text-primary">My file</span>
                      <?php else: ?>
                        <?= htmlspecialchars($n['owner_name'] ?? 'Shared') ?>
                      <?php endif; ?>
                    </td>
                    <td><?= date('d M Y, H:i', strtotime($n['created_at'])) ?></td>
                    <td class="text-end">
                      <a href="note_download.php?id=<?= $n['id'] ?>" title="Download" class="btn btn-sm btn-outline-secondary me-1">
                        <i class="fas fa-download"></i>
                      </a>
                      
                      <a href="note_preview.php?file=<?= $n['id'] ?>" target="_blank" class="btn btn-sm btn-outline-info me-1" title="Preview">
                        <i class="fas fa-eye"></i>
                      </a>
                      
                      <a href="#" class="btn btn-sm btn-outline-warning me-1 favorite-btn" data-type="file" data-id="<?= $n['id'] ?>" data-fav="1" title="Remove from Favorites">
                        <i class="fas fa-star"></i>
                      </a>
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

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="previewLabel">File Preview</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="previewContent"></div>
      </div>
    </div>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.preview-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        let file = btn.getAttribute('data-file');
        let name = btn.getAttribute('data-name');
        let type = btn.getAttribute('data-type');
        let previewContent = document.getElementById('previewContent');
        
        if (type === 'image') {
            previewContent.innerHTML = '<img src="' + file + '" style="max-width:100%;max-height:60vh;">';
        } else if (type === 'text') {
            fetch('note_preview.php?file=' + encodeURIComponent(file))
              .then(r=>r.text())
              .then(text => {
                  previewContent.innerHTML = '<pre style="font-family:inherit;max-height:60vh;overflow-y:auto;">' + text + '</pre>';
              });
        } else if (type === 'pdf') {
            previewContent.innerHTML = '<iframe src="' + file + '" style="width:100%;height:60vh;" frameborder="0"></iframe>';
        }
        
        document.getElementById('previewLabel').textContent = name + " (Preview)";
        let modal = new bootstrap.Modal(document.getElementById('previewModal'));
        modal.show();
    });
});

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

<?php if ($modal_message): ?>
    const feedbackModal = new bootstrap.Modal(document.getElementById('feedbackModal'));
    window.addEventListener('DOMContentLoaded', function() { feedbackModal.show(); });
    document.getElementById('feedbackModal').addEventListener('hidden.bs.modal', function () {
        window.location.replace(window.location.pathname);
    });
    setTimeout(() => { feedbackModal.hide(); }, 2500);
<?php endif; ?>
</script>
</body>
</html>
