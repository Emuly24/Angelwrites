-- Q51: tables in the production database that no code reads or writes (checked with a search of
-- every PHP file; they are also no longer created on new installs, see includes/schema.php).
-- NOT run automatically. Back up data/site.db first, then run this with the sqlite3 tool:
--   sqlite3 data/site.db < tools/q51_drop_unused_tables.sql
-- tools/ is never deployed. All of these were empty in the production copy reviewed on
-- 6 Nov 2026 except blog_categories (3 rows), which is left commented out for you to decide.
BEGIN;
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS batch_jobs;
DROP TABLE IF EXISTS book_content_drafts;
DROP TABLE IF EXISTS book_highlights;
DROP TABLE IF EXISTS book_processing_log;
DROP TABLE IF EXISTS connections;
DROP TABLE IF EXISTS invites;
DROP TABLE IF EXISTS media_files;
DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS reflection_reads;
DROP TABLE IF EXISTS user_tags;
-- DROP TABLE IF EXISTS blog_categories;
COMMIT;
