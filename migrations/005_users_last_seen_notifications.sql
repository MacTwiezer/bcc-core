-- users.last_seen_notifications_at kolonu.
-- Neden: Bildirim paneli (zil ikonu) — onaylanan "basit" model: yeni bir
-- notifications tablosu YOK (fan-out INSERT gerektirmez), audit_log salt-okunur
-- gösterilir (team_id ile KVKK-filtreli + sabit action whitelist'i). "Unread" =
-- bu kolondan SONRAKİ audit_log satırları; "Read" = bu kolondan ÖNCEKİLER.
-- "Mark all as read" bu tek kolonu NOW()'a çeker. NULL = kullanıcı paneli hiç
-- açmamış → o takımlardaki TÜM whitelist olayları "unread" sayılır.

ALTER TABLE users ADD COLUMN last_seen_notifications_at DATETIME NULL AFTER is_active;
