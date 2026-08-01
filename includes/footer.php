<?php
// ============================================================
// includes/footer.php — Standardized NoteNest Footer Component
// ============================================================
?>
<footer class="mt-auto py-4 bg-white border-top shadow-sm" style="font-family:'Inter',sans-serif;">
    <div class="container">
        <div class="row g-4 align-items-center">
            <div class="col-md-5 text-center text-md-start">
                <div class="d-flex align-items-center justify-content-center justify-content-md-start mb-2">
                    <img src="img/fav.ico" height="30" alt="NoteNest Logo" class="me-2">
                    <span class="fw-bold" style="color:#0b4954;font-size:1.1rem;">NoteNest AI Platform</span>
                </div>
                <p class="text-muted small mb-0">AI-Assisted Academic Workspace &amp; Google Classroom Synchronization.</p>
            </div>
            <div class="col-md-7 text-center text-md-end">
                <div class="d-inline-flex flex-wrap gap-3 justify-content-center justify-content-md-end mb-2" style="font-size:.9rem;">
                    <a href="dashboard.php" class="text-decoration-none text-secondary hover-primary">Dashboard</a>
                    <a href="course_management.php" class="text-decoration-none text-secondary hover-primary">Courses</a>
                    <a href="google_classroom.php" class="text-decoration-none text-secondary hover-primary"><i class="fab fa-google text-primary me-1"></i>Google Sync</a>
                    <a href="privacy.php" class="text-decoration-none fw-semibold" style="color:#197f8f;">Privacy Policy</a>
                    <a href="terms.php" class="text-decoration-none fw-semibold" style="color:#197f8f;">Terms of Service</a>
                </div>
                <div class="text-muted small">
                    &copy; <?php echo date('Y'); ?> NoteNest AI. All rights reserved. &nbsp;|&nbsp; Support: <a href="mailto:support@notenest.ai" class="text-decoration-none text-muted">support@notenest.ai</a>
                </div>
            </div>
        </div>
    </div>
</footer>
