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

function strip_js_comments($src)
{
    $out = '';
    $len = strlen($src);
    $i = 0;
    $inLine = false;
    $inBlock = false;
    $quote = '';
    while ($i < $len) {
        $c = $src[$i];
        $n = ($i + 1 < $len) ? $src[$i + 1] : '';
        if ($inLine) {
            if ($c === "\n") { $inLine = false; $out .= $c; }
            $i++;
            continue;
        }
        if ($inBlock) {
            if ($c === '*' && $n === '/') { $inBlock = false; $i += 2; continue; }
            $i++;
            continue;
        }
        if ($quote !== '') {
            $out .= $c;
            if ($c === '\\') { $out .= $n; $i += 2; continue; }
            if ($c === $quote) { $quote = ''; }
            $i++;
            continue;
        }
        if ($c === '/' && $n === '/') { $inLine = true; $i += 2; continue; }
        if ($c === '/' && $n === '*') { $inBlock = true; $i += 2; continue; }
        if ($c === '"' || $c === "'") { $quote = $c; $out .= $c; $i++; continue; }
        $out .= $c;
        $i++;
    }
    return $out;
}

$js = strip_js_comments((string) file_get_contents($root . '/public/assets/home.js'));

echo "--- A) Dinleyici dugmede DEGIL belgede (sonradan eklenen kart da calisir) ---\n";

check('eski "her dugmeye tek tek bagla" deseni kaldirildi',
    strpos($js, "querySelectorAll('[data-base-delete]')") === false);
check('tiklama belge seviyesinde yakalaniyor',
    strpos($js, "closest('[data-base-delete]')") !== false);
check('kartin <a> gezinmesi iptal ediliyor',
    preg_match('/closest\(\'\[data-base-delete\]\'\).*?e\.preventDefault\(\)/s', $js) === 1);
check('devre disi dugme yeniden tetiklenmiyor',
    strpos($js, '!btn || btn.disabled') !== false);

echo "\n--- B) Silme sonrasi sayfada iz birakmiyor ---\n";

check('temizleme yardimcisi tanimli', strpos($js, 'function baseKartiniTemizle(') !== false);

$pos = strpos($js, 'function baseKartiniTemizle(');
$govde = $pos === false ? '' : substr($js, $pos, 900);

check('grup basligindaki sayac guncelleniyor',
    strpos($govde, "meta.textContent = kalan + ' base'") !== false);
check('sayimda "Yeni Base Olustur" karosu sayilmiyor',
    strpos($govde, '.home-base-card:not(.home-base-create)') !== false);
check('grubun son base i silinince baslik ve izgara da kaldiriliyor',
    strpos($govde, 'head.remove()') !== false && strpos($govde, 'grid.remove()') !== false);
check('izgara KOSULSUZ silinmiyor — "Yeni Base Olustur" karosu ayni izgarada olabilir',
    strpos($govde, "if (!grid.querySelector('.home-base-card'))") !== false, trim($govde));
check('baslik yoksa (basliksiz izgara) sessizce cikiliyor',
    strpos($govde, "head.classList.contains('home-section-head')") !== false);

check('sol paneldeki yildizli satiri da siliniyor',
    strpos($js, "'[data-starred-base-id=\"' + baseId + '\"]'") !== false);
check('bosalan yildizli grubu da kaldiriliyor',
    strpos($js, "grup.querySelector('[data-starred-base-id]')") !== false);
check('arama dizini de guncelleniyor',
    strpos($js, 'window.bcc_searchRemoveItem(baseId)') !== false);

echo "\n--- C) Vazgecme ve hata yollari korundu ---\n";

check('onay verilmezse hicbir sey yapilmiyor',
    strpos($js, 'if (!onaylandi)') !== false);
check('sunucu reddederse dugme geri aciliyor',
    strpos($js, "window.alert((data && data.error) || 'Silinemedi.')") !== false);
check('baglanti hatasi ayrica ele aliniyor',
    strpos($js, "window.alert('Silinemedi (bağlantı hatası).')") !== false);

echo "\n--- D) JS'in dayandigi isaretleme sunucuda hala var ---\n";

$schema = (string) file_get_contents($root . '/src/schema.php');
$dashboard = (string) file_get_contents($root . '/public/dashboard.php');

check('kart sinifi: .home-base-card', strpos($schema, 'class="home-base-card') !== false);
check('silme dugmesi: data-base-delete', strpos($schema, 'data-base-delete=') !== false);
check('olusturma karosu: .home-base-create', strpos($schema, 'home-base-card home-base-create') !== false);
check('grup basligi ve sayaci gridin HEMEN ONUNDE basiliyor',
    strpos($schema, "<span class=\"home-section-meta\">") !== false
    && strpos($dashboard, 'home-section-meta') !== false);
check('geri yukleme kartin HTML ini enjekte ediyor (delegasyon bu yuzden sart)',
    strpos((string) file_get_contents($root . '/public/assets/account-menu.js'), 'kap.innerHTML = data.card_html') !== false);

$total = count($results);
$passed = count(array_filter($results));
echo "\n" . $passed . '/' . $total . ($passed === $total ? " GECTI\n" : " -- BAZI KONTROLLER KALDI\n");
exit($passed === $total ? 0 : 1);
