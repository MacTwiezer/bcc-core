<?php
// CSV enjeksiyonuna (Excel/Sheets formül yorumlaması) karşı ortak koruma —
// view_export_csv.php'de bulunan gerçek güvenlik açığının düzeltmesiydi: bir
// hücre '=', '+', '-', '@' ile BAŞLIYORSA Excel/Sheets bunu açılışta formül
// sanıp çalıştırır. OWASP'ın önerdiği standart savunma: bu karakterlerle
// başlayan değerlerin başına tek tırnak eklenir, Excel'de literal metin olarak
// kalır. Her CSV export uçnoktası (view_export_csv.php, team_members_export_csv.php)
// AYNI fonksiyonu kullanır — kopya YOK.
function bcc_csv_injection_guard($value)
{
    $value = (string) $value;

    return ($value !== '' && preg_match('/^[=+\-@]/', $value) === 1) ? ("'" . $value) : $value;
}
