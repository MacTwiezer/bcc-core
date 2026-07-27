<?php
// Ortak form doğrulama kuralları — e-posta format kontrolü ve şifre uzunluk
// kuralı önceden 4'er ayrı dosyada birebir tekrarlanıyordu (register.php,
// admin/create_user.php, scripts/create_admin.php, api/account_update_*.php).
// Kural (davranış) BİREBİR AYNI kaldı, yalnızca tek yerden geliyor.

function bcc_is_valid_email($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function bcc_is_valid_password($password)
{
    return strlen($password) >= 8;
}
