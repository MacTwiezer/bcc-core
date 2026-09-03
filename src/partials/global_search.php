<?php

$bccSearchPlaceholder = isset($searchPlaceholder) ? $searchPlaceholder : 'Ara...';
$bccSearchEmptyText = isset($searchEmptyText) ? $searchEmptyText : 'Aramanızla eşleşen bir sonuç yok.';
$bccSearchTriggerClass = isset($searchTriggerClass) ? $searchTriggerClass : '';
?>
<details class="home-search" id="home-search">
    <summary class="home-search-trigger <?php echo htmlspecialchars($bccSearchTriggerClass, ENT_QUOTES, 'UTF-8'); ?>">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="7" cy="7" r="5.2" stroke="currentColor" stroke-width="1.4"/><path d="M11 11l3.5 3.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
        <span class="home-search-trigger-label"><?php echo htmlspecialchars($bccSearchPlaceholder, ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="home-search-kbd">Ctrl K</span>
    </summary>
    <div class="home-search-overlay"></div>
    <div class="home-search-popover">
        <div class="home-search-popover-inputwrap">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="7" cy="7" r="5.2" stroke="currentColor" stroke-width="1.4"/><path d="M11 11l3.5 3.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
            <input type="text" id="home-search-input" placeholder="<?php echo htmlspecialchars($bccSearchPlaceholder, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Ara" autocomplete="off">
        </div>
                <div class="home-search-scope" id="home-search-scope" hidden></div>
        <div class="home-search-results" id="home-search-results" role="listbox"></div>
        <div class="home-search-empty" id="home-search-empty" hidden><?php echo htmlspecialchars($bccSearchEmptyText, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="home-search-hint">Aramak için istediğiniz zaman Ctrl K'ya basın · <kbd>↑</kbd><kbd>↓</kbd> gezin · <kbd>Esc</kbd> kapat</div>
    </div>
</details>
