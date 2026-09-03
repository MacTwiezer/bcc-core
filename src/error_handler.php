<?php

ini_set('display_errors', '0');

$GLOBALS['BCC_FATAL_RESERVE'] = str_repeat(' ', 256 * 1024);

function bcc_is_api_request()
{
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';

    return strpos($script, '/api/') !== false;
}

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

register_shutdown_function(function () {
    $GLOBALS['BCC_FATAL_RESERVE'] = null;

    $son = error_get_last();
    if ($son === null) {
        return;
    }

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
