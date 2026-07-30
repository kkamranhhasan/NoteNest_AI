<?php
// filepath: c:\xampp\htdocs\NoteNest\profile.php
require 'includes/auth.php';
require 'config.php';
require_once 'includes/google_classroom_service.php';

$user_id = $_SESSION['user_id'];
$modal_message = "";

// Fetch user info
$stmt = $conn->prepare("SELECT name, email, phone, gender, photo, created_at FROM users WHERE id=?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stmt->bind_result($name, $email, $phone, $gender, $photo, $created_at);
$stmt->fetch();
$stmt->close();

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_name = trim($_POST['name'] ?? '');
    $new_phone = trim($_POST['phone'] ?? '');
    $new_gender = $_POST['gender'] ?? '';
    $new_pass = $_POST['password'] ?? '';
    $new_pass2 = $_POST['confirm_password'] ?? '';
    $update_photo = $photo;

    // Handle photo upload
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $target_dir = "img/user_photos/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            $new_filename = 'user_' . $user_id . '_' . time() . '.' . $ext;
            $target_file = $target_dir . $new_filename;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) {
                // Optionally delete old photo if not default
                if ($photo && $photo !== 'img/user.png' && file_exists($photo)) {
                    @unlink($photo);
                }
                $update_photo = $target_file;
            } else {
                $modal_message = "Failed to upload photo.";
            }
        } else {
            $modal_message = "Invalid photo format. Allowed: jpg, jpeg, png, gif.";
        }
    }

    if ($new_name === '') $modal_message = "Name is required.";
    elseif ($new_pass && $new_pass !== $new_pass2) $modal_message = "Passwords do not match.";
    else {
        if ($new_pass) {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET name=?, phone=?, gender=?, password=?, photo=? WHERE id=?");
            $stmt->bind_param('sssssi', $new_name, $new_phone, $new_gender, $hash, $update_photo, $user_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET name=?, phone=?, gender=?, photo=? WHERE id=?");
            $stmt->bind_param('ssssi', $new_name, $new_phone, $new_gender, $update_photo, $user_id);
        }
        $stmt->execute(); $stmt->close();
        $_SESSION['user_name'] = $new_name;
        $modal_message = "Profile updated!";
        header("Location: profile.php");
        exit;
    }
    // Refresh photo if changed
    $photo = $update_photo;
}

// Google Classroom connection data
$gc_account = gc_get_account($conn, $user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Profile - NoteNest</title>
  <link rel="shortcut icon" href="img/fav.ico" type="image/x-icon">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #0b4954;
      --accent: #197f8f;
      --bg: #f0f4f8;
    }
    body {
      font-family: 'Inter', sans-serif;
      background: var(--bg);
    }
    .profile-card {
      border: none;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(11,73,84,0.06);
      background: #fff;
      margin-bottom: 24px;
      overflow: hidden;
    }
    .profile-cover {
      height: 100px;
      background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
    }
    .profile-avatar-wrapper {
      margin-top: -55px;
      margin-bottom: 12px;
    }
    .profile-avatar-img {
      width: 100px;
      height: 100px;
      border-radius: 50%;
      border: 4px solid #fff;
      box-shadow: 0 6px 15px rgba(0,0,0,0.1);
      object-fit: cover;
    }
    .card-title-custom {
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--primary);
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .form-control, .form-select {
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 10px 14px;
      font-size: 0.9rem;
      transition: all 0.2s;
    }
    .form-control:focus, .form-select:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(25, 127, 143, 0.15);
    }
    .form-label {
      font-weight: 600;
      color: #334155;
      font-size: 0.85rem;
      margin-bottom: 6px;
    }
    .btn-save {
      background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
      color: #fff;
      border: none;
      border-radius: 10px;
      padding: 12px 24px;
      font-weight: 600;
      transition: all 0.2s;
      width: 100%;
    }
    .btn-save:hover {
      opacity: 0.95;
      transform: translateY(-1px);
      color: #fff;
      box-shadow: 0 4px 12px rgba(11, 73, 84, 0.2);
    }
    .btn-logout {
      background: #fff;
      color: #e74c3c;
      border: 1px solid #fccdca;
      border-radius: 10px;
      padding: 10px 20px;
      font-weight: 600;
      transition: all 0.2s;
      width: 100%;
    }
    .btn-logout:hover {
      background: #fdf2f2;
      color: #c0392b;
      border-color: #f8b4b0;
    }

    /* Custom Logout Modal Styles */
    .custom-modal-overlay {
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(11, 73, 84, 0.4);
        backdrop-filter: blur(8px);
        display: flex; align-items: center; justify-content: center;
        z-index: 9999;
        opacity: 0; pointer-events: none;
        transition: opacity 0.3s ease;
    }
    .custom-modal-overlay.active {
        opacity: 1; pointer-events: all;
    }
    .custom-modal-card {
        background: #fff;
        border-radius: 16px;
        padding: 32px;
        width: 90%; max-width: 400px;
        box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        transform: scale(0.9);
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        text-align: center;
    }
    .custom-modal-overlay.active .custom-modal-card {
        transform: scale(1);
    }
    .custom-modal-icon {
        font-size: 3rem;
        color: #e74c3c;
        margin-bottom: 16px;
    }
    .custom-modal-title {
        font-size: 1.3rem;
        font-weight: 700;
        color: #0b4954;
        margin-bottom: 8px;
    }
    .custom-modal-text {
        font-size: 0.9rem;
        color: #666;
        margin-bottom: 24px;
    }
    .custom-modal-btn-confirm {
        background: #e74c3c;
        color: #fff;
        border: none;
        padding: 10px 24px;
        border-radius: 8px;
        font-weight: 600;
        margin-right: 12px;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-block;
    }
    .custom-modal-btn-confirm:hover {
        background: #c0392b;
        color: #fff;
        transform: translateY(-1px);
    }
    .custom-modal-btn-cancel {
        background: #f1f2f6;
        color: #555;
        border: none;
        padding: 10px 24px;
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.2s;
        display: inline-block;
    }
    .custom-modal-btn-cancel:hover {
        background: #dfe4ea;
        transform: translateY(-1px);
    }
  </style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>

