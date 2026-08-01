<?php
// ============================================================
// terms.php — NoteNest AI Platform
// Terms of Service
// ============================================================
session_start();
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Terms of Service — NoteNest AI</title>
    <link rel="shortcut icon" href="img/fav.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0b4954;
            --accent:  #197f8f;
            --bg:      #f0f4f8;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: #2c3e50;
        }
        .legal-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            color: #fff;
            padding: 48px 0 36px;
            margin-bottom: 32px;
        }
        .legal-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,.06);
            padding: 36px;
            margin-bottom: 32px;
        }
        .legal-card h2 {
            color: var(--primary);
            font-weight: 700;
            font-size: 1.35rem;
            margin-top: 24px;
            margin-bottom: 12px;
            border-bottom: 2px solid #e8edf2;
            padding-bottom: 8px;
        }
        .legal-card h2:first-of-type { margin-top: 0; }
        .legal-card p, .legal-card li {
            font-size: .95rem;
            line-height: 1.7;
            color: #4a5568;
        }
        .highlight-box {
            background: #f0f7f9;
            border-left: 4px solid var(--accent);
            padding: 16px 20px;
            border-radius: 8px;
            margin: 16px 0;
        }
    </style>
</head>
<body>

<?php if (isset($_SESSION['user_id'])): ?>
    <?php include 'includes/navbar.php'; ?>
<?php else: ?>
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <img src="img/fav.ico" height="40" alt="NoteNest Logo" class="me-2">
                <span class="fw-bold" style="color:var(--primary);font-size:1.3rem;">NoteNest AI</span>
            </a>
            <div class="ms-auto">
                <a href="login.php" class="btn btn-outline-primary me-2">Log In</a>
                <a href="register.php" class="btn btn-primary" style="background:var(--accent);border:none;">Sign Up</a>
            </div>
        </div>
    </nav>
<?php endif; ?>

<div class="legal-header text-center">
    <div class="container">
        <i class="fas fa-file-contract fa-3x mb-3 opacity-75"></i>
        <h1 class="fw-bold">Terms of Service</h1>
        <p class="mb-0 opacity-75">Effective Date: August 1, 2026 &nbsp;|&nbsp; NoteNest AI Platform</p>
    </div>
</div>

<div class="container pb-5">
    <div class="row">
        <div class="col-lg-10 mx-auto">
            <div class="legal-card">

                <h2>1. Acceptance of Terms</h2>
                <p>By accessing or using the NoteNest AI Academic Platform ("NoteNest", "we", "us", or "our"), you agree to be bound by these Terms of Service. If you do not agree to these terms, please do not access or use our platform.</p>

                <h2>2. Description of Platform Services</h2>
                <p>NoteNest AI provides students and educators with academic organization tools, course management, Google Classroom synchronization, digital note management, private vector indexing, AI tutoring, and quiz evaluation services.</p>

                <h2>3. User Account Responsibilities</h2>
                <ul>
                    <li>You must provide accurate information when creating an account.</li>
                    <li>You are responsible for maintaining the confidentiality of your credentials and account access.</li>
                    <li>You agree not to use NoteNest for any unlawful purpose, unauthorized scraping, or distribution of copyrighted materials without authorization.</li>
                </ul>

                <h2>4. Third-Party API Integrations (Google Classroom & Google Drive)</h2>
                <p>NoteNest AI integrates with Google Classroom and Google Drive APIs to sync academic courses, coursework, assignments, and study materials:</p>
                <ul>
                    <li>Connecting your Google account is optional and initiated explicitly by you.</li>
                    <li>You grant NoteNest AI permission to access authorized read-only data for the sole purpose of displaying and organizing your study materials within your private account.</li>
                    <li>NoteNest AI adheres strictly to the <strong>Google API Services User Data Policy</strong>, including the Limited Use requirements.</li>
                </ul>

                <h2>5. Intellectual Property & User Content</h2>
                <p>You retain full ownership of all study materials, notes, and documents you upload or import into NoteNest AI. NoteNest AI claims no ownership rights over your personal academic content.</p>

                <h2>6. Disclaimers & Limitation of Liability</h2>
                <p>NoteNest AI provides platform services and AI-generated study aids "as is". While our AI models strive for accuracy based on your provided study material, AI outputs (such as practice questions and summaries) should be reviewed alongside official course syllabi and instructions.</p>

                <h2>7. Termination of Account</h2>
                <p>We reserve the right to suspend or terminate accounts that violate these Terms of Service or engage in malicious platform activity. You may also request account termination and full data erasure at any time by contacting support.</p>

                <h2>8. Contact Information</h2>
                <div class="highlight-box">
                    <p class="mb-1"><strong>NoteNest AI Support Team</strong></p>
                    <p class="mb-1"><i class="fas fa-envelope me-2" style="color:var(--accent)"></i> Support Email: <a href="mailto:support@notenest.ai">support@notenest.ai</a> / <a href="mailto:kamranhhasan@gmail.com">kamranhhasan@gmail.com</a></p>
                    <p class="mb-0"><i class="fas fa-shield-alt me-2" style="color:var(--accent)"></i> Privacy Policy: <a href="privacy.php">View Privacy Policy</a></p>
                </div>

            </div>
        </div>
    </div>
</div>

<footer class="bg-white py-4 border-top">
    <div class="container text-center text-muted" style="font-size:.88rem;">
        <p class="mb-1">&copy; <?php echo date('Y'); ?> NoteNest AI Platform. All rights reserved.</p>
        <p class="mb-0">
            <a href="privacy.php" class="text-decoration-none me-3" style="color:var(--accent)">Privacy Policy</a>
            <a href="terms.php" class="text-decoration-none me-3" style="color:var(--accent)">Terms of Service</a>
            <a href="google_classroom.php" class="text-decoration-none" style="color:var(--accent)">Google Classroom Sync</a>
        </p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
