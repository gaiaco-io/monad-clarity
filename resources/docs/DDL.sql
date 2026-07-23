-- Monad Clarity — setup-owned built-in tables
-- Created by `php mitosis setup`. DDL here is a compatibility surface per
-- CrossRepoContracts.md §8 — altering it is a semver-major change requiring a shipped migration.
-- Convention: DATETIME (second precision) and UUID char(36) primary keys across built-in tables.

-- ============================================================================
-- sessions — Services\Session (ReleaseNotes_26.07.md §17)
-- user_id is NULLABLE: supports guest / pre-login sessions, including
-- DB-backed pre-authentication CSRF token storage (Middlewares\Csrf, §13.2).
-- ============================================================================
CREATE TABLE IF NOT EXISTS `sessions` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `user_id` char(36) COLLATE utf8mb4_unicode_ci NULL,
  `digest` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(39) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` json NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `expire_at` datetime NOT NULL,
  `revoked_at` datetime,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sessions_digest` (`digest`),
  KEY `idx_sessions_user_id` (`user_id`)
) ENGINE=INNODB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- caches — Services\Cache DB driver (ReleaseNotes_26.07.md §26, PSR-16)
-- key_hash = SHA-256 of cache_key, used as PK to avoid long-key index limits.
-- Driver rule: always compare cache_key on read; never trust key_hash alone.
-- expires_at NULL = never expires (maps PSR-16 ttl: null).
-- ============================================================================
CREATE TABLE IF NOT EXISTS `caches` (
    `key_hash` BINARY(32) NOT NULL,
    `cache_key` VARCHAR(512) NOT NULL,
    `cache_value` LONGBLOB NOT NULL,
    `encoding` VARCHAR(20) NOT NULL DEFAULT 'serialize',
    `expires_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`key_hash`),
    INDEX `idx_cache_expires_at` (`expires_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
