# 🗒️ NoteNest — AI-Powered Academic Resource Management Platform

<p align="center">
  <img src="img/fav.ico" alt="NoteNest Logo" width="64"/>
</p>

<p align="center">
  <strong>An intelligent, all-in-one academic platform built with Raw PHP, MySQL, Bootstrap 5 & Vanilla JavaScript.</strong><br/>
  Manage notes, get AI tutoring, generate exams, record lectures, and collaborate — all in one place.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white"/>
  <img src="https://img.shields.io/badge/MySQL-8.x-4479A1?logo=mysql&logoColor=white"/>
  <img src="https://img.shields.io/badge/Bootstrap-5.3-7952B3?logo=bootstrap&logoColor=white"/>
  <img src="https://img.shields.io/badge/Groq-AI-F55036?logo=openai&logoColor=white"/>
  <img src="https://img.shields.io/badge/License-MIT-green"/>
</p>

---

## ✨ Features

### 🤖 AI Features (Powered by Groq — Llama 3.3)
| Feature | Description |
|---------|-------------|
| **AI Tutor Chat** | Ask any academic question, get Markdown-formatted answers with chat history |
| **AI Exam Wizard** | Upload any document → AI generates MCQ + Short Answer questions |
| **AI Answer Evaluation** | Submit your answers → AI scores and gives per-question feedback |
| **Study Recommendations** | AI-personalized learning plan and weekly schedule |
| **Progress Analytics** | Activity heatmap, exam scores, and learning insights |

### 📁 File & Folder Management
| Feature | Description |
|---------|-------------|
| **Upload Notes** | Upload any file type (PDF, DOCX, images, audio, video, etc.) |
| **Nested Folders** | Organize files in unlimited nested folder structure |
| **File Preview** | In-browser preview for PDF, Image, Text, DOCX, XLSX, CSV, Audio, Video |
| **Download** | One-click download for any file |
| **Rename & Delete** | Rename or delete files and folders |
| **Favorites** | Mark files/folders as favorites for quick access |

### 🔗 Sharing System
| Feature | Description |
|---------|-------------|
| **File Sharing** | Share any file with another user by email |
| **Folder Sharing** | Share folders (recursively shares all sub-content) |
| **View-Only Access** | Shared users can preview and download only |
| **Revoke Access** | Remove sharing access at any time |
| **Shared With Me** | View all files/folders shared with you |

### 📚 Course Management
| Feature | Description |
|---------|-------------|
| **Create Courses** | Add courses with name, code, color and description |
| **Syllabus Topics** | Define topics week-by-week for each course |
| **Attach Materials** | Link uploaded files directly to course topics |
| **Course Overview** | See all materials organized under their course |

### ✅ Task & Productivity
| Feature | Description |
|---------|-------------|
| **Todo List** | Create tasks with priority (High/Medium/Low) and deadlines |
| **Task Reminders** | Automated notifications for upcoming tasks (via cron) |
| **Completion Tracking** | Mark tasks done and track completion rate |

### 🎙️ Lecture Recorder
| Feature | Description |
|---------|-------------|
| **Browser Recording** | Record audio lectures directly in the browser |
| **Waveform Visualizer** | Real-time audio waveform while recording |
| **Auto-Save** | Recordings saved to your file library automatically |

### 📊 Dashboard & Analytics
| Feature | Description |
|---------|-------------|
| **Activity Graph** | 7-day study activity chart (Chart.js) |
| **Performance Metrics** | Quiz/exam score bar chart |
| **Todo Donut Chart** | Task completion rate visualization |
| **Recent Activity Feed** | Timeline of your uploads, chats, exams |

### 👤 Authentication & Profile
| Feature | Description |
|---------|-------------|
| **Register & Login** | Secure registration with SMTP email verification |
| **Email Verification** | Verification link sent via Gmail SMTP (PHPMailer) |
| **Resend Verification** | Request a new verification email if link expires |
| **Profile Photo** | Upload profile picture at signup, shown everywhere |
| **Profile Management** | Edit name, phone, gender, password |
| **Notifications** | Real-time notification bell with unread count |

---

## 🛠️ Tech Stack

