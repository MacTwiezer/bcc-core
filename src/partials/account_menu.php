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

        <!-- Aşağıdaki öğelerin hiçbiri işlev yapmaz (özelliği yazılmamış) — yalnızca
             Airtable menüsünün görünümünü tamamlar, tıklanınca sessizce hiçbir şey
             yapmaz. Rozetler (Business/Beta) yalnızca görsel, gerçek bir plan bilgisi
             ima etmez. Gerçek işlevi olan TEK öğe en alttaki "Log out" formudur. -->
        <div class="<?php echo $accountMenuPrefix; ?>-account-section">
            <button type="button" class="<?php echo $accountMenuPrefix; ?>-account-item">Account</button>
            <button type="button" class="<?php echo $accountMenuPrefix; ?>-account-item">
                Manage groups
                <span class="<?php echo $accountMenuPrefix; ?>-account-badge <?php echo $accountMenuPrefix; ?>-account-badge-blue">Business</span>
            </button>
            <button type="button" class="<?php echo $accountMenuPrefix; ?>-account-item">
                Notification preferences
                <svg class="<?php echo $accountMenuPrefix; ?>-account-item-arrow" width="8" height="8" viewBox="0 0 12 12" fill="none"><path d="M4.5 3l3 3-3 3" stroke="#8a8a8e" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button type="button" class="<?php echo $accountMenuPrefix; ?>-account-item">
                Language preferences
                <svg class="<?php echo $accountMenuPrefix; ?>-account-item-arrow" width="8" height="8" viewBox="0 0 12 12" fill="none"><path d="M4.5 3l3 3-3 3" stroke="#8a8a8e" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button type="button" class="<?php echo $accountMenuPrefix; ?>-account-item">
                Appearance
                <span class="<?php echo $accountMenuPrefix; ?>-account-badge <?php echo $accountMenuPrefix; ?>-account-badge-orange">Beta</span>
                <svg class="<?php echo $accountMenuPrefix; ?>-account-item-arrow" width="8" height="8" viewBox="0 0 12 12" fill="none"><path d="M4.5 3l3 3-3 3" stroke="#8a8a8e" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
        </div>

        <div class="<?php echo $accountMenuPrefix; ?>-account-divider"></div>

        <div class="<?php echo $accountMenuPrefix; ?>-account-section">
            <button type="button" class="<?php echo $accountMenuPrefix; ?>-account-item">Contact sales</button>
            <button type="button" class="<?php echo $accountMenuPrefix; ?>-account-item">
                <svg class="<?php echo $accountMenuPrefix; ?>-account-item-icon" width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M10 2.5l2.2 4.6 5 .7-3.6 3.5.8 5-4.4-2.3-4.4 2.3.8-5-3.6-3.5 5-.7L10 2.5z" stroke="#5f6368" stroke-width="1.3" stroke-linejoin="round"/></svg>
                Upgrade
            </button>
            <button type="button" class="<?php echo $accountMenuPrefix; ?>-account-item">Tell a friend</button>
        </div>

        <div class="<?php echo $accountMenuPrefix; ?>-account-divider"></div>

        <div class="<?php echo $accountMenuPrefix; ?>-account-section">
            <button type="button" class="<?php echo $accountMenuPrefix; ?>-account-item">Integrations</button>
            <button type="button" class="<?php echo $accountMenuPrefix; ?>-account-item">Builder hub</button>
        </div>

        <div class="<?php echo $accountMenuPrefix; ?>-account-divider"></div>

        <div class="<?php echo $accountMenuPrefix; ?>-account-section">
            <button type="button" class="<?php echo $accountMenuPrefix; ?>-account-item">Trash</button>
        </div>

        <!-- Trash/Log out arasında bilinçli ikinci ayırıcı: ikisi yan yana/bitişik
             olduğu için yanlış tıklama riskine karşı (bkz. YAPILACAKLAR-UI.md). -->
        <div class="<?php echo $accountMenuPrefix; ?>-account-divider"></div>

        <form method="post" action="/logout.php" class="<?php echo $accountMenuPrefix; ?>-account-logout">
            <?php echo csrf_field(); ?>
            <button type="submit">Log out</button>
        </form>
    </div>
</div>
