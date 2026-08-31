-- 023 — Herkese açık FORM görünümü özelliğinin kaldırılması.
--
-- NEDEN: ürün kararı (kullanıcı bildirdi) — "bizim kullanıcılarımızın zaten
-- kayıtlı hesabı olacak". Dışarıdan, hesabı olmayan kişilerden kayıt toplama
-- ihtiyacı yok. Özellik kullanılmıyordu: canlı veride dört form görünümü vardı
-- ve DÖRDÜNDE DE seçili alan sayısı SIFIRDI (yani biri linki açsa doldurulacak
-- hiçbir kutu göremezdi); ayrıca form linkini kopyalatan arayüz kartı daha
-- önce kaldırılmış olduğu için adres hiçbir ekranda gösterilmiyordu.
--
-- ⚠️ BU MIGRATION migrations/015_views_form_token.sql'İ GERİ ALIR. 015 dosyası
-- SİLİNMEDİ ve silinmemeli: migration geçmişi, sıfırdan kurulan bir veritabanının
-- da aynı adımlardan geçebilmesi için olduğu gibi durur. schema.sql (tek seferde
-- kurulan güncel şema) ise bu kolonları ARTIK İÇERMEZ.
--
-- KALDIRILAN KOD (bu migration'la BİRLİKTE gitmeli, yoksa uygulama patlar):
--   public/form.php                 herkese açık doldurma sayfası
--   public/form_edit.php            tasarımcı ekranı
--   public/api/form_submit.php      anonim gönderim uç noktası
--   src/form_security.php           honeypot + HMAC nonce koruması
--   public/assets/form-edit.{css,js}, public/assets/public-form.{css,js}
--   src/schema.php                  BCC_VIEW_TYPES['form'], BCC_VIEW_ROUTES['form'],
--                                   bcc_field_allowed_in_form(),
--                                   bcc_form_config_from_view(),
--                                   görünüm kopyalamadaki form_token/form_enabled dalı,
--                                   görünüm SELECT listelerindeki iki kolon
--   public/api/view_create.php      form_token üretimi
--
-- GÜVENLİK YAN FAYDASI: form_submit.php projedeki TEK kimlik doğrulamasız yazma
-- yoluydu (src/form_security.php başlığındaki nota bakınız). Bu turla birlikte
-- uygulamada auth'suz hiçbir yazma uç noktası kalmıyor.
--
-- SIRA ÖNEMLİ: önce satırlar, sonra kolonlar. Kolonlar önce düşürülseydi
-- silinecek satırları view_type dışında ayırt edecek bir işaret kalmazdı
-- (view_type ENUM değil, serbest VARCHAR — bkz. schema.sql).
--
-- VERİ KAYBI: view_type='form' olan satırlar SİLİNİR. Bu satırların taşıdığı tek
-- benzersiz veri form başlığı/açıklaması ve seçili alan listesiydi; dördünde de
-- alan listesi boştu. Kayıtlara (records/cell_values) DOKUNULMAZ — form
-- görünümü yalnızca bir "görünüm", verinin kendisi tabloda durur.

DELETE FROM views WHERE view_type = 'form';

ALTER TABLE views
    DROP INDEX uq_views_form_token,
    DROP COLUMN form_token,
    DROP COLUMN form_enabled;
