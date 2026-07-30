-- audit_log.entity_type + entity_id (+ action) bileşik index'i.
-- Neden: dashboard.php tarih filtresi (src/audit.php log_base_open()) her açılışta
-- action='base.open' AND entity_type='base' AND entity_id=:id ile audit_log'u tarıyor;
-- şu an bu kolonlarda index yok (yalnızca team_id/user_id indeksli). Bkz.
-- docs/PROJE-DURUM.md "Biten İşler" → Dashboard tarih filtresi.
-- IF NOT EXISTS: migration'ların hangisinin uygulandığını takip eden ayrı bir
-- mekanizma yok (elle/hafızayla yönetiliyor) — bu dosya yanlışlıkla ikinci kez
-- çalıştırılırsa "Duplicate key name" ile patlamak yerine sessizce no-op olur.

ALTER TABLE audit_log ADD INDEX IF NOT EXISTS idx_audit_log_entity (entity_type, entity_id, action);
