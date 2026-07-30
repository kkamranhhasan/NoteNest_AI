-- ============================================================
-- migration_add_missing_tables.sql — NoteNest AI Platform
-- Run this ONLY if you have an existing DB that's missing these tables.
-- For fresh deployments, database.sql already includes everything.
-- ============================================================

SET FOREIGN_KEY_CHECKS=0;

-- ── Add embedding_id column to document_chunks if it doesn't exist ──
ALTER TABLE document_chunks
  ADD COLUMN IF NOT EXISTS embedding_id VARCHAR(36) DEFAULT NULL
  COMMENT 'Qdrant/Jina point UUID' AFTER page_number;

ALTER TABLE document_chunks
  ADD INDEX IF NOT EXISTS idx_dc_embedding (embedding_id);

-- ── 24. Embedding Jobs ────────────────────────────────────────
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

-- ── 25. Vector Index ──────────────────────────────────────────
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

-- ── 26. AI Query Logs ─────────────────────────────────────────
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

SET FOREIGN_KEY_CHECKS=1;

SELECT 'Migration completed successfully!' AS status;
