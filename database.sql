-- Nexus SBI HostGator / Laragon schema for authentication, tests, and AI Notebook.
-- Select the target database in phpMyAdmin before importing this file.
-- This script drops and recreates only the tables listed below. Back up first.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `folder_access_payments`;
DROP TABLE IF EXISTS `folder_access_installments`;
DROP TABLE IF EXISTS `folder_access_plans`;
DROP TABLE IF EXISTS `folder_permissions`;
DROP TABLE IF EXISTS `test_folders`;
DROP TABLE IF EXISTS `folders`;
DROP TABLE IF EXISTS `test_attempt_files`;
DROP TABLE IF EXISTS `test_source_files`;
DROP TABLE IF EXISTS `test_attempts`;
DROP TABLE IF EXISTS `tests`;
DROP TABLE IF EXISTS `subject_user_access`;
DROP TABLE IF EXISTS `ai_notebook_daily_usage`;
DROP TABLE IF EXISTS `ai_notebook_user_prompts`;
DROP TABLE IF EXISTS `ai_notebook_prompts`;
DROP TABLE IF EXISTS `ai_notebook_chat_tickets`;
DROP TABLE IF EXISTS `ai_notebook_chat_messages`;
DROP TABLE IF EXISTS `ai_notebook_chat_sessions`;
DROP TABLE IF EXISTS `ai_notebook_chunks`;
DROP TABLE IF EXISTS `ai_notebook_sources`;
DROP TABLE IF EXISTS `ai_grading_settings`;
DROP TABLE IF EXISTS `student_queries`;
DROP TABLE IF EXISTS `student_cohort_memberships`;
DROP TABLE IF EXISTS `students`;
DROP TABLE IF EXISTS `school_years`;
DROP TABLE IF EXISTS `licenses`;
DROP TABLE IF EXISTS `lecture_progress`;
DROP TABLE IF EXISTS `lectures`;
DROP TABLE IF EXISTS `flashcard_reviews`;
DROP TABLE IF EXISTS `flashcards`;
DROP TABLE IF EXISTS `quiz_attempt_answers`;
DROP TABLE IF EXISTS `quiz_attempts`;
DROP TABLE IF EXISTS `mock_exams`;
DROP TABLE IF EXISTS `topic_progress`;
DROP TABLE IF EXISTS `questions`;
DROP TABLE IF EXISTS `topics`;
DROP TABLE IF EXISTS `grids`;
DROP TABLE IF EXISTS `courses`;
DROP TABLE IF EXISTS `subject_teachers`;
DROP TABLE IF EXISTS `class_subjects`;
DROP TABLE IF EXISTS `subjects`;
DROP TABLE IF EXISTS `sections`;
DROP TABLE IF EXISTS `classes`;
DROP TABLE IF EXISTS `schools`;
DROP TABLE IF EXISTS `devices`;
DROP TABLE IF EXISTS `user_role_assignments`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `migrations`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `users` (
  `id` CHAR(36) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'student') NOT NULL DEFAULT 'student',
  `studentId` VARCHAR(255) NULL,
  `refreshTokenHash` CHAR(64) NULL,
  `previousRefreshTokenHash` CHAR(64) NULL,
  `refreshCounter` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `fullName` VARCHAR(200) NULL,
  `phone` VARCHAR(32) NULL,
  `disabled_at` TIMESTAMP NULL,
  `must_reset_password` TINYINT(1) NOT NULL DEFAULT 0,
  `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_student_id` (`studentId`),
  KEY `idx_users_role_email` (`role`, `email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_role_assignments` (
  `userId` CHAR(36) NOT NULL,
  `granteeId` CHAR(36) NOT NULL,
  `permission` VARCHAR(64) NOT NULL,
  `grantedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`userId`, `granteeId`, `permission`),
  KEY `idx_user_role_assignments_grantee` (`granteeId`),
  CONSTRAINT `fk_user_role_assignments_user` FOREIGN KEY (`userId`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_role_assignments_grantee` FOREIGN KEY (`granteeId`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_logs` (
  `id` CHAR(36) NOT NULL,
  `event` VARCHAR(100) NOT NULL,
  `details` TEXT NULL,
  `userId` CHAR(36) NULL,
  `metadata` JSON NULL,
  `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_event_created` (`event`, `createdAt`),
  CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`userId`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `student_queries` (
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

CREATE TABLE `subjects` (
  `id` CHAR(36) NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `created_by_user_id` CHAR(36) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subjects_code` (`code`),
  CONSTRAINT `fk_subjects_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `subject_user_access` (
  `subject_id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `granted_by_user_id` CHAR(36) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`subject_id`, `user_id`),
  KEY `idx_subject_user_access_user` (`user_id`),
  CONSTRAINT `fk_subject_user_access_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_subject_user_access_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_subject_user_access_granted_by` FOREIGN KEY (`granted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `team_member_subject_access` (
  `user_id` CHAR(36) NOT NULL,
  `subject_id` CHAR(36) NOT NULL,
  `granted_by_user_id` CHAR(36) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `subject_id`),
  KEY `idx_team_member_subject_access_subject` (`subject_id`),
  CONSTRAINT `fk_team_member_subject_access_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_team_member_subject_access_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_team_member_subject_access_granted_by` FOREIGN KEY (`granted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tests` (
  `id` CHAR(36) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT NULL,
  `testDate` DATE NULL,
  `deadline_at` TIMESTAMP NULL,
  `archived_at` TIMESTAMP NULL,
  `totalMarks` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `questions` JSON NULL,
  `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `createdById` CHAR(36) NULL,
  `subject_id` CHAR(36) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tests_subject_created` (`subject_id`, `createdAt`),
  KEY `idx_tests_created` (`createdAt`),
  CONSTRAINT `fk_tests_created_by` FOREIGN KEY (`createdById`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tests_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `test_attempts` (
  `id` CHAR(36) NOT NULL,
  `testId` CHAR(36) NOT NULL,
  `studentId` CHAR(36) NOT NULL,
  `appeared` TINYINT(1) NOT NULL DEFAULT 0,
  `marksObtained` DECIMAL(8,2) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'NOT_ATTEMPTED',
  `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deadline_override_at` TIMESTAMP NULL,
  `access_requested_at` TIMESTAMP NULL,
  `submitted_at` TIMESTAMP NULL,
  `result_available_at` TIMESTAMP NULL,
  `submission_mode` VARCHAR(20) NULL,
  `submitted_answers` JSON NULL,
  `draft_answers` JSON NULL,
  `client_submission_id` CHAR(36) NULL,
  `ai_assessment` JSON NULL,
  `deficiencyNotes` TEXT NULL,
  `grading_error` TEXT NULL,
  `grading_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `review_note` TEXT NULL,
  `review_submitted_at` TIMESTAMP NULL,
  `review_resolution` TEXT NULL,
  `review_resolved_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_test_attempts_test_student` (`testId`, `studentId`),
  KEY `idx_test_attempts_student_status_request` (`studentId`, `status`, `access_requested_at`),
  KEY `idx_test_attempts_grading_queue` (`status`, `grading_attempts`, `submitted_at`),
  CONSTRAINT `fk_test_attempts_test` FOREIGN KEY (`testId`) REFERENCES `tests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_test_attempts_student` FOREIGN KEY (`studentId`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `test_source_files` (
  `id` CHAR(36) NOT NULL,
  `test_id` CHAR(36) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(127) NOT NULL,
  `size_bytes` INT UNSIGNED NOT NULL,
  `storage_key` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_test_source_files_test` (`test_id`),
  CONSTRAINT `fk_test_source_files_test` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `test_attempt_files` (
  `id` CHAR(36) NOT NULL,
  `attempt_id` CHAR(36) NOT NULL,
  `question_number` INT UNSIGNED NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(127) NOT NULL,
  `size_bytes` INT UNSIGNED NOT NULL,
  `storage_key` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_test_attempt_files_attempt_question` (`attempt_id`, `question_number`),
  CONSTRAINT `fk_test_attempt_files_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `test_attempts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_test_attempt_files_size` CHECK (`size_bytes` > 0 AND `size_bytes` <= 31457280),
  CONSTRAINT `chk_test_attempt_files_question_number` CHECK (`question_number` IS NULL OR `question_number` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_notebook_sources` (
  `id` CHAR(36) NOT NULL,
  `subject_id` CHAR(36) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(128) NOT NULL,
  `storage_key` VARCHAR(255) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'processing',
  `error_message` TEXT NULL,
  `uploaded_by_user_id` CHAR(36) NULL,
  `total_chunks` INT UNSIGNED NOT NULL DEFAULT 0,
  `completed_chunks` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_notebook_sources_subject_created` (`subject_id`, `created_at`),
  CONSTRAINT `fk_ai_notebook_sources_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_notebook_sources_uploader` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_ai_notebook_sources_status` CHECK (`status` IN ('processing', 'ready', 'failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_notebook_chunks` (
  `id` CHAR(36) NOT NULL,
  `source_id` CHAR(36) NOT NULL,
  `ordinal` INT UNSIGNED NOT NULL,
  `content` LONGTEXT NOT NULL,
  `embedding` JSON NOT NULL,
  `embedding_model` VARCHAR(128) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ai_notebook_chunks_source_ordinal` (`source_id`, `ordinal`),
  KEY `idx_ai_notebook_chunks_source_model` (`source_id`, `embedding_model`),
  CONSTRAINT `fk_ai_notebook_chunks_source` FOREIGN KEY (`source_id`) REFERENCES `ai_notebook_sources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_notebook_chat_sessions` (
  `id` CHAR(36) NOT NULL,
  `subject_id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `title` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_notebook_sessions_user_subject_updated` (`user_id`, `subject_id`, `updated_at`),
  CONSTRAINT `fk_ai_notebook_sessions_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_notebook_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_notebook_chat_messages` (
  `id` CHAR(36) NOT NULL,
  `session_id` CHAR(36) NOT NULL,
  `role` VARCHAR(16) NOT NULL,
  `content` LONGTEXT NOT NULL,
  `citations` JSON NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_notebook_messages_session_created` (`session_id`, `created_at`),
  CONSTRAINT `fk_ai_notebook_messages_session` FOREIGN KEY (`session_id`) REFERENCES `ai_notebook_chat_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_ai_notebook_messages_role` CHECK (`role` IN ('user', 'assistant'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_notebook_chat_tickets` (
  `id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `client_request_id` CHAR(36) NOT NULL,
  `subject_id` CHAR(36) NOT NULL,
  `session_id` CHAR(36) NULL,
  `question` LONGTEXT NOT NULL,
  `study_aid_mode` VARCHAR(20) NULL,
  `image_storage_key` VARCHAR(240) NULL,
  `usage_date` DATE NULL,
  `usage_reserved` TINYINT(1) NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `response_json` LONGTEXT NULL,
  `queued_at` TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `last_checked_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processing_at` TIMESTAMP NULL,
  `completed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ai_notebook_chat_ticket_owner_request` (`user_id`, `client_request_id`),
  KEY `idx_ai_notebook_chat_tickets_fifo` (`status`, `queued_at`, `id`),
  KEY `idx_ai_notebook_chat_tickets_expiry` (`status`, `last_checked_at`),
  CONSTRAINT `fk_ai_notebook_chat_tickets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_notebook_chat_tickets_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_ai_notebook_chat_tickets_status` CHECK (`status` IN ('pending', 'processing', 'completed', 'expired'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_notebook_prompts` (
  `subject_id` CHAR(36) NOT NULL,
  `prompt` TEXT NOT NULL,
  `response_language` VARCHAR(32) NOT NULL DEFAULT 'english',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`subject_id`),
  CONSTRAINT `fk_ai_notebook_prompts_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_notebook_user_prompts` (
  `subject_id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `prompt` TEXT NOT NULL,
  `response_language` VARCHAR(32) NOT NULL DEFAULT 'english',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`subject_id`, `user_id`),
  CONSTRAINT `fk_ai_notebook_user_prompts_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_notebook_user_prompts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per user. usageDate is the day the counter applies to, and
-- AiNotebookController::reserveUsage() resets requestCount at the configured day boundary,
-- so the primary key is intentionally userId alone (not userId + usageDate).
CREATE TABLE `ai_notebook_daily_usage` (
  `userId` CHAR(36) NOT NULL,
  `usageDate` DATE NOT NULL,
  `requestCount` INT UNSIGNED NOT NULL DEFAULT 0,
  `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`userId`),
  CONSTRAINT `fk_ai_notebook_daily_usage_user` FOREIGN KEY (`userId`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_ai_notebook_daily_usage_count` CHECK (`requestCount` BETWEEN 0 AND 1000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_grading_settings` (
  `id` TINYINT UNSIGNED NOT NULL,
  `deficiency_report_prompt` TEXT NOT NULL,
  `result_release_delay_hours` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `notebook_daily_question_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_ai_grading_settings_singleton` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ai_grading_settings` (`id`, `deficiency_report_prompt`, `result_release_delay_hours`, `notebook_daily_question_limit`) VALUES (
  1,
  'Review the student work fairly, highlight the strongest points first, then identify the main gaps with clear, actionable guidance.',
  0,
  15
);