<div class="container py-4">
  <div class="row g-4">
    
    <!-- ── Left Column (Profile Summary Card) ── -->
    <div class="col-lg-4">
      <div class="profile-card text-center pb-4">
        <div class="profile-cover"></div>
        <div class="profile-avatar-wrapper">
          <img src="<?= htmlspecialchars($photo ?: 'img/user.png') ?>" alt="Profile" class="profile-avatar-img" id="profileImgPreviewSide">
        </div>
        <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($name) ?></h5>
        <p class="text-muted small mb-3"><?= htmlspecialchars($email) ?></p>

        <div class="px-4 mb-4">
          <div class="p-3 bg-light rounded-3 text-start small">
            <div class="d-flex justify-content-between mb-2">
              <span class="text-secondary">Joined:</span>
              <span class="fw-semibold text-dark"><?= date('M d, Y', strtotime($created_at)) ?></span>
            </div>
            <div class="d-flex justify-content-between mb-2">
              <span class="text-secondary">Phone:</span>
              <span class="fw-semibold text-dark"><?= htmlspecialchars($phone ?: 'Not set') ?></span>
            </div>
            <div class="d-flex justify-content-between">
              <span class="text-secondary">Google Classroom:</span>
              <?php if ($gc_account): ?>
                <span class="badge bg-success-subtle text-success border border-success-subtle px-2">Connected</span>
              <?php else: ?>
                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2">Disconnected</span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="px-4">
          <button type="button" class="btn btn-logout logout-btn-trigger">
            <i class="fas fa-sign-out-alt me-2"></i> Log Out
          </button>
        </div>
      </div>
    </div>

    <!-- ── Right Column (Edit Settings & Integrations) ── -->
    <div class="col-lg-8">
      
      <!-- Edit Profile Card -->
      <div class="profile-card p-4">
        <div class="card-title-custom">
          <i class="fas fa-user-cog"></i> Account Settings
        </div>
        
        <form method="post" enctype="multipart/form-data" autocomplete="off">
          <div class="row align-items-center g-3 mb-4">
            <div class="col-auto">
              <img src="<?= htmlspecialchars($photo ?: 'img/user.png') ?>" alt="Profile" class="profile-avatar-img" id="profileImgPreviewForm" style="width: 80px; height: 80px; margin-top: 0;">
            </div>
            <div class="col">
              <label for="photo" class="form-label mb-1">Change Profile Photo</label>
              <input type="file" name="photo" id="photo" class="form-control form-control-sm" accept="image/*" onchange="previewPhoto(this)">
              <small class="text-muted" style="font-size: 0.75rem;">Allowed: jpg, jpeg, png, gif</small>
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">Full Name</label>
              <input type="text" name="name" class="form-control" maxlength="100" value="<?= htmlspecialchars($name) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email Address</label>
              <input type="email" class="form-control" value="<?= htmlspecialchars($email) ?>" disabled>
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label">Phone Number</label>
              <input type="text" name="phone" class="form-control" maxlength="20" value="<?= htmlspecialchars($phone) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Gender</label>
              <select name="gender" class="form-select" required>
                <option value="">Select Gender</option>
                <option value="Male" <?= $gender=='Male'?'selected':'' ?>>Male</option>
                <option value="Female" <?= $gender=='Female'?'selected':'' ?>>Female</option>
                <option value="Other" <?= $gender=='Other'?'selected':'' ?>>Other</option>
              </select>
            </div>
          </div>

          <div class="card-title-custom mb-3 mt-4" style="font-size: 1rem;">
            <i class="fas fa-lock text-secondary"></i> Security & Password
          </div>

          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label">New Password</label>
              <input type="password" name="password" class="form-control" placeholder="Leave blank to keep unchanged">
            </div>
            <div class="col-md-6">
              <label class="form-label">Confirm New Password</label>
              <input type="password" name="confirm_password" class="form-control" placeholder="Leave blank to keep unchanged">
            </div>
          </div>

          <button type="submit" class="btn btn-save"><i class="fas fa-save me-1"></i> Save Changes</button>
        </form>
      </div>

      <!-- Google Classroom Connection Card -->
      <div class="profile-card p-4">
        <div class="card-title-custom mb-3" style="color: #4285f4;">
          <i class="fab fa-google"></i> Google Classroom Integration
        </div>
        
        <?php if ($gc_account): ?>
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
              <div class="rounded-3 d-flex align-items-center justify-content-center text-white" style="width:48px;height:48px;background:linear-gradient(135deg,#4285f4,#3367d6);font-size:1.2rem;">
                <i class="fab fa-google"></i>
              </div>
              <div>
                <div class="d-flex align-items-center gap-2">
                  <span class="fw-semibold text-dark" style="font-size: 0.95rem;"><?= htmlspecialchars($gc_account['google_email']) ?></span>
                  <span class="badge bg-success-subtle text-success border border-success-subtle py-1" style="font-size: 0.7rem;"><i class="fas fa-check-circle me-1"></i>Connected</span>
                </div>
                <div class="text-muted" style="font-size:0.75rem; margin-top: 3px;">
                  Connected on <?= date('M d, Y', strtotime($gc_account['connected_at'])) ?>
                  &nbsp;·&nbsp;
                  Last sync: <?= $gc_account['last_sync_at'] ? date('M d, g:i A', strtotime($gc_account['last_sync_at'])) : 'Never' ?>
                </div>
              </div>
            </div>
            <div class="d-flex gap-2">
              <a href="google_classroom.php" class="btn btn-sm btn-outline-primary px-3 py-2 fw-semibold" style="border-radius:8px;font-size:.82rem;">
                <i class="fas fa-external-link-alt me-1"></i>Manage Sync
              </a>
              <button class="btn btn-sm btn-outline-danger px-3 py-2 fw-semibold" style="border-radius:8px;font-size:.82rem;" onclick="if(confirm('Disconnect Google account? Synced data stays but no new sync.'))location.href='google_disconnect.php'">
                <i class="fas fa-unlink me-1"></i>Disconnect
              </button>
            </div>
          </div>
          <?php if ($gc_account['sync_error']): ?>
            <div class="alert alert-warning mt-3 mb-0 d-flex align-items-center gap-2" style="font-size:.82rem;border-radius:10px;">
              <i class="fas fa-exclamation-triangle"></i>
              <span><?= htmlspecialchars(substr($gc_account['sync_error'], 0, 150)) ?></span>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="text-center py-3">
            <div class="rounded-circle d-flex align-items-center justify-content-center text-secondary" style="width:64px;height:64px;margin:0 auto 14px;background:#f8fafc;border: 1px solid #e2e8f0;font-size:1.6rem;">
              <i class="fab fa-google"></i>
            </div>
            <h6 class="fw-semibold text-dark">Connect Google Classroom</h6>
            <p class="text-muted small mx-auto mb-4" style="max-width: 480px;">Automatically import your academic courses, class materials, assignments, deadlines and notifications directly into your NoteNest account.</p>
            <a href="google_auth.php" class="btn btn-outline-secondary px-4 py-2 fw-semibold d-inline-flex align-items-center gap-2" style="border-radius:12px;font-size:.88rem; transition: all 0.2s;">
              <img src="https://developers.google.com/identity/images/g-logo.png" alt="G" style="width:18px;height:18px;">
              Connect Google Account
            </a>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<!-- Custom Logout Confirmation Modal -->
