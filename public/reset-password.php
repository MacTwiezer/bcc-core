<?php
// Şifremi unuttum — 2. ekran: forgot-password.php'nin e-postayla gönderdiği tek
// kullanımlık bağlantı buraya çıkar. Kullanıcı yeni şifresini belirler; başarılı
// olursa token AYNI sorguda NULL'lanır ve link bir daha çalışmaz.
// verify_email.php ile bilinçli olarak aynı iskelet.

require __DIR__ . '/../src/bootstrap.php';

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

// CSRF kontrolü POST'un EN BAŞINDA: aşağıdaki hiçbir sorgu, isteğin bizim
// formumuzdan geldiği doğrulanmadan çalışmasın.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
}

/**
 * URL/formdan gelen HAM token'a karşılık gelen AKTİF kullanıcıyı döndürür;
 * yoksa false.
 *
 * ==== TOKEN HASH'LEME, TÜKETİCİ TARAF ====
 * Veritabanında ham token YOK, yalnızca SHA-256 özeti var (bkz. migrations/016
 * ve forgot-password.php). Bu yüzden arama, gelen ham token'ın özeti alınarak
 * yapılıyor. sha256 deterministik olduğu için index kullanılabiliyor.
 *
 * Arama TEK bir yerde: hem GET hem POST bu kapıdan geçer. verify_email.php:18
 * ile aynı desen — iki ayrı yerde iki ayrı WHERE yazmak, birini güncelleyip
 * diğerini unutmanın en kolay yoludur.
 *
 * NOT: kolon adı `password_reset_token` ama içinde ÖZET durur (adlandırma notu
 * migrations/016'da).
 */
function bcc_find_user_by_reset_token($token)
{
    if ($token === '') {
        return false;
    }

    return bcc_fetch_one(
        'SELECT id, full_name, password_reset_expires_at
         FROM users
         WHERE password_reset_token = :hash AND is_active = 1
         LIMIT 1',
        array('hash' => hash('sha256', $token))
    );
}

$error = null;

// Başarı ekranı Post/Redirect/Get ile geliyor (aşağıya bak).
$done = isset($_GET['done']) && $_GET['done'] === '1';

// Token GET'ten (maildeki link) ya da POST'tan (formdaki gizli alan) gelir.
// POST'a öncelik: hatalı bir denemeden sonra formu tekrar gönderirken URL'de
// token olmayabilir.
$token = isset($_POST['token'])
    ? (string) $_POST['token']
    : (isset($_GET['token']) ? (string) $_GET['token'] : '');

$user = $done ? false : bcc_find_user_by_reset_token($token);

// --- 1. KAPI: TOKEN seviyesindeki sorunlar --------------------------------
// Burada bir hata varsa form HİÇ basılmaz — kullanıcının düzeltebileceği bir şey
// yok, yeni bir bağlantı istemesi gerekiyor.
if (!$done) {
    if ($token === '') {
        $error = 'Geçersiz istek: bağlantı parametresi eksik. Lütfen e-postanızdaki bağlantıyı kullanın.';
    } elseif (!$user) {
        $error = 'Bağlantı geçersiz veya daha önce kullanılmış. Lütfen yeni bir sıfırlama bağlantısı isteyin.';
    } elseif (strtotime($user['password_reset_expires_at']) < time()) {
        // Süre kontrolü SQL'deki NOW() ile değil PHP'de yapılıyor: forgot-password.php
        // son kullanma damgasını da PHP saatiyle yazıyor, iki taraf aynı saat
        // kaynağını kullansın.
        $error = 'Bu bağlantının süresi dolmuş (bağlantılar 1 saat geçerlidir). Lütfen yeni bir sıfırlama bağlantısı isteyin.';
        $user = false;
    }
}

