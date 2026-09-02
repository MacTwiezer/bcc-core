-- 024 — Başarısız giriş denemelerinin kaydı (kaba kuvvet freni).
--
-- NEDEN: login.php'de bugüne kadar HİÇBİR deneme sınırı yoktu. Tek doğal fren
-- bcrypt'in maliyetiydi — bu makinede ölçüldü: başarısız bir deneme ~55 ms
-- (src/auth.php:340'taki maliyet-10 hash sayesinde, e-posta var olsa da
-- olmasa da AYNI). 55 ms, saniyede ~18, günde ~1,5 milyon deneme demektir.
-- Zayıf bir parola için bu fren yeterli değil.
--
-- TASARIM — İKİ AYRI KURAL, İKİ AYRI SALDIRIYA KARŞI:
--   (ip, e-posta) → 5 hata / 15 dk : TEK bir hesabı deneme yanılma ile kırmayı
--                                    durdurur.
--   (ip)          → 20 hata / 15 dk: AYNI kaynaktan ÇOK hesabı taramayı
--                                    (credential stuffing) durdurur.
-- Eşikler src/auth.php'de sabit olarak tanımlı (BCC_LOGIN_*), burada değil —
-- eşik değiştirmek için migration çalıştırmak gerekmesin.
--
-- ⚠️ BİLEREK YOK: "yalnızca e-postaya göre" global kilit. Öyle olsaydı bir
-- saldırgan, hedefinin adresine 5 yanlış parola göndererek O KİŞİYİ sistemden
-- kilitleyebilirdi (hizmet engelleme / DoS). Anahtarın içinde IP'nin olması
-- kilidi saldırganın kendi kaynağına hapseder; kurban başka bir ağdan girebilir.
--
-- ⚠️ ip NEDEN VARBINARY(16), VARCHAR DEĞİL: inet_pton() çıktısı saklanıyor —
-- IPv4 için 4, IPv6 için 16 bayt. Metin olarak saklamak aynı adresin iki farklı
-- yazımını ("::1" ve "0:0:0:0:0:0:0:1") FARKLI iki anahtar yapardı ve sınır
-- sessizce ikiye katlanırdı.
--
-- email VARCHAR(190): users.email ile BİREBİR aynı (bkz. schema.sql:29).
-- Kasıtlı olarak users(id)'ye FK DEĞİL — var OLMAYAN e-postalara yapılan
-- denemeler de sayılmak zorunda, yoksa saldırgan var olmayan bir adresle
-- sınırsız deneme yapıp sınırın nasıl davrandığını ölçebilirdi.
--
-- BÜYÜME: satırlar kalıcı değil. src/auth.php başarılı girişte o (ip,e-posta)
-- çiftinin satırlarını siler, ayrıca her ~50 kayıtta bir pencere dışındaki
-- TÜM satırları temizler. Ayrı bir cron gerekmez.

CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip           VARBINARY(16) NOT NULL,
    email        VARCHAR(190) NOT NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    -- Sorguların İKİSİ de "anahtar + zaman aralığı" biçiminde; bileşik
    -- index'ler tam olarak o sırayla tanımlı. idx_..._ip ayrıca (ip, e-posta)
    -- sorgusuna soldan önek olarak da hizmet edebilirdi ama e-posta araya
    -- girdiği için AYRI index gerekiyor.
    KEY idx_login_attempts_ip_email (ip, email, attempted_at),
    KEY idx_login_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
