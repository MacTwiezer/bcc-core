-- 020 — Base ikonu + ikon rengi kullanıcı tarafından seçilebilir olsun.
--
-- NEDEN: bugüne kadar kart ikonu base'in ADINDAN türetiliyordu
-- (bcc_base_icon_category), rengi ise base'in ID'sinden (bcc_base_icon_theme).
-- İkisi de deterministikti ama kullanıcı seçemiyordu; "Satış CRM" adını
-- taşımayan bir müşteri tablosu veritabanı glifiyle kalıyordu.
--
-- İKİSİ DE NULL OLABİLİR ve varsayılan NULL'dır — bu KASITLI:
--   icon       NULL -> eski davranış, ad'dan türetilir (bcc_base_icon_category)
--   icon_color NULL -> eski davranış, id'den türetilir (bcc_base_icon_theme)
-- Yani mevcut 10 base'in HİÇBİRİNİN görünümü değişmez; kolonlar yalnızca
-- kullanıcı açıkça bir seçim yaptığında dolar. Geri alma da bu yüzden
-- zararsız: kolonlar düşürülse eski türetme kuralı zaten yerinde.
--
-- TİP SEÇİMLERİ:
--   icon VARCHAR(20)         — $GLOBALS['BCC_BASE_ICON_PATHS'] anahtarı
--                              ('database', 'flask', 'users', ...). Ham SVG
--                              DEĞİL: çizim yolları kodda tek yerde durmalı,
--                              DB'ye SVG yazmak hem XSS yüzeyi hem de ikon
--                              setini güncellenemez hâle getirirdi. En uzun
--                              anahtar 8 karakter; 20 rahat pay.
--   icon_color TINYINT UNSIGNED — $GLOBALS['BCC_BASE_ICON_THEMES'] dizisinin
--                              İNDEKSİ (bugün 0-5, palet büyüyebilir). Ham hex
--                              DEĞİL: palet değişirse
--                              (koyu tema karşılıkları dahil) tek yerden
--                              güncellenebilsin, DB'de donmuş renk kalmasın.
-- Geçersiz/bilinmeyen değerler sunucuda whitelist'ten geçirilir
-- (bcc_create_base), DB'ye asla yazılmaz — bu yüzden CHECK constraint
-- eklenmedi (MariaDB 10.4'te ENUM da olurdu ama ikon seti büyüdükçe her
-- eklemede DDL gerekirdi).
--
-- Index YOK: bu kolonlar hiçbir WHERE/ORDER BY'da kullanılmıyor, yalnızca
-- satırla birlikte okunuyor.

ALTER TABLE bases
    ADD COLUMN icon VARCHAR(20) NULL AFTER description,
    ADD COLUMN icon_color TINYINT UNSIGNED NULL AFTER icon;
