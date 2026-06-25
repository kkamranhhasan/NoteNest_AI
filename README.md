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
  <img src="https://img.shields.io/badge/Gemini-2.5%20Flash-4285F4?logo=google&logoColor=white"/>
  <img src="https://img.shields.io/badge/License-MIT-green"/>
</p>

---



### 🤖 AI Features (Powered by Gemini 2.5 Flash)
| Feature | Description |
|---------|-------------|
| **AI Tutor Chat** | Ask any academic question, get Markdown-formatted answers with chat history |
| **AI Exam Wizard** | Upload any document → AI generates MCQ + Short Answer questions |
| **AI Answer Evaluation** | Submit your answers → AI scores and gives per-question feedback |

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
| **Register & Login** | Secure registration with email verification |
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
| **AI Engine** | Google Gemini 2.5 Flash API |
| **Charts** | Chart.js |
| **DOCX Preview** | Mammoth.js |
| **XLSX Preview** | SheetJS (xlsx.js) |
| **Email** | PHPMailer |
| **Icons** | Font Awesome 6 |
| **Fonts** | Google Fonts (Inter) |

---

## 🚀 Installation & Setup

### Prerequisites
- XAMPP (or any Apache + PHP 8.x + MySQL stack)
- PHP 8.0 or higher
- MySQL 8.0 or higher
- Internet connection (for Gemini API, CDN libraries)

### Step 1 — Clone the Repository
```bash
git clone https://github.com/YOUR_USERNAME/NoteNest.git
cd NoteNest
```

### Step 2 — Place in Web Server Root
```
XAMPP:   /Applications/XAMPP/xamppfiles/htdocs/NoteNest-main/
Windows: C:\xampp\htdocs\NoteNest-main\
```

### Step 3 — Create the Database
1. Open **phpMyAdmin** → `http://localhost/phpmyadmin`
2. Create a new database named `notenest`
3. Import `database/notenest_ai.sql` (or `database.sql`)

### Step 4 — Configure the App
Open `config.php` and update:
```php
define('GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY_HERE');
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'notenest');
```

### Step 5 — Set Folder Permissions
```bash
chmod 777 uploads/notes/
chmod 777 uploads/recordings/
chmod 777 img/user_photos/
```

### Step 6 — Configure Email (for verification)
In `includes/mailer.php`, update SMTP settings:
```php
$mail->Host     = 'smtp.gmail.com';
$mail->Username = 'your_email@gmail.com';
$mail->Password = 'your_app_password';
```

### Step 7 — Access the App
```
http://localhost/NoteNest-main/
```

### Step 8 — Set Up Todo Reminders (Optional)
Add a cron job to run every hour:
```
0 * * * * php /path/to/NoteNest-main/cron/todo_reminder.php
```

---

## 📂 Project Structure

```
NoteNest-main/
│
├── 📄 index.php / dashboard.php     — Main dashboard
├── 📄 login.php                     — User login
├── 📄 register.php                  — User registration + profile photo
├── 📄 logout.php                    — Session logout
├── 📄 verify_email.php              — Email verification
│
├── 📄 my_note_nest.php              — Personal files & folders
├── 📄 shared_note_nest.php          — Files shared with me
├── 📄 favorites.php                 — Starred files/folders
├── 📄 note_preview.php              — File preview API
├── 📄 note_download.php             — File download handler
│
├── 📄 course_management.php         — Course & syllabus management
├── 📄 todo.php                      — Task management
├── 📄 notifications.php             — Notification center
├── 📄 profile.php                   — User profile editor
│
├── 📄 ai_tutor.php                  — AI Chat Tutor (Gemini)
├── 📄 ai_exam.php                   — AI Exam Generator & Evaluator
├── 📄 lecture_recorder.php          — In-browser lecture recorder
│
├── 📄 share.php                     — Share file/folder modal handler
├── 📄 share_management.php          — Manage shared access
│
├── 📄 config.php                    — App config & Gemini API key
│
├── 📁 includes/
│   ├── auth.php                     — Session auth guard
│   ├── db.php                       — Database connection
│   ├── navbar.php                   — Navigation bar
│   └── mailer.php                   — PHPMailer email setup
│
├── 📁 css/                          — Custom stylesheets
├── 📁 js/                           — Custom JavaScript
├── 📁 img/
│   └── user_photos/                 — Profile pictures
│
├── 📁 uploads/
│   ├── notes/                       — Uploaded study files
│   └── recordings/                  — Lecture recordings
│
├── 📁 cron/
│   └── todo_reminder.php            — Scheduled reminder script
│
└── 📁 database/
    └── notenest_ai.sql              — Full database schema
```

---

## 🗄️ Database Tables

| Table | Purpose |
|-------|---------|
| `users` | User accounts, profile photos, email verification |
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

## 🔒 Security

- ✅ All DB queries use **prepared statements** (SQL injection safe)
- ✅ **Password hashing** with `password_hash()` / `password_verify()`
- ✅ **Email verification** required before login
- ✅ **Session-based authentication** with auth guard on every page
- ✅ **File ownership checks** — users can only access their own data
- ✅ **Input validation & sanitization** on all forms
- ✅ **MIME type verification** for uploads
- ✅ **XSS protection** with `htmlspecialchars()` on all output

---

## 👥 Team / Contributors

- [@kkamranhasan](https://github.com/kkamranhasan) — AI integration, File Management, UI/UX

---

## 📝 License

MIT License © 2025 NoteNest Team

Permission is hereby granted, free of charge, to any person obtaining a copy of this software to use, copy, modify, merge, publish, and distribute it, subject to the condition that the above copyright notice is included in all copies.

---

## 🌐 Get Your Gemini API Key

1. Go to [https://aistudio.google.com/app/apikey](https://aistudio.google.com/app/apikey)
2. Sign in with Google
3. Click **"Create API Key"**
4. Paste it in `config.php`

---

<p align="center">Made with ❤️ for academic excellence</p>
