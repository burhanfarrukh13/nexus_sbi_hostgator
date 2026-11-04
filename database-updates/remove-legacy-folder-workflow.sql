-- Remove legacy folder workflow tables from an existing database.
-- Back up first and select the target database in phpMyAdmin before importing.
-- This permanently deletes any data in these obsolete tables.

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `folder_access_payments`;
DROP TABLE IF EXISTS `folder_access_installments`;
DROP TABLE IF EXISTS `folder_access_plans`;
DROP TABLE IF EXISTS `folder_permissions`;
DROP TABLE IF EXISTS `test_folders`;
DROP TABLE IF EXISTS `folders`;
SET FOREIGN_KEY_CHECKS = 1;
