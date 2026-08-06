-- "Last modified by" (Airtable paritesi, Grup B2): records.updated_at zaten
-- var ama HİÇBİR YAZMA NOKTASI bunu güncellemiyordu (bkz. docs/PROJE-DURUM.md
-- teşhis notu) — bu migration yalnızca EKSİK OLAN "kim" kolonunu ekliyor,
-- "ne zaman" için yeni kolon YOK. deleted_by (migrations/012) ile AYNI ilke:
-- ON DELETE SET NULL — düzenleyen kullanıcının hesabı silinirse kaydın
-- kendisi bozulmaz, yalnızca "kim değiştirdi" bilgisi anonimleşir.
-- Idempotent (IF NOT EXISTS / DROP FOREIGN KEY IF EXISTS) — 008/012'deki
-- AYNI gerekçeyle, ikinci kez çalıştırılırsa hata vermeden no-op olmalı.

ALTER TABLE records
    ADD COLUMN IF NOT EXISTS updated_by INT UNSIGNED NULL AFTER updated_at;

ALTER TABLE records DROP FOREIGN KEY IF EXISTS fk_records_updated_by;
ALTER TABLE records ADD CONSTRAINT fk_records_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;
