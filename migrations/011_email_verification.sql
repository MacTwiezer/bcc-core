-- Kayıt akışı artık e-posta doğrulamalı: register.php şifreyi hemen almıyor,
-- kullanıcı e-postasına gelen tek kullanımlık token ile /verify_email.php
-- üzerinden kendi şifresini oluşturuyor. Token bu iki kolonda tutuluyor;
-- doğrulama tamamlanınca (veya süresi dolunca yeniden gönderilince) NULL'a
-- döner, tekrar kullanılamaz.
-- IF NOT EXISTS: migrations/002'deki AYNI düzeltme — bu dosya yanlışlıkla
-- ikinci kez çalıştırılırsa hata vermeden no-op olur.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS email_verify_token VARCHAR(64) NULL AFTER is_active,
    ADD COLUMN IF NOT EXISTS email_verify_expires_at DATETIME NULL AFTER email_verify_token;

CREATE INDEX IF NOT EXISTS idx_users_email_verify_token ON users (email_verify_token);
