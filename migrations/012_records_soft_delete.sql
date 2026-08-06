-- Trash özelliği (Airtable paritesi, "Kaydı sil" — 7 gün çöp kutusu + geri
-- yükleme, bases'teki AYNI desen, bkz. migrations/008_bases_soft_delete.sql):
-- kayıt silme artık gerçek DELETE değil, geri alınabilir soft-delete.
-- deleted_by ON DELETE SET NULL — created_by/uploaded_by/audit_log.user_id/
-- bases.deleted_by ile AYNI ilke (silen kullanıcının hesabı silinirse
-- kaydın kendisi bozulmaz, yalnızca "kim sildi" bilgisi anonimleşir).
-- Idempotent (IF NOT EXISTS / DROP FOREIGN KEY IF EXISTS) — 008'deki AYNI
-- gerekçeyle, ikinci kez çalıştırılırsa hata vermeden no-op olmalı.
--
-- Index: bases'ten FARKLI olarak (tek kolon idx_bases_deleted_at) burada
-- BİLEŞİK bir index — (table_id, deleted_at). records, bases'ten çok daha
-- sık sorgulanıyor (her grid sayfa yüklemesi) ve her sorgu zaten
-- "WHERE table_id = ? AND deleted_at IS NULL" şeklinde olacak (bkz.
-- bcc_build_grid_records_query, src/schema.php — filtre Adım 3c'de eklenecek).
-- Mevcut idx_records_table (tek kolon) BİLEREK kaldırılmadı, ayrı bir
-- sadeleştirme kararı olarak sonra değerlendirilecek.

ALTER TABLE records
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER updated_at,
    ADD COLUMN IF NOT EXISTS deleted_by INT UNSIGNED NULL AFTER deleted_at;

ALTER TABLE records DROP FOREIGN KEY IF EXISTS fk_records_deleted_by;
ALTER TABLE records ADD CONSTRAINT fk_records_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_records_table_deleted_at ON records (table_id, deleted_at);
