-- Faz 1 migration — Faz 0'da içe aktarılmış bir bcc_core veritabanına uygulanır.
-- (Yeni kurulumlarda schema.sql zaten is_admin sütununu içerir; bu dosya sadece
-- daha önce Faz 0 şemasını içe aktarmış olanlar için idempotent bir tamamlayıcıdır.)

ALTER TABLE users ADD COLUMN IF NOT EXISTS is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER full_name;

INSERT IGNORE INTO teams (name) VALUES ('TY'), ('GULF'), ('ATP');
