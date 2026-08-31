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

    $errorTitle = $title;
    $errorMessage = $message;
    $errorStatus = $status;
    require __DIR__ . '/partials/error_page.php';
    exit;
}
