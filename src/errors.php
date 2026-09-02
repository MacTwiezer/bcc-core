<?php
// Sayfa (HTML) tarafındaki ölümcül hataların TEK çıkış noktası.
//
// API tarafının karşılığı json_fail() (src/api_bootstrap.php). İkisi bilerek
// ayrı: API'ye HTML, sayfaya JSON dönmemeli. Hangisini kullanacağını dosyanın
// türü belirler — public/api/* json_fail(), diğer public/*.php bcc_error_page().

// $status: gerçek HTTP durum kodu — tarayıcı ve arama motorları için önemli.
// Eskiden bu yollar http_response_code() ÇAĞIRMADAN die() ediyordu, yani
// "yetkiniz yok" sayfaları 200 OK olarak dönüyordu.
function bcc_error_page($title, $message = '', $status = 403)
{
    // Sayfanın yarısı basılmışken hata çıkarsa (ör. grid.php'nin ortasında bir
    // yetki kontrolü) yarım HTML'in üstüne ikinci bir <!doctype> yazmayalım.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);

    // src/auth.php'deki require_team_access()/require_role()/require_admin()
    // HEM sayfalardan HEM public/api/*.php'den çağrılıyor ve koşulsuz buraya
    // düşüyordu — API'ye "Content-Type: application/json" başlığıyla HTML gövde
    // dönüyor, istemcinin res.json()'ı ayrıştırma hatası veriyordu (canlı
    // doğrulandı). Doğru durum kodu (403) zaten geliyordu, eksik olan gövdenin
    // biçimiydi; yukarıdaki kuralın uygulanması bu blok.
    if (bcc_is_api_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            array('ok' => false, 'error' => $message !== '' ? $message : $title),
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    $errorTitle = $title;
    $errorMessage = $message;
    $errorStatus = $status;
    require __DIR__ . '/partials/error_page.php';
    exit;
}
