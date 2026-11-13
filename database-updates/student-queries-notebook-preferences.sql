-- Add student support queries and personal notebook language preferences.
-- Select the existing database in phpMyAdmin before importing.

CREATE TABLE IF NOT EXISTS `student_queries` (
  `id` CHAR(36) NOT NULL,
  `userId` CHAR(36) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `response` TEXT NULL,
  `respondedById` CHAR(36) NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'open',
  `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_student_queries_user` (`userId`),
  KEY `idx_student_queries_status` (`status`),
  KEY `idx_student_queries_created_at` (`createdAt`, `id`),
  CONSTRAINT `fk_student_queries_user` FOREIGN KEY (`userId`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_student_queries_responded_by` FOREIGN KEY (`respondedById`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @add_prompt_language_sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `ai_notebook_user_prompts` ADD COLUMN `response_language` VARCHAR(32) NOT NULL DEFAULT ''english'' AFTER `prompt`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'ai_notebook_user_prompts'
    AND column_name = 'response_language'
);

PREPARE add_prompt_language_stmt FROM @add_prompt_language_sql;
EXECUTE add_prompt_language_stmt;
DEALLOCATE PREPARE add_prompt_language_stmt;

SET @add_result_release_delay_sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `ai_grading_settings` ADD COLUMN `result_release_delay_hours` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `deficiency_report_prompt`',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'ai_grading_settings'
    AND column_name = 'result_release_delay_hours'
);

PREPARE add_result_release_delay_stmt FROM @add_result_release_delay_sql;
EXECUTE add_result_release_delay_stmt;
DEALLOCATE PREPARE add_result_release_delay_stmt;

INSERT IGNORE INTO `ai_grading_settings`
  (`id`, `deficiency_report_prompt`, `result_release_delay_hours`)
VALUES
  (1, 'Address the student directly using you and your. Highlight your strongest points first, then identify the main gaps with clear, actionable guidance.', 0);

UPDATE `ai_grading_settings`
SET `result_release_delay_hours` = 0
WHERE `id` = 1;
