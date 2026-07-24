-- audit_log.entity_type + entity_id (+ action) bileşik index'i.
-- Neden: dashboard.php tarih filtresi (src/audit.php log_base_open()) her açılışta
-- action='base.open' AND entity_type='base' AND entity_id=:id ile audit_log'u tarıyor;
-- şu an bu kolonlarda index yok (yalnızca team_id/user_id indeksli). Bkz. YAPILACAKLAR-UI.md #1.

ALTER TABLE audit_log ADD INDEX idx_audit_log_entity (entity_type, entity_id, action);
