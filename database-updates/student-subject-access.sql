-- Additive update for an existing HostGator/Laragon database.
-- Select the target database in phpMyAdmin before importing.
-- This does not modify existing users, subjects, tests, or folders.

CREATE TABLE IF NOT EXISTS `subject_user_access` (
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
