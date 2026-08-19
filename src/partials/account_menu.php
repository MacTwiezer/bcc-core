<?php
// Ortak hesap menüsü: avatar + açılır panel (isim/e-posta + çıkış formu).
// dashboard.php ("home" öneki), grid.php ("gs" öneki) ve interface.php ("if"
// öneki) tarafından paylaşılır — görünüm (konum, açılma yönü, renk teması)
// çağıran sayfanın kendi CSS'inden (home.css / grid-shell.css / interface.css)
// gelir, bu partial yalnızca ortak HTML yapısını üretir. data-account-toggle /
// data-account-menu, önekten bağımsız çalışan assets/account-menu.js
// tarafından kullanılır.
//
// "Görünüm" (Açık/Koyu) alt-paneli: menü İKİ "sayfa" barındırır
// (data-account-page="main"/"appearance"), assets/account-menu.js sayfa
// yenilemeden ikisi arasında geçiş yapar (geri oku / "Görünüm" satırı) — yeni
// ok/onay ikonları prefix'ten bağımsız (home.css'te tek yerde, .bcc-account-*
// jenerik sınıflar, renk currentColor'dan gelir — .X-account-item zaten
// prefix'e göre renkleniyor).
//
// Beklenen değişkenler (include eden sayfa tarafından ayarlanır):
//   $accountMenuPrefix - string, CSS sınıf öneki ("home", "gs" veya "if")
//   $accountMenuUser    - array, current_user() satırı (full_name, email içerir)

$accountMenuInitial = bcc_user_initial($accountMenuUser);
$p = $accountMenuPrefix;
?>
<div class="<?php echo $p; ?>-account">
    <button type="button" class="<?php echo $p; ?>-avatar" id="<?php echo $p; ?>-account-toggle" data-account-toggle><?php echo htmlspecialchars($accountMenuInitial, ENT_QUOTES, 'UTF-8'); ?></button>
    <div class="<?php echo $p; ?>-account-menu" id="<?php echo $p; ?>-account-menu" data-account-menu>
        <div data-account-page="main">
            <div class="<?php echo $p; ?>-account-info">
                <div class="<?php echo $p; ?>-account-name"><?php echo htmlspecialchars($accountMenuUser['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="<?php echo $p; ?>-account-email"><?php echo htmlspecialchars($accountMenuUser['email'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>

            <!-- Trash işlev yapmaz (özelliği yazılmamış) — tıklanınca sessizce hiçbir
                 şey yapmaz, onaylanmış karar (bkz. PROJE-DURUM.md). "Hesap" artık
                 gerçek bir sayfaya gidiyor (bkz. public/account.php). -->
            <div class="<?php echo $p; ?>-account-section">
                <a href="/account.php" class="<?php echo $p; ?>-account-item">Hesap</a>
                <?php if (is_platform_admin()): ?>
                    <a href="/admin/index.php" class="<?php echo $p; ?>-account-item">Admin</a>
                <?php endif; ?>
            </div>

            <div class="<?php echo $p; ?>-account-divider"></div>

            <div class="<?php echo $p; ?>-account-section">
                <button type="button" class="<?php echo $p; ?>-account-item" data-account-appearance-open>
                    <span>Görünüm</span>
                    <svg class="bcc-account-menu-arrow" width="7" height="11" viewBox="0 0 7 11" fill="none"><path d="M1 1l4.5 4.5L1 10" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </div>

            <div class="<?php echo $p; ?>-account-divider"></div>

            <div class="<?php echo $p; ?>-account-section">
                <button type="button" class="<?php echo $p; ?>-account-item" data-account-trash-open>Çöp kutusu</button>
            </div>

            <!-- Trash/Log out arasında bilinçli ikinci ayırıcı: ikisi yan yana/bitişik
                 olduğu için yanlış tıklama riskine karşı (bkz. YAPILACAKLAR-UI.md). -->
            <div class="<?php echo $p; ?>-account-divider"></div>

            <form method="post" action="/logout.php" class="<?php echo $p; ?>-account-logout">
                <?php echo csrf_field(); ?>
                <button type="submit">Çıkış</button>
            </form>
        </div>

        <div data-account-page="appearance" hidden>
            <div class="<?php echo $p; ?>-account-section">
                <button type="button" class="<?php echo $p; ?>-account-item" data-account-appearance-back>
                    <svg class="bcc-account-menu-arrow bcc-account-menu-arrow-back" width="7" height="11" viewBox="0 0 7 11" fill="none"><path d="M6 1L1.5 5.5 6 10" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span>Görünüm</span>
                </button>
            </div>

            <div class="<?php echo $p; ?>-account-divider"></div>

            <div class="<?php echo $p; ?>-account-section">
                <button type="button" class="<?php echo $p; ?>-account-item" data-theme-option="light">
                    <span>Light</span>
                    <svg class="bcc-account-menu-check" data-theme-check width="12" height="10" viewBox="0 0 12 10" fill="none"><path d="M1 5l3 3 7-7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <button type="button" class="<?php echo $p; ?>-account-item" data-theme-option="dark">
                    <span>Dark</span>
                    <svg class="bcc-account-menu-check" data-theme-check width="12" height="10" viewBox="0 0 12 10" fill="none"><path d="M1 5l3 3 7-7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Trash — OpsFlow'un workspace trash referansı (bkz. api/trash_list.php
     yorumu). Sayfa-geneli bir overlay olduğu için .X-account'ın dışında,
     tek kopya (bu partial sayfa başına zaten tek kez require ediliyor).
     Jenerik (prefix'siz) .bcc-trash-* sınıflar — home.css'te TEK yerde
     tanımlı, 3 CSS dosyasında kopya YOK (Görünüm alt-panelindeki
     .bcc-account-menu-* ile AYNI gerekçe). -->
<div class="bcc-trash-overlay" id="<?php echo $p; ?>-trash-overlay" hidden>
    <div class="bcc-trash-modal">
        <div class="bcc-trash-header">
            <h2 class="bcc-trash-title">Çöp kutusu</h2>
            <button type="button" class="bcc-trash-close" data-account-trash-close aria-label="Kapat">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 2l10 10M12 2L2 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            </button>
        </div>
        <p class="bcc-trash-desc">Takımlarınızda silinen base'ler ve kayıtlar burada listelenir (kayıtlar 7 gün sonra kalıcı silinir).</p>
        <h3 class="bcc-trash-section-title">Base'ler</h3>
        <div class="bcc-trash-list" data-trash-list>
            <div class="bcc-trash-empty" data-trash-empty hidden>Çöp kutusu boş.</div>
        </div>
        <!-- Kayıtlar bölümü — Adım 3d. AYNI overlay/modal içinde, kopya bir
             arayüz YOK; base'lerle AYNI .bcc-trash-list/.bcc-trash-item
             sınıfları yeniden kullanılıyor, tek yeni şey bu küçük başlık. -->
        <h3 class="bcc-trash-section-title">Kayıtlar</h3>
        <div class="bcc-trash-list" data-trash-record-list>
            <div class="bcc-trash-empty" data-trash-record-empty hidden>Çöp kutusu boş.</div>
        </div>
    </div>
</div>
