-- views.is_published: hiç okunmayan/yazılmayan ölü kolon (kod taramasıyla
-- doğrulandı — hiçbir INSERT/SELECT/UPDATE bu kolonu kullanmıyor).
-- IF EXISTS: migration'ların hangisinin uygulandığını takip eden ayrı bir
-- mekanizma yok (elle/hafızayla yönetiliyor, bkz. migrations/002'deki AYNI
-- düzeltme) — bu dosya yanlışlıkla ikinci kez çalıştırılırsa hata vermeden
-- sessizce no-op olur.
ALTER TABLE views DROP COLUMN IF EXISTS is_published;

-- idx_user_starred_bases_user / idx_user_favorite_views_user: gereksiz —
-- aynı tablodaki UNIQUE bileşik anahtar zaten user_id ile BAŞLADIĞI için
-- (uq_user_starred_bases(user_id, base_id), uq_user_favorite_views(user_id, view_id))
-- tekil user_id sorguları için ayrı bir index MySQL'e hiçbir ek fayda sağlamıyor,
-- yalnızca yazma/depolama maliyeti ekliyor.
ALTER TABLE user_starred_bases DROP INDEX IF EXISTS idx_user_starred_bases_user;
ALTER TABLE user_favorite_views DROP INDEX IF EXISTS idx_user_favorite_views_user;
