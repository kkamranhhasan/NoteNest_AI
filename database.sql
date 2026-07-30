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

-- ============================================================
-- Google Classroom Integration Tables
-- ============================================================

-- ── 16. Google Accounts (OAuth tokens per user) ──────────────
CREATE TABLE IF NOT EXISTS google_accounts (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT NOT NULL UNIQUE,
  google_email    VARCHAR(150) NOT NULL,
  access_token    TEXT NOT NULL,
  refresh_token   TEXT NOT NULL,
  token_expiry    DATETIME NOT NULL,
  last_sync_at    DATETIME DEFAULT NULL,
  sync_status     ENUM('idle','syncing','error') DEFAULT 'idle',
  sync_error      TEXT DEFAULT NULL,
  connected_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ── 17. Google Courses ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS google_courses (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  user_id           INT NOT NULL,
  google_course_id  VARCHAR(100) NOT NULL,
  course_id         INT DEFAULT NULL,
  root_folder_id    INT DEFAULT NULL,
  course_name       VARCHAR(255) NOT NULL,
  section           VARCHAR(255) DEFAULT NULL,
  description       TEXT DEFAULT NULL,
  course_code       VARCHAR(50) DEFAULT NULL,
  course_state      VARCHAR(20) DEFAULT 'ACTIVE',
  teacher_name      VARCHAR(200) DEFAULT NULL,
  last_synced_at    DATETIME DEFAULT NULL,
  created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES courses(id)  ON DELETE SET NULL,
  FOREIGN KEY (root_folder_id) REFERENCES folders(id) ON DELETE SET NULL,
  UNIQUE KEY unique_google_course (user_id, google_course_id)
);

-- ── 18. Google Topics ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS google_topics (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  user_id           INT NOT NULL,
  google_course_id  VARCHAR(100) NOT NULL,
  google_topic_id   VARCHAR(100) NOT NULL,
  topic_id          INT DEFAULT NULL,
  folder_id         INT DEFAULT NULL,
  topic_name        VARCHAR(255) NOT NULL,
  sort_order        INT DEFAULT 0,
  created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)  REFERENCES users(id)          ON DELETE CASCADE,
  FOREIGN KEY (topic_id) REFERENCES course_topics(id)  ON DELETE SET NULL,
  FOREIGN KEY (folder_id) REFERENCES folders(id)       ON DELETE SET NULL,
  UNIQUE KEY unique_google_topic (user_id, google_course_id, google_topic_id)
);

-- ── 19. Google Files ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS google_files (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  user_id           INT NOT NULL,
  google_course_id  VARCHAR(100) NOT NULL,
  google_file_id    VARCHAR(200) NOT NULL,
  google_material_id VARCHAR(200) DEFAULT NULL,
  file_id           INT DEFAULT NULL,
  folder_id         INT DEFAULT NULL,
  course_id         INT DEFAULT NULL,
  topic_id          INT DEFAULT NULL,
  file_title        VARCHAR(255) NOT NULL,
  file_type         VARCHAR(50) DEFAULT NULL,
  mime_type         VARCHAR(100) DEFAULT NULL,
  file_url          TEXT DEFAULT NULL,
  download_status   ENUM('pending','downloaded','failed','skipped') DEFAULT 'pending',
  error_message     TEXT DEFAULT NULL,
  created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  FOREIGN KEY (file_id)   REFERENCES files(id)   ON DELETE SET NULL,
  FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
  UNIQUE KEY unique_google_file (user_id, google_file_id)
);

-- ── 20. Google Assignments ───────────────────────────────────
CREATE TABLE IF NOT EXISTS google_assignments (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  user_id               INT NOT NULL,
  google_course_id      VARCHAR(100) NOT NULL,
  google_coursework_id  VARCHAR(100) NOT NULL,
  todo_id               INT DEFAULT NULL,
  calendar_event_id     INT DEFAULT NULL,
  course_id             INT DEFAULT NULL,
  title                 VARCHAR(255) NOT NULL,
  description           TEXT DEFAULT NULL,
  due_date              DATE DEFAULT NULL,
  due_time              TIME DEFAULT NULL,
  max_points            DECIMAL(6,2) DEFAULT NULL,
  work_type             VARCHAR(50) DEFAULT NULL,
  state                 VARCHAR(20) DEFAULT 'PUBLISHED',
  ai_analysis           LONGTEXT DEFAULT NULL,
  ai_questions_generated TINYINT(1) DEFAULT 0,
  reminders_created     TINYINT(1) DEFAULT 0,
  created_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  FOREIGN KEY (todo_id)   REFERENCES todos(id)    ON DELETE SET NULL,
  FOREIGN KEY (course_id) REFERENCES courses(id)  ON DELETE SET NULL,
  UNIQUE KEY unique_google_assignment (user_id, google_course_id, google_coursework_id)
);

