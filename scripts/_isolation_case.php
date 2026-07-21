<?php
// İç yardımcı — test_isolation.php tarafından alt süreç olarak çalıştırılır.
// Tek bir require_team_access() çağrısını, uygulamanın (public/*.php) kullandığı
// GERÇEK fonksiyonla, izole bir PHP sürecinde dener. curl/HTTP yok.
//
// Kullanım: php _isolation_case.php <user_id> <team_id>
// Çıktı: "ERISIM_VAR" → erişim verildi. Aksi halde require_team_access()'in
// ürettiği 403 mesajı basılır (die() içinde).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnızca komut satırından çalıştırılabilir.\n");
}

require __DIR__ . '/../src/auth.php';

$userId = isset($argv[1]) ? (int) $argv[1] : 0;
$teamId = isset($argv[2]) ? (int) $argv[2] : 0;

// Gerçek bir oturum başlatmadan current_user()'ın okuduğu $_SESSION'ı taklit ediyoruz.
$_SESSION = array('user_id' => $userId);

// Uygulamanın her ekip verisi erişiminden önce çağırdığı GERÇEK fonksiyon.
require_team_access($teamId);

echo "ERISIM_VAR\n";
