-- Şifremi unuttum akışı: public/forgot-password.php token üretir,
-- public/reset-password.php tüketir. Token tek kullanımlıktır — şifre
-- güncellendiği anda NULL'a döner.
--
-- ===========================================================================
-- users.password_reset_token — TOKEN'IN KENDİSİ DEĞİL, SHA-256 ÖZETİ
-- ===========================================================================
-- Kolon adı "token" olsa da içinde HAM TOKEN DURMAZ: forgot-password.php
-- kullanıcıya 64 karakterlik hex bir sır gönderir ve buraya yalnızca onun
-- hash('sha256', $rawToken) özetini yazar.
--
-- Neden: bir veritabanı yedeği, bir SQL enjeksiyonu ya da salt-okunur bir
-- rapor erişimi sızarsa, ham token saklansaydı o an bekleyen TÜM sıfırlama
-- linkleri doğrudan kullanılabilir hâle gelirdi (= hesap ele geçirme).
-- Özet sızarsa saldırganın elinde işe yaramaz bir hash olur.
--
-- Neden password_hash() DEĞİL de düz sha256:
--   * password_hash() her çağrıda RASTGELE tuz üretir -> aynı token her
--     seferinde farklı çıktı verir -> WHERE ile ARANAMAZ, index kullanılamaz.
--   * password_hash()'in yavaşlığı DÜŞÜK entropili insan şifrelerini kaba
--     kuvvetten korumak içindir. Bu token 256 bit CSPRNG çıktısı — kaba kuvvet
--     zaten imkânsız, yavaşlatmanın koruyacağı bir zayıflık yok.
--
-- (Kolonun adı içeriğini tam yansıtmıyor; `password_reset_token_hash` daha
-- dürüst olurdu. Kolon CANLI VERİTABANINDA bu adla zaten oluşturulduğu için
-- kod da bu ada göre yazıldı. Yeniden adlandırmak istenirse:
--     ALTER TABLE users CHANGE password_reset_token
--         password_reset_token_hash CHAR(64)
--         CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL;
--  ve forgot-password.php + reset-password.php'deki kolon adı güncellenmeli.)
--
-- Neden 011'deki email_verify_* kolonları tekrar kullanılmadı: o çift kayıt
-- akışının (is_active 0 -> 1) durumunu taşıyor. Paylaşılsalardı, doğrulama
-- bekleyen bir kullanıcı şifre sıfırlama istediğinde iki akıştan biri
-- diğerinin token'ını sessizce ezerdi.
--
-- FOREIGN KEY YOK: token bir referans değil, bir sır (014/015 ile aynı).
--
-- IF NOT EXISTS — 008/011/012/013/014/015 ile AYNI gerekçe: projede hangi
-- migration'ın uygulandığını takip eden bir mekanizma YOK, bu yüzden dosya
-- ikinci kez çalıştırılırsa hata vermeden no-op olmalı. (Bu dosyanın ilk
-- sürümünde IF NOT EXISTS yoktu ve ikinci çalıştırma "Duplicate column name"
-- ile YARIDA KESİLİYORDU — kolon adı değiştirilip yeniden çalıştırıldığında
-- yeni kolon hiç oluşmuyor, eski kolon yerinde kalıyordu.)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS password_reset_token VARCHAR(64) DEFAULT NULL AFTER email_verify_expires_at,
    ADD COLUMN IF NOT EXISTS password_reset_expires_at DATETIME NULL AFTER password_reset_token;

-- reset-password.php her açılışta WHERE password_reset_token = ? ile satır
-- arıyor; index olmadan bu, users tablosunun tamamını tarardı.
-- UNIQUE değil düz KEY: 011'deki idx_users_email_verify_token ile aynı çizgi
-- (NULL'lar çakışmaz, CSPRNG çakışması pratikte imkânsız).
CREATE INDEX IF NOT EXISTS idx_users_password_reset_token ON users (password_reset_token);

-- ===========================================================================
-- password_reset_attempts — IP BAZLI HIZ SINIRI
-- ===========================================================================
-- Neden YENİ BİR TABLO: tüm depoda REMOTE_ADDR başka hiçbir yerde geçmiyor —
-- istemci IP'si bugüne kadar hiçbir yere kaydedilmiyordu. audit_log'da da IP
-- kolonu yok (schema.sql:351-369). Türetilebilecek mevcut bir veri OLMADIĞI
-- için bu tablo gerçekten yeni bilgi taşıyor.
--
-- Neden users'a kolon olarak eklenemez: sınır KULLANICI başına değil, İSTEK
-- KAYNAĞI başına. Denenen adreslerin çoğu zaten sistemde yok — ilişkilendirilecek
-- bir users satırı bile bulunmuyor.
--
-- ip_address VARCHAR(45): IPv6'nın en uzun metin gösterimi (IPv4-mapped dahil).
--
-- attempted_at'in DEFAULT'u YOK: değer forgot-password.php'den açıkça
-- yazılıyor, çünkü pencere hesabı da (NOW() değil) PHP'nin saatiyle yapılıyor —
-- iki taraf aynı saat kaynağını kullansın.
--
-- KVKK notu: IP kişisel veridir. Bu yüzden burada SADECE ip + zaman tutuluyor
-- (e-posta, kullanıcı id'si, User-Agent YOK) ve satırlar pencere dolar dolmaz
-- forgot-password.php tarafından siliniyor — kalıcı bir ziyaretçi günlüğü
-- DEĞİL, yalnızca birkaç saatlik bir sayaç.
CREATE TABLE IF NOT EXISTS password_reset_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    -- Sorgu HER ZAMAN (ip_address, attempted_at) ikilisiyle geliyor: bileşik
    -- index hem sayımı hem temizliği tek taramada bitirir.
    INDEX idx_ip_attempt (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