-- ── 21. Google Sync Logs ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS google_sync_logs (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT NOT NULL,
  sync_type       ENUM('manual','cron','auto') DEFAULT 'manual',
  status          ENUM('started','completed','failed') DEFAULT 'started',
  courses_synced  INT DEFAULT 0,
  topics_synced   INT DEFAULT 0,
  files_synced    INT DEFAULT 0,
  assignments_synced INT DEFAULT 0,
  errors_count    INT DEFAULT 0,
  error_details   LONGTEXT DEFAULT NULL,
  duration_sec    DECIMAL(8,2) DEFAULT NULL,
  started_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  completed_at    DATETIME DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY idx_sync_user_date (user_id, started_at)
);

-- ── 22. Calendar Events ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS calendar_events (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT NOT NULL,
  todo_id         INT DEFAULT NULL,
  assignment_id   INT DEFAULT NULL,
  course_id       INT DEFAULT NULL,
  title           VARCHAR(255) NOT NULL,
  description     TEXT DEFAULT NULL,
  event_date      DATE NOT NULL,
  event_time      TIME DEFAULT NULL,
  priority        ENUM('low','medium','high') DEFAULT 'medium',
  event_type      ENUM('assignment','exam','lecture','reminder','other') DEFAULT 'assignment',
  color           VARCHAR(7) DEFAULT '#4285f4',
  is_completed    TINYINT(1) DEFAULT 0,
  source          ENUM('manual','google_classroom') DEFAULT 'google_classroom',
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  FOREIGN KEY (todo_id)   REFERENCES todos(id)    ON DELETE SET NULL,
  FOREIGN KEY (course_id) REFERENCES courses(id)  ON DELETE SET NULL,
  KEY idx_calendar_user_date (user_id, event_date)
);

-- ── 23. Document Chunks (Private Knowledge Vector/Sparse Embeddings) ──
CREATE TABLE IF NOT EXISTS document_chunks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  course_id INT DEFAULT NULL,
  folder_id INT DEFAULT NULL,
  topic_id INT DEFAULT NULL,
  file_id INT NOT NULL,
  chunk_index INT NOT NULL,
  content LONGTEXT NOT NULL,
  page_number INT DEFAULT 1,
  embedding_id VARCHAR(36) DEFAULT NULL COMMENT 'Qdrant/Jina point UUID',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
  INDEX idx_dc_user (user_id),
  INDEX idx_dc_file (file_id),
  INDEX idx_dc_embedding (embedding_id)
);

-- ── 24. Embedding Jobs (Tracks async file indexing status) ────
CREATE TABLE IF NOT EXISTS embedding_jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  file_id INT NOT NULL UNIQUE,
  status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
  error_message TEXT DEFAULT NULL,
  attempts INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
  INDEX idx_ej_status (status)
);

-- ── 25. Vector Index (Maps Qdrant IDs to MySQL records) ───────
CREATE TABLE IF NOT EXISTS vector_index (
  id INT AUTO_INCREMENT PRIMARY KEY,
  embedding_id VARCHAR(36) NOT NULL UNIQUE COMMENT 'UUID used in Qdrant',
  file_id INT NOT NULL,
  chunk_id INT DEFAULT NULL,
  user_id INT NOT NULL,
  course_id INT DEFAULT NULL,
  folder_id INT DEFAULT NULL,
  topic_id INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_vi_file (file_id),
  INDEX idx_vi_user (user_id)
);

-- ── 26. AI Query Logs (Vector search query history) ──────────
CREATE TABLE IF NOT EXISTS ai_query_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  query TEXT NOT NULL,
  response TEXT DEFAULT NULL,
  course_id INT DEFAULT NULL,
  folder_id INT DEFAULT NULL,
  topic_id INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_aql_user (user_id),
  INDEX idx_aql_created (created_at)
);

