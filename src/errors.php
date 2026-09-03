<?php

function bcc_error_page($title, $message = '', $status = 403)
{

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);

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
