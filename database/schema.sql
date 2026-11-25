-- bMaxSend database schema (MariaDB 10.6+ / MySQL 8+)
-- All DATETIME columns are stored in UTC.

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ---------------------------------------------------------------------------
-- Users: one table, three roles.
--   admin    : can do everything
--   reseller : creates and manages its own clients
--   client   : manages lists, campaigns and scheduled sends
-- A client's parent_id points to its reseller (NULL = managed directly by admin).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role            ENUM('admin','reseller','client') NOT NULL,
    parent_id       INT UNSIGNED NULL,
    name            VARCHAR(150) NOT NULL,
    company         VARCHAR(190) NULL,
    email           VARCHAR(190) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    status          ENUM('active','suspended') NOT NULL DEFAULT 'active',
    language        VARCHAR(8) NULL,
    timezone        VARCHAR(64) NULL,
    max_clients     INT UNSIGNED NULL COMMENT 'reseller only, NULL = unlimited',
    max_subscribers INT UNSIGNED NULL COMMENT 'client only, NULL = unlimited',
    monthly_limit   INT UNSIGNED NULL COMMENT 'client only: emails per calendar month, NULL = unlimited',
    notes           TEXT NULL,
    last_login_at   DATETIME NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_parent (parent_id),
    KEY idx_users_role (role),
    CONSTRAINT fk_users_parent FOREIGN KEY (parent_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- SMTP servers: created by admin only.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS smtp_servers (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(150) NOT NULL,
    host            VARCHAR(190) NOT NULL,
    port            SMALLINT UNSIGNED NOT NULL DEFAULT 587,
    encryption      ENUM('none','tls','ssl') NOT NULL DEFAULT 'tls',
    auth            TINYINT(1) NOT NULL DEFAULT 1,
    username        VARCHAR(190) NULL,
    password_enc    TEXT NULL COMMENT 'sodium secretbox, see App\\Core\\Crypto',
    verify_peer     TINYINT(1) NOT NULL DEFAULT 1,
    helo_hostname   VARCHAR(190) NULL,
    return_path     VARCHAR(190) NULL COMMENT 'envelope sender / bounce address (optional)',
    allowed_from    VARCHAR(500) NULL COMMENT 'comma separated domains allowed in From, empty = any',
    rate_per_minute INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
    rate_per_hour   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
    active          TINYINT(1) NOT NULL DEFAULT 1,
    notes           TEXT NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin assigns servers to resellers (or directly to admin-managed clients);
-- resellers assign their servers to their own clients.
CREATE TABLE IF NOT EXISTS smtp_assignments (
    smtp_server_id  INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    assigned_by     INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (smtp_server_id, user_id),
    KEY idx_smtp_assign_user (user_id),
    CONSTRAINT fk_sa_smtp FOREIGN KEY (smtp_server_id) REFERENCES smtp_servers (id) ON DELETE CASCADE,
    CONSTRAINT fk_sa_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_sa_by   FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Alias domains used for the web version and the links inside emails.
-- A domain belongs to an owner. If "shared" is set, the users below the owner
-- (reseller -> its clients, admin -> everybody) can use it too.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS domains (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_id        INT UNSIGNED NOT NULL,
    hostname        VARCHAR(190) NOT NULL,
    shared          TINYINT(1) NOT NULL DEFAULT 0,
    use_https       TINYINT(1) NOT NULL DEFAULT 1,
    verify_token    CHAR(32) NOT NULL,
    status          ENUM('pending','verified','failed') NOT NULL DEFAULT 'pending',
    verified_at     DATETIME NULL,
    last_check_at   DATETIME NULL,
    last_check_msg  VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_domains_hostname (hostname),
    KEY idx_domains_owner (owner_id),
    CONSTRAINT fk_domains_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Mailing lists and subscribers (owned by clients).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lists (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id       INT UNSIGNED NOT NULL,
    name            VARCHAR(150) NOT NULL,
    description     VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_lists_client (client_id),
    CONSTRAINT fk_lists_client FOREIGN KEY (client_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscribers (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    list_id         INT UNSIGNED NOT NULL,
    email           VARCHAR(190) NOT NULL,
    name            VARCHAR(190) NULL,
    fields          TEXT NULL COMMENT 'JSON object {field_key: value}, keys defined in list_fields',
    status          TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = disabled, 1 = enabled, 2 = unsubscribed',
    track           TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '1 = opens/clicks tracked, 0 = not tracked',
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    unsubscribed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subscribers_list_email (list_id, email),
    KEY idx_subscribers_status (list_id, status),
    KEY idx_subscribers_email (email),
    CONSTRAINT fk_subscribers_list FOREIGN KEY (list_id) REFERENCES lists (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom fields of each list. Created automatically from the CSV header on
-- import, or by hand; usable in the emails as {{field_key}}.
CREATE TABLE IF NOT EXISTS list_fields (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    list_id         INT UNSIGNED NOT NULL,
    field_key       VARCHAR(64) NOT NULL,
    label           VARCHAR(150) NOT NULL,
    position        INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_list_fields_key (list_id, field_key),
    CONSTRAINT fk_list_fields_list FOREIGN KEY (list_id) REFERENCES lists (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-client suppression list: these addresses are never mailed by that client.
CREATE TABLE IF NOT EXISTS suppressions (
    client_id       INT UNSIGNED NOT NULL,
    email           VARCHAR(190) NOT NULL,
    reason          ENUM('unsubscribed','bounced','complained','manual') NOT NULL DEFAULT 'manual',
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (client_id, email),
    CONSTRAINT fk_suppr_client FOREIGN KEY (client_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Campaigns (DEM / newsletters).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS campaigns (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id       INT UNSIGNED NOT NULL,
    name            VARCHAR(190) NOT NULL,
    subject         VARCHAR(255) NOT NULL,
    preheader       VARCHAR(255) NULL,
    from_name       VARCHAR(150) NOT NULL,
    from_email      VARCHAR(190) NOT NULL,
    reply_to        VARCHAR(190) NULL,
    html_body       MEDIUMTEXT NULL,
    text_body       MEDIUMTEXT NULL,
    smtp_server_id  INT UNSIGNED NULL,
    domain_id       INT UNSIGNED NULL,
    track_opens     TINYINT(1) NOT NULL DEFAULT 1,
    track_clicks    TINYINT(1) NOT NULL DEFAULT 1,
    status          ENUM('draft','scheduled','sending','paused','sent','cancelled') NOT NULL DEFAULT 'draft',
    scheduled_at    DATETIME NULL,
    started_at      DATETIME NULL,
    finished_at     DATETIME NULL,
    last_error      VARCHAR(500) NULL,
    total_recipients INT UNSIGNED NOT NULL DEFAULT 0,
    sent_count      INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count    INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_campaigns_client (client_id),
    KEY idx_campaigns_status (status, scheduled_at),
    CONSTRAINT fk_campaigns_client FOREIGN KEY (client_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_campaigns_smtp   FOREIGN KEY (smtp_server_id) REFERENCES smtp_servers (id) ON DELETE SET NULL,
    CONSTRAINT fk_campaigns_domain FOREIGN KEY (domain_id) REFERENCES domains (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_lists (
    campaign_id     INT UNSIGNED NOT NULL,
    list_id         INT UNSIGNED NOT NULL,
    PRIMARY KEY (campaign_id, list_id),
    KEY idx_cl_list (list_id),
    CONSTRAINT fk_cl_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE,
    CONSTRAINT fk_cl_list     FOREIGN KEY (list_id) REFERENCES lists (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Send queue: one row per recipient, snapshot taken when the campaign starts.
CREATE TABLE IF NOT EXISTS campaign_recipients (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id     INT UNSIGNED NOT NULL,
    subscriber_id   INT UNSIGNED NULL,
    email           VARCHAR(190) NOT NULL,
    name            VARCHAR(190) NULL,
    fields          TEXT NULL,
    track           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    token           CHAR(32) NOT NULL,
    status          ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    error           VARCHAR(500) NULL,
    sent_at         DATETIME NULL,
    opened_at       DATETIME NULL,
    open_count      INT UNSIGNED NOT NULL DEFAULT 0,
    clicked_at      DATETIME NULL,
    click_count     INT UNSIGNED NOT NULL DEFAULT 0,
    unsubscribed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cr_token (token),
    UNIQUE KEY uq_cr_campaign_email (campaign_id, email),
    KEY idx_cr_queue (campaign_id, status, attempts),
    KEY idx_cr_sent_at (sent_at),
    CONSTRAINT fk_cr_campaign   FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE,
    CONSTRAINT fk_cr_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaign_links (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id     INT UNSIGNED NOT NULL,
    url_hash        CHAR(40) NOT NULL,
    url             TEXT NOT NULL,
    clicks          INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_links_campaign_hash (campaign_id, url_hash),
    CONSTRAINT fk_links_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tracking_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id     INT UNSIGNED NOT NULL,
    recipient_id    BIGINT UNSIGNED NOT NULL,
    type            ENUM('open','click','webview','unsubscribe') NOT NULL,
    link_id         INT UNSIGNED NULL,
    ip              VARCHAR(45) NULL,
    user_agent      VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_te_campaign (campaign_id, type),
    CONSTRAINT fk_te_campaign  FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE,
    CONSTRAINT fk_te_recipient FOREIGN KEY (recipient_id) REFERENCES campaign_recipients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Security / audit
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip              VARCHAR(45) NOT NULL,
    email           VARCHAR(190) NULL,
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_la_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NULL COMMENT 'account the action was performed on behalf of',
    actor_id        INT UNSIGNED NULL COMMENT 'real user (differs when impersonating)',
    action          VARCHAR(100) NOT NULL,
    details         TEXT NULL,
    ip              VARCHAR(45) NULL,
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_audit_user (user_id),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version         VARCHAR(50) NOT NULL,
    applied_at      DATETIME NOT NULL,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version, applied_at) VALUES ('0001_initial', UTC_TIMESTAMP()), ('0002_subscriber_fields', UTC_TIMESTAMP());

SET foreign_key_checks = 1;
