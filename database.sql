-- Nexus SBI HostGator / Laragon database schema
-- Empty schema only; no source data copied.
-- Select the target database in phpMyAdmin before importing, or run:
-- mysql -u root -p nexus_sbi_local < database.sql
-- This file drops and recreates only the tables listed below. Back up the target first.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `lecture_progress`;
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
DROP TABLE IF EXISTS `test_attempt_files`;
DROP TABLE IF EXISTS `test_source_files`;
DROP TABLE IF EXISTS `test_folders`;
DROP TABLE IF EXISTS `test_attempts`;
DROP TABLE IF EXISTS `tests`;
DROP TABLE IF EXISTS `ai_notebook_daily_usage`;
DROP TABLE IF EXISTS `ai_notebook_user_prompts`;
DROP TABLE IF EXISTS `ai_notebook_prompts`;
DROP TABLE IF EXISTS `ai_notebook_chat_messages`;
DROP TABLE IF EXISTS `ai_notebook_chat_sessions`;
DROP TABLE IF EXISTS `ai_notebook_chunks`;
DROP TABLE IF EXISTS `ai_notebook_sources`;
DROP TABLE IF EXISTS `ai_grading_settings`;
DROP TABLE IF EXISTS `student_queries`;
DROP TABLE IF EXISTS `licenses`;
DROP TABLE IF EXISTS `student_cohort_memberships`;
DROP TABLE IF EXISTS `students`;
DROP TABLE IF EXISTS `school_years`;
DROP TABLE IF EXISTS `folder_access_payments`;
DROP TABLE IF EXISTS `folder_access_installments`;
DROP TABLE IF EXISTS `folder_access_plans`;
DROP TABLE IF EXISTS `folder_permissions`;
DROP TABLE IF EXISTS `lectures`;
DROP TABLE IF EXISTS `folders`;
DROP TABLE IF EXISTS `subject_teachers`;
DROP TABLE IF EXISTS `class_subjects`;
DROP TABLE IF EXISTS `subjects`;
DROP TABLE IF EXISTS `sections`;
DROP TABLE IF EXISTS `classes`;
DROP TABLE IF EXISTS `schools`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `user_role_assignments`;
DROP TABLE IF EXISTS `devices`;
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
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `studentId` (`studentId`),
  KEY `idx_users_role_email` (`role`, `email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `devices` (
  `id` CHAR(36) NOT NULL,
  `userId` CHAR(36) NOT NULL,
  `deviceFingerprint` VARCHAR(128) NULL,
  `name` VARCHAR(255) NULL,
  `publicKey` TEXT NOT NULL,
  `isActive` TINYINT(1) NOT NULL DEFAULT 1,
  `lastHeartbeatAt` TIMESTAMP NULL,
  `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_devices_user_active` (`userId`, `isActive`),
  CONSTRAINT `fk_devices_user` FOREIGN KEY (`userId`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_role_assignments` (
  `userId` CHAR(36) NOT NULL,
  `granteeId` CHAR(36) NOT NULL,
  `permission` ENUM(
    'admin',
    'teacher_assistant',
    'folder_manager',
    'user_manager',
    'password_manager',
    'accountant',
    'accountant_fees',
    'accountant_payments',
    'accountant_expenses',
    'accountant_reports',
    'student_query',
    'testing_manager'
  ) NOT NULL,
  `grantedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`userId`, `granteeId`, `permission`),
  KEY `idx_user_role_assignments_user` (`userId`),
  KEY `idx_user_role_assignments_grantee` (`granteeId`),
  CONSTRAINT `fk_user_role_assignments_user` FOREIGN KEY (`userId`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_role_assignments_grantee` FOREIGN KEY (`granteeId`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_logs` (
  `id` CHAR(36) NOT NULL,
  `event` VARCHAR(100) NOT NULL,
  `details` TEXT NULL,
  `userId` CHAR(36) NULL,
  `deviceId` CHAR(36) NULL,
  `metadata` JSON NULL,
  `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_event_created` (`event`, `createdAt`),
  CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`userId`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_audit_logs_device` FOREIGN KEY (`deviceId`) REFERENCES `devices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schools` (
  `id` CHAR(36) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `address` TEXT NULL,
  `phone` VARCHAR(32) NULL,
  `logo_url` VARCHAR(512) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `classes` (
  `id` CHAR(36) NOT NULL,
  `school_id` CHAR(36) NOT NULL,
  `numeric_level` TINYINT UNSIGNED NOT NULL,
  `display_name` VARCHAR(64) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_classes_school_level` (`school_id`, `numeric_level`),
  CONSTRAINT `fk_classes_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_classes_numeric_level` CHECK (`numeric_level` BETWEEN 1 AND 16)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sections` (
  `id` CHAR(36) NOT NULL,
  `class_id` CHAR(36) NOT NULL,
  `name` VARCHAR(16) NOT NULL,
  `capacity` INT UNSIGNED NOT NULL DEFAULT 40,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sections_class_name` (`class_id`, `name`),
  CONSTRAINT `fk_sections_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `subjects` (
  `id` CHAR(36) NOT NULL,
  `school_id` CHAR(36) NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `class_id` CHAR(36) NULL,
  `section_id` CHAR(36) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subjects_school_code` (`school_id`, `code`),
  KEY `idx_subjects_school` (`school_id`),
  KEY `idx_subjects_class` (`class_id`),
  KEY `idx_subjects_section` (`section_id`),
  CONSTRAINT `fk_subjects_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_subjects_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_subjects_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `class_subjects` (
  `class_id` CHAR(36) NOT NULL,
  `subject_id` CHAR(36) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`class_id`, `subject_id`),
  CONSTRAINT `fk_class_subjects_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_class_subjects_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `subject_teachers` (
  `id` CHAR(36) NOT NULL,
  `subject_id` CHAR(36) NOT NULL,
  `class_id` CHAR(36) NOT NULL,
  `section_id` CHAR(36) NULL,
  `user_id` CHAR(36) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subject_teachers_user` (`user_id`),
  CONSTRAINT `fk_subject_teachers_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_subject_teachers_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_subject_teachers_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_subject_teachers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `folders` (
  `id` CHAR(36) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `default_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `coverImageUrl` VARCHAR(512) NULL,
  `subject_id` CHAR(36) NULL,
  `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_folders_subject` (`subject_id`),
  CONSTRAINT `fk_folders_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lectures` (
  `id` CHAR(36) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `duration` VARCHAR(100) NULL,
  `contentGroup` VARCHAR(255) NOT NULL DEFAULT '',
  `orderIndex` INT NULL,
  `contentDate` DATE NULL,
  `folderId` CHAR(36) NOT NULL,
  `contentKeyId` CHAR(36) NULL,
  `sourceLectureId` CHAR(36) NULL,
  `deletedAt` TIMESTAMP NULL,
  `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contentKeyId` (`contentKeyId`),
  KEY `idx_lectures_folder_deleted` (`folderId`, `deletedAt`),
  CONSTRAINT `fk_lectures_folder` FOREIGN KEY (`folderId`) REFERENCES `folders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lectures_source` FOREIGN KEY (`sourceLectureId`) REFERENCES `lectures` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `folder_permissions` (
  `id` CHAR(36) NOT NULL,
  `userId` CHAR(36) NOT NULL,
  `folderId` CHAR(36) NOT NULL,
  `canView` TINYINT(1) NOT NULL DEFAULT 1,
  `billing_suspended` TINYINT(1) NOT NULL DEFAULT 0,
  `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_folder_permissions_user_folder` (`userId`, `folderId`),
  CONSTRAINT `fk_folder_permissions_user` FOREIGN KEY (`userId`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_folder_permissions_folder` FOREIGN KEY (`folderId`) REFERENCES `folders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `school_years` (
  `id` CHAR(36) NOT NULL,
  `school_id` CHAR(36) NOT NULL,
  `label` VARCHAR(32) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `is_current` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_school_years_school_label` (`school_id`, `label`),
  KEY `idx_school_years_school` (`school_id`),
  CONSTRAINT `fk_school_years_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `students` (
  `id` CHAR(36) NOT NULL,
  `enrollment_no` VARCHAR(32) NOT NULL,
  `full_name` VARCHAR(255) NOT NULL,
  `guardian_name` VARCHAR(255) NULL,
  `guardian_phone` VARCHAR(32) NULL,
  `phone` VARCHAR(32) NULL,
  `dob` DATE NULL,
  `address` TEXT NULL,
  `joined_at` DATE NOT NULL,
  `status` ENUM('active', 'left', 'graduated', 'suspended') NOT NULL DEFAULT 'active',
  `user_id` CHAR(36) NULL,
  `current_class_id` CHAR(36) NULL,
  `current_section_id` CHAR(36) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_students_enrollment` (`enrollment_no`),
  UNIQUE KEY `uq_students_user_id` (`user_id`),
  KEY `idx_students_class_section` (`current_class_id`, `current_section_id`),
  CONSTRAINT `fk_students_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_students_class` FOREIGN KEY (`current_class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_students_section` FOREIGN KEY (`current_section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `folder_access_plans` (
  `id` CHAR(36) NOT NULL,
  `folder_id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NULL,
  `student_id` CHAR(36) NULL,
  `permission_id` CHAR(36) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permission_id` (`permission_id`),
  KEY `idx_folder_access_user_folder` (`user_id`, `folder_id`),
  KEY `idx_folder_access_student` (`student_id`),
  KEY `idx_folder_access_plans_folder` (`folder_id`),
  CONSTRAINT `fk_folder_access_plans_folder` FOREIGN KEY (`folder_id`) REFERENCES `folders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_folder_access_plans_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_folder_access_plans_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_folder_access_plans_permission` FOREIGN KEY (`permission_id`) REFERENCES `folder_permissions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `folder_access_installments` (
  `id` CHAR(36) NOT NULL,
  `plan_id` CHAR(36) NOT NULL,
  `sequence_no` INT UNSIGNED NOT NULL,
  `kind` VARCHAR(24) NOT NULL,
  `period_month` DATE NULL,
  `due_date` DATE NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `paid_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_folder_access_installments_plan_sequence` (`plan_id`, `sequence_no`),
  KEY `idx_folder_access_installments_due` (`due_date`),
  CONSTRAINT `fk_folder_access_installments_plan` FOREIGN KEY (`plan_id`) REFERENCES `folder_access_plans` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `folder_access_payments` (
  `id` CHAR(36) NOT NULL,
  `installment_id` CHAR(36) NOT NULL,
  `entry_type` VARCHAR(12) NOT NULL DEFAULT 'payment',
  `amount` DECIMAL(12,2) NOT NULL,
  `method` VARCHAR(16) NOT NULL,
  `received_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `received_by_user_id` CHAR(36) NULL,
  `reference_no` VARCHAR(64) NULL,
  `notes` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_folder_access_payments_installment_received` (`installment_id`, `received_at`),
  CONSTRAINT `fk_folder_access_payments_installment` FOREIGN KEY (`installment_id`) REFERENCES `folder_access_installments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_folder_access_payments_user` FOREIGN KEY (`received_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `student_cohort_memberships` (
  `id` CHAR(36) NOT NULL,
  `student_id` CHAR(36) NOT NULL,
  `batch_id` CHAR(36) NULL,
  `school_year_id` CHAR(36) NULL,
  `class_id` CHAR(36) NULL,
  `section_id` CHAR(36) NULL,
  `starts_on` DATE NULL,
  `ends_on` DATE NULL,
  PRIMARY KEY (`id`),
  KEY `idx_student_cohort_student_dates` (`student_id`, `starts_on`, `ends_on`),
  KEY `idx_student_cohort_batch_year` (`batch_id`, `school_year_id`),
  CONSTRAINT `fk_student_cohort_memberships_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_student_cohort_membership_dates` CHECK (`ends_on` IS NULL OR `starts_on` IS NULL OR `ends_on` >= `starts_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `licenses` (
  `id` CHAR(36) NOT NULL,
  `userId` CHAR(36) NOT NULL,
  `deviceId` CHAR(36) NOT NULL,
  `status` ENUM('active', 'expired', 'revoked') NOT NULL DEFAULT 'active',
  `expiresAt` TIMESTAMP NULL,
  `notes` TEXT NULL,
  `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_licenses_user_status` (`userId`, `status`),
  CONSTRAINT `fk_licenses_user` FOREIGN KEY (`userId`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_licenses_device` FOREIGN KEY (`deviceId`) REFERENCES `devices` (`id`) ON DELETE CASCADE
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
  CONSTRAINT `fk_student_queries_responder` FOREIGN KEY (`respondedById`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_student_queries_status` CHECK (`status` IN ('open', 'pending', 'responded', 'closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tests` (
  `id` CHAR(36) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT NULL,
  `testDate` DATE NULL,
  `deadline_at` TIMESTAMP NULL,
  `totalMarks` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `questions` JSON NULL,
  `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `createdById` CHAR(36) NULL,
  `classId` CHAR(36) NULL,
  `sectionId` CHAR(36) NULL,
  `contentCovered` JSON NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tests_section` (`sectionId`),
  KEY `idx_tests_created_at` (`createdAt`),
  CONSTRAINT `fk_tests_created_by` FOREIGN KEY (`createdById`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tests_class` FOREIGN KEY (`classId`) REFERENCES `classes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tests_section` FOREIGN KEY (`sectionId`) REFERENCES `sections` (`id`) ON DELETE SET NULL
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
  `ai_assessment` JSON NULL,
  `deficiencyNotes` TEXT NULL,
  `grading_error` TEXT NULL,
  `review_note` TEXT NULL,
  `review_submitted_at` TIMESTAMP NULL,
  `review_resolution` TEXT NULL,
  `review_resolved_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_test_attempts_test_student` (`testId`, `studentId`),
  KEY `idx_test_attempts_student` (`studentId`),
  KEY `idx_attempts_leaderboard` (`appeared`, `status`, `studentId`, `testId`, `marksObtained`),
  KEY `idx_test_attempts_student_status_request` (`studentId`, `status`, `access_requested_at`),
  CONSTRAINT `fk_test_attempts_test` FOREIGN KEY (`testId`) REFERENCES `tests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_test_attempts_student` FOREIGN KEY (`studentId`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `test_folders` (
  `testId` CHAR(36) NOT NULL,
  `folderId` CHAR(36) NOT NULL,
  PRIMARY KEY (`testId`, `folderId`),
  CONSTRAINT `fk_test_folders_test` FOREIGN KEY (`testId`) REFERENCES `tests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_test_folders_folder` FOREIGN KEY (`folderId`) REFERENCES `folders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `test_source_files` (
  `id` CHAR(36) NOT NULL,
  `test_id` CHAR(36) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(127) NOT NULL,
  `size_bytes` INT UNSIGNED NOT NULL,
  `storage_key` VARCHAR(255) NULL,
  `file_data` LONGBLOB NULL,
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
  `file_data` LONGBLOB NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_test_attempt_files_attempt_question` (`attempt_id`, `question_number`),
  CONSTRAINT `fk_test_attempt_files_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `test_attempts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_test_attempt_files_size` CHECK (`size_bytes` > 0 AND `size_bytes` <= 31457280),
  CONSTRAINT `chk_test_attempt_files_question_number` CHECK (`question_number` IS NULL OR `question_number` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `courses` (
  `id` CHAR(36) NOT NULL,
  `code` VARCHAR(128) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `target_exam_date` DATE NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `folder_id` CHAR(36) NULL,
  `class_id` CHAR(36) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `folder_id` (`folder_id`),
  CONSTRAINT `fk_courses_folder` FOREIGN KEY (`folder_id`) REFERENCES `folders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_courses_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `grids` (
  `id` CHAR(36) NOT NULL,
  `course_id` CHAR(36) NOT NULL,
  `grid_code` VARCHAR(64) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `total_past_papers` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_grids_course` (`course_id`),
  CONSTRAINT `fk_grids_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `topics` (
  `id` CHAR(36) NOT NULL,
  `grid_id` CHAR(36) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `sequence_order` INT UNSIGNED NOT NULL,
  `lecture_folder_id` CHAR(36) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_topics_grid` (`grid_id`),
  CONSTRAINT `fk_topics_grid` FOREIGN KEY (`grid_id`) REFERENCES `grids` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `questions` (
  `id` CHAR(36) NOT NULL,
  `topic_id` CHAR(36) NOT NULL,
  `tier_type` VARCHAR(32) NOT NULL,
  `question_text` TEXT NOT NULL,
  `options` JSON NOT NULL,
  `correct_answer` TEXT NULL,
  `marks` INT NOT NULL DEFAULT 1,
  `marking_scheme` JSON NULL,
  `explanation` TEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `difficulty` VARCHAR(16) NOT NULL DEFAULT 'MODERATE',
  `solution` TEXT NULL,
  `feedback` TEXT NULL,
  `question_type` VARCHAR(16) NOT NULL DEFAULT 'MCQ',
  PRIMARY KEY (`id`),
  KEY `idx_questions_topic_id` (`topic_id`),
  KEY `idx_questions_difficulty` (`topic_id`, `difficulty`),
  CONSTRAINT `fk_questions_topic` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_questions_difficulty` CHECK (`difficulty` IN ('EASY', 'MODERATE', 'DIFFICULT', 'MEDIUM', 'HARD', 'TIER_1', 'TIER_2', 'TIER_3'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `topic_progress` (
  `user_id` CHAR(36) NOT NULL,
  `topic_id` CHAR(36) NOT NULL,
  `video_status` VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  PRIMARY KEY (`user_id`, `topic_id`),
  CONSTRAINT `fk_topic_progress_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_topic_progress_topic` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quiz_attempts` (
  `id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `topic_id` CHAR(36) NULL,
  `mock_exam_id` CHAR(36) NULL,
  `tier_type` VARCHAR(32) NOT NULL,
  `seed` INT UNSIGNED NULL,
  `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `submitted_at` TIMESTAMP NULL,
  `score` DECIMAL(5,2) NULL,
  `total_marks` INT UNSIGNED NULL,
  `score_percent` DECIMAL(5,2) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'IN_PROGRESS',
  `idempotency_key` VARCHAR(255) NULL,
  `question_ids` JSON NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `idx_quiz_attempts_user_topic_tier_submitted` (`user_id`, `topic_id`, `tier_type`, `submitted_at`),
  KEY `idx_quiz_attempts_analytics_status_date` (`status`, `submitted_at`),
  CONSTRAINT `fk_quiz_attempts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_quiz_attempts_score_percent` CHECK (`score_percent` IS NULL OR (`score_percent` >= 0 AND `score_percent` <= 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mock_exams` (
  `id` CHAR(36) NOT NULL,
  `course_id` CHAR(36) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `duration_minutes` INT UNSIGNED NOT NULL DEFAULT 60,
  `total_marks` INT UNSIGNED NOT NULL DEFAULT 0,
  `question_selection` JSON NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_mock_exams_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quiz_attempt_answers` (
  `id` CHAR(36) NOT NULL,
  `attempt_id` CHAR(36) NOT NULL,
  `question_id` CHAR(36) NOT NULL,
  `selected_option` TEXT NULL,
  `is_correct` TINYINT(1) NOT NULL DEFAULT 0,
  `marks_awarded` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quiz_attempt_answers_attempt_question` (`attempt_id`, `question_id`),
  KEY `idx_quiz_attempt_answers_attempt_id` (`attempt_id`),
  CONSTRAINT `fk_quiz_attempt_answers_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `quiz_attempts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_quiz_attempt_answers_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `flashcards` (
  `id` CHAR(36) NOT NULL,
  `topic_id` CHAR(36) NOT NULL,
  `standard_reference` VARCHAR(255) NULL,
  `content` TEXT NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_flashcards_topic` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `flashcard_reviews` (
  `user_id` CHAR(36) NOT NULL,
  `flashcard_id` CHAR(36) NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `last_reviewed_at` TIMESTAMP NULL,
  `next_review_at` TIMESTAMP NULL,
  `interval_days` INT UNSIGNED NOT NULL DEFAULT 0,
  `ease_factor` DECIMAL(4,2) NOT NULL DEFAULT 2.50,
  PRIMARY KEY (`user_id`, `flashcard_id`),
  KEY `idx_flashcard_reviews_next_review_at` (`next_review_at`),
  CONSTRAINT `fk_flashcard_reviews_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_flashcard_reviews_flashcard` FOREIGN KEY (`flashcard_id`) REFERENCES `flashcards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lecture_progress` (
  `user_id` CHAR(36) NOT NULL,
  `lecture_id` CHAR(36) NOT NULL,
  `position_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_seconds` INT UNSIGNED NULL,
  `watched_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `status` VARCHAR(20) NOT NULL DEFAULT 'NOT_STARTED',
  `first_started_at` TIMESTAMP NULL,
  `last_watched_at` TIMESTAMP NULL,
  `completed_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `lecture_id`),
  KEY `idx_lecture_progress_lecture_watched` (`lecture_id`, `last_watched_at`),
  CONSTRAINT `fk_lecture_progress_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lecture_progress_lecture` FOREIGN KEY (`lecture_id`) REFERENCES `lectures` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_lecture_progress_watched_percent` CHECK (`watched_percent` >= 0 AND `watched_percent` <= 100),
  CONSTRAINT `chk_lecture_progress_status` CHECK (`status` IN ('NOT_STARTED', 'IN_PROGRESS', 'COMPLETED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_notebook_sources` (
  `id` CHAR(36) NOT NULL,
  `folder_id` CHAR(36) NOT NULL,
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
  KEY `idx_ai_notebook_sources_folder_created` (`folder_id`, `created_at`),
  CONSTRAINT `fk_ai_notebook_sources_folder` FOREIGN KEY (`folder_id`) REFERENCES `folders` (`id`) ON DELETE CASCADE,
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
  `folder_id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `title` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_notebook_sessions_user_folder_updated` (`user_id`, `folder_id`, `updated_at`),
  CONSTRAINT `fk_ai_notebook_sessions_folder` FOREIGN KEY (`folder_id`) REFERENCES `folders` (`id`) ON DELETE CASCADE,
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

CREATE TABLE `ai_notebook_prompts` (
  `folder_id` CHAR(36) NOT NULL,
  `prompt` TEXT NOT NULL,
  `response_language` VARCHAR(32) NOT NULL DEFAULT 'english',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`folder_id`),
  CONSTRAINT `fk_ai_notebook_prompts_folder` FOREIGN KEY (`folder_id`) REFERENCES `folders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_notebook_user_prompts` (
  `folder_id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `prompt` TEXT NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`folder_id`, `user_id`),
  CONSTRAINT `fk_ai_notebook_user_prompts_folder` FOREIGN KEY (`folder_id`) REFERENCES `folders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_notebook_user_prompts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_notebook_daily_usage` (
  `userId` CHAR(36) NOT NULL,
  `usageDate` DATE NOT NULL,
  `requestCount` INT UNSIGNED NOT NULL DEFAULT 0,
  `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`userId`),
  CONSTRAINT `fk_ai_notebook_daily_usage_user` FOREIGN KEY (`userId`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_ai_notebook_daily_usage_count` CHECK (`requestCount` BETWEEN 0 AND 20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_grading_settings` (
  `id` TINYINT UNSIGNED NOT NULL,
  `deficiency_report_prompt` TEXT NOT NULL DEFAULT '',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_ai_grading_settings_singleton` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ai_grading_settings` (`id`, `deficiency_report_prompt`) VALUES (
  1,
  'Review the student work fairly, highlight the strongest points first, then identify the main gaps with clear, actionable guidance.'
);

CREATE TABLE `migrations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` VARCHAR(255) NOT NULL,
  `batch` INT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `migrations_migration_unique` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_11_03_000001_create_identity_tables', 1),
  ('2026_11_03_000002_create_audit_logs_table', 1),
  ('2026_11_03_000003_create_schools_table', 1),
  ('2026_11_03_000004_create_subject_domain_tables', 1),
  ('2026_11_03_000005_create_folder_access_domain_tables', 1),
  ('2026_11_03_000006_create_student_domain_tables', 1),
  ('2026_11_03_000007_add_student_deletion_dependencies', 1),
  ('2026_11_03_000008_create_query_and_leaderboard_tables', 1),
  ('2026_11_03_000009_create_analytics_domain_tables', 1),
  ('2026_11_03_000010_expand_tests_authoring_schema', 1),
  ('2026_11_03_000011_expand_test_student_attempt_state', 1),
  ('2026_11_04_000012_create_ai_notebook_tables', 1);
