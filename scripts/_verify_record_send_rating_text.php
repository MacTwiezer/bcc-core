<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

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

$js = (string) file_get_contents($root . '/public/assets/grid-row-detail.js');
$send = (string) file_get_contents($root . '/public/api/record_send.php');

$start = strpos($js, 'function fieldPrintText(valueWrap) {');
$end = $start === false ? false : strpos($js, 'function preparePrintView()', $start);
$body = ($start !== false && $end !== false) ? substr($js, $start, $end - $start) : '';

echo "A) Degerlendirme alani mail / onizleme / yazdirmada yazi olarak\n";

check('A) fieldPrintText bulundu', $body !== '');
check('A) .rating-view dali var', strpos($body, "valueWrap.querySelector('.rating-view')") !== false);
check('A) toplam = yildiz sayisi, puan = dolu yildiz sayisi',
    strpos($body, "ratingView.querySelectorAll('.rating-star').length") !== false
    && strpos($body, "ratingView.querySelectorAll('.rating-star-filled').length") !== false);
check('A) bicim "<toplam> uzerinden <puan>"', strpos($body, "ratingTotal + ' üzerinden ' + ratingFilled") !== false);
check('A) puan yoksa bos alan isareti', strpos($body, "return ratingFilled ? ratingTotal + ' üzerinden ' + ratingFilled : '—';") !== false);
$ratingPos = strpos($body, "querySelector('.rating-view')");
$cellViewPos = strpos($body, "querySelector('.cell-view')");
check('A) salt okunur gorunumdeki .cell-view dalindan ONCE (yoksa yildiz karakterleri yazilirdi)',
    $ratingPos !== false && $cellViewPos !== false && $ratingPos < $cellViewPos);

echo "\nB) Mail bu metni kullaniyor\n";

check('B) gonderimde preview_fields = fieldPrintText ciktisi',
    strpos($js, 'value: fieldPrintText(valueWrap),') !== false
    && strpos($js, 'preview_fields: JSON.stringify(previewFields),') !== false);
check('B) sunucu degeri kacisliyor (htmlspecialchars)',
    strpos($send, "nl2br(htmlspecialchars(\$f['value'], ENT_QUOTES, 'UTF-8'))") !== false);

echo "\nC) Mail varsayilan olarak TABLO duzeninde gidiyor (2026-09-15)\n";

$gridPhp = (string) file_get_contents($root . '/public/grid.php');
check('C) "Tablo duzenini kullan" anahtari sayfada acik basiliyor',
    strpos($gridPhp, '<input type="checkbox" id="grid-send-use-grid-layout" checked>') !== false);
check('C) pencere her acilista anahtari ACIK yapiyor (eskiden kapaliya sifirliyordu)',
    strpos($js, 'sendUseGridLayoutToggle.checked = true;') !== false
    && strpos($js, 'sendUseGridLayoutToggle.checked = false;') === false);
check('C) kullanici kapatirsa alt alta (use_grid_layout=0) hala mumkun',
    strpos($js, "use_grid_layout: sendUseGridLayoutToggle && sendUseGridLayoutToggle.checked ? '1' : '0',") !== false
    && strpos($send, '} else {') !== false);

echo "\nD) Gorunumu kopyala (Excel) ve Excel indir de ayni yaziyi kullaniyor\n";

$copyJs = (string) file_get_contents($root . '/public/assets/grid-copy.js');
$pasteJs = (string) file_get_contents($root . '/public/assets/grid-paste.js');
$exportPhp = (string) file_get_contents($root . '/public/api/view_export_xlsx.php');
$schemaPhp = (string) file_get_contents($root . '/src/schema.php');

check('D) kopyalama: rating dali cellDisplay icinde', strpos($copyJs, "if (type === 'rating') {") !== false);
check('D) kopyalama: puan DOM sinifindan degil data-value dan (uzerine gelince yildizlar gecici boyaniyor)',
    strpos($copyJs, "parseInt(td.getAttribute('data-value'), 10) || 0") !== false
    && strpos($copyJs, 'rating-star-filled') === false);
check('D) kopyalama: toplam data-options max_rating dan', strpos($copyJs, 'ratingOptions.max_rating') !== false);
check('D) kopyalama: "<toplam> uzerinden <puan>", puansiz bos',
    strpos($copyJs, "return ratingValue > 0 ? ratingMax + ' üzerinden ' + Math.min(ratingValue, ratingMax) : '';") !== false);
check('D) yapistirma: "10 uzerinden 4" -> 4 (Excel den geri yapistirma)',
    strpos($pasteJs, "s.toLocaleLowerCase('tr').match(/^\\d+\\s+üzerinden\\s+(\\d+)$/)") !== false);
check('D) Excel indir: rating icin bcc_rating_out_of_text',
    strpos($exportPhp, "\$displayText = bcc_rating_out_of_text(\$cellRow, \$f['options']);") !== false);
check('D) sunucu yardimcisi tanimli', strpos($schemaPhp, 'function bcc_rating_out_of_text($cellRow, $options)') !== false);

$satir = function ($v) {
    return array('value_text' => null, 'value_number' => $v, 'value_date' => null, 'value_json' => null);
};
check('D) bcc_rating_out_of_text(8, max 10) = "10 üzerinden 8"', bcc_rating_out_of_text($satir(8), '{"max_rating":10}') === '10 üzerinden 8');
check('D) puan 0 ve bos hucre -> ""',
    bcc_rating_out_of_text($satir(0), '{"max_rating":10}') === '' && bcc_rating_out_of_text(null, '{"max_rating":10}') === '');
check('D) ust sinir asilamiyor (12, max 10 -> 10)', bcc_rating_out_of_text($satir(12), '{"max_rating":10}') === '10 üzerinden 10');
check('D) grid/kanban/Slack gorunumu DEGISMEDI (cell_display_text hala yildiz)',
    cell_display_text('rating', $satir(4), array(), '{"max_rating":10}') === str_repeat('★', 4) . str_repeat('☆', 6));

$gecti = count(array_filter($results));
$toplam = count($results);
echo "\n==== SONUC: " . $gecti . '/' . $toplam . " ====\n";
exit($gecti === $toplam ? 0 : 1);
