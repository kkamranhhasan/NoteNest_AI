# 🗒️ NoteNest — AI-Powered Academic Resource Management & Google Classroom Platform

<p align="center">
  <img src="img/fav.ico" alt="NoteNest Logo" width="64"/>
</p>

<p align="center">
  <strong>An intelligent, all-in-one academic platform built with PHP, MySQL, Bootstrap 5 & Vanilla JavaScript.</strong><br/>
  Manage notes, sync with Google Classroom, get AI tutoring, generate exams, record lectures, and track study analytics — all in one place.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white"/>
  <img src="https://img.shields.io/badge/MySQL-8.x-4479A1?logo=mysql&logoColor=white"/>
  <img src="https://img.shields.io/badge/Bootstrap-5.3-7952B3?logo=bootstrap&logoColor=white"/>
  <img src="https://img.shields.io/badge/Groq-AI-F55036?logo=openai&logoColor=white"/>
  <img src="https://img.shields.io/badge/Google_Classroom-API-4285F4?logo=googleclassroom&logoColor=white"/>
  <img src="https://img.shields.io/badge/License-MIT-green"/>
</p>

---

## ✨ Key Features

### 🏫 Google Classroom Integration (Full Auto-Sync & AJAX Drill-Down)
| Feature | Description |
|---------|-------------|
| **Google OAuth 2.0** | Connect Google account securely with Google Classroom & Drive read-only permissions |
| **Course & Topic Sync** | Automatically imports all enrolled courses, topic structure, and syllabus sections |
| **Material & File Download** | Downloads course attachments directly to NoteNest storage with full file preview support |
| **Assignment & Task Sync** | Imports assignment titles, descriptions, and due dates into NoteNest To-Do & Calendar |
| **AJAX Drill-Down Navigator** | Browse Courses → Topics → Files & Assignments seamlessly without page reloads |
| **AI Assignment Analysis** | Groq AI analyzes assignment text for difficulty score, estimated hours, key concepts & study tips |
| **Interactive Calendar** | Visual monthly calendar populated automatically with assignment deadlines |
| **Background Sync & Reminders** | Automated cron jobs keep course materials fresh and send upcoming assignment reminders |

### 🤖 AI Features (Powered by Groq — Llama 3.3 70B & Vector RAG)
| Feature | Description |
|---------|-------------|
| **AI Tutor Chat** | Ask any academic question, get Markdown-formatted answers with persistent chat history |
| **AI Exam Wizard** | Upload documents/notes → AI generates customized MCQ & Short Answer exams |
| **AI Answer Evaluation** | Submit exam answers → AI evaluates correctness, scores, and provides detailed feedback |
| **Study Recommendations** | AI-personalized learning plans, study tips, and weekly schedules |
| **Progress Analytics** | Activity heatmaps, exam score bar charts, and AI learning insights |
| **Local RAG Search** | Python ChromaDB microservice for semantic similarity search over study materials |

### 📁 File & Folder Management
| Feature | Description |
|---------|-------------|
| **Upload Notes** | Upload PDF, DOCX, XLSX, images, audio, video, and code files |
| **Nested Folders** | Unlimited hierarchical folder organization |
| **In-Browser File Preview** | Native preview for PDFs, Images, TXT, DOCX (Mammoth), XLSX (SheetJS), Audio, Video |
| **One-Click Download** | Single file or folder material download support |
| **Favorites & Organization** | Star important files/folders for single-click access |

### 🔗 Collaboration & Sharing
| Feature | Description |
|---------|-------------|
| **File & Folder Sharing** | Share items with classmates via user email |
| **Recursive Folder Access** | Sharing a folder grants view-only access to all contained subfolders & files |
| **Access Control** | Revoke shared access anytime from the share management view |

### 🎙️ Lecture Recorder & Productivity
| Feature | Description |
|---------|-------------|
| **In-Browser Audio Recorder** | Record lectures with real-time waveform visualization |
| **Auto-Save Recordings** | Recorded audio auto-saves straight to your NoteNest file library |
| **To-Do Management** | Task checklist with priority tags (High/Medium/Low) and due dates |
| **Automated Reminders** | Cron-triggered email & notification alerts for pending tasks & assignments |

---

## 🛠️ Tech Stack

| Layer | Technology |
|-------|-----------|
| **Backend Core** | PHP 8.x (Raw PHP, no heavy framework overhead) |
| **Database** | MySQL 8.x with MySQLi prepared statements |
| **Frontend** | Bootstrap 5.3, Vanilla JavaScript, jQuery, AJAX |
| **AI Processing** | Groq API (`llama-3.3-70b-versatile`) |
| **Vector DB / RAG** | ChromaDB (Python FastAPI microservice) |
| **Integrations** | Google Classroom API & Google Drive API (OAuth 2.0) |
| **Email Service** | PHPMailer + Gmail SMTP |
| **Document Parsing** | Mammoth.js (DOCX), SheetJS (XLSX/CSV), PDF.js |
| **Visualizations** | Chart.js |

