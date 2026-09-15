<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

$root = dirname(__DIR__);
$results = array();

function check($label, $passed, $detail = null)
{
    global $results;
    $results[] = $passed;
    echo ($passed ? '[GECTI] ' : '[KALDI] ') . $label . "\n";
    if (!$passed && $detail !== null) {
        echo '         detay: ' . $detail . "\n";
    }
}

$resizeJs = (string) file_get_contents($root . '/public/assets/grid-column-resize.js');
$styleCss = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($root . '/public/assets/style.css'));
$shellCss = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($root . '/public/assets/grid-shell.css'));
$gridPhp = (string) file_get_contents($root . '/public/grid.php');

echo "A) Tutamaca cift tiklayinca sutun icerige gore ayarlaniyor\n";

check('A) seride dblclick dinleyicisi', strpos($resizeJs, "strip.addEventListener('dblclick'") !== false);
check('A) autoFitColumn tanimli', strpos($resizeJs, 'function autoFitColumn(key)') !== false);
check('A) baslik + TUM veri satirlarinin ayni sutunu olculuyor',
    strpos($resizeJs, 'var cells = [heads[index]];') !== false
    && strpos($resizeJs, "table.querySelectorAll('tbody tr[data-record-id]')") !== false);
check('A) olcumden once sutun en dar hale getiriliyor (genis sutun da DARALABILIR)',
    strpos($resizeJs, "col.style.width = MIN_WIDTH + 'px';") !== false);
check('A) cocuklarin sag kenari + hucrenin sag dolgusu/kenarligi toplaniyor',
    strpos($resizeJs, 'child.offsetLeft + child.offsetWidth + marginRight') !== false
    && strpos($resizeJs, 'cellStyle.paddingRight') !== false);
check('A) olcum siniflari her durumda geri aliniyor',
    strpos($resizeJs, "cell.classList.remove('is-col-measure');") !== false
    && strpos($resizeJs, "table.classList.remove('is-col-measuring');") !== false);
check('A) sonuc MIN/MAX sinirina oturtuluyor', strpos($resizeJs, 'clampWidth(Math.ceil(widest) + 2)') !== false);
check('A) sonuc kaydediliyor (persist) ve yerlesim tazeleniyor',
    preg_match('/function autoFitColumn\(key\) \{.*?layout\(\);\s*persist\(\);/s', $resizeJs) === 1);

check('A) olcum CSS i: kesme/kirpma/satir sinirlama kapali',
    preg_match('/\.is-col-measure \*\s*\{[^}]*max-width: none !important;[^}]*overflow: visible !important;[^}]*white-space: nowrap !important;/s', $styleCss) === 1);
check('A) olcum CSS i: .cell-view kendi icerik genisliginde (max-content)',
    preg_match('/\.is-col-measure > \.cell-view \{\s*width: max-content !important;/s', $styleCss) === 1);
check('A) orta/uzun satir modunda -webkit-box yerine blok olculuyor (kisa modda DEGIL)',
    strpos($styleCss, 'table.grid.is-col-measuring:not(.row-h-short) td.is-col-measure') !== false);

echo "\nB) Boyutlandirma seridi \"satir ekle\" satirinin ustunde bitiyor\n";

check('B) serit yuksekligi add-row un ust kenari',
    strpos($resizeJs, 'var height = addRow ? addRow.offsetTop : table.offsetHeight;') !== false);

echo "\nC) \"satir ekle\" yatay kaydirmada sabit\n";

check('C) sira numarasi sutun genisligi CSS degiskenine yaziliyor',
    strpos($resizeJs, "table.style.setProperty('--grid-rownum-w', heads[0].offsetWidth + 'px');") !== false);
check('C) .grid-add-row-bulk yapiskan, sira sutununun hemen sagina',
    preg_match('/\.grid-add-row-bulk \{[^}]*position: sticky;[^}]*left: var\(--grid-rownum-w, 0px\);/s', $shellCss) === 1);
check('C) hucre overflow: hidden DEGIL (yoksa sticky hucrenin icine hapsolur)',
    strpos($shellCss, 'table.grid.grid-has-col-widths td.grid-add-row-hint { overflow: visible; }') !== false);
check('C) "Shift-Enter ... yeni kayit da ekleyebilirsiniz" ipucu balonu KALDIRILDI (2026-09-15)',
    strpos($gridPhp, 'herhangi bir yere yeni kay') === false
    && preg_match('/<tr class="grid-add-row"[^>]*data-tooltip-host/', $gridPhp) === 0
    && strpos($shellCss, '.grid-add-row-hint .gs-kbd-tooltip') === false);
check('C) "satir ekle" yazisi kutuda dikey ortali (inline-flex + align-items, line-height 1)',
    preg_match('/\.grid-add-row-bulk-btn \{[^}]*display: inline-flex;[^}]*align-items: center;[^}]*line-height: 1;/s', $shellCss) === 1
    && preg_match('/\.grid-add-row-bulk-btn \{[^}]*line-height: 1\.5rem;/s', $shellCss) === 0);
check('C) JS kancalari yerinde', strpos($gridPhp, 'data-grid-add-bulk-count') !== false && strpos($gridPhp, 'data-grid-add-bulk-btn') !== false);

$gecti = count(array_filter($results));
$toplam = count($results);
echo "\n==== SONUC: " . $gecti . '/' . $toplam . " ====\n";
exit($gecti === $toplam ? 0 : 1);
