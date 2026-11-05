-- Apply once to an existing installation after taking a database backup.
-- Adds server-side draft recovery and an idempotency token for safe submit retries.
ALTER TABLE `test_attempts`
  ADD COLUMN `draft_answers` JSON NULL AFTER `submitted_answers`,
  ADD COLUMN `client_submission_id` CHAR(36) NULL AFTER `draft_answers`;
