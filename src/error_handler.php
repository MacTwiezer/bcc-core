<?php
// Genel (uncaught) exception yakalayıcı — config/database.php'nin
// mysqli_report(MYSQLI_REPORT_ERROR) ile AÇTIĞI hataların (ve yakalanmamış her
// türlü Throwable'ın) kullanıcıya ham PHP stack trace + SQL sorgu metni olarak
// sızmasını önler. Teknik detay yalnızca sunucu error log'una yazılır; tarayıcıya
// sade bir mesaj döner. public/api/*.php uç noktaları zaten kendi try/catch'leriyle
// json_fail() kullanıyor — bu yakalayıcı yalnızca ONLARIN DIŞINDA kalan, hiçbir
// yerde yakalanmamış hatalar için son güvenlik ağı.
//
// Bulunan gerçek bug: bu dosya yalnızca YAKALANMAMIŞ EXCEPTION'LARI ele alıyordu
// — PHP'nin klasik notice/warning/deprecated mesajları (ör. "Undefined variable",
// "Undefined array key") bambaşka bir mekanizma ve hiç yakalanmıyordu. Canlı
// test ile doğrulandı: php.ini'de display_errors etkin olduğu için basit bir
// notice bile tarayıcıya DOĞRUDAN "Notice: ... in <DocumentRoot>\src\... on
// line N" biçiminde, TAM SUNUCU DOSYA YOLUYLA birlikte basılıyordu — tam da bu
// dosyanın önlemeyi amaçladığı bilgi sızıntısının (dosya yolu + iç kod yapısı)
// aynısı, farklı bir hata sınıfından. log_errors zaten açık (hatalar sunucu
// log'una yine düşüyor), yalnızca TARAYICIYA basılması kapatılıyor.
ini_set('display_errors', '0');

set_exception_handler(function (Throwable $e) {
    error_log('Yakalanmamış hata: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ")\n" . $e->getTraceAsString());

    if (!headers_sent()) {
        http_response_code(500);
    }

    $isApiRequest = isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/') !== false;

    if ($isApiRequest) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array('ok' => false, 'error' => 'Sunucu hatası. Lütfen tekrar deneyin.'), JSON_UNESCAPED_UNICODE);

        return;
    }

    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><title>Hata</title></head>'
        . '<body style="font-family:sans-serif;max-width:32rem;margin:4rem auto;text-align:center;color:#333;">'
        . '<h1 style="font-size:1.2rem;">Bir şeyler ters gitti</h1>'
        . '<p>Sayfa yüklenirken beklenmeyen bir hata oluştu. Lütfen tekrar deneyin.</p>'
        . '</body></html>';
});
