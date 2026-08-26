<?php

require __DIR__ . '/../src/bootstrap.php';

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

$error = null;
$info = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($email === '' || $password === '') {
        $error = 'E-posta ve şifre gerekli.';
    } else {
        $status = attempt_login($email, $password);

        if ($status === 'ok') {
            log_audit('user.login');
            header('Location: /dashboard.php');
            exit;
        } elseif ($status === 'inactive') {
            $error = 'Hesabınız henüz yönetici tarafından onaylanmadı.';
        } else {
            $error = 'E-posta veya şifre hatalı.';
        }
    }
} elseif (isset($_GET['registered']) && $_GET['registered'] === '1') {
    $info = 'Kaydınız alındı. E-postanıza gönderilen bağlantıdan şifrenizi oluşturup hesabınızı etkinleştirebilirsiniz.';
} elseif (isset($_GET['verified']) && $_GET['verified'] === '1') {
    $info = 'Hesabınız etkinleştirildi. Şimdi giriş yapabilirsiniz.';
}
?>
<?php
// Ortak oturumsuz kabuk (src/partials/auth_shell_top.php) — <head>, marka
// logosu ve kart kutusu BES sayfada birebir aynıydı, tek yere alındı.
$authPageTitle = 'Giriş';
require __DIR__ . '/../src/partials/auth_shell_top.php';
?>
        <h1 class="login-title">Hoş geldiniz</h1>

        <?php if ($error !== null): ?>
            <p class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <?php if ($info !== null): ?>
            <p class="login-info"><?php echo htmlspecialchars($info, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <form method="post" action="/login.php">
            <?php echo csrf_field(); ?>
            <div class="login-field">
                <label for="login-email">E-posta</label>
                <input type="email" id="login-email" name="email" required autofocus>
            </div>
            <div class="login-field">
                <label for="login-password">Şifre</label>
                <div class="input-with-toggle">
                    <input type="password" id="login-password" name="password" required>
                    <button type="button" class="input-toggle-btn" aria-label="Şifreyi göster">
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M2 10s3-5.5 8-5.5 8 5.5 8 5.5-3 5.5-8 5.5-8-5.5-8-5.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
                    </button>
                </div>
            </div>
            <p class="login-forgot">
                <a href="/forgot-password.php">Şifremi unuttum?</a>
            </p>
            <button type="submit" class="login-submit">Giriş yap</button>
        </form>

        <p class="login-register">
            <a href="/register.php">Kayıt ol</a>
        </p>

        <?php if (bcc_demo_login_enabled()): ?>
            <?php
            // Hızlı Demo Girişi — YALNIZCA $BCC_DEMO_LOGIN açıkken basılır
            // (varsayılan false, bkz. config/app.php). Kapalıyken bu blok hiç
            // çalışmaz: sabit şifreler sayfa kaynağında GÖRÜNMEZ.
            //
            // Butonlar oturum AÇMAZ, yalnızca yukarıdaki iki alanı doldurur —
            // giriş yine normal POST + CSRF + attempt_login() yolundan geçer,
            // yani kimlik doğrulamayı atlayan ikinci bir kapı açılmaz. Bu,
            // bilinçli bir tercih (kullanıcı onayladı): yeni bir uç nokta
            // eklemek, canlıda unutulursa gerçek bir güvenlik açığı olurdu.
            //
            // Kimlik bilgileri src/demo_accounts.php'den gelir — seed betiği
            // (scripts/seed_demo_users.php) DE aynı listeyi kullanır, ikinci
            // bir kopya YOK.
            ?>
            <div class="login-demo">
                <div class="login-demo-head">
                    <span class="login-demo-title">Hızlı Demo Girişi</span>
                    <span class="login-demo-badge">yalnızca yerel test</span>
                </div>

                <div class="login-demo-grid">
                    <?php foreach (bcc_demo_accounts() as $demo): ?>
                        <button
                            type="button"
                            class="login-demo-btn"
                            data-demo-email="<?php echo htmlspecialchars($demo['email'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-demo-password="<?php echo htmlspecialchars($demo['password'], ENT_QUOTES, 'UTF-8'); ?>"
                            title="<?php echo htmlspecialchars($demo['email'] . ' — ' . $demo['hint'], ENT_QUOTES, 'UTF-8'); ?>"
                        >
                            <span class="login-demo-btn-label"><?php echo htmlspecialchars($demo['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="login-demo-btn-hint"><?php echo htmlspecialchars($demo['hint'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <p class="login-demo-note" id="login-demo-note">Bir rol seçin, alanlar dolsun — sonra “Giriş yap”.</p>
            </div>
        <?php endif; ?>
<?php
// Ortak kapanış (src/partials/auth_shell_bottom.php): marka satırı, kart
// kapanışı ve </body></html> BEŞ sayfada aynıydı.
// Demo giriş betiği KOŞULLU kalır: yalnızca $BCC_DEMO_LOGIN açıkken listeye girer.
$authScripts = array('password-toggle.js');
if (bcc_demo_login_enabled()) {
    $authScripts[] = 'demo-login.js';
}
require __DIR__ . '/../src/partials/auth_shell_bottom.php';
