-- One-time upgrade for existing HostGator databases; back up before applying.
-- Adds private, owner-scoped, idempotent Notebook chat tickets for FIFO waiting.
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