| Layer | Technology |
|-------|-----------|
| **Backend** | Raw PHP 8.x (no framework) |
| **Database** | MySQL 8.x with MySQLi prepared statements |
| **Frontend** | Bootstrap 5.3, Vanilla JavaScript, AJAX/Fetch API |
| **AI Engine** | Groq API (Llama 3.3 70B) |
| **Charts** | Chart.js |
| **DOCX Preview** | Mammoth.js |
| **XLSX Preview** | SheetJS (xlsx.js) |
| **Email** | PHPMailer + Gmail SMTP |
| **Icons** | Font Awesome 6 |
| **Fonts** | Google Fonts (Inter) |

---

## 🚀 Installation & Setup

### Prerequisites
- XAMPP (or any Apache + PHP 8.x + MySQL stack)
- PHP 8.0 or higher
- MySQL 8.0 or higher
- Internet connection (for Groq API, CDN libraries, SMTP)

### Step 1 — Clone the Repository
```bash
git clone https://github.com/YOUR_USERNAME/NoteNest.git
cd NoteNest
```

### Step 2 — Place in Web Server Root
```
XAMPP (macOS):  /Applications/XAMPP/xamppfiles/htdocs/NoteNest-main/
XAMPP (Windows): C:\xampp\htdocs\NoteNest-main\
```

### Step 3 — Create the Database
1. Open **phpMyAdmin** → `http://localhost/phpmyadmin`
2. Create a new database named `notenest`
3. Import the schema file: **`database.sql`** (root of project)

### Step 4 — Configure the App
```bash
cp config.example.php config.php
```

Open `config.php` and fill in your values:
```php
// Database
define('DB_SERVER',   'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME',     'notenest');

// Gmail SMTP (for email verification)
define('MAIL_HOST',     'smtp.gmail.com');
define('MAIL_USERNAME', 'your_email@gmail.com');
define('MAIL_PASSWORD', 'your_gmail_app_password');  // 16-char App Password
define('MAIL_PORT',     587);
define('APP_URL',       'http://localhost/NoteNest-main');

// Groq AI
define('GROQ_API_KEY', 'your_groq_api_key_here');
define('GROQ_MODEL',   'llama-3.3-70b-versatile');
```

> ⚠️ Never commit `config.php` to GitHub — it is listed in `.gitignore`.

### Step 5 — Set Folder Permissions
```bash
chmod 777 uploads/notes/
chmod 777 uploads/recordings/
chmod 777 img/user_photos/
```

### Step 6 — Access the App
```
http://localhost/NoteNest-main/login.php
```

Register a new account → check email for verification link → login → dashboard.

### Step 7 — Set Up Todo Reminders (Optional)
Add a cron job to run every hour:
```
0 * * * * php /path/to/NoteNest-main/cron/todo_reminder.php
```

### Step 8 — Start ChromaDB Local Vector Service (Required for RAG)
NoteNest employs a local Python **ChromaDB** microservice for semantic similarity vector search. Start the service by running:
```bash
# Initialize Python virtual environment & install dependencies (first time only)
python3 -m venv .venv
./.venv/bin/pip install chromadb

# Start the local HTTP vector DB service
./.venv/bin/python chroma_service.py
```
The service runs locally at `http://127.0.0.1:8000/`. The PHP core communicates with it via cURL. If the Python service is offline, the system automatically falls back to database-level lexical similarity matching.

### 🐳 Step 9 — Docker Deployment (Recommended for Cloud Hosting)
For cloud hosting (AWS, VPS, Render, etc.), you can orchestrate the entire stack (PHP, MySQL, and ChromaDB) using Docker Compose:
```bash
# Start all containers in the background
docker-compose up --build -d

# Verify containers are running
docker-compose ps
```
Once built, the web application will be accessible at `http://localhost:8080/login.php`, the ChromaDB vector database will run internally at `http://chroma:8000/`, and data persistence volumes will automatically map MySQL data and Chroma embeddings.

---

## 📂 Project Structure

