-- views.description + views.position kolonları.
-- Neden: Görünüm seçenekleri menüsü (grid.php .gs-view-options-menu) — "Edit
-- view description" bir açıklama metni kaydedecek (description); sol Views
-- paneli artık birden fazla view'ı listeleyip yukarı/aşağı taşımayı
-- destekleyecek (position) — mevcut fields.position/tables_meta.position ile
-- AYNI desen (INT NOT NULL DEFAULT 0). Yeni satırlar 0 ile başlar, uygulama
-- tarafı (view oluşturma/duplicate) mevcut en yüksek position+1'i atar.

ALTER TABLE views ADD COLUMN description VARCHAR(500) NULL AFTER name;
ALTER TABLE views ADD COLUMN position INT NOT NULL DEFAULT 0 AFTER view_type;
