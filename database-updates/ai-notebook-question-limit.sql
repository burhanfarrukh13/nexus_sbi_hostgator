-- One-time upgrade for existing HostGator databases; back up before applying.
-- Adds the admin-configurable Notebook daily cap (default 15) and permits counters
-- up to the API's validation maximum of 1,000.
ALTER TABLE `ai_grading_settings`
  ADD COLUMN `notebook_daily_question_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 15;

ALTER TABLE `ai_notebook_daily_usage`
  DROP CHECK `chk_ai_notebook_daily_usage_count`,
  ADD CONSTRAINT `chk_ai_notebook_daily_usage_count`
    CHECK (`requestCount` BETWEEN 0 AND 1000);
