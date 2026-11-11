-- Additive test lifecycle update for existing Nexus SBI databases.
-- Back up first and apply exactly once. Do not run this against a database
-- that already has the tests.archived_at column.
ALTER TABLE `tests`
  ADD COLUMN `archived_at` TIMESTAMP NULL AFTER `deadline_at`;
