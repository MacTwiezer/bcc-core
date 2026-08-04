<?php
// Her public/ sayfasının başında dahil edilir: oturum + ortak yardımcılar.

require_once __DIR__ . '/error_handler.php';

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
require_once __DIR__ . '/csv.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/slack.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/mailer.php';

// public/assets/*.css|js dosyalarını mtime tabanlı sürüm sorgu string'iyle
// döndürür — bulunan gerçek bug: bu dosyalar hiç cache-bust edilmiyordu (yalnızca
// home.css/interface.css/interface.js istisnaen elle versiyonlanmıştı, her
// dosya yolu için kendi göreli __DIR__ hesabıyla AYRI AYRI, tutarsız bir
// şekilde). Bir kullanıcı tarayıcısı eski bir JS/CSS'i önbellekten sunmaya
// devam ederse, buraya yapılan bir düzeltme o kullanıcıda hiç görünmez —
// "hâlâ eski davranış var" şikayetlerinin asıl nedeni çoğunlukla budur.
// Bu fonksiyon her zaman KENDİ __DIR__'ine göre çözer (src/), çağıran dosyanın
// public/ altında mı yoksa src/partials/ altında mı olduğuna bakılmaksızın
// AYNI, doğru yolu üretir.
function bcc_asset_url($relativePath)
{
    $fsPath = __DIR__ . '/../public/assets/' . $relativePath;
    $version = @filemtime($fsPath);

    return '/assets/' . $relativePath . ($version !== false ? '?v=' . $version : '');
}

header('Content-Type: text/html; charset=utf-8');
