<?php
// Genel arama ("Ara..." / Ctrl K) — TÜM sayfaların PAYLAŞTIĞI tek markup.
//
// Önceden bu blok yalnızca src/partials/home_shell_top.php'nin içinde,
// gömülü olarak vardı; grid.php (kendi kabuğu var) hiç almıyordu. Buraya
// çıkarıldı ki iki kabuk da AYNI DOM'u bassın — davranış assets/global-search.js
// tarafından, sayfa fark etmeksizin tek yerden yönetilir.
//
// Beklenen değişkenler (opsiyonel, include eden sayfa ayarlayabilir):
//   $searchPlaceholder - girdi/tetikleyici metni (varsayılan "Ara...")
//   $searchEmptyText   - hiç eşleşme yokken gösterilen metin
//   $searchTriggerClass- tetikleyiciye eklenecek ek sınıf (grid.php kendi
//                        üst barının ölçülerine uydurmak için kullanır)
//
// Sınıf adları BİLEREK "home-search-*" olarak KORUNDU: mevcut CSS (home.css)
// ve bu sınıfları hedefleyen testler değişmesin diye. Sınıf adı artık
// sayfaya değil bileşene ait — grid.php de aynı adları kullanır.

$bccSearchPlaceholder = isset($searchPlaceholder) ? $searchPlaceholder : 'Ara...';
$bccSearchEmptyText = isset($searchEmptyText) ? $searchEmptyText : 'Aramanızla eşleşen bir sonuç yok.';
$bccSearchTriggerClass = isset($searchTriggerClass) ? $searchTriggerClass : '';
?>
<details class="home-search" id="home-search">
    <summary class="home-search-trigger <?php echo htmlspecialchars($bccSearchTriggerClass, ENT_QUOTES, 'UTF-8'); ?>">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="7" cy="7" r="5.2" stroke="#8a8a8e" stroke-width="1.4"/><path d="M11 11l3.5 3.5" stroke="#8a8a8e" stroke-width="1.4" stroke-linecap="round"/></svg>
        <span class="home-search-trigger-label"><?php echo htmlspecialchars($bccSearchPlaceholder, ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="home-search-kbd">Ctrl K</span>
    </summary>
    <div class="home-search-overlay"></div>
    <div class="home-search-popover">
        <div class="home-search-popover-inputwrap">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="7" cy="7" r="5.2" stroke="#8a8a8e" stroke-width="1.4"/><path d="M11 11l3.5 3.5" stroke="#8a8a8e" stroke-width="1.4" stroke-linecap="round"/></svg>
            <input type="text" id="home-search-input" placeholder="<?php echo htmlspecialchars($bccSearchPlaceholder, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Ara" autocomplete="off">
        </div>
        <?php // Bağlam etiketi: aramanın NEYİ süzdüğünü söyler ("3 base",
              // "12 kayıt"...). global-search.js sayfa yüklenince doldurur. ?>
        <div class="home-search-scope" id="home-search-scope" hidden></div>
        <div class="home-search-results" id="home-search-results" role="listbox"></div>
        <div class="home-search-empty" id="home-search-empty" hidden><?php echo htmlspecialchars($bccSearchEmptyText, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="home-search-hint">Aramak için istediğiniz zaman Ctrl K'ya basın · <kbd>↑</kbd><kbd>↓</kbd> gezin · <kbd>Esc</kbd> kapat</div>
    </div>
</details>