// --- 2. KAPI: FORM seviyesindeki sorunlar ---------------------------------
// Token geçerli; sorun kullanıcının girdiğinde. Form TEKRAR basılır ki kullanıcı
// düzeltip yeniden deneyebilsin.
//
// NOT: verify_email.php:65 bu ayrımı yapmıyor — orada "şifreler eşleşmiyor"
// hatası formu da ekrandan kaldırıyor ve kullanıcı maildeki linke geri dönmek
// zorunda kalıyor. Burada o davranış bilerek tekrarlanmadı.
if (!$done && $user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
    $passwordConfirm = isset($_POST['password_confirm']) ? (string) $_POST['password_confirm'] : '';

    if (!bcc_is_valid_password($password)) {
        // src/validation.php:18 — 8-72 karakter. ÜST SINIR ÖNEMLİ: bcrypt girdiyi
        // sessizce 72 baytta kırpar, elle yazılmış bir `strlen < 8` kontrolü bu
        // tuzağı açık bırakırdı (o dosyadaki uzun yorum bunu anlatıyor).
        $error = 'Şifre 8-72 karakter arasında olmalı.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Şifreler eşleşmiyor.';
    } else {
        // PASSWORD_DEFAULT sabitlenmiyor: PHP sürümü yükselince daha güçlü bir
        // varsayılana geçer, password_verify() eski hash'leri okumaya devam eder.
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Şifreyi yazmak ve token'ı öldürmek TEK sorguda, TEK adımda.
        //
        // WHERE'deki `password_reset_token = :hash_check` fazlalık DEĞİL, kilit:
        // iki istek aynı anda gelirse (çift tıklama, iki sekme) yalnızca BİRİ
        // satırı günceller ve affected_rows 1 döner; ikincisi 0 satır etkiler,
        // çünkü o an kolon artık NULL'dur. İki ayrı sorguya bölseydin (önce şifre,
        // sonra token=NULL) aradaki boşlukta token bir kez daha kullanılabilirdi.
        //
        // Kolon adı `password_hash` — `password` DEĞİL (bkz. schema.sql:30).
        $affected = bcc_execute(
            'UPDATE users
             SET password_hash = :hash, password_reset_token = NULL, password_reset_expires_at = NULL
             WHERE id = :id AND password_reset_token = :hash_check',
            array(
                'hash' => $hash,
                'id' => $user['id'],
                'hash_check' => hash('sha256', $token),
            )
        );

        if ($affected < 1) {
            $error = 'Bağlantı geçersiz veya daha önce kullanılmış. Lütfen yeni bir sıfırlama bağlantısı isteyin.';
            $user = false;
        } else {
            // Yeni şifre HİÇBİR log'a yazılmaz — yalnızca "değişti" olayı
            // (api/account_update_password.php:6-7 ile aynı kural).
            log_audit('user.password_reset_completed', 'user', $user['id']);

            // Post/Redirect/Get: kullanıcı başarı ekranını yenilediğinde form
            // yeniden gönderilmesin (ve "geçersiz token" hatası almasın).
            // Token URL'den de böylece düşer.
            header('Location: /reset-password.php?done=1');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title><?php echo htmlspecialchars(bcc_brand_name() . ' — Yeni şifre belirle', ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" type="image/svg+xml" href="<?php echo bcc_asset_url('favicon.svg'); ?>">
<script src="<?php echo bcc_asset_url('theme-init.js'); ?>"></script>
<link rel="stylesheet" href="<?php echo bcc_asset_url('theme.css'); ?>">
<link rel="stylesheet" href="<?php echo bcc_asset_url('login.css'); ?>">
</head>
<body class="login-page">
<div class="login-card">
    <div class="login-logo">
        <?php $brandLogoClass = 'login-logo-mark'; $brandLogoHeight = 44; require __DIR__ . '/../src/partials/brand_logo.php'; ?>
    </div>
    <div class="login-card-body">
        <h1 class="login-title"><?php echo $done ? 'Şifreniz güncellendi' : 'Yeni şifre belirle'; ?></h1>

        <?php if ($error !== null): ?>
            <p class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <?php if ($done): ?>
            <p class="login-info">Şifreniz başarıyla güncellendi. Artık yeni şifrenizle giriş yapabilirsiniz.</p>
            <a class="login-submit login-submit-link" href="/login.php">Giriş sayfasına git</a>

        <?php elseif ($user): ?>
            <p class="login-tagline">Merhaba <?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>, hesabınız için yeni bir şifre belirleyin. Şifre en az 8 karakter olmalı.</p>

            <?php // Token gizli alanda taşınıyor (form action'ının query string'inde
                  // DEĞİL): sorgu dizesi Referer başlığıyla dış sitelere ve sunucu
                  // erişim log'larına sızabilir. ?>
            <form method="post" action="/reset-password.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="login-field">
                    <label for="reset-password">Yeni Şifre</label>
                    <div class="input-with-toggle">
                        <input type="password" id="reset-password" name="password" minlength="8" maxlength="72" required autofocus>
                        <button type="button" class="input-toggle-btn" aria-label="Şifreyi göster">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-5.5 8-5.5 8 5.5 8 5.5-3 5.5-8 5.5-8-5.5-8-5.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
                        </button>
                    </div>
                </div>

                <div class="login-field">
                    <label for="reset-password-confirm">Yeni Şifre (Tekrar)</label>
                    <div class="input-with-toggle">
                        <input type="password" id="reset-password-confirm" name="password_confirm" minlength="8" maxlength="72" required>
                        <button type="button" class="input-toggle-btn" aria-label="Şifreyi göster">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-5.5 8-5.5 8 5.5 8 5.5-3 5.5-8 5.5-8-5.5-8-5.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="login-submit">Şifremi Güncelle</button>
            </form>

        <?php else: ?>
            <p class="login-register">
                <a href="/forgot-password.php">Yeni sıfırlama bağlantısı iste</a>
            </p>
        <?php endif; ?>

        <div class="login-legal">
            <p class="login-tagline"><?php echo htmlspecialchars(bcc_brand_full(), ENT_QUOTES, 'UTF-8'); ?> — ekiplerin verilerini güvenle yönettiği iç platform.</p>
        </div>
    </div>
</div>
<script src="<?php echo bcc_asset_url('password-toggle.js'); ?>" defer></script>
</body>
</html>