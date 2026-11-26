CREATE TABLE IF NOT EXISTS `feer_cert_issued` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `serial` varchar(64) NOT NULL,
  `not_after` datetime NOT NULL,
  `created` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `feer_cert_serial` (`serial`),
  KEY `feer_cert_user` (`user_id`, `created`),
  CONSTRAINT `feer_cert_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ROW_FORMAT=DYNAMIC ENGINE=INNODB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
