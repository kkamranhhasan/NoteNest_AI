<?php
// ============================================================
// privacy.php — NoteNest AI Platform
// Privacy Policy & Google User Data Policy Compliance
// ============================================================
session_start();
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Policy — NoteNest AI</title>
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
        .limited-use-badge {
            display: inline-block;
            background: #e8f4f8;
            color: var(--primary);
            font-weight: 700;
            font-size: .8rem;
            padding: 4px 12px;
            border-radius: 20px;
            margin-bottom: 12px;
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
        <i class="fas fa-shield-alt fa-3x mb-3 opacity-75"></i>
        <h1 class="fw-bold">Privacy Policy</h1>
        <p class="mb-0 opacity-75">Effective Date: August 1, 2026 &nbsp;|&nbsp; NoteNest AI Platform</p>
    </div>
</div>

<div class="container pb-5">
    <div class="row">
        <div class="col-lg-10 mx-auto">
            <div class="legal-card">

                <div class="highlight-box">
                    <span class="limited-use-badge"><i class="fab fa-google me-1"></i> Google API Compliance</span>
                    <p class="mb-0">NoteNest AI's use and transfer to any other app of information received from Google APIs will adhere to the <strong><a href="https://developers.google.com/terms/api-services-user-data-policy#additional_requirements_for_specific_api_scopes" target="_blank" rel="noopener">Google API Services User Data Policy</a></strong>, including the Limited Use requirements.</p>
                </div>

                <h2>1. Information We Collect</h2>
                <p>NoteNest AI collects information necessary to provide our academic productivity and AI-assisted learning platform:</p>
                <ul>
                    <li><strong>Account Information:</strong> When you register, we collect your name, email address, password hash, and optional phone number or profile picture.</li>
                    <li><strong>Google Account Data (OAuth):</strong> When you connect Google Classroom, with your explicit authorization, we access:
                        <ul>
                            <li>Your Google email address and basic profile info (ID, name).</li>
                            <li>Google Classroom courses, sections, and topics.</li>
                            <li>Coursework materials, assignments, and announcements.</li>
                            <li>Files attached to Google Classroom coursework stored in Google Drive.</li>
                        </ul>
                    </li>
                    <li><strong>Uploaded Documents & Study Notes:</strong> Files you directly upload or import (PDFs, DOCX, text notes) to organize into folders.</li>
                    <li><strong>Usage & Progress Data:</strong> Information regarding AI tutor interactions, exam scores, completed tasks, and system logs to generate your personal study analytics.</li>
                </ul>

                <h2>2. How We Use Your Information</h2>
                <p>We use your data solely for educational and platform functionality purposes:</p>
                <ul>
                    <li>Synchronizing your Google Classroom courses, topic structures, and study materials automatically.</li>
                    <li>Creating organized course folders and topic subfolders inside your private NoteNest account.</li>
                    <li>Generating private AI study summaries, flashcards, vector indexing, and practice exam questions from your course materials.</li>
                    <li>Providing personalized academic analytics and reminder notifications for upcoming assignments.</li>
                </ul>

                <h2>3. Data Protection & Privacy Commitment</h2>
                <p>We enforce strict technical and organizational safeguards to keep your personal academic data secure:</p>
                <ul>
                    <li><strong>Encryption:</strong> OAuth tokens and user access credentials are encrypted at rest using AES-256-CBC encryption. All data transfers occur over HTTPS (TLS encryption).</li>
                    <li><strong>User Isolation:</strong> Your study files and Google Classroom materials are strictly isolated to your user account. No other user can view or access your private files or AI context.</li>
                    <li><strong>No Data Selling:</strong> We <strong>never sell, rent, or trade</strong> your personal information or Google user data to third parties or advertising networks.</li>
                    <li><strong>No Model Training:</strong> Your Google Classroom data and uploaded study files are NOT used to train public AI models.</li>
                </ul>

                <h2>4. Sharing and Disclosure</h2>
                <p>We do not share your personal data with third parties, except in the following limited circumstances:</p>
                <ul>
                    <li><strong>Explicit Sharing:</strong> When you explicitly choose to share a note or folder with another registered NoteNest user via our sharing feature.</li>
                    <li><strong>Service Providers:</strong> Secure infrastructure providers (such as hosting and database services) bound by strict confidentiality agreements.</li>
                    <li><strong>Legal Compliance:</strong> If required by law, regulation, or court order to comply with legal obligations.</li>
                </ul>

                <h2>5. User Control & Data Retention</h2>
                <p>You maintain complete control over your data at all times:</p>
                <ul>
                    <li><strong>Disconnecting Google Account:</strong> You can disconnect your Google Classroom account at any time from your Profile or Google Classroom dashboard. Disconnecting revokes access tokens immediately.</li>
                    <li><strong>Data Deletion:</strong> You can delete any imported file, folder, course, or AI chat session from NoteNest. Upon account deletion, all associated files and indexed chunks are permanently erased from our databases.</li>
                    <li><strong>Google Security Settings:</strong> You may also revoke NoteNest AI's permissions directly via <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">Google Account Permissions Settings</a>.</li>
                </ul>

                <h2>6. Developer & Contact Information</h2>
                <p>If you have any questions, concerns, or requests regarding this Privacy Policy or your data, please contact our team:</p>
                <div class="highlight-box">
                    <p class="mb-1"><strong>NoteNest AI Support Team</strong></p>
                    <p class="mb-1"><i class="fas fa-envelope me-2" style="color:var(--accent)"></i> Email: <a href="mailto:support@notenest.ai">support@notenest.ai</a> / <a href="mailto:kamranhhasan@gmail.com">kamranhhasan@gmail.com</a></p>
                    <p class="mb-0"><i class="fas fa-globe me-2" style="color:var(--accent)"></i> Platform: NoteNest Academic Platform</p>
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