---

## 🚀 Installation & Setup

### Prerequisites
- XAMPP / WAMP / MAMP (PHP 8.0+ & MySQL 8.0+)
- Python 3.9+ (Required for ChromaDB vector search service)
- Google Cloud Console Project (Required for Google Classroom Integration)
- Groq API Key (Required for AI features)

---

### Step 1 — Clone Repository
```bash
git clone https://github.com/kkamranhasan/NoteNest.git
cd NoteNest
```

---

### Step 2 — Place in Web Server Directory
Place the repository in your web server root directory:
- **XAMPP (macOS):** `/Applications/XAMPP/xamppfiles/htdocs/NoteNest-main/`
- **XAMPP (Windows):** `C:\xampp\htdocs\NoteNest-main\`

---

### Step 3 — Database Setup
1. Open **phpMyAdmin** (`http://localhost/phpmyadmin`)
2. Create a new database named `notenest`
3. Import the main schema: **`database.sql`**
4. Import any missing migrations if necessary: **`migration_add_missing_tables.sql`**

---

### Step 4 — Environment Configuration
Create your local environment file:
```bash
cp config.example.php config.php
```

Open `config.php` and configure database, email, AI, and Google OAuth credentials:

```php
// Database Credentials
define('DB_SERVER',   'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME',     'notenest');

// App URL
define('APP_URL', 'http://localhost/NoteNest-main');

// SMTP Email Settings (Google App Password)
define('MAIL_HOST',     'smtp.gmail.com');
define('MAIL_USERNAME', 'your_email@gmail.com');
define('MAIL_PASSWORD', 'your_16_char_app_password');
define('MAIL_PORT',     587);

// Groq AI API Key
define('GROQ_API_KEY', 'gsk_your_groq_api_key');
define('GROQ_MODEL',   'llama-3.3-70b-versatile');

// Google Classroom OAuth 2.0 Credentials
define('GOOGLE_CLIENT_ID',     'your_google_client_id.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'your_google_client_secret');
define('GOOGLE_REDIRECT_URI',  APP_URL . '/google_callback.php');
```

---

