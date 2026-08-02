-- ============================================================
-- gc_migration.sql
-- NoteNest AI — Google Classroom Account Isolation Migration
-- Run this ONCE after deploying the updated PHP files.
-- SAFE: Does NOT touch courses, folders, files (NoteNest data).
-- ============================================================

-- 1. Add google_account_id to google_courses
ALTER TABLE google_courses
    ADD COLUMN IF NOT EXISTS google_account_id INT DEFAULT NULL AFTER user_id,
    ADD INDEX IF NOT EXISTS idx_gc_account (google_account_id);

-- Backfill from current google_accounts
UPDATE google_courses gc
JOIN google_accounts ga ON ga.user_id = gc.user_id
SET gc.google_account_id = ga.id
WHERE gc.google_account_id IS NULL;

-- 2. Add google_account_id to google_topics
ALTER TABLE google_topics
    ADD COLUMN IF NOT EXISTS google_account_id INT DEFAULT NULL AFTER user_id,
    ADD INDEX IF NOT EXISTS idx_gt_account (google_account_id);

UPDATE google_topics gt
JOIN google_accounts ga ON ga.user_id = gt.user_id
SET gt.google_account_id = ga.id
WHERE gt.google_account_id IS NULL;

-- 3. Add google_account_id to google_files
ALTER TABLE google_files
    ADD COLUMN IF NOT EXISTS google_account_id INT DEFAULT NULL AFTER user_id,
    ADD INDEX IF NOT EXISTS idx_gf_account (google_account_id);

UPDATE google_files gf
JOIN google_accounts ga ON ga.user_id = gf.user_id
SET gf.google_account_id = ga.id
WHERE gf.google_account_id IS NULL;

-- 4. Add google_account_id to google_assignments
ALTER TABLE google_assignments
    ADD COLUMN IF NOT EXISTS google_account_id INT DEFAULT NULL AFTER user_id,
    ADD INDEX IF NOT EXISTS idx_ga_account (google_account_id);

UPDATE google_assignments gasgn
JOIN google_accounts ga ON ga.user_id = gasgn.user_id
SET gasgn.google_account_id = ga.id
WHERE gasgn.google_account_id IS NULL;

-- Done!
SELECT 'Migration complete. google_account_id added to all Google Classroom sync tables.' AS status;
