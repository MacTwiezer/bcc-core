-- İsim benzersizliği — GLOBAL DEĞİL, SCOPE'LU.
--
-- Kural: bir isim yalnızca AİT OLDUĞU ÜST YAPI içinde benzersizdir.
-- "Müşteriler" tablosu Base A'da da Base B'de de olabilir; ama AYNI base
-- içinde iki kez olamaz. Uygulama katmanı bu kontrolü zaten yapıyor
-- (src/schema.php bcc_name_taken()); buradaki indeksler SON SAVUNMA HATTI:
-- ileride bir yazma yolu eklenip kontrol atlanırsa veri yine de bozulmasın.
--
-- Scope'lar veri modelindeki gerçek sahiplik zincirinden alındı:
--   tables_meta -> base_id
--   fields      -> table_id
--   views       -> table_id
--
-- ---------------------------------------------------------------------------
-- bases BİLEREK KAPSAM DIŞI
-- ---------------------------------------------------------------------------
-- bases'te soft-delete var (deleted_at, bkz. migrations/008). UNIQUE(team_id,
-- name) indeksi ÇÖP KUTUSUNDAKİ satırları da sayardı: kullanıcı bir base'i
-- siler, aynı adla yenisini oluşturmak ister ve "zaten var" hatası alırdı —
-- üstelik ortada görünen bir base olmadığı için sebebi anlaşılamazdı.
-- MySQL/MariaDB kısmi (WHERE koşullu) UNIQUE index desteklemediği için bu
-- indeks yazılamıyor. bases için benzersizlik YALNIZCA uygulama katmanında,
-- "AND deleted_at IS NULL" koşuluyla uygulanır (bcc_name_taken()).
--
-- teams.name da kapsam dışı: uq_teams_name (name) GLOBAL kalır ve bu DOĞRU —
-- teams veri modelinin EN ÜST yapısıdır, üstünde scope alınacak bir şey yok.
--
-- ---------------------------------------------------------------------------
-- BÜYÜK/KÜÇÜK HARF
-- ---------------------------------------------------------------------------
-- Kolonlar utf8mb4_unicode_ci ile karşılaştırır: "Users" ile "users" AYNI
-- sayılır ve çakışır. Bilinçli — kullanıcının ayırt edemeyeceği iki isim ayrı
-- sayılmamalı. Uygulama kontrolü de aynı kolonu sorguladığı için iki katman
-- AYNI kuralı uygular, ayrışamaz.
--
-- ---------------------------------------------------------------------------
-- UYGULAMADAN ÖNCE
-- ---------------------------------------------------------------------------
-- Bu indeksler mevcut veride çakışma varsa BAŞARISIZ olur (errno 1062) ve
-- migration yarım kalır. Önce şunu çalıştırıp ÜÇÜNÜN DE 0 döndüğünü doğrulayın:
--
--   SELECT 'tables_meta', COUNT(*) FROM (SELECT base_id,name FROM tables_meta
--     GROUP BY base_id,name HAVING COUNT(*)>1) x
--   UNION ALL SELECT 'fields', COUNT(*) FROM (SELECT table_id,name FROM fields
--     GROUP BY table_id,name HAVING COUNT(*)>1) y
--   UNION ALL SELECT 'views', COUNT(*) FROM (SELECT table_id,name FROM views
--     GROUP BY table_id,name HAVING COUNT(*)>1) z;
--
-- Çakışma çıkarsa VERİ SİLİNMEZ — çakışan isimlerden biri elle yeniden
-- adlandırılır, sonra migration çalıştırılır.
--
-- migrations/015'teki AYNI not: MariaDB'de "ADD UNIQUE KEY IF NOT EXISTS"
-- yoktur ama "ADD UNIQUE INDEX IF NOT EXISTS" vardır — idempotent olan bu biçim
-- kullanılıyor, böylece migration ikinci kez çalıştırılabilir.

ALTER TABLE tables_meta
    ADD UNIQUE INDEX IF NOT EXISTS uq_tables_meta_base_name (base_id, name);

ALTER TABLE fields
    ADD UNIQUE INDEX IF NOT EXISTS uq_fields_table_name (table_id, name);

ALTER TABLE views
    ADD UNIQUE INDEX IF NOT EXISTS uq_views_table_name (table_id, name);
