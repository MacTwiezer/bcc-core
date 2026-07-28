<?php
// Ortak hesap menüsü: avatar + açılır panel (isim/e-posta + çıkış formu).
// dashboard.php ("home" öneki) ve grid.php ("gs" öneki) tarafından paylaşılır —
// görünüm (konum, açılma yönü) çağıran sayfanın kendi CSS'inden (home.css /
// grid-shell.css) gelir, bu partial yalnızca ortak HTML yapısını üretir.
// data-account-toggle / data-account-menu, önekten bağımsız çalışan
// assets/account-menu.js tarafından kullanılır.
//
// Beklenen değişkenler (include eden sayfa tarafından ayarlanır):
//   $accountMenuPrefix - string, CSS sınıf öneki ("home" veya "gs")
//   $accountMenuUser    - array, current_user() satırı (full_name, email içerir)

$accountMenuInitial = bcc_user_initial($accountMenuUser);
?>
<div class="<?php echo $accountMenuPrefix; ?>-account">
    <button type="button" class="<?php echo $accountMenuPrefix; ?>-avatar" id="<?php echo $accountMenuPrefix; ?>-account-toggle" data-account-toggle><?php echo htmlspecialchars($accountMenuInitial, ENT_QUOTES, 'UTF-8'); ?></button>
    <div class="<?php echo $accountMenuPrefix; ?>-account-menu" id="<?php echo $accountMenuPrefix; ?>-account-menu" data-account-menu>
        <div class="<?php echo $accountMenuPrefix; ?>-account-info">
            <div class="<?php echo $accountMenuPrefix; ?>-account-name"><?php echo htmlspecialchars($accountMenuUser['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="<?php echo $accountMenuPrefix; ?>-account-email"><?php echo htmlspecialchars($accountMenuUser['email'], ENT_QUOTES, 'UTF-8'); ?></div>
        </div>

        <!-- Trash işlev yapmaz (özelliği yazılmamış) — tıklanınca sessizce hiçbir
             şey yapmaz, onaylanmış karar (bkz. PROJE-DURUM.md). "Hesap" artık
             gerçek bir sayfaya gidiyor (bkz. public/account.php). -->
        <div class="<?php echo $accountMenuPrefix; ?>-account-section">
            <a href="/account.php" class="<?php echo $accountMenuPrefix; ?>-account-item">Hesap</a>
            <?php if (is_platform_admin()): ?>
                <a href="/admin/index.php" class="<?php echo $accountMenuPrefix; ?>-account-item">Admin</a>
            <?php endif; ?>
        </div>

        <div class="<?php echo $accountMenuPrefix; ?>-account-divider"></div>

        <div class="<?php echo $accountMenuPrefix; ?>-account-section">
            <button type="button" class="<?php echo $accountMenuPrefix; ?>-account-item">Çöp kutusu</button>
        </div>

        <!-- Trash/Log out arasında bilinçli ikinci ayırıcı: ikisi yan yana/bitişik
             olduğu için yanlış tıklama riskine karşı (bkz. YAPILACAKLAR-UI.md). -->
        <div class="<?php echo $accountMenuPrefix; ?>-account-divider"></div>

        <form method="post" action="/logout.php" class="<?php echo $accountMenuPrefix; ?>-account-logout">
            <?php echo csrf_field(); ?>
            <button type="submit">Çıkış</button>
        </form>
    </div>
</div>
