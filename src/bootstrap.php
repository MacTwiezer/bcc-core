<?php
// Her public/ sayfasının başında dahil edilir: oturum + ortak yardımcılar.

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false, // localhost http için false; canlıda https arkasına alınırsa true yapılmalı
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/schema.php';

header('Content-Type: text/html; charset=utf-8');
