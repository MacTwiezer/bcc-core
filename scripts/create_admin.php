<?php
// Tek seferlik ilk admin oluşturma betiği.
// Çalıştırma: C:\php73\php.exe scripts\create_admin.php
// Zaten bir admin (is_admin=1) varsa çalışmayı reddeder.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnızca komut satırından çalıştırılabilir.\n");
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/validation.php';

function prompt($label)
{
    echo $label;

    return trim((string) fgets(STDIN));
}

$existing = bcc_fetch_one('SELECT COUNT(*) AS c FROM users WHERE is_admin = 1');
if ((int) $existing['c'] > 0) {
    fwrite(STDERR, "HATA: Zaten bir admin kullanıcı mevcut. Bu betik yalnızca ilk admin için kullanılabilir.\n");
    exit(1);
}

$email = prompt('Admin e-posta: ');
$fullName = prompt('Ad Soyad: ');
$password = prompt('Şifre (en az 8 karakter): ');
$passwordConfirm = prompt('Şifre (tekrar): ');

if (!bcc_is_valid_email($email)) {
    fwrite(STDERR, "HATA: Geçersiz e-posta.\n");
    exit(1);
}

if (mb_strlen($email, 'UTF-8') > 190) {
    // users.email VARCHAR(190) — public/register.php/admin/create_user.php ile
    // AYNI kontrol; bu betik atlanmıştı. sql_mode'da STRICT_TRANS_TABLES yok,
    // bu kontrol olmadan uzun bir e-posta hatasız sessizce kırpılır — ilk admin
    // hesabı yanlışlıkla farklı (kırpılmış) bir e-postayla oluşturulmuş olur.
    fwrite(STDERR, "HATA: E-posta en fazla 190 karakter olabilir.\n");
    exit(1);
}

if ($fullName === '') {
    fwrite(STDERR, "HATA: Ad Soyad boş olamaz.\n");
    exit(1);
}

if (mb_strlen($fullName, 'UTF-8') > 150) {
    // users.full_name VARCHAR(150) — aynı gerekçe.
    fwrite(STDERR, "HATA: Ad Soyad en fazla 150 karakter olabilir.\n");
    exit(1);
}

if (!bcc_is_valid_password($password)) {
    fwrite(STDERR, "HATA: Şifre 8-72 karakter arasında olmalı.\n");
    exit(1);
}

if ($password !== $passwordConfirm) {
    fwrite(STDERR, "HATA: Şifreler eşleşmiyor.\n");
    exit(1);
}

$existingEmail = bcc_fetch_one('SELECT id FROM users WHERE email = :email', array(':email' => $email));
if ($existingEmail) {
    fwrite(STDERR, "HATA: Bu e-posta zaten kayıtlı.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

bcc_execute(
    'INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:email, :hash, :full_name, 1, 1)',
    array(
        ':email' => $email,
        ':hash' => $hash,
        ':full_name' => $fullName,
    )
);

echo "Admin kullanıcı oluşturuldu: {$email} (id=" . bcc_last_insert_id() . ")\n";
