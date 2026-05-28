-- -- ============================================================
-- -- schema.sql  —  Smart Study Companion
-- -- Run once to create the database and all tables.
-- -- MySQL 8.0+ / MariaDB 10.4+
-- -- ============================================================

-- -- Create database
-- CREATE DATABASE IF NOT EXISTS smart_study_companion
--     CHARACTER SET  utf8mb4
--     COLLATE        utf8mb4_unicode_ci;

-- USE smart_study_companion;

-- -- ── users ─────────────────────────────────────────────────────
-- -- Stores registered accounts.
-- CREATE TABLE IF NOT EXISTS users (
--     id            INT          UNSIGNED NOT NULL AUTO_INCREMENT,
--     name          VARCHAR(100) NOT NULL,
--     email         VARCHAR(254) NOT NULL,
--     password_hash VARCHAR(255) NOT NULL,          -- bcrypt hash
--     is_active     TINYINT(1)   NOT NULL DEFAULT 1,
--     last_login    DATETIME         NULL,
--     created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     updated_at    DATETIME         NULL ON UPDATE CURRENT_TIMESTAMP,

--     PRIMARY KEY (id),
--     UNIQUE KEY uq_users_email (email)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -- ── token_blacklist ───────────────────────────────────────────
-- -- Server-side JWT revocation (populated on logout).
-- CREATE TABLE IF NOT EXISTS token_blacklist (
--     id           INT          UNSIGNED NOT NULL AUTO_INCREMENT,
--     token_hash   CHAR(64)     NOT NULL,           -- SHA-256 hex of raw JWT
--     user_id      INT          UNSIGNED NOT NULL,
--     expires_at   DATETIME     NOT NULL,           -- mirrors JWT exp claim
--     revoked_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

--     PRIMARY KEY  (id),
--     UNIQUE KEY   uq_token_hash   (token_hash),
--     INDEX        idx_expires_at  (expires_at),    -- for pruning queries
--     INDEX        idx_user_id     (user_id),
--     CONSTRAINT   fk_tbl_user
--         FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -- ── notes ─────────────────────────────────────────────────────
-- -- Stores uploaded study notes with extracted text content.
-- CREATE TABLE IF NOT EXISTS notes (
--     id               INT           UNSIGNED NOT NULL AUTO_INCREMENT,
--     user_id          INT           UNSIGNED NOT NULL,
--     name             VARCHAR(120)  NOT NULL,
--     content          LONGTEXT      NOT NULL,       -- full extracted text
--     file_type        VARCHAR(50)   NOT NULL DEFAULT 'Text',
--     file_size        VARCHAR(20)   NOT NULL DEFAULT '0 B',
--     file_path        VARCHAR(500)       NULL,      -- relative path to stored file
--     upload_date      DATE          NOT NULL,
--     last_accessed_at DATETIME           NULL,
--     created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     updated_at       DATETIME           NULL ON UPDATE CURRENT_TIMESTAMP,
--     deleted_at       DATETIME           NULL,      -- soft-delete

--     PRIMARY KEY (id),
--     INDEX idx_notes_user_id    (user_id),
--     INDEX idx_notes_deleted_at (deleted_at),
--     INDEX idx_notes_created_at (created_at),
--     FULLTEXT INDEX ft_notes_name_content (name, content),  -- enables FULLTEXT search
--     CONSTRAINT fk_notes_user
--         FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -- ── quiz_results ──────────────────────────────────────────────
-- -- Records every completed quiz attempt.
-- CREATE TABLE IF NOT EXISTS quiz_results (
--     id         INT      UNSIGNED NOT NULL AUTO_INCREMENT,
--     user_id    INT      UNSIGNED NOT NULL,
--     note_id    INT      UNSIGNED NOT NULL,
--     score      TINYINT  UNSIGNED NOT NULL,         -- correct answers
--     total      TINYINT  UNSIGNED NOT NULL,         -- total questions
--     percent    TINYINT  UNSIGNED NOT NULL,         -- 0-100
--     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

--     PRIMARY KEY (id),
--     INDEX idx_qr_user_id    (user_id),
--     INDEX idx_qr_note_id    (note_id),
--     INDEX idx_qr_created_at (created_at),
--     CONSTRAINT fk_qr_user
--         FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
--     CONSTRAINT fk_qr_note
--         FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE SET NULL
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -- ── ai_summaries (optional cache) ────────────────────────────
-- -- Caches generated summaries so re-requesting the same note
-- -- doesn't call the Gemini API again unnecessarily.
-- CREATE TABLE IF NOT EXISTS ai_summaries (
--     id         INT      UNSIGNED NOT NULL AUTO_INCREMENT,
--     note_id    INT      UNSIGNED NOT NULL,
--     user_id    INT      UNSIGNED NOT NULL,
--     summary    LONGTEXT NOT NULL,
--     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

--     PRIMARY KEY (id),
--     UNIQUE KEY  uq_summary_note (note_id),         -- one summary per note
--     INDEX       idx_sm_user_id  (user_id),
--     CONSTRAINT  fk_sm_note
--         FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
--     CONSTRAINT  fk_sm_user
--         FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;







-- ════════════════════════════════════════════════════════════════
-- database/schema.sql  —  Smart Study Companion
-- ════════════════════════════════════════════════════════════════
-- Complete MySQL 8.0+ / MariaDB 10.4+ database schema.
--
-- Run once to create the database and all tables:
--   mysql -u root -p < database/schema.sql
--
-- Then seed with development data (optional):
--   mysql -u root -p smart_study_companion < database/seed.sql
--
-- Tables (in dependency order):
--   1. users              — registered accounts
--   2. token_blacklist    — revoked JWT tokens (logout)
--   3. notes              — uploaded study notes with content
--   4. quiz_results       — completed quiz attempt records
--   5. ai_summaries       — cached AI-generated note summaries
--   6. chat_history       — stored AI chat conversations (optional)
--   7. user_sessions      — login audit log (optional)
-- ════════════════════════════════════════════════════════════════

-- ── Safety settings ───────────────────────────────────────────
SET SQL_MODE   = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';
SET time_zone  = '+00:00';   -- store all timestamps in UTC

-- ════════════════════════════════════════════════════════════════
-- 0.  DATABASE
-- ════════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS `smart_study_companion`
    CHARACTER SET  utf8mb4
    COLLATE        utf8mb4_unicode_ci;

USE `smart_study_companion`;

-- ════════════════════════════════════════════════════════════════
-- 1.  USERS
-- ════════════════════════════════════════════════════════════════
-- Stores all registered user accounts.
--
-- Columns:
--   id            Auto-increment primary key
--   name          Display name (1–100 chars)
--   email         Unique login identifier (max 254 per RFC 5321)
--   password_hash bcrypt hash (cost 12, max 255 chars)
--   is_active     0 = deactivated account, 1 = active (default)
--   last_login    Timestamp of the most recent successful login
--   created_at    Account creation timestamp
--   updated_at    Auto-updated on every row change
-- ════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT          UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(100) NOT NULL,
    `email`         VARCHAR(254) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL
                    ,
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1
                   ,
    `last_login`    DATETIME         NULL DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME         NULL DEFAULT NULL
                    ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- Enforce unique email addresses (case-insensitive via collation)
    UNIQUE KEY `uq_users_email` (`email`),

    -- Speed up lookups by is_active for admin queries
    INDEX `idx_users_is_active` (`is_active`),

    -- Speed up sorting by join date
    INDEX `idx_users_created_at` (`created_at`)

) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE         = utf8mb4_unicode_ci;


-- ════════════════════════════════════════════════════════════════
-- 2.  TOKEN BLACKLIST
-- ════════════════════════════════════════════════════════════════
-- Server-side JWT revocation list populated on logout.
-- Stores the SHA-256 hash of each revoked token (not the raw JWT)
-- so that even if this table is compromised, the tokens are safe.
--
-- A scheduled event (or the opportunistic cleanup in logout.php)
-- deletes rows whose expires_at is in the past, since expired
-- tokens cannot be replayed regardless.
-- ════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `token_blacklist` (
    `id`         INT      UNSIGNED NOT NULL AUTO_INCREMENT,
    `token_hash` CHAR(64) NOT NULL
                 ,
    `user_id`    INT      UNSIGNED NOT NULL,
    `expires_at` DATETIME NOT NULL
                 ,
    `revoked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- Hash is globally unique — one entry per token
    UNIQUE KEY `uq_token_hash` (`token_hash`),

    -- Used by the prune query: DELETE FROM token_blacklist WHERE expires_at < NOW()
    INDEX `idx_tbl_expires_at` (`expires_at`),

    -- Lets us revoke all tokens for a user (e.g., password change)
    INDEX `idx_tbl_user_id` (`user_id`),

    CONSTRAINT `fk_tbl_user`
        FOREIGN KEY (`user_id`)
        REFERENCES  `users` (`id`)
        ON DELETE   CASCADE
        ON UPDATE   CASCADE

) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE         = utf8mb4_unicode_ci;


-- ════════════════════════════════════════════════════════════════
-- 3.  NOTES
-- ════════════════════════════════════════════════════════════════
-- Stores uploaded study notes with their extracted text content.
-- PDF text is extracted by ai/extract_pdf.py and stored here.
--
-- Design decisions:
--   • content is LONGTEXT (max 4 GB) — handles large PDFs
--   • deleted_at implements soft-delete so quiz history is preserved
--   • FULLTEXT index enables fast keyword search across all notes
--   • file_path stores the relative path under uploads/ so files
--     can be served or deleted without another DB query
-- ════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `notes` (
    `id`               INT          UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          INT          UNSIGNED NOT NULL,
    `name`             VARCHAR(120) NOT NULL
                       ,
    `content`          LONGTEXT     NOT NULL
                       ,
    `file_type`        VARCHAR(50)  NOT NULL DEFAULT 'Text'
                       ,
    `file_size`        VARCHAR(20)  NOT NULL DEFAULT '0 B'
                       ,
    `file_path`        VARCHAR(500)     NULL DEFAULT NULL
                       ,
    `upload_date`      DATE         NOT NULL
                       ,
    `last_accessed_at` DATETIME         NULL DEFAULT NULL
                       ,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME         NULL DEFAULT NULL
                       ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`       DATETIME         NULL DEFAULT NULL
                       ,

    PRIMARY KEY (`id`),

    -- Speed up "all notes for user X" queries
    INDEX `idx_notes_user_id`    (`user_id`),

    -- Speed up soft-delete filter (WHERE deleted_at IS NULL)
    INDEX `idx_notes_deleted_at` (`deleted_at`),

    -- Speed up sorting by upload date
    INDEX `idx_notes_created_at` (`created_at`),

    -- Speed up sorting/filtering by last access (for "Recently Viewed")
    INDEX `idx_notes_last_accessed` (`last_accessed_at`),

    -- FULLTEXT index for keyword search across name and content
    -- Usage: WHERE MATCH(name, content) AGAINST ('deadlock' IN NATURAL LANGUAGE MODE)
    FULLTEXT INDEX `ft_notes_name_content` (`name`, `content`),

    CONSTRAINT `fk_notes_user`
        FOREIGN KEY (`user_id`)
        REFERENCES  `users` (`id`)
        ON DELETE   CASCADE
        ON UPDATE   CASCADE

) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE         = utf8mb4_unicode_ci;


-- ════════════════════════════════════════════════════════════════
-- 4.  QUIZ RESULTS
-- ════════════════════════════════════════════════════════════════
-- Records every completed quiz attempt.
-- One row per attempt — multiple attempts per note are allowed
-- and expected (students re-take quizzes to improve).
--
-- percent is stored as a computed column equivalent (calculated
-- in save_result.php) so aggregate queries don't need arithmetic.
-- ════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `quiz_results` (
    `id`         INT          UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT          UNSIGNED NOT NULL,
    `note_id`    INT          UNSIGNED     NULL DEFAULT NULL
                ,
    `score`      TINYINT      UNSIGNED NOT NULL
                 ,
    `total`      TINYINT      UNSIGNED NOT NULL
                 ,
    `percent`    TINYINT      UNSIGNED NOT NULL
                 ,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- Speed up "all results for user X" (Report page)
    INDEX `idx_qr_user_id`    (`user_id`),

    -- Speed up "all results for note X" (per-note breakdown)
    INDEX `idx_qr_note_id`    (`note_id`),

    -- Speed up sorting by date (score trend chart)
    INDEX `idx_qr_created_at` (`created_at`),

    -- Speed up "best score" / "avg score" aggregate queries
    INDEX `idx_qr_percent`    (`percent`),

    -- Composite index for common Report query:
    -- WHERE user_id = X ORDER BY created_at DESC
    INDEX `idx_qr_user_date`  (`user_id`, `created_at`),

    CONSTRAINT `fk_qr_user`
        FOREIGN KEY (`user_id`)
        REFERENCES  `users` (`id`)
        ON DELETE   CASCADE
        ON UPDATE   CASCADE,

    -- SET NULL instead of CASCADE so quiz history survives note deletion
    CONSTRAINT `fk_qr_note`
        FOREIGN KEY (`note_id`)
        REFERENCES  `notes` (`id`)
        ON DELETE   SET NULL
        ON UPDATE   CASCADE

) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE         = utf8mb4_unicode_ci;


-- ════════════════════════════════════════════════════════════════
-- 5.  AI SUMMARIES  (Gemini response cache)
-- ════════════════════════════════════════════════════════════════
-- Caches AI-generated summaries so re-requesting the same note
-- returns the cached result without calling the Gemini API again.
--
-- One summary per note enforced by UNIQUE KEY uq_summary_note.
-- generate_summary.php uses INSERT ... ON DUPLICATE KEY UPDATE
-- to refresh the cache when the user explicitly regenerates.
-- ════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `ai_summaries` (
    `id`         INT      UNSIGNED NOT NULL AUTO_INCREMENT,
    `note_id`    INT      UNSIGNED NOT NULL,
    `user_id`    INT      UNSIGNED NOT NULL,
    `summary`    LONGTEXT NOT NULL
                 ,
    `word_count` INT      UNSIGNED     NULL DEFAULT NULL
                 ,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NULL DEFAULT NULL
                 ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- One summary per note (refresh via ON DUPLICATE KEY UPDATE)
    UNIQUE KEY `uq_summary_note` (`note_id`),

    INDEX `idx_sm_user_id`   (`user_id`),
    INDEX `idx_sm_created_at`(`created_at`),

    CONSTRAINT `fk_sm_note`
        FOREIGN KEY (`note_id`)
        REFERENCES  `notes` (`id`)
        ON DELETE   CASCADE
        ON UPDATE   CASCADE,

    CONSTRAINT `fk_sm_user`
        FOREIGN KEY (`user_id`)
        REFERENCES  `users` (`id`)
        ON DELETE   CASCADE
        ON UPDATE   CASCADE

) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE         = utf8mb4_unicode_ci;


-- ════════════════════════════════════════════════════════════════
-- 6.  CHAT HISTORY  (optional — stores AI chat conversations)
-- ════════════════════════════════════════════════════════════════
-- Persists the chat_assistant.py conversation history so students
-- can resume a chat session after closing the browser.
--
-- The frontend chat.js currently keeps history in memory only.
-- Enable this table if you add session persistence to the UI.
-- ════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `chat_history` (
    `id`         INT           UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT           UNSIGNED NOT NULL,
    `note_id`    INT           UNSIGNED     NULL DEFAULT NULL,
    `role`       ENUM('user','model') NOT NULL
                 ,
    `message`    TEXT          NOT NULL
                 ,
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- Speed up loading chat history for a specific note session
    INDEX `idx_ch_user_note`  (`user_id`, `note_id`),
    INDEX `idx_ch_created_at` (`created_at`),

    CONSTRAINT `fk_ch_user`
        FOREIGN KEY (`user_id`)
        REFERENCES  `users` (`id`)
        ON DELETE   CASCADE
        ON UPDATE   CASCADE,

    CONSTRAINT `fk_ch_note`
        FOREIGN KEY (`note_id`)
        REFERENCES  `notes` (`id`)
        ON DELETE   SET NULL
        ON UPDATE   CASCADE

) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE         = utf8mb4_unicode_ci;


-- ════════════════════════════════════════════════════════════════
-- 7.  USER SESSIONS  (login audit log — optional)
-- ════════════════════════════════════════════════════════════════
-- Records each login event for security auditing and
-- "Recently active devices" UI (if you build that feature).
-- ════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id`          INT          UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT          UNSIGNED NOT NULL,
    `ip_address`  VARCHAR(45)      NULL DEFAULT NULL
                  ,
    `user_agent`  VARCHAR(512)     NULL DEFAULT NULL
                  ,
    `login_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `logout_at`   DATETIME         NULL DEFAULT NULL,

    PRIMARY KEY (`id`),

    INDEX `idx_us_user_id`   (`user_id`),
    INDEX `idx_us_login_at`  (`login_at`),

    CONSTRAINT `fk_us_user`
        FOREIGN KEY (`user_id`)
        REFERENCES  `users` (`id`)
        ON DELETE   CASCADE
        ON UPDATE   CASCADE

) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE         = utf8mb4_unicode_ci;


-- ════════════════════════════════════════════════════════════════
-- 8.  CLEANUP EVENT  (auto-prune expired blacklist tokens)
-- ════════════════════════════════════════════════════════════════
-- MySQL Event Scheduler automatically removes expired blacklist
-- rows once per hour so the table never grows unbounded.
--
-- Requires the Event Scheduler to be enabled (it is by default
-- in MySQL 8.0). Check with: SHOW VARIABLES LIKE 'event_scheduler';
-- Enable with:               SET GLOBAL event_scheduler = ON;
-- ════════════════════════════════════════════════════════════════

DROP EVENT IF EXISTS `evt_prune_token_blacklist`;

CREATE EVENT `evt_prune_token_blacklist`
    ON SCHEDULE EVERY 1 HOUR
    STARTS CURRENT_TIMESTAMP
    ON COMPLETION PRESERVE
    ENABLE
  
DO
    DELETE FROM `token_blacklist`
    WHERE  `expires_at` < NOW();


-- ════════════════════════════════════════════════════════════════
-- 9.  STORED PROCEDURE — get_user_stats()
-- ════════════════════════════════════════════════════════════════
-- Convenience procedure used by the Report page PHP endpoint.
-- Returns all aggregate stats for a user in a single call.
--
-- Usage:
--   CALL get_user_stats(1);
-- ════════════════════════════════════════════════════════════════

DROP PROCEDURE IF EXISTS `get_user_stats`;

DELIMITER //

CREATE PROCEDURE `get_user_stats`(IN p_user_id INT UNSIGNED)
BEGIN
    SELECT
        -- Notes
        (SELECT COUNT(*)
         FROM   `notes`
         WHERE  `user_id`    = p_user_id
           AND  `deleted_at` IS NULL)               AS notes_count,

        -- Quiz stats
        (SELECT COUNT(*)
         FROM   `quiz_results`
         WHERE  `user_id` = p_user_id)              AS quizzes_taken,

        (SELECT ROUND(AVG(`percent`), 1)
         FROM   `quiz_results`
         WHERE  `user_id` = p_user_id)              AS avg_score,

        (SELECT MAX(`percent`)
         FROM   `quiz_results`
         WHERE  `user_id` = p_user_id)              AS best_score,

        (SELECT SUM(`score`)
         FROM   `quiz_results`
         WHERE  `user_id` = p_user_id)              AS total_correct,

        (SELECT SUM(`total`)
         FROM   `quiz_results`
         WHERE  `user_id` = p_user_id)              AS total_answered,

        -- Summaries generated
        (SELECT COUNT(*)
         FROM   `ai_summaries`
         WHERE  `user_id` = p_user_id)              AS summaries_generated,

        -- Chat messages sent
        (SELECT COUNT(*)
         FROM   `chat_history`
         WHERE  `user_id` = p_user_id
           AND  `role`    = 'user')                 AS chat_messages_sent;
END //

DELIMITER ;


-- ════════════════════════════════════════════════════════════════
-- 10.  VIEW — active_notes
-- ════════════════════════════════════════════════════════════════
-- Convenience view that filters out soft-deleted notes.
-- Use this in queries instead of adding WHERE deleted_at IS NULL
-- everywhere.
--
-- Usage:
--   SELECT * FROM active_notes WHERE user_id = 1;
-- ════════════════════════════════════════════════════════════════

CREATE OR REPLACE VIEW `active_notes` AS
    SELECT
        n.id,
        n.user_id,
        n.name,
        n.content,
        n.file_type,
        n.file_size,
        n.file_path,
        n.upload_date,
        n.last_accessed_at,
        n.created_at,
        n.updated_at,
        u.name  AS user_name,
        u.email AS user_email
    FROM  `notes` n
    JOIN  `users` u ON u.id = n.user_id
    WHERE n.deleted_at IS NULL
      AND u.is_active  = 1;


-- ════════════════════════════════════════════════════════════════
-- 11.  VIEW — quiz_leaderboard
-- ════════════════════════════════════════════════════════════════
-- Top students by average quiz score.
-- Useful for a future leaderboard or gamification feature.
-- ════════════════════════════════════════════════════════════════

CREATE OR REPLACE VIEW `quiz_leaderboard` AS
    SELECT
        u.id                                   AS user_id,
        u.name                                 AS user_name,
        COUNT(qr.id)                           AS quizzes_taken,
        ROUND(AVG(qr.percent), 1)              AS avg_score,
        MAX(qr.percent)                        AS best_score,
        SUM(qr.score)                          AS total_correct,
        SUM(qr.total)                          AS total_answered,
        MAX(qr.created_at)                     AS last_quiz_at
    FROM  `users`        u
    JOIN  `quiz_results` qr ON qr.user_id = u.id
    WHERE u.is_active = 1
    GROUP BY u.id, u.name
    ORDER BY avg_score DESC, quizzes_taken DESC;


-- ════════════════════════════════════════════════════════════════
-- 12.  Verification
-- ════════════════════════════════════════════════════════════════
-- Run after setup to confirm everything was created:
--
--   SHOW TABLES;
--   SHOW CREATE TABLE users;
--   SHOW CREATE TABLE notes;
--   SHOW EVENTS;
--   SHOW PROCEDURE STATUS WHERE Db = 'smart_study_companion';
--   SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_COMMENT
--     FROM information_schema.TABLES
--    WHERE TABLE_SCHEMA = 'smart_study_companion'
--    ORDER BY TABLE_NAME;
-- ════════════════════════════════════════════════════════════════