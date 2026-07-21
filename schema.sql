-- BCC-Core — Faz 0 şeması
-- Airtable benzeri EAV (Entity-Attribute-Value) veri modeli.
-- Karakter seti: utf8mb4 / utf8mb4_unicode_ci (her yerde, Türkçe karakter desteği için).
-- Motor: InnoDB (foreign key desteği için).
--
-- İçe aktarma (cmd, XAMPP MySQL çalışırken):
--   C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 3306 -u root bcc_core < schema.sql
-- (bcc_core veritabanı zaten mevcut olmalı; utf8mb4_unicode_ci ile oluşturulmuş olmalı.)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- teams — KVKK izolasyonu için ekip (TY, GULF, ATP …)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS teams (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(100) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_teams_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- users — sistem kullanıcıları (giriş bilgisi)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email          VARCHAR(190) NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    full_name      VARCHAR(150) NOT NULL,
    is_admin       TINYINT(1) NOT NULL DEFAULT 0,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- team_members — kullanıcı + ekip + rol (owner/editor/commenter/viewer)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS team_members (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    team_id     INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    role        ENUM('owner','editor','commenter','viewer') NOT NULL DEFAULT 'viewer',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_team_members_team_user (team_id, user_id),
    KEY idx_team_members_user (user_id),
    CONSTRAINT fk_team_members_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_team_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- bases — çalışma alanı (bir ekibe bağlı, birden çok tablo içerir)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bases (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    team_id      INT UNSIGNED NOT NULL,
    name         VARCHAR(150) NOT NULL,
    description  VARCHAR(500) DEFAULT NULL,
    created_by   INT UNSIGNED DEFAULT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bases_team (team_id),
    CONSTRAINT fk_bases_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_bases_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- tables_meta — kullanıcı tanımlı tablolar (sekmeler: DUYURU, ITSM …)
-- ("tables" yerine "tables_meta": MySQL'de ayrılmış kelime çakışmasını önler)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tables_meta (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    base_id      INT UNSIGNED NOT NULL,
    name         VARCHAR(150) NOT NULL,
    description  VARCHAR(500) DEFAULT NULL,
    position     INT NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tables_meta_base (base_id),
    CONSTRAINT fk_tables_meta_base FOREIGN KEY (base_id) REFERENCES bases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- fields — tablo alanları (tip + seçenekler)
-- field_type: single_line_text, long_text, number, checkbox, date, single_select,
--             multiple_select, attachment, user, phone_number, email, url,
--             currency, percent, link, created_time, last_modified_time
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fields (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    table_id     INT UNSIGNED NOT NULL,
    name         VARCHAR(150) NOT NULL,
    field_type   VARCHAR(30) NOT NULL,
    options      JSON DEFAULT NULL,
    position     INT NOT NULL DEFAULT 0,
    is_required  TINYINT(1) NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fields_table (table_id),
    CONSTRAINT fk_fields_table FOREIGN KEY (table_id) REFERENCES tables_meta(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- records — satırlar
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS records (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    table_id    INT UNSIGNED NOT NULL,
    position    INT NOT NULL DEFAULT 0,
    created_by  INT UNSIGNED DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_records_table (table_id),
    CONSTRAINT fk_records_table FOREIGN KEY (table_id) REFERENCES tables_meta(id) ON DELETE CASCADE,
    CONSTRAINT fk_records_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- cell_values — hücreler (tipe göre uygun value_* kolonu kullanılır)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cell_values (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    record_id     INT UNSIGNED NOT NULL,
    field_id      INT UNSIGNED NOT NULL,
    value_text    TEXT DEFAULT NULL,
    value_number  DECIMAL(20,6) DEFAULT NULL,
    value_date    DATETIME DEFAULT NULL,
    value_json    JSON DEFAULT NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cell_values_record_field (record_id, field_id),
    KEY idx_cell_values_field (field_id),
    CONSTRAINT fk_cell_values_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE,
    CONSTRAINT fk_cell_values_field FOREIGN KEY (field_id) REFERENCES fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- record_links — tablolar arası bağlantı (link tipi alan)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS record_links (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    field_id           INT UNSIGNED NOT NULL,
    source_record_id   INT UNSIGNED NOT NULL,
    target_record_id   INT UNSIGNED NOT NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_record_links (field_id, source_record_id, target_record_id),
    KEY idx_record_links_source (source_record_id),
    KEY idx_record_links_target (target_record_id),
    CONSTRAINT fk_record_links_field FOREIGN KEY (field_id) REFERENCES fields(id) ON DELETE CASCADE,
    CONSTRAINT fk_record_links_source FOREIGN KEY (source_record_id) REFERENCES records(id) ON DELETE CASCADE,
    CONSTRAINT fk_record_links_target FOREIGN KEY (target_record_id) REFERENCES records(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- views — görünümler (grid ve "interface"/duyuru arayüzü ayarları)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS views (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    table_id       INT UNSIGNED NOT NULL,
    name           VARCHAR(150) NOT NULL,
    view_type      VARCHAR(30) NOT NULL DEFAULT 'grid',
    config         JSON DEFAULT NULL,
    is_published   TINYINT(1) NOT NULL DEFAULT 0,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_views_table (table_id),
    CONSTRAINT fk_views_table FOREIGN KEY (table_id) REFERENCES tables_meta(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- attachments — dosya ekleri
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attachments (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    record_id     INT UNSIGNED NOT NULL,
    field_id      INT UNSIGNED NOT NULL,
    file_name     VARCHAR(255) NOT NULL,
    stored_path   VARCHAR(500) NOT NULL,
    mime_type     VARCHAR(150) DEFAULT NULL,
    file_size     INT UNSIGNED DEFAULT NULL,
    uploaded_by   INT UNSIGNED DEFAULT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attachments_record (record_id),
    KEY idx_attachments_field (field_id),
    CONSTRAINT fk_attachments_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE,
    CONSTRAINT fk_attachments_field FOREIGN KEY (field_id) REFERENCES fields(id) ON DELETE CASCADE,
    CONSTRAINT fk_attachments_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- slack_webhooks — duyuru → Slack gönderimi ayarı
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS slack_webhooks (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    team_id       INT UNSIGNED NOT NULL,
    table_id      INT UNSIGNED DEFAULT NULL,
    webhook_url   VARCHAR(500) NOT NULL,
    channel_name  VARCHAR(150) DEFAULT NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_slack_webhooks_team (team_id),
    KEY idx_slack_webhooks_table (table_id),
    CONSTRAINT fk_slack_webhooks_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_slack_webhooks_table FOREIGN KEY (table_id) REFERENCES tables_meta(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- audit_log — kim neyi ne zaman değiştirdi (KVKK için de faydalı)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    team_id      INT UNSIGNED DEFAULT NULL,
    user_id      INT UNSIGNED DEFAULT NULL,
    action       VARCHAR(100) NOT NULL,
    entity_type  VARCHAR(50) DEFAULT NULL,
    entity_id    INT UNSIGNED DEFAULT NULL,
    details      JSON DEFAULT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_log_team (team_id),
    KEY idx_audit_log_user (user_id),
    CONSTRAINT fk_audit_log_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
