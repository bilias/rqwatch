#!/bin/bash

#CREATE DATABASE /*!32312 IF NOT EXISTS*/ `rqwatch` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;

USE `rqwatch`;

#DROP TABLE IF EXISTS `mail_aliases`;
#DROP TABLE IF EXISTS `users`;

CREATE TABLE users (
 `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 `username` VARCHAR(100) NOT NULL UNIQUE,
 `email` VARCHAR(255),
 `firstname` VARCHAR(100) DEFAULT NULL,
 `lastname` VARCHAR(100) DEFAULT NULL,
 `disable_notifications` TINYINT(1) NOT NULL DEFAULT '0',
 `is_admin` TINYINT(1) NOT NULL DEFAULT '0',
 `last_login` datetime DEFAULT NULL,
 `auth_provider` tinyint(3) unsigned NOT NULL DEFAULT 0,
 `password` VARCHAR(255) NOT NULL,
 `created_at` datetime NOT NULL DEFAULT current_timestamp(),
 `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  KEY `username_index` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mail_aliases (
 `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 `user_id` INT UNSIGNED NOT NULL,
 `alias` VARCHAR(255) NOT NULL,
 `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `user_id_index` (`user_id`),
  CONSTRAINT `fk_aliases_user_id`
    FOREIGN KEY (`user_id`) REFERENCES users(`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

#DROP TABLE IF EXISTS `maps_combined`;
CREATE TABLE maps_combined (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  map_name VARCHAR(64) NOT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  mail_from VARCHAR(255) DEFAULT NULL,
  rcpt_to VARCHAR(255) DEFAULT NULL,
  mime_from VARCHAR(255) DEFAULT NULL,
  mime_to VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY ip_index (ip),
  KEY mail_from_index (mail_from),
  KEY rcpt_to_index (rcpt_to),
  KEY map_name_ip_index (map_name, ip),
  KEY map_name_mail_from_rcpt_to_index (map_name, mail_from, rcpt_to),
  KEY `map_name_updated_at_index` (`map_name`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

#DROP TABLE IF EXISTS `map_activity_logs`;
CREATE TABLE `map_activity_logs` (
  `map_name` varchar(255) NOT NULL,
  `last_changed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`map_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci

INSERT INTO map_activity_logs (map_name) VALUES
('mail_from_rcpt_to_blacklist'),
('mail_from_rcpt_to_whitelist'),
('mail_from_blacklist'),
('mail_from_whitelist')
ON DUPLICATE KEY UPDATE map_name = map_name;

#DROP TABLE IF EXISTS `maps_generic`;
CREATE TABLE maps_generic (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  map_name VARCHAR(64) NOT NULL,   -- e.g. body_regex, url_regex, subject_regex
  pattern TEXT NOT NULL,           -- the actual (regex) pattern
  score int(11) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Indexes:
  INDEX map_name_index (map_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

#DROP TABLE IF EXISTS `maps_custom`;
CREATE TABLE maps_custom (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  map_name VARCHAR(64) NOT NULL,   -- e.g. body_regex, url_regex, subject_regex
  pattern TEXT NOT NULL,           -- the actual (regex) pattern
  score int(11) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Indexes:
  INDEX map_name_index (map_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

#DROP TABLE IF EXISTS `custom_map_config`;
CREATE TABLE custom_map_config (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  map_name VARCHAR(64) NOT NULL,   -- e.g. body_regex, url_regex, subject_regex
  field_name TEXT NOT NULL,        -- field name
  field_label TEXT NOT NULL,       -- field description
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- Indexes:
  INDEX map_name_index (map_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

#DROP TABLE IF EXISTS `mail_logs`;

CREATE TABLE `mail_logs` (
 `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
 `qid` VARCHAR(30) DEFAULT NULL,
 `server` VARCHAR(10) DEFAULT NULL,
 `subject` VARCHAR(500) DEFAULT NULL,
 `score` FLOAT(8,2) DEFAULT NULL,
 `action` CHAR(20) DEFAULT NULL,
 `symbols` JSON DEFAULT NULL,
 `has_virus` TINYINT(1) DEFAULT '0',
 `fuzzy_hashes` JSON DEFAULT NULL,
 `ip` VARCHAR(50) DEFAULT NULL,
 `mail_from` VARCHAR(255) DEFAULT NULL,
 `mime_from` VARCHAR(255) DEFAULT NULL,
 `rcpt_to` VARCHAR(255) DEFAULT NULL,
 `mime_to` VARCHAR(255) DEFAULT NULL,
 `size` bigint(20) DEFAULT NULL,
 `created_at` datetime NOT NULL DEFAULT current_timestamp(),
 `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 `mail_stored` TINYINT(1) NOT NULL DEFAULT '0',
 `mail_location` VARCHAR(255) DEFAULT NULL,
 `notified` TINYINT(1) DEFAULT '0',
 `notify_date` DATETIME(0) DEFAULT NULL,
 `released` TINYINT(1) DEFAULT '0',
 `release_date` DATETIME(0) DEFAULT NULL,
 `notification_pending` TINYINT(1) AS ( (`mail_stored` = 1) AND (`notified` = 0) AND (`action` IN ('discard', 'reject'))) STORED,
 `headers` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `created_at_index` (`created_at`),
  KEY `qid_index` (`qid`),
  KEY `action_index` (`action`),
  KEY `mail_from_index` (`mail_from`),
  KEY `mime_from_index` (`mime_from`),
  KEY `rcpt_to_index` (`rcpt_to`),
  KEY `mime_to_index` (`mime_to`),
  KEY `mail_stored_index` (`mail_stored`)
  KEY `notification_pending_index` (`notification_pending`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
