-- Trash özelliği (Airtable workspace trash referansı): base silme artık
-- gerçek DELETE değil, geri alınabilir soft-delete. deleted_by ON DELETE
-- SET NULL — mevcut created_by/uploaded_by/audit_log.user_id ile AYNI desen
-- (silen kullanıcının hesabı silinirse base'in kendisi/trash kaydı bozulmaz,
-- yalnızca "kim sildi" bilgisi anonimleşir).
-- Bulunan gerçek bug: bu dosya, klasördeki diğer iki migration'ın (002, 009)
-- açıkça belirttiği "migration'ların hangisinin uygulandığını takip eden ayrı
-- bir mekanizma yok, bu yüzden ikinci kez çalıştırılırsa hata vermeden no-op
-- olmalı" prensibini UYGULAMIYORDU — IF NOT EXISTS/IF EXISTS hiç yoktu, bu
-- dosya yanlışlıkla tekrar çalıştırılsaydı "Duplicate column name" ile
-- patlardı (foreign key için ADD CONSTRAINT ... IF NOT EXISTS diye bir
-- MariaDB sözdizimi yok — canlı test ile doğrulandı; onun yerine DROP
-- FOREIGN KEY IF EXISTS + yeniden ADD CONSTRAINT deseni kullanılır, aynı
-- sonuca idempotent şekilde ulaşır).
ALTER TABLE bases
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER created_at,
    ADD COLUMN IF NOT EXISTS deleted_by INT UNSIGNED NULL AFTER deleted_at;

ALTER TABLE bases DROP FOREIGN KEY IF EXISTS fk_bases_deleted_by;
ALTER TABLE bases ADD CONSTRAINT fk_bases_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_bases_deleted_at ON bases (deleted_at);
