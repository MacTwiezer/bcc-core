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

// Olumcul hatalarda son cikis icin AYRILMIS bellek. Bellek limiti dolunca
// kapanis isleyicisinin KENDISI de bellek isteyemez (json_encode/echo dahil);
// bu tampon serbest birakilinca isleyiciye calisacak kadar alan kalir.
$GLOBALS['BCC_FATAL_RESERVE'] = str_repeat(' ', 256 * 1024);

// İstek public/api/ altındaki bir uçnoktaya mı geldi? Hata yollarının HTML mi
// JSON mu döneceğini bu belirler (bkz. src/errors.php'deki "API'ye HTML,
// sayfaya JSON dönmemeli" kuralı). SCRIPT_NAME çalışan dosyanın yolu — sorgu
// dizesinde "/api/" geçen bir SAYFA isteğini yanlışlıkla API sanmaz.
function bcc_is_api_request()
{
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';

    return strpos($script, '/api/') !== false;
}

// Argümansız yığın izi. getTraceAsString() çağrı argümanlarını da basar ve
// mysqli_connect() başarısız olduğunda DB şifresi düz metin olarak log'a düşer.
function bcc_safe_trace(Throwable $e)
{
    $lines = array();

    foreach ($e->getTrace() as $i => $frame) {
        $where = isset($frame['file'])
            ? $frame['file'] . ':' . (isset($frame['line']) ? $frame['line'] : '?')
            : '[internal]';
        $call = (isset($frame['class']) ? $frame['class'] . $frame['type'] : '')
            . (isset($frame['function']) ? $frame['function'] : '?') . '()';

        $lines[] = '#' . $i . ' ' . $where . ' ' . $call;
    }

    return implode("\n", $lines);
}

set_exception_handler(function (Throwable $e) {
    error_log('Yakalanmamış hata: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ")\n" . bcc_safe_trace($e));

    if (!headers_sent()) {
        http_response_code(500);
    }

    if (bcc_is_api_request()) {
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

// SON GUVENLIK AGI — set_exception_handler'in ULASAMADIGI hatalar icin.
//
// Bulunan gercek bosluk (olculdu): yakalayici yalnizca Throwable goruyor.
// PHP 7'de "tanimsiz fonksiyon" gibi eski olumcul hatalar Error'a donustugu
// icin onlar yakalaniyor, ama GERCEK E_ERROR'lar (bellek limiti, azami
// calisma suresi, derleme hatasi) Throwable DEGIL. O durumda display_errors
// kapali oldugu icin kullanici hicbir mesaj gormuyor: sonda ile olculdu,
// bellek tukenmesinde yanit 14 bayt kaldi ve sayfa yarida kesildi.
//
// display_errors KAPALI KALIYOR (dosya yolu sizmasin); teknik detay yalnizca
// sunucu gunlugune yaziliyor, kullaniciya sade bir mesaj gidiyor.
register_shutdown_function(function () {
    $GLOBALS['BCC_FATAL_RESERVE'] = null;

    $son = error_get_last();
    if ($son === null) {
        return;
    }
    // Yalnizca KURTARILAMAZ siniflar; notice/warning burada ele alinmaz
    // (onlar zaten gunluge dusuyor ve sayfayi kesmiyor).
    $olumcul = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if (!in_array($son['type'], $olumcul, true)) {
        return;
    }

    error_log('Ölümcül hata: ' . $son['message'] . ' (' . $son['file'] . ':' . $son['line'] . ')');

    if (!headers_sent()) {
        http_response_code(500);
    }

    if (bcc_is_api_request()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array('ok' => false, 'error' => 'Sunucu hatası. Lütfen tekrar deneyin.'), JSON_UNESCAPED_UNICODE);

        return;
    }

    // Sayfanin bir kismi zaten basilmis olabilir; o durumda ikinci bir
    // <!doctype> yazmak yerine gorunur bir uyari EKLENIR.
    if (headers_sent()) {
        echo '<div style="font-family:sans-serif;margin:2rem auto;max-width:32rem;text-align:center;color:#333;">'
            . '<h1 style="font-size:1.2rem;">Bir şeyler ters gitti</h1>'
            . '<p>Sayfa yüklenirken beklenmeyen bir hata oluştu. Lütfen tekrar deneyin.</p></div>';

        return;
    }

    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><title>Hata</title></head>'
        . '<body style="font-family:sans-serif;max-width:32rem;margin:4rem auto;text-align:center;color:#333;">'
        . '<h1 style="font-size:1.2rem;">Bir şeyler ters gitti</h1>'
        . '<p>Sayfa yüklenirken beklenmeyen bir hata oluştu. Lütfen tekrar deneyin.</p>'
        . '</body></html>';
});