<div class="custom-modal-overlay" id="customLogoutModal">
  <div class="custom-modal-card">
    <div class="custom-modal-icon">
      <i class="fas fa-sign-out-alt"></i>
    </div>
    <div class="custom-modal-title">Confirm Logout</div>
    <div class="custom-modal-text">Are you sure you want to log out of NoteNest? Your current session will end.</div>
    <div class="d-flex justify-content-center">
      <a href="logout.php" class="custom-modal-btn-confirm text-decoration-none">Logout</a>
      <button type="button" class="custom-modal-btn-cancel">Cancel</button>
    </div>
  </div>
</div>

<?php if($modal_message): ?>
<script>
  window.onload = () => alert("<?= htmlspecialchars($modal_message) ?>");
</script>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
function previewPhoto(input) {
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    reader.onload = function(e) {
      document.getElementById('profileImgPreviewSide').src = e.target.result;
      document.getElementById('profileImgPreviewForm').src = e.target.result;
    }
    reader.readAsDataURL(input.files[0]);
  }
}

// ── Logout Confirmation Modal Logic ──
$(function() {
  $(document).on('click', '.logout-btn-trigger', function(e) {
    e.preventDefault();
    $('#customLogoutModal').addClass('active');
  });
  
  $(document).on('click', '.custom-modal-btn-cancel, .custom-modal-overlay', function(e) {
    if (e.target === this || $(e.target).hasClass('custom-modal-btn-cancel') || $(e.target).closest('.custom-modal-btn-cancel').length) {
      $('#customLogoutModal').removeClass('active');
    }
  });
});
</script>
</body>
</html>