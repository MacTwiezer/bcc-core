<?php
// Ortak form doğrulama kuralları — e-posta format kontrolü ve şifre uzunluk
// kuralı önceden 4'er ayrı dosyada birebir tekrarlanıyordu (register.php,
// admin/create_user.php, scripts/create_admin.php, api/account_update_*.php).
// Kural (davranış) BİREBİR AYNI kaldı, yalnızca tek yerden geliyor.

function bcc_is_valid_email($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Bulunan gerçek bug: üst sınır hiç yoktu. PHP'nin password_hash()/password_verify()'ı
// (bcrypt) girdiyi SESSİZCE 72 BAYT'ta kırpar — canlı test ile doğrulandı: ilk 72
// baytı aynı, sonrası TAMAMEN FARKLI iki "şifre" password_verify()'da eşleşiyor
// (true dönüyor). Üst sınır olmadan bir kullanıcı 72 bayttan uzun bir şifre
// belirleyip "uzun, güvenli" sandığı şifresinin aslında yalnızca ilk 72 baytının
// önemli olduğunu (geri kalanı sessizce yok sayıldığını) hiç fark etmezdi.
function bcc_is_valid_password($password)
{
    $len = strlen($password);

    return $len >= 8 && $len <= 72;
}
