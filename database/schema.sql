SET SQL_MODE   = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';
SET time_zone  = '+00:00';  
CREATE DATABASE IF NOT EXISTS `smart_study_companion`
    CHARACTER SET  utf8mb4
    COLLATE        utf8mb4_unicode_ci;

USE `smart_study_companion`;

CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT          UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(100) NOT NULL,
    `email`         VARCHAR(254) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `last_login`    DATETIME         NULL DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME         NULL DEFAULT NULL
                    ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    INDEX `idx_users_is_active` (`is_active`),
    INDEX `idx_users_created_at` (`created_at`)

) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE         = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `token_blacklist` (
    `id`         INT      UNSIGNED NOT NULL AUTO_INCREMENT,
    `token_hash` CHAR(64) NOT NULL,
    `user_id`    INT      UNSIGNED NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `revoked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token_hash` (`token_hash`),
    INDEX `idx_tbl_expires_at` (`expires_at`),
    INDEX `idx_tbl_user_id` (`user_id`),

    CONSTRAINT `fk_tbl_user`
        FOREIGN KEY (`user_id`)
        REFERENCES  `users` (`id`)
        ON DELETE   CASCADE
        ON UPDATE   CASCADE

) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE         = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notes` (
    `id`               INT          UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          INT          UNSIGNED NOT NULL,
    `name`             VARCHAR(120) NOT NULL,
    `content`          LONGTEXT     NOT NULL,
    `file_type`        VARCHAR(50)  NOT NULL DEFAULT 'Text',
    `file_size`        VARCHAR(20)  NOT NULL DEFAULT '0 B',
    `file_path`        VARCHAR(500)     NULL DEFAULT NULL,
    `upload_date`      DATE         NOT NULL,
    `last_accessed_at` DATETIME         NULL DEFAULT NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME         NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`       DATETIME         NULL DEFAULT NULL,

    PRIMARY KEY (`id`),

    CONSTRAINT `fk_notes_user`
        FOREIGN KEY (`user_id`)
        REFERENCES  `users` (`id`)
        ON DELETE   CASCADE
        ON UPDATE   CASCADE

) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE         = utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `quiz_results` (
    `id`         INT          UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT          UNSIGNED NOT NULL,
    `note_id`    INT          UNSIGNED     NULL DEFAULT NULL,
    `score`      TINYINT      UNSIGNED NOT NULL,
    `total`      TINYINT      UNSIGNED NOT NULL,
    `percent`    TINYINT      UNSIGNED NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    CONSTRAINT `fk_qr_user`
        FOREIGN KEY (`user_id`)
        REFERENCES  `users` (`id`)
        ON DELETE   CASCADE
        ON UPDATE   CASCADE,

    CONSTRAINT `fk_qr_note`
        FOREIGN KEY (`note_id`)
        REFERENCES  `notes` (`id`)
        ON DELETE   SET NULL
        ON UPDATE   CASCADE

) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE         = utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `ai_summaries` (
    `id`         INT      UNSIGNED NOT NULL AUTO_INCREMENT,
    `note_id`    INT      UNSIGNED NOT NULL,
    `user_id`    INT      UNSIGNED NOT NULL,
    `summary`    LONGTEXT NOT NULL,
    `word_count` INT      UNSIGNED     NULL DEFAULT NULL=,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
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

CREATE TABLE IF NOT EXISTS `chat_history` (
    `id`         INT           UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT           UNSIGNED NOT NULL,
    `note_id`    INT           UNSIGNED     NULL DEFAULT NULL,
    `role`       ENUM('user','model') NOT NULL,
    `message`    TEXT          NOT NULL,
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

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


CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id`          INT          UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT          UNSIGNED NOT NULL,
    `ip_address`  VARCHAR(45)      NULL DEFAULT NULL,
    `user_agent`  VARCHAR(512)     NULL DEFAULT NULL,
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


DROP EVENT IF EXISTS `evt_prune_token_blacklist`;

CREATE EVENT `evt_prune_token_blacklist`
    ON SCHEDULE EVERY 1 HOUR
    STARTS CURRENT_TIMESTAMP
    ON COMPLETION PRESERVE
    ENABLE
  
DO
    DELETE FROM `token_blacklist`
    WHERE  `expires_at` < NOW();


DROP PROCEDURE IF EXISTS `get_user_stats`;

CREATE PROCEDURE `get_user_stats`(IN p_user_id INT UNSIGNED)
BEGIN
    SELECT
        (SELECT COUNT(*)
         FROM   `notes`
         WHERE  `user_id`    = p_user_id
           AND  `deleted_at` IS NULL)               AS notes_count,

        (SELECT COUNT(*)
         FROM   `quiz_results`
         WHERE  `user_id` = p_user_id)              AS quizzes_taken,

        (SELECT ROUND(AVG(`percent`), 1)
         FROM   `quiz_results`
         WHERE  `user_id` = p_user_id)           AS avg_score,

        (SELECT MAX(`percent`)
         FROM   `quiz_results`
         WHERE  `user_id` = p_user_id)       AS best_score,

        (SELECT SUM(`score`)
         FROM   `quiz_results`
         WHERE  `user_id` = p_user_id)         AS total_correct,

        (SELECT SUM(`total`)
         FROM   `quiz_results`
         WHERE  `user_id` = p_user_id)       AS total_answered,

        (SELECT COUNT(*)
         FROM   `ai_summaries`
         WHERE  `user_id` = p_user_id)         AS summaries_generated,

        (SELECT COUNT(*)
         FROM   `chat_history`
         WHERE  `user_id` = p_user_id
           AND  `role`    = 'user')            AS chat_messages_sent;
END //

DELIMITER ;


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
