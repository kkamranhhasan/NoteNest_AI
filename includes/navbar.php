<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<link rel="stylesheet" href="css/navbar.css">
<style>
.nav-ai-link {
    font-size: 1rem;
    margin-left: 4px;
    color: #197f8f !important;
    font-weight: 600;
    transition: background .2s, color .2s;
}
.nav-ai-link:hover {
    background: #e8f6f8;
    color: #0b4954 !important;
    text-decoration: none;
    border-radius: 8px;
}
</style>
<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
        <img src="img/fav.ico" height="45px" alt="">
        <span class="brand-text fw-bold">NoteNest</span>
    </a>
    <div class="d-flex align-items-center ms-auto">
        <div class="me-3 position-relative">
            <a href="#" id="notifBell" class="btn btn-link position-relative" title="Notifications">
                <i class="fa-regular fa-bell fa-lg"></i>
                <span id="notifCount" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="display:none;">0</span>
            </a>
            <div id="notifDropdown" class="dropdown-menu dropdown-menu-end p-2" style="min-width:300px;max-width:350px;display:none;"></div>
        </div>
        <img src="<?php echo isset($_SESSION['user_photo']) && $_SESSION['user_photo'] ? $_SESSION['user_photo'] : 'img/user.png'; ?>" alt="User Photo" class="rounded-circle me-2" style="width:40px;height:40px;object-fit:cover;">
        <span class="me-3 user-name text-secondary">
            <a href="profile.php" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($_SESSION['user_name']); ?></a>
        </span>
        <a class="btn btn-link nav-ai-link" href="course_management.php" title="Courses">
            <i class="fas fa-graduation-cap"></i> Courses
        </a>
        <a class="btn btn-link nav-ai-link" href="ai_tutor.php" title="AI Tutor">
            <i class="fas fa-robot"></i> AI Tutor
        </a>
        <a class="btn btn-link nav-ai-link" href="ai_exam.php" title="AI Exam">
            <i class="fas fa-brain"></i> AI Exam
        </a>
        <a class="btn btn-link nav-ai-link" href="lecture_recorder.php" title="Recorder">
            <i class="fas fa-microphone"></i> Recorder
        </a>
        <a class="btn btn-link nav-ai-link" href="progress_analytics.php" title="Analytics" style="color:#8e44ad !important;">
            <i class="fas fa-chart-line"></i> Analytics
        </a>
        <a class="btn btn-link nav-ai-link" href="study_recommendations.php" title="Study Plan" style="color:#e67e22 !important;">
            <i class="fas fa-lightbulb"></i> Study Plan
        </a>
        <a class="btn btn-link text-danger logout-link" href="logout.php" title="Logout">
            <i class="fas fa-right-from-bracket"></i> Logout
        </a>
    </div>
  </div>
</nav>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(function() {
    function loadNotifCount() {
        $.get('notifications.php?action=count', function(data) {
            var count = parseInt(data, 10);
            if (count > 0) {
                $('#notifCount').text(count).show();
            } else {
                $('#notifCount').hide();
            }
        });
    }
    function loadNotifDropdown() {
        $.get('notifications.php?action=latest', function(html) {
            $('#notifDropdown').html(html).show();
        });
    }
    $('#notifBell').on('click', function(e) {
        e.preventDefault();
        loadNotifDropdown();
        $.post('notifications.php?action=mark_read');
        $('#notifCount').hide();
    });
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#notifBell').length && !$(e.target).closest('#notifDropdown').length) {
            $('#notifDropdown').hide();
        }
    });
    loadNotifCount();
    setInterval(loadNotifCount, 10000);
});
</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