### Step 5 — Google Cloud Console Setup (Classroom API)
1. Go to [Google Cloud Console](https://console.cloud.google.com/).
2. Create a new project named **NoteNest**.
3. Enable the following APIs under **APIs & Services**:
   - Google Classroom API
   - Google Drive API
4. Configure **OAuth consent screen** (User type: External / Test).
5. Create **OAuth 2.0 Client IDs** (Application type: Web application):
   - **Authorized redirect URIs:** `http://localhost/NoteNest-main/google_callback.php`
6. Copy the **Client ID** and **Client Secret** into your `config.php`.

---

### Step 6 — Directory Permissions
Ensure upload folders exist and have write permissions:
```bash
mkdir -p uploads/notes uploads/recordings img/user_photos
chmod -R 777 uploads img/user_photos
```

---

### Step 7 — Run Local Vector Microservice (ChromaDB)
To enable AI Semantic Document Search & RAG:
```bash
# Set up Python virtual environment
python3 -m venv .venv
./.venv/bin/pip install chromadb fastapi uvicorn pydantic

# Start ChromaDB microservice on port 8000
./.venv/bin/python chroma_service.py
```

---

### Step 8 — Set Up Automated Background Cron Jobs (Optional)
To enable automated background syncs and task/assignment reminders:
```bash
# Add to crontab (crontab -e)
# Run task reminders every hour
0 * * * * /usr/bin/php /path/to/NoteNest-main/cron/todo_reminder.php >/dev/null 2>&1

# Run Google Classroom background sync every 6 hours
0 */6 * * * /usr/bin/php /path/to/NoteNest-main/cron/google_sync.php >/dev/null 2>&1

# Send Google Classroom assignment reminders daily at 8 AM
0 8 * * * /usr/bin/php /path/to/NoteNest-main/cron/google_reminders.php >/dev/null 2>&1
```

---

### Step 9 — Docker Setup (Alternative Quick Start)
You can run the entire application using Docker Compose:
```bash
docker-compose up --build -d
```
Access the application at `http://localhost:8080/login.php`.

---

## 📂 Project Directory Structure

```
NoteNest-main/
│
├── 📄 dashboard.php                 — Main Dashboard (Quick stats, Google Classroom card, Recent activity)
├── 📄 google_classroom.php          — Google Classroom Hub (Courses, Topics, Files, Assignments, Calendar, Sync logs)
├── 📄 google_auth.php               — Google OAuth 2.0 Auth Redirect handler
├── 📄 google_callback.php           — Google OAuth 2.0 Access/Refresh Token Exchange handler
├── 📄 google_disconnect.php         — Disconnect Google Account handler
├── 📄 dashboard_gc_ajax.php         — AJAX endpoint for Google Classroom dynamic course/topic/file drill-down
│
├── 📄 my_note_nest.php              — Personal File & Folder Explorer
├── 📄 shared_note_nest.php          — Files & Folders Shared With Me
├── 📄 favorites.php                 — Starred Files & Folders
├── 📄 note_preview.php              — Document & Media Preview Modal API
├── 📄 note_download.php             — File Download Manager
├── 📄 sync_storage_ajax.php         — Real-time Storage Usage Sync Endpoint
│
├── 📄 ai_tutor.php                  — Groq-Powered Interactive AI Academic Tutor
├── 📄 ai_exam.php                   — Automated Exam Generation & AI Grading System
├── 📄 study_recommendations.php     — AI Learning Schedule & Plan Generator
├── 📄 progress_analytics.php        — Analytics Dashboard (Chart.js Heatmaps & Scores)
├── 📄 lecture_recorder.php          — Audio Lecture Recorder with Waveform Visualizer
│
├── 📄 course_management.php         — Manual Course & Syllabus Management
├── 📄 todo.php                      — Task & Assignment Manager
├── 📄 notifications.php             — System Notification Center Endpoint
├── 📄 profile.php                   — User Profile & Password Editor
│
├── 📄 login.php                     — Login Page
├── 📄 register.php                  — Registration Page with Avatar Upload
├── 📄 verify_email.php              — Email Verification Endpoint
├── 📄 resend_verification.php       — Resend Verification Link Handler
├── 📄 logout.php                    — Logout Session Handler
│
├── 📄 config.example.php            — Environment Configuration Template
├── 📄 database.sql                  — Primary Database Schema
├── 📄 migration_add_missing_tables.sql — Schema Updates & Foreign Keys
├── 📄 chroma_service.py             — Python FastAPI Microservice for ChromaDB Vector Search
├── 📄 Dockerfile / docker-compose.yml — Docker Deployment Files
│
├── 📁 includes/                     — Core Backend Helpers & Service Engines
│   ├── auth.php                     — Session Guard & Access Protection
│   ├── db.php                       — Database Connection Handler
│   ├── navbar.php                   — Main Top Navigation Bar
│   ├── footer.php                   — Shared Page Footer
│   ├── functions.php                — General Utility Functions
│   ├── ai_service.php               — Groq AI API Wrapper
│   ├── google_classroom_service.php — Google Classroom & Drive API Client
│   ├── google_sync_engine.php       — Background Data Sync Engine
│   ├── google_ai_analyzer.php       — AI Assignment & Material Analyzer
│   ├── google_reminder_engine.php   — Classroom Notification & Reminder Generator
│   └── send_email.php               — PHPMailer SMTP Email Helper
│
├── 📁 css/                          — Modular CSS Files for Each View
├── 📁 cron/                         — Background Scheduled Tasks
├── 📁 uploads/                      — Storage Directory for User Files & Media
└── 📁 phpmailer/                    — Email Delivery Library
```

---

## 🗄️ Database Architecture

| Table Name | Description |
|------------|-------------|
| `users` | User credentials, avatar photos, verification status, and tokens |
| `folders` | Hierarchical directory tree for note storage |
| `files` | Uploaded document metadata, mime types, file sizes, and paths |
| `favorites` | User-starred files and folders for quick access |
| `shared_access` | Permission matrix for file and folder sharing |
| `google_accounts` | Google OAuth tokens, connected account email, sync state |
| `google_courses` | Synced Google Classroom course metadata |
| `google_topics` | Course topics and syllabus modules |
| `google_files` | Classroom attachments and Drive materials linked to local files |
| `google_assignments` | Classroom assignments, due dates, and AI analysis data |
| `google_sync_logs` | Audit trail of manual and background sync executions |
| `calendar_events` | Aggregated calendar events and assignment deadlines |
| `todos` / `todo_notifications` | User tasks, priority flags, and reminder delivery status |
| `courses` / `course_topics` | Manual course and syllabus structures |
| `ai_chat_history` | Persistent chat logs with the AI Tutor |
| `ai_evaluations` | AI exam scores, submission details, and feedback breakdown |
| `user_progress` | User activity logs used for progress analytics |

---

## 🔒 Security Measures

- **SQL Injection Safeguards:** 100% of database operations use MySQLi prepared statements with bound parameters.
- **Authentication Protection:** Session guards (`includes/auth.php`) protect all internal application routes.
- **Password Hashing:** Passwords are standardly hashed using `password_hash()` (Bcrypt).
- **OAuth 2.0 Security:** Google Tokens stored securely per user with automated expiration refresh.
- **XSS Prevention:** Output data sanitization via `htmlspecialchars()` across UI elements.
- **Access Authorization:** Strict ownership and share-permission validation before file access/download.

---

## 👥 Contributors

- **Kamran Hasan** ([@kkamranhasan](https://github.com/kkamranhasan)) — Lead Developer & Architect

---

## 📝 License

This project is licensed under the **MIT License**.
