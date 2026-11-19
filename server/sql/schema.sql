-- 随身听 sst-music 数据库结构
-- MySQL 5.7+ / 8.0，utf8mb4

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(20)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    nickname      VARCHAR(32)  NOT NULL DEFAULT '',
    created_at    INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tokens (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at INT UNSIGNED NOT NULL,
    created_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_token (token_hash),
    KEY idx_user (user_id),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username   VARCHAR(20) NOT NULL,
    ip         VARCHAR(45) NOT NULL DEFAULT '',
    created_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_user_time (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS friend_requests (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    from_id    BIGINT UNSIGNED NOT NULL,
    to_id      BIGINT UNSIGNED NOT NULL,
    status     TINYINT NOT NULL DEFAULT 0 COMMENT '0待处理 1已同意 2已拒绝',
    created_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pair (from_id, to_id),
    KEY idx_to (to_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS friends (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    friend_id  BIGINT UNSIGNED NOT NULL,
    created_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pair (user_id, friend_id),
    KEY idx_friend (friend_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS rooms (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code       CHAR(6) NOT NULL,
    owner_id   BIGINT UNSIGNED NOT NULL,
    guest_id   BIGINT UNSIGNED NULL DEFAULT NULL,
    status     TINYINT NOT NULL DEFAULT 1 COMMENT '1进行中 2已关闭',
    created_at INT UNSIGNED NOT NULL,
    updated_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_code (code),
    KEY idx_owner (owner_id, status),
    KEY idx_guest (guest_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS room_queue (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id    BIGINT UNSIGNED NOT NULL,
    sort       INT NOT NULL DEFAULT 0,
    platform   VARCHAR(8)  NOT NULL,
    source_id  VARCHAR(32) NOT NULL,
    name       VARCHAR(255) NOT NULL,
    artist     VARCHAR(255) NOT NULL DEFAULT '',
    album      VARCHAR(255) NOT NULL DEFAULT '',
    cover      VARCHAR(512) NOT NULL DEFAULT '',
    duration   INT NOT NULL DEFAULT 0,
    qualities  JSON NULL,
    added_by   BIGINT UNSIGNED NOT NULL,
    created_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_room_sort (room_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS room_state (
    room_id     BIGINT UNSIGNED NOT NULL,
    queue_index INT NOT NULL DEFAULT 0,
    is_playing  TINYINT NOT NULL DEFAULT 0,
    position_ms INT NOT NULL DEFAULT 0,
    state_at    BIGINT NOT NULL DEFAULT 0 COMMENT '服务器毫秒时间戳',
    version     INT NOT NULL DEFAULT 0,
    updated_by  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS url_cache (
    cache_key  VARCHAR(191) NOT NULL,
    value      TEXT NOT NULL,
    expires_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (cache_key),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
