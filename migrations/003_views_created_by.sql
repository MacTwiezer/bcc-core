-- views.created_by kolonu.
-- Neden: görünüm bilgi popover'ında "Created by" satırı gösterilmek isteniyor
-- (grid.php gs-view-info-popover), ama views tablosunda oluşturan kullanıcı bilgisi
-- yoktu. Bkz. YAPILACAKLAR-UI.md #1. bcc_get_or_create_default_view() (src/schema.php)
-- yeni satır oluştururken bu kolonu oturumdaki kullanıcıyla doldurur; eski satırlarda
-- NULL kalır (popover satırı bu durumda gösterilmez, uydurma veri yok).

ALTER TABLE views ADD COLUMN created_by INT UNSIGNED NULL AFTER config;
ALTER TABLE views ADD CONSTRAINT fk_views_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
