-- Apply once to an existing MySQL database, after taking a backup.
-- Team members with no rows remain unrestricted for backwards compatibility.
CREATE TABLE `team_member_subject_access` (
  `user_id` CHAR(36) NOT NULL,
  `subject_id` CHAR(36) NOT NULL,
  `granted_by_user_id` CHAR(36) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `subject_id`),
  KEY `idx_team_member_subject_access_subject` (`subject_id`),
  CONSTRAINT `fk_team_member_subject_access_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_team_member_subject_access_subject`
    FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_team_member_subject_access_granted_by`
    FOREIGN KEY (`granted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