```
NoteNest-main/
│
├── 📄 dashboard.php                 — Main dashboard (entry after login)
├── 📄 login.php                     — User login
├── 📄 register.php                  — User registration + profile photo
├── 📄 logout.php                    — Session logout
├── 📄 verify_email.php              — Email verification (confirm button)
├── 📄 resend_verification.php       — Resend verification email
│
├── 📄 my_note_nest.php              — Personal files & folders
├── 📄 shared_note_nest.php          — Files shared with me
├── 📄 favorites.php                 — Starred files/folders
├── 📄 note_preview.php              — File preview API
├── 📄 note_download.php             — File download handler
├── 📄 sync_storage_ajax.php         — Storage sync utility
│
├── 📄 course_management.php         — Course & syllabus management
├── 📄 todo.php                      — Task management
├── 📄 notifications.php             — Notification center (AJAX)
├── 📄 profile.php                   — User profile editor
│
├── 📄 ai_tutor.php                  — AI Chat Tutor
├── 📄 ai_exam.php                   — AI Exam Generator & Evaluator
├── 📄 study_recommendations.php     — AI study plan
├── 📄 progress_analytics.php        — Learning analytics
├── 📄 lecture_recorder.php          — In-browser lecture recorder
│
├── 📄 share.php                     — Share file/folder handler
├── 📄 share_management.php          — Manage shared access
│
├── 📄 config.example.php            — Config template (copy → config.php)
├── 📄 database.sql                  — Full database schema
│
├── 📁 includes/
│   ├── auth.php                     — Session auth guard
│   ├── db.php                       — Database connection
│   ├── navbar.php                   — Navigation bar
│   ├── send_email.php               — PHPMailer verification emails
│   ├── ai_service.php               — Groq AI service layer
│   └── functions.php                — Shared utility functions
│
├── 📁 css/                          — Page-specific stylesheets
├── 📁 phpmailer/                    — PHPMailer library
├── 📁 img/
│   └── user_photos/                 — Profile pictures
│
├── 📁 uploads/
│   ├── notes/                       — Uploaded study files
│   └── recordings/                  — Lecture recordings
│
└── 📁 cron/
    └── todo_reminder.php            — Scheduled reminder script
```

---

## 🗄️ Database Tables

| Table | Purpose |
|-------|---------|
| `users` | User accounts, profile photos, email verification tokens |
| `folders` | Nested folder structure |
| `files` | Uploaded files metadata |
| `shared_access` | File/folder sharing records |
| `favorites` | Starred items |
| `todos` | Task list items |
| `todo_notifications` | Reminder tracking |
| `notifications` | System notifications |
| `courses` | Academic courses |
| `course_topics` | Syllabus topics per course |
| `file_course_tags` | Files linked to course topics |
| `ai_chat_history` | AI Tutor conversation history |
| `ai_evaluations` | Exam results and AI feedback |
| `user_progress` | Activity tracking for analytics |
| `lecture_recordings` | Recorded lecture metadata |

---

## 📧 Email Verification Flow

1. User registers on `register.php`
2. Account created with `is_verified = 0`
3. Verification email sent via Gmail SMTP (`includes/send_email.php`)
4. User clicks link → `verify_email.php` → clicks **Verify Email** button
5. Account activated → user can login

If the link expires (24 hours), use `resend_verification.php` to get a new one.

---

## 🔒 Security

- ✅ All DB queries use **prepared statements** (SQL injection safe)
- ✅ **Password hashing** with `password_hash()` / `password_verify()`
- ✅ **Email verification** required before login
- ✅ **Verification tokens** expire after 24 hours
- ✅ **Session-based authentication** with auth guard on every page
- ✅ **File ownership checks** — users can only access their own data
- ✅ **Input validation & sanitization** on all forms
- ✅ **XSS protection** with `htmlspecialchars()` on all output

---

## 🔑 API Keys

### Groq AI (required for AI features)
1. Go to [https://console.groq.com/keys](https://console.groq.com/keys)
2. Create a free API key
3. Paste it in `config.php` as `GROQ_API_KEY`

### Gmail App Password (required for email verification)
1. Enable 2-Step Verification on your Google Account
2. Go to **Google Account → Security → App Passwords**
3. Generate a 16-character app password
4. Paste it in `config.php` as `MAIL_PASSWORD`

---

## 👥 Team / Contributors

- [@kkamranhasan](https://github.com/kkamranhasan) — AI integration, File Management, UI/UX

---

## 📝 License

MIT License © 2025 NoteNest Team

Permission is hereby granted, free of charge, to any person obtaining a copy of this software to use, copy, modify, merge, publish, and distribute it, subject to the condition that the above copyright notice is included in all copies.

---

<p align="center">Made with ❤️ for academic excellence</p>
