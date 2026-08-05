<div align="center">

<img src="img/fav.ico" alt="NoteNest AI Logo" width="80"/>

# 🧠 NoteNest AI

### *Your Intelligent Academic Companion*

> **An all-in-one AI-powered academic resource management platform that transforms the way students learn, organize, and excel.**

<br/>

[![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.x-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://mysql.com)
[![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)](https://getbootstrap.com)
[![JavaScript](https://img.shields.io/badge/JavaScript-ES6+-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)](https://developer.mozilla.org/en-US/docs/Web/JavaScript)
[![Groq AI](https://img.shields.io/badge/Groq-Llama%203.3%2070B-F55036?style=for-the-badge&logo=openai&logoColor=white)](https://groq.com)
[![Google Classroom](https://img.shields.io/badge/Google_Classroom-API-4285F4?style=for-the-badge&logo=googleclassroom&logoColor=white)](https://developers.google.com/classroom)
[![License: MIT](https://img.shields.io/badge/License-MIT-22c55e?style=for-the-badge)](LICENSE)
[![Status](https://img.shields.io/badge/Status-Final%20Year%20Project-f97316?style=for-the-badge)]()

<br/>

[📸 Screenshots](#-screenshots) · [🚀 Quick Start](#-installation--setup) · [✨ Features](#-features) · [🏗 Architecture](#️-system-architecture) · [🎬 Demo](#-demo-video)

---

</div>

## 📖 Project Overview

**NoteNest AI** was born from a real frustration: students today juggle multiple platforms — Google Classroom for coursework, cloud drives for files, separate apps for notes, and ChatGPT for studying — creating a fragmented, inefficient learning experience.

### 🎯 The Problem

| Pain Point | Common Student Experience |
|---|---|
| 📂 Disorganized notes | Files scattered across drives, devices, and apps |
| 🔄 Manual syncing | Manually downloading course materials from Google Classroom |
| 📚 Passive studying | No personalized AI guidance or adaptive exam preparation |
| 📊 No progress insight | Unable to track learning patterns and weak areas |
| 🎙️ Lost lecture content | No easy way to record and store lectures alongside notes |

### 💡 The Solution

**NoteNest AI** unifies everything into a single, intelligent platform — course management, Google Classroom sync, AI tutoring, AI exam generation, lecture recording, and progress analytics — all powered by Groq's Llama 3.3 70B model.

### 👥 Target Users

- 🎓 **University & college students** managing academic workload
- 📚 **Self-learners** building structured study materials
- 🏫 **Study groups** sharing and collaborating on notes
- 📝 **Final year students** needing AI-powered exam preparation

---

## ✨ Features

### 🔐 Authentication & User Management

| Feature | Description |
|---|---|
| Secure Registration | Email/password registration with avatar upload |
| Email Verification | OTP-style email verification via Gmail SMTP + PHPMailer |
| Session Protection | Route guards on all internal pages via `auth.php` |
| Password Hashing | Bcrypt (`password_hash()`) for secure credential storage |
| Profile Management | Update name, avatar, and change password |

### 📘 Course & Academic Management

| Feature | Description |
|---|---|
| Course Creation | Create and manage custom courses with descriptions |
| Topic Management | Hierarchical topic structure within each course |
| Folder System | Unlimited nested folders for organized file storage |
| File Upload | Upload PDF, DOCX, XLSX, images, audio, video, code files |
| File Preview | In-browser preview using PDF.js, Mammoth.js, SheetJS |
| One-Click Download | Instantly download individual files or folder contents |

### 🏫 Google Classroom Integration

| Feature | Description |
|---|---|
| Google OAuth 2.0 | Secure account connection with Classroom & Drive scopes |
| Course & Topic Sync | Auto-import enrolled courses, topics & syllabus structure |
| Material Download | Download attachments directly into NoteNest storage |
| Assignment Sync | Import assignment titles, due dates into To-Do & Calendar |
| AJAX Drill-Down | Browse Courses → Topics → Files without page reloads |
| AI Assignment Analysis | Groq AI scores difficulty, estimates hours, gives study tips |
| Interactive Calendar | Visual monthly calendar auto-populated with deadlines |
| Background Sync | Cron jobs keep materials fresh & send assignment reminders |

### 🤖 AI-Powered Features

| Feature | Description |
|---|---|
| AI Tutor Chat | Ask any academic question, get Markdown-formatted answers |
| Persistent Chat History | All AI conversations saved and resumable per session |
| AI Exam Wizard | Upload notes → AI generates customized MCQ & Short Answer exams |
| AI Answer Evaluation | Submit exam answers → AI scores, evaluates, gives feedback |
| Study Recommendations | AI-personalized learning plans and weekly study schedules |
| Semantic Search (RAG) | Python ChromaDB microservice for document semantic search |

### ⭐ Productivity & Collaboration

| Feature | Description |
|---|---|
| Favorites | Star important files and folders for quick access |
| Lecture Recorder | In-browser audio recorder with real-time waveform visualization |
| To-Do Manager | Task checklist with priority tags (High/Medium/Low) and due dates |
| File Sharing | Share files and folders with classmates via email |
| Recursive Access | Sharing a folder grants view-only access to all sub-items |
| Notifications | System notification center for reminders and alerts |

### 📊 Analytics & Insights

| Feature | Description |
|---|---|
| Progress Analytics | Activity heatmaps, exam score bar charts (Chart.js) |
| AI Learning Insights | Personalized feedback on study habits and weak topics |
| Storage Tracking | Real-time storage usage sync |
| Sync Audit Logs | Full audit trail of all Google Classroom sync events |

---

## 🛠️ Technology Stack

<div align="center">

| Layer | Technology | Purpose |
|---|---|---|
| 🖥️ **Backend** | PHP 8.x | Core server-side logic, routing, API calls |
| 🗄️ **Database** | MySQL 8.x | Relational data storage with prepared statements |
| 🎨 **Frontend** | Bootstrap 5.3 + Vanilla JS + jQuery | Responsive UI, AJAX interactions |
| 🤖 **AI Engine** | Groq API — `llama-3.3-70b-versatile` | Tutoring, exam generation, analysis |
| 🔍 **Vector Search** | ChromaDB + Python FastAPI | Semantic RAG document search |
| 🔗 **Integrations** | Google Classroom API + Drive API (OAuth 2.0) | Course and material sync |
| 📧 **Email** | PHPMailer + Gmail SMTP | Verification emails and reminders |
| 📄 **Doc Parsing** | Mammoth.js · SheetJS · PDF.js | In-browser document preview |
| 📈 **Charts** | Chart.js | Analytics visualizations |
| 🐳 **DevOps** | Docker + Docker Compose | Containerized deployment |

</div>

---

## 🏗️ System Architecture

### High-Level Architecture

```mermaid
graph TB
    subgraph CLIENT["🌐 Client Layer"]
        U[👤 User / Student]
    end

    subgraph FRONTEND["🎨 Frontend Layer"]
        UI[Bootstrap 5 + Vanilla JS]
        AJAX[jQuery AJAX]
    end

    subgraph APP["⚙️ Application Layer - PHP 8.x"]
        AUTH[🔐 Auth Module]
        DASH[📊 Dashboard]
        COURSE[📘 Course Management]
        FILE[📁 File Module]
        GC[🏫 Google Classroom]
        AI_T[🤖 AI Tutor]
        AI_E[📝 AI Exam]
        REC[🎙️ Lecture Recorder]
        ANALYTICS[📈 Progress Analytics]
    end

    subgraph EXTERNAL["🔌 External Services"]
        GROQ[Groq AI Llama 3.3 70B]
        GOOG[Google APIs Classroom + Drive]
        SMTP[Gmail SMTP PHPMailer]
        CHROMA[ChromaDB Python FastAPI]
    end

    subgraph DATA["🗄️ Data Layer"]
        MYSQL[(MySQL 8.x Database)]
        UPLOADS[📂 File Storage uploads/]
    end

    U --> UI
    UI --> AJAX
    AJAX --> AUTH
    AJAX --> DASH
    AJAX --> COURSE
    AJAX --> FILE
    AJAX --> GC
    AJAX --> AI_T
    AJAX --> AI_E
    AJAX --> REC
    AJAX --> ANALYTICS

    AUTH --> MYSQL
    COURSE --> MYSQL
    FILE --> MYSQL
    FILE --> UPLOADS
    GC --> GOOG
    GC --> MYSQL
    AI_T --> GROQ
    AI_E --> GROQ
    AI_T --> CHROMA
    AUTH --> SMTP
    ANALYTICS --> MYSQL
```

### 🔄 Request Flow — Google Classroom Sync

```mermaid
sequenceDiagram
    participant S as 👤 Student
    participant F as 🎨 Frontend
    participant P as ⚙️ PHP Backend
    participant G as 🏫 Google API
    participant AI as 🤖 Groq AI
    participant DB as 🗄️ MySQL

    S->>F: Clicks "Sync Google Classroom"
    F->>P: AJAX POST /dashboard_gc_ajax.php
    P->>DB: Check stored OAuth token
    DB-->>P: Access Token
    P->>G: GET /v1/courses (Classroom API)
    G-->>P: Courses JSON
    P->>G: GET /v1/courses/id/courseWork
    G-->>P: Assignments + Materials
    P->>AI: Analyze assignment difficulty
    AI-->>P: Difficulty score + study tips
    P->>DB: INSERT courses, topics, files, assignments
    DB-->>P: Success
    P-->>F: JSON response with synced data
    F-->>S: Classroom synced — Updated UI
```

---

## 🗄️ Database Design

### Entity Relationship Diagram

```mermaid
erDiagram
    USERS {
        int id PK
        varchar name
        varchar email
        varchar password
        varchar avatar
        tinyint is_verified
        datetime created_at
    }
    COURSES {
        int id PK
        int user_id FK
        varchar title
        text description
        datetime created_at
    }
    COURSE_TOPICS {
        int id PK
        int course_id FK
        varchar title
        int order_index
    }
    FOLDERS {
        int id PK
        int user_id FK
        int parent_id FK
        varchar name
        datetime created_at
    }
    FILES {
        int id PK
        int user_id FK
        int folder_id FK
        varchar original_name
        varchar stored_name
        varchar mime_type
        bigint file_size
        datetime uploaded_at
    }
    FAVORITES {
        int id PK
        int user_id FK
        varchar item_type
        int item_id
        datetime created_at
    }
    GOOGLE_ACCOUNTS {
        int id PK
        int user_id FK
        varchar google_email
        text access_token
        text refresh_token
        datetime token_expiry
    }
    AI_CHAT_HISTORY {
        int id PK
        int user_id FK
        varchar session_id
        varchar role
        text content
        datetime created_at
    }
    AI_EVALUATIONS {
        int id PK
        int user_id FK
        varchar exam_title
        int total_questions
        decimal score_percent
        text feedback_json
        datetime submitted_at
    }

    USERS ||--o{ COURSES : "creates"
    USERS ||--o{ FOLDERS : "owns"
    USERS ||--o{ FILES : "uploads"
    USERS ||--o{ FAVORITES : "stars"
    USERS ||--|| GOOGLE_ACCOUNTS : "connects"
    USERS ||--o{ AI_CHAT_HISTORY : "chats"
    USERS ||--o{ AI_EVALUATIONS : "takes"
    COURSES ||--o{ COURSE_TOPICS : "has"
    FOLDERS ||--o{ FILES : "contains"
    FOLDERS ||--o{ FOLDERS : "nests"
```

### Database Schema Summary

| Table | Description |
|---|---|
| `users` | Credentials, avatar, verification status |
| `courses` | Manual course records per user |
| `course_topics` | Syllabus sections within courses |
| `folders` | Hierarchical folder tree |
| `files` | Uploaded document metadata & paths |
| `favorites` | Starred files and folders |
| `shared_access` | File/folder permission grants |
| `google_accounts` | Google OAuth tokens per user |
| `google_courses` | Synced Google Classroom courses |
| `google_topics` | Classroom topic modules |
| `google_files` | Classroom attachments |
| `google_assignments` | Coursework + AI analysis data |
| `google_sync_logs` | Audit trail of sync events |
| `todos` | User tasks with priorities |
| `ai_chat_history` | AI Tutor conversation logs |
| `ai_evaluations` | Exam scores and AI feedback |
| `user_progress` | Activity log for heatmaps |

---

## 🔄 Workflow Diagrams

### 🔐 Authentication Flow

```mermaid
flowchart TD
    A([👤 User Visits Site]) --> B{Has Account?}
    B -- No --> C[📝 Register Page]
    C --> D[Fill Name, Email, Password, Avatar]
    D --> E[PHP Validates Input]
    E --> F{Valid?}
    F -- No --> D
    F -- Yes --> G[Hash Password with Bcrypt]
    G --> H[Insert User into DB]
    H --> I[📧 Send Verification Email via PHPMailer]
    I --> J[User Clicks Email Link]
    J --> K[verify_email.php sets is_verified=1]
    K --> L[✅ Account Activated → Redirect to Login]
    B -- Yes --> M[🔑 Login Page]
    M --> N[Enter Email + Password]
    N --> O[PHP Checks DB]
    O --> P{password_verify?}
    P -- No --> Q[❌ Invalid Credentials]
    P -- Yes --> R{Is Verified?}
    R -- No --> S[⚠️ Resend Verification Email]
    R -- Yes --> T[✅ Session Created → Dashboard]
```

### 📘 Course Creation & File Upload

```mermaid
flowchart LR
    A[👤 Student] --> B[Go to Course Management]
    B --> C[Create New Course]
    C --> D[Add Course Topics]
    D --> E[Create Folders inside Topics]
    E --> F[Upload Files to Folder]
    F --> G{File Type?}
    G --> H[PDF → PDF.js Preview]
    G --> I[DOCX → Mammoth.js Preview]
    G --> J[XLSX → SheetJS Preview]
    G --> K[Audio/Video → HTML5 Player]
    H & I & J & K --> L[File stored in uploads/]
    L --> M[Metadata saved in MySQL]
    M --> N[✅ File available for Preview, Download, Share, AI Tutor]
```

### 🏫 Google Classroom Sync

```mermaid
flowchart TD
    A[Student clicks Connect Google] --> B[Redirect to Google OAuth]
    B --> C[User Grants Classroom + Drive Permissions]
    C --> D[google_callback.php receives Auth Code]
    D --> E[Exchange Code for Access + Refresh Tokens]
    E --> F[Tokens saved to google_accounts table]
    F --> G[Student clicks Sync Now]
    G --> H[google_sync_engine.php runs]
    H --> I[Fetch all Enrolled Courses via API]
    I --> J[For each Course fetch Topics + Materials + Assignments]
    J --> K{Assignment has attachments?}
    K -- Yes --> L[Download file from Google Drive]
    L --> M[Save to uploads/ + files table]
    K -- No --> N[Skip]
    M & N --> O[Send assignment to Groq AI for analysis]
    O --> P[Store difficulty score + study tips]
    P --> Q[Populate Calendar with due dates]
    Q --> R[Log sync event in google_sync_logs]
    R --> S[✅ Sync Complete — UI refreshes]
```

### 🤖 AI Tutor Flow

```mermaid
flowchart TD
    A[Student opens AI Tutor] --> B[Loads previous chat history from DB]
    B --> C[Student types academic question]
    C --> D[AJAX POST to ai_tutor.php]
    D --> E[Fetch user's study materials as context]
    E --> F{RAG Search enabled?}
    F -- Yes --> G[Query ChromaDB for relevant document chunks]
    G --> H[Append document context to prompt]
    F -- No --> I[Use question only]
    H & I --> J[Send to Groq API llama-3.3-70b-versatile]
    J --> K[Stream AI Response]
    K --> L[Render Markdown in chat UI]
    L --> M[Save exchange to ai_chat_history]
    M --> N[Student continues conversation]
    N --> C
```

### 📝 AI Exam Flow

```mermaid
flowchart TD
    A[Student opens AI Exam] --> B[Select Topic or Upload Document]
    B --> C[Choose Exam Type: MCQ / Short Answer / Mixed]
    C --> D[Set Number of Questions]
    D --> E[Click Generate Exam]
    E --> F[generate_exam.php sends prompt to Groq AI]
    F --> G[Groq generates structured exam JSON]
    G --> H[Exam displayed in interactive UI]
    H --> I[Student answers all questions]
    I --> J[Click Submit Exam]
    J --> K[generate_answer.php sends answers to Groq AI]
    K --> L[AI evaluates each answer]
    L --> M[Score calculated + detailed feedback generated]
    M --> N[Result saved in ai_evaluations table]
    N --> O[Score shown in Progress Analytics heatmap]
```

---

## 📸 Screenshots

> 📌 Add screenshots to a `screenshots/` folder in the project root to populate this section.

### 🔐 Login Page
![Login Page](screenshots/login.png)

### 📊 Dashboard
![Dashboard](screenshots/dashboard.png)

### 📘 Course Management
![Course Management](screenshots/course_management.png)

### 🏫 Google Classroom Integration
![Google Classroom](screenshots/google_classroom.png)

### 🤖 AI Tutor
![AI Tutor](screenshots/ai_tutor.png)

### 📝 AI Exam
![AI Exam](screenshots/ai_exam.png)

### 📈 Progress Analytics
![Analytics](screenshots/progress_analytics.png)

### 🎙️ Lecture Recorder
![Lecture Recorder](screenshots/lecture_recorder.png)

> 💡 **Tip:** Take screenshots of each page in your browser, save them in `screenshots/`, and GitHub will render them automatically in this README.

---

## 🚀 Installation & Setup

### ✅ Prerequisites

| Requirement | Version | Notes |
|---|---|---|
| XAMPP / WAMP / MAMP | PHP 8.0+ | Web server with MySQL |
| Python | 3.9+ | Required for ChromaDB RAG service |
| Google Cloud Project | — | For Classroom API + OAuth 2.0 |
| Groq API Key | — | Free at [console.groq.com](https://console.groq.com) |
| Gmail App Password | — | For PHPMailer email delivery |

---

### Step 1 — Clone the Repository

```bash
git clone https://github.com/kkamranhasan/NoteNest.git
cd NoteNest-main
```

---

### Step 2 — Place in Web Server Root

| Platform | Path |
|---|---|
| XAMPP macOS | `/Applications/XAMPP/xamppfiles/htdocs/NoteNest-main/` |
| XAMPP Windows | `C:\xampp\htdocs\NoteNest-main\` |
| WAMP | `C:\wamp64\www\NoteNest-main\` |

---

### Step 3 — Database Setup

1. Open **phpMyAdmin** at `http://localhost/phpmyadmin`
2. Create a new database named **`notenest`**
3. Import the schema:

```bash
mysql -u root -p notenest < database.sql
mysql -u root -p notenest < migration_add_missing_tables.sql
```

Or use phpMyAdmin's **Import** tab and upload `database.sql`.

---

### Step 4 — Configure Environment

```bash
cp config.example.php config.php
```

Open `config.php` and fill in your credentials:

```php
// ── Database ───────────────────────────────
define('DB_SERVER',   'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');             // Your MySQL password
define('DB_NAME',     'notenest');

// ── Groq AI ────────────────────────────────
define('GROQ_API_KEY', 'gsk_your_groq_api_key_here');
define('GROQ_MODEL',   'llama-3.3-70b-versatile');

// ── Gmail SMTP ─────────────────────────────
define('MAIL_USERNAME', 'your_email@gmail.com');
define('MAIL_PASSWORD', 'your_16char_app_password');

// ── Google OAuth 2.0 ───────────────────────
define('GOOGLE_CLIENT_ID',     'your_client_id.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'your_client_secret');
```

---

### Step 5 — Google Cloud Console Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/) and create a new project
2. Enable these APIs under **APIs & Services → Library**:
   - ✅ Google Classroom API
   - ✅ Google Drive API
3. Configure **OAuth Consent Screen** (User type: External)
4. Create **OAuth 2.0 Credentials** → Web Application
5. Add **Authorized Redirect URI**: `http://localhost/NoteNest-main/google_callback.php`
6. Copy **Client ID** and **Client Secret** into `config.php`

---

### Step 6 — Set Folder Permissions

```bash
mkdir -p uploads/notes uploads/recordings img/user_photos
chmod -R 777 uploads img/user_photos
```

---

### Step 7 — Start ChromaDB Vector Service (Optional — for RAG)

```bash
# Create Python virtual environment
python3 -m venv .venv
./.venv/bin/pip install chromadb fastapi uvicorn pydantic

# Start on port 8000
./.venv/bin/python chroma_service.py
```

---

### Step 8 — Setup Cron Jobs (Optional — for automation)

```bash
# Edit crontab
crontab -e

# Add these jobs:
0 * * * *   /usr/bin/php /path/to/NoteNest-main/cron/todo_reminder.php    > /dev/null 2>&1
0 */6 * * * /usr/bin/php /path/to/NoteNest-main/cron/google_sync.php      > /dev/null 2>&1
0 8 * * *   /usr/bin/php /path/to/NoteNest-main/cron/google_reminders.php  > /dev/null 2>&1
```

---

### Step 9 — Docker Quick Start (Alternative)

```bash
docker-compose up --build -d
```

Access at: `http://localhost:8080/login.php`

---

### Step 10 — Launch the App 🚀

1. Start **Apache** and **MySQL** in XAMPP Control Panel
2. Open your browser: `http://localhost/NoteNest-main/login.php`
3. Register a new account and verify your email
4. Start learning! 🎓

---

## 📂 Project Structure

```
NoteNest-main/
│
├── 📄 index.php                        — Entry point redirect
├── 📄 login.php                        — User login page
├── 📄 register.php                     — Registration + avatar upload
├── 📄 verify_email.php                 — Email verification handler
├── 📄 logout.php                       — Session destroy
│
├── 📄 dashboard.php                    — Main dashboard (stats, overview)
├── 📄 course_management.php            — Course, topic, folder, file manager
├── 📄 my_note_nest.php                 — Personal file & folder explorer
├── 📄 shared_note_nest.php             — Shared-with-me view
├── 📄 favorites.php                    — Starred items
│
├── 📄 google_classroom.php             — GC hub (courses, assignments, calendar)
├── 📄 google_auth.php                  — OAuth redirect initiator
├── 📄 google_callback.php              — OAuth token exchange handler
├── 📄 google_disconnect.php            — Disconnect Google account
├── 📄 dashboard_gc_ajax.php            — AJAX drill-down endpoint
│
├── 📄 ai_tutor.php                     — Groq-powered AI academic tutor
├── 📄 ai_exam.php                      — AI exam generation & grading
├── 📄 generate_exam.php                — Exam question generator API
├── 📄 generate_answer.php              — Answer evaluation API
├── 📄 study_recommendations.php        — AI study plan generator
│
├── 📄 progress_analytics.php           — Analytics (heatmaps, charts)
├── 📄 lecture_recorder.php             — Audio recorder + waveform
├── 📄 todo.php                         — Task manager
├── 📄 notifications.php                — Notification center
├── 📄 profile.php                      — User profile editor
│
├── 📄 config.php                       — App configuration (gitignored)
├── 📄 config.example.php               — Configuration template
├── 📄 database.sql                     — Main database schema
├── 📄 chroma_service.py                — Python FastAPI ChromaDB service
├── 📄 Dockerfile                       — Docker image definition
├── 📄 docker-compose.yml               — Multi-container orchestration
│
├── 📁 includes/                        — Core backend modules
│   ├── auth.php                        — Session guard
│   ├── db.php                          — DB connection
│   ├── navbar.php                      — Navigation bar
│   ├── footer.php                      — Page footer
│   ├── functions.php                   — Utility functions
│   ├── ai_service.php                  — Groq API wrapper
│   ├── google_classroom_service.php    — GC + Drive API client
│   ├── google_sync_engine.php          — Background sync engine
│   ├── google_ai_analyzer.php          — AI assignment analyzer
│   ├── google_reminder_engine.php      — Notification & reminder engine
│   └── send_email.php                  — PHPMailer SMTP helper
│
├── 📁 css/                             — Modular per-page stylesheets
├── 📁 cron/                            — Scheduled background tasks
├── 📁 uploads/                         — User file storage
│   ├── notes/                          — Uploaded documents
│   └── recordings/                     — Lecture audio recordings
├── 📁 img/                             — Static images & user avatars
├── 📁 phpmailer/                       — Email delivery library
├── 📁 vendor/                          — Composer dependencies
└── 📁 embeddings/                      — Vector embedding cache
```

---

## 🎬 Demo Video

<div align="center">

[![Watch Demo](https://img.shields.io/badge/▶_Watch_Demo-YouTube-FF0000?style=for-the-badge&logo=youtube&logoColor=white)](https://youtube-link.com)

> 🎥 Click the badge above to watch a full walkthrough of NoteNest AI's features.

</div>

---

## 🔒 Security Measures

| Measure | Implementation |
|---|---|
| 🛡️ SQL Injection Prevention | 100% MySQLi prepared statements with bound parameters |
| 🔐 Password Security | `password_hash()` + `password_verify()` — Bcrypt algorithm |
| 🚪 Route Protection | Session guard `auth.php` on every internal page |
| 🔑 OAuth Token Security | Tokens stored per user with auto-refresh handling |
| 🧹 XSS Prevention | `htmlspecialchars()` on all rendered user data |
| 🔒 File Access Control | Strict ownership & permission checks before download |
| 📧 Email Verification | Token-based verification before account activation |
| 🔄 CSRF Awareness | Session-based action validation on sensitive operations |

---

## 👥 Team Members

| Name | Role | Responsibilities |
|---|---|---|
| **Kamran Hasan** | 🏗️ Lead Developer & Architect | Full-stack PHP, Google Classroom API, AI Integration, Database Design |
| Member 2 | 🎨 Frontend Developer | UI/UX Design, Bootstrap, JavaScript, AJAX interactions |
| Member 3 | 🤖 AI / ML Engineer | Groq API integration, Prompt Engineering, ChromaDB RAG, Exam System |

> ✏️ Update this table with your actual team member names and roles before submission.

---

## 🔮 Future Improvements

| Priority | Feature | Description |
|---|---|---|
| 🔥 High | 📱 Mobile App | Native Android/iOS app using React Native or Flutter |
| 🔥 High | 🔊 Voice Assistant | Voice-activated AI tutor using Web Speech API |
| 🟡 Medium | 🔤 OCR Support | Extract text from scanned PDFs and handwritten notes |
| 🟡 Medium | 👥 Real-time Collaboration | Live shared editing of notes using WebSockets |
| 🟡 Medium | 📡 Offline Mode | Progressive Web App (PWA) with offline note access |
| 🟢 Low | 🌐 Multi-language Support | i18n support for non-English speaking students |
| 🟢 Low | 🎯 Adaptive Learning | ML-based personalized quiz difficulty adjustment |
| 🟢 Low | 📊 Advanced Analytics | Predictive study performance modeling |
| 🟢 Low | 🔗 LMS Integrations | Moodle, Canvas, Microsoft Teams integrations |

---

## 📊 Project Stats

<div align="center">

| Metric | Value |
|---|---|
| 🧩 Total Modules | 11+ |
| 🗄️ Database Tables | 17+ |
| 📄 PHP Files | 50+ |
| 🤖 AI Model | Llama 3.3 70B (Groq) |
| 🔗 External APIs | 3 (Groq, Google Classroom, Google Drive) |
| 🎓 Project Type | University Final Year Project |

</div>

---

## 📝 License

```
MIT License

Copyright (c) 2026 Kamran Hasan

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
```

---

<div align="center">

**Built with ❤️ as a Final Year Project**

⭐ If you found this project helpful, please give it a star!

[![GitHub stars](https://img.shields.io/github/stars/kkamranhasan/NoteNest?style=social)](https://github.com/kkamranhasan/NoteNest)

---

*NoteNest AI — Empowering Students with Intelligence* 🧠

</div>
