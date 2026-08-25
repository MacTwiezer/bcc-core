-- 021 — Bildirimleri TEK TEK "okundu" işaretleyebilme.
--
-- NEDEN: bugüne kadar okundu/okunmadı TEK bir zaman damgasından türetiliyordu
-- (users.last_seen_notifications_at, bkz. migrations/005): "bu andan sonraki
-- audit_log satırları okunmamış". Bu model "hepsini okundu yap" için yeterliydi
-- ama TEK bir bildirimi okundu yapmayı imkânsız kılıyor — aradaki bir satırı
-- işaretlemenin damgayla ifadesi yok.
--
-- DESEN YENİ DEĞİL: user_starred_bases (migrations/004) ile BİREBİR aynı —
-- kullanıcı ↔ kayıt arası "işaretledim" ilişkisi, UNIQUE(user_id, hedef_id) +
-- iki yönlü ON DELETE CASCADE. Yeni bir kavram icat edilmedi.
--
-- MEVCUT DAVRANIŞ BOZULMAZ: tablo BOŞ başlar, yani hiçbir satır "okundu"
-- değildir ve okunmuş/okunmamış ayrımı bugünkü gibi yalnızca damgadan gelir.
-- Yeni kural EKLEMELİ:
--   okunmamış = (created_at > last_seen_notifications_at) VE bu tabloda YOK
-- "Tümünü okundu işaretle" damgayı NOW()'a çekmeye devam eder — o yol
-- değişmedi, bu tablo yalnızca tek tek işaretlemeyi taşır.
--
-- TİPLER: audit_log.id BIGINT UNSIGNED (ölçüldü: SHOW COLUMNS), users.id ise
-- INT UNSIGNED — FK'ler hedef kolonlarla birebir aynı tipte, yoksa MariaDB
-- kısıtı reddeder.
--
-- ⚠️ ON DELETE CASCADE audit_log tarafında da BİLEREK var: audit_log write-only
-- bir geçmiş tablosu ve bugün hiçbir yerden silinmiyor, ama ileride
-- arşivlenirse burada öksüz satır kalmasın (öksüz bir okundu kaydı, artık var
-- olmayan bir bildirimi "okundu" saymak demekti — zararsız ama çöp).
--
-- Ayrı bir user_id index'i YOK: UNIQUE(user_id, audit_log_id) zaten user_id ile
-- BAŞLADIĞI için tekil user_id sorguları bu bileşik anahtarın soldan önekini
-- kullanır (migrations/009'da AYNI gerekçeyle gereksiz index'ler kaldırılmıştı).

CREATE TABLE IF NOT EXISTS user_read_notifications (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    audit_log_id  BIGINT UNSIGNED NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_read_notifications (user_id, audit_log_id),
    CONSTRAINT fk_user_read_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_read_notifications_audit FOREIGN KEY (audit_log_id) REFERENCES audit_log(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
