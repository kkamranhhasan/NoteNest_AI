-- ============================================================
-- NoteNest AI Academic Platform — Full Database Schema
-- Version 2.0 | Updated for AI-Powered Features
-- ============================================================

CREATE DATABASE IF NOT EXISTS notenest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE notenest;

-- ── 1. Users ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  name               VARCHAR(100) NOT NULL,
  email              VARCHAR(100) NOT NULL UNIQUE,
  password           VARCHAR(255) NOT NULL,
  phone              VARCHAR(20)  DEFAULT NULL,
  gender             ENUM('Male','Female','Other') DEFAULT NULL,
  photo              VARCHAR(255) DEFAULT 'img/user.png',
  is_verified        TINYINT(1)   DEFAULT 0,
  verification_token VARCHAR(64)  DEFAULT NULL,
  token_created_at   DATETIME     DEFAULT NULL,
  created_at         DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ── 2. Notifications ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  message    TEXT NOT NULL,
  is_read    TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ── 3. Courses ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS courses (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  name        VARCHAR(150) NOT NULL,
  code        VARCHAR(20)  NOT NULL,
  description TEXT,
  color       VARCHAR(7) DEFAULT '#197f8f',
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY unique_course_per_user (user_id, code)
);

-- ── 4. Folders ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS folders (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(255) NOT NULL,
  owner_id         INT NOT NULL,
  course_id        INT DEFAULT NULL,
  is_course_root   TINYINT(1) DEFAULT 0,
  is_shared        TINYINT(1) DEFAULT 0,
  parent_folder_id INT DEFAULT NULL,
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id)         REFERENCES users(id)   ON DELETE CASCADE,
  FOREIGN KEY (course_id)        REFERENCES courses(id) ON DELETE SET NULL,
  FOREIGN KEY (parent_folder_id) REFERENCES folders(id) ON DELETE SET NULL,
  INDEX idx_folder_course (course_id),
  INDEX idx_folder_root (is_course_root, owner_id)
);

-- ── 5. Course Topics (Syllabus) ───────────────────────────────
CREATE TABLE IF NOT EXISTS course_topics (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  course_id  INT NOT NULL,
  folder_id  INT DEFAULT NULL,
  title      VARCHAR(255) NOT NULL,
  week_no    INT DEFAULT NULL,
  sort_order INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL
);

-- ── 6. Files ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS files (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  folder_id  INT,
  owner_id   INT NOT NULL,
  course_id  INT DEFAULT NULL,
  name       VARCHAR(255) NOT NULL,
  file_path  VARCHAR(255) NOT NULL,
  mime_type  VARCHAR(100),
  is_shared  TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL,
  FOREIGN KEY (owner_id)  REFERENCES users(id)   ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
  INDEX idx_file_course (course_id)
);

-- ── 7. File → Course/Topic Tags ───────────────────────────────
CREATE TABLE IF NOT EXISTS file_course_tags (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  file_id   INT NOT NULL,
  course_id INT NOT NULL,
  topic_id  INT DEFAULT NULL,
  tagged_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (file_id)   REFERENCES files(id)         ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES courses(id)       ON DELETE CASCADE,
  FOREIGN KEY (topic_id)  REFERENCES course_topics(id) ON DELETE SET NULL,
  UNIQUE KEY unique_file_course (file_id, course_id)
);

-- ── 8. Shared Access ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS shared_access (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  item_type          ENUM('file','folder') NOT NULL,
  item_id            INT NOT NULL,
  shared_with_user_id INT NOT NULL,
  can_edit           TINYINT(1) NOT NULL DEFAULT 0,
  permission_type    ENUM('view','comment') DEFAULT 'view',
  share_token        VARCHAR(64) DEFAULT NULL,
  expires_at         DATETIME DEFAULT NULL,
  created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (shared_with_user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY unique_share (item_type, item_id, shared_with_user_id),
  KEY idx_shared_with (shared_with_user_id),
  KEY idx_item (item_type, item_id)
);

-- ── 9. Favorites ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS favorites (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  item_type  ENUM('file','folder') NOT NULL,
  item_id    INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ── 10. Todos / Tasks ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS todos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL,
  title          VARCHAR(100) NOT NULL,
  event_datetime DATETIME NOT NULL,
  details        TEXT,
  status         ENUM('pending','done') DEFAULT 'pending',
  priority       ENUM('low','medium','high') DEFAULT 'medium',
  task_type      ENUM('assignment','exam','reminder','lecture','other') DEFAULT 'other',
  course_id      INT DEFAULT NULL,
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
);

-- ── 11. Todo Notification Log ─────────────────────────────────
CREATE TABLE IF NOT EXISTS todo_notifications (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  todo_id     INT NOT NULL,
  notified_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (todo_id) REFERENCES todos(id) ON DELETE CASCADE
);

-- ── 12. AI Chat History ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS ai_chat_history (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  user_id          INT NOT NULL,
  session_id       VARCHAR(64) NOT NULL,
  role             ENUM('user','assistant') NOT NULL,
  message          TEXT NOT NULL,
  interaction_type ENUM('tutor','exam_hint','summary','general') DEFAULT 'tutor',
  course_id        INT DEFAULT NULL,
  tokens_used      INT DEFAULT 0,
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
  KEY idx_session (session_id),
  KEY idx_user_created (user_id, created_at)
);

-- ── 13. AI Evaluations (Quiz/Exam) ───────────────────────────
CREATE TABLE IF NOT EXISTS ai_evaluations (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL,
  file_id        INT DEFAULT NULL,
  course_id      INT DEFAULT NULL,
  questions_json LONGTEXT NOT NULL COMMENT 'AI-generated questions as JSON',
  user_answers   LONGTEXT          COMMENT 'Student answers as JSON',
  score          DECIMAL(5,2) DEFAULT NULL,
  max_score      INT DEFAULT 100,
  feedback       LONGTEXT          COMMENT 'AI evaluation feedback',
  weak_areas     TEXT              COMMENT 'Comma-separated weak topics',
  status         ENUM('generated','submitted','evaluated') DEFAULT 'generated',
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  evaluated_at   DATETIME DEFAULT NULL,
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  FOREIGN KEY (file_id)   REFERENCES files(id)   ON DELETE SET NULL,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
);

-- ── 14. User Progress / Analytics ────────────────────────────
CREATE TABLE IF NOT EXISTS user_progress (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  course_id    INT DEFAULT NULL,
  event_type   ENUM('file_upload','ai_chat','exam_taken','task_done','login','note_view') NOT NULL,
  event_detail VARCHAR(255) DEFAULT NULL,
  score_value  DECIMAL(5,2) DEFAULT NULL,
  recorded_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
  KEY idx_user_event (user_id, event_type),
  KEY idx_recorded (recorded_at)
);

-- ── 15. Lecture Recordings ───────────────────────────────────
CREATE TABLE IF NOT EXISTS lecture_recordings (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  course_id    INT DEFAULT NULL,
  title        VARCHAR(255) NOT NULL,
  file_path    VARCHAR(255) NOT NULL,
  duration_sec INT DEFAULT 0,
  file_size    BIGINT DEFAULT 0,
  transcript   LONGTEXT DEFAULT NULL,
  status       ENUM('recorded','processing','transcribed') DEFAULT 'recorded',
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
);
