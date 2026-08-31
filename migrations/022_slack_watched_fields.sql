-- 022 — Hücre DEĞİŞİKLİĞİNDE Slack bildirimi: hangi alanların izleneceği.
--
-- NEDEN: bugüne kadar Slack'e mesaj atan üç olay vardı — yeni kayıt, yeni tablo,
-- yeni alan (bkz. src/slack.php). Var olan bir satırda bir hücreyi değiştirmek
-- (public/api/cell_update.php) HİÇBİR bildirim üretmiyordu; kullanıcı "Durum"u
-- "Kazanildi" yapıp Slack'i bekledi, hiçbir şey gelmedi (kullanıcı bildirdi).
--
-- NEDEN "TÜM HÜCRELER" DEĞİL: bu tablo olmadan tek seçenek "her hücre
-- değişikliğinde gönder" olurdu. Canlı veride ölçüldü: Musteriler tablosunda
-- uzun metin (Notlar) ve sayı (Butce) alanları var, 147 kayıtlı tablolar da
-- mevcut — biri not yazarken her kaydetmede kanala mesaj düşerdi ve toplu
-- yapıştırma tek hamlede onlarca mesaj üretirdi. Kullanıcı "seçtiğim alanlar"
-- seçeneğini onayladı: yalnızca burada işaretlenen alanlar bildirim üretir.
--
-- BOŞ TABLO = ÖZELLİK KAPALI. Hiçbir satır yoksa hiçbir alan izlenmiyordur,
-- yani MEVCUT DAVRANIŞ AYNEN KORUNUR — var olan hiçbir tablo/kanal bu
-- migration'dan etkilenmez. Özellik satır eklendikçe, tablo tablo açılır.
--
-- DESEN YENİ DEĞİL: slack_routing_rules (bkz. yukarısı) ile BİREBİR aynı
-- iskelet — (team_id, table_id, field_id) üçlüsü + hepsinde ON DELETE CASCADE.
-- Yeni bir kavram icat edilmedi. Fark yalnızca operator/value/webhook_id
-- kolonlarının OLMAMASI: burada bir koşul yok, sadece "bu alan izleniyor mu"
-- bilgisi var. Mesajın hangi kanala gideceğine yine bcc_find_slack_webhook()
-- karar verir (kural → tablo-özel → ekip-geneli), yani ikinci bir yönlendirme
-- mekanizması YAZILMADI.
--
-- UNIQUE(table_id, field_id): aynı alan iki kez izlenemez — form aynı alanı
-- iki kez POST etse (bayat sayfa, elle istek) bile ikinci satır DB tarafından
-- reddedilir, yani "iki kez bildirim" durumu şemada imkânsız.
--
-- team_id KOLONU field_id'den TÜRETİLEBİLİR olmasına rağmen duruyor:
-- slack_routing_rules ve slack_webhooks'ta da aynısı var ve KVKK süzgeçleri
-- (team_id IN (...)) bu kolona bakıyor; JOIN zinciri kurmadan ekip bazlı
-- sorgulanabilmesi için tutarlılık adına korundu.
--
-- TİPLER: teams.id / tables_meta.id / fields.id ÜÇÜ DE INT UNSIGNED
-- (ölçüldü: SHOW COLUMNS) — FK'ler hedef kolonlarla birebir aynı tipte,
-- yoksa MariaDB kısıtı reddeder.
--
-- Ayrı bir table_id index'i YOK: UNIQUE(table_id, field_id) zaten table_id ile
-- BAŞLADIĞI için "bu tablonun izlenen alanları" sorgusu (tek çağrı yeri:
-- bcc_slack_watched_field_ids) bu bileşik anahtarın soldan önekini kullanır —
-- migrations/009 ve 021'de AYNI gerekçeyle gereksiz index'ler eklenmemişti.

CREATE TABLE IF NOT EXISTS slack_watched_fields (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    team_id     INT UNSIGNED NOT NULL,
    table_id    INT UNSIGNED NOT NULL,
    field_id    INT UNSIGNED NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_slack_watched_fields (table_id, field_id),
    KEY idx_slack_watched_fields_team (team_id),
    KEY idx_slack_watched_fields_field (field_id),
    CONSTRAINT fk_slack_watched_fields_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    CONSTRAINT fk_slack_watched_fields_table FOREIGN KEY (table_id) REFERENCES tables_meta(id) ON DELETE CASCADE,
    CONSTRAINT fk_slack_watched_fields_field FOREIGN KEY (field_id) REFERENCES fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
