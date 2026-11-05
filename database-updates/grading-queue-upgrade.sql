-- Grading queue upgrade for an EXISTING database (for example your local nexus_database).
-- Do NOT run this on a fresh database created from database.sql: it already has these changes.
-- Back up first. Run once in phpMyAdmin with the correct database selected.

ALTER TABLE `test_attempts`
  ADD COLUMN `grading_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `grading_error`,
  ADD KEY `idx_test_attempts_grading_queue` (`status`, `grading_attempts`, `submitted_at`);
