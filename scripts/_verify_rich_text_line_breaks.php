<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu betik yalnizca komut satirindan calistirilabilir.\n");
}

require __DIR__ . '/../src/bootstrap.php';

$gecti = 0;
$kaldi = 0;

function check($ad, $kosul, $ek = '')
{
    global $gecti, $kaldi;
    if ($kosul) { $gecti++; echo "  [OK]   $ad\n"; }
    else        { $kaldi++; echo "  [HATA] $ad" . ($ek !== '' ? "  -> $ek" : '') . "\n"; }
}

$link = ' target="_blank" rel="noopener noreferrer"';

echo "A) Tarayicinin Enter ile actigi <div> satirlari alt alta kaliyor\n";

$ornekler = array(
    array('Chromium: kalin / italik / link (2026-09-15 raporu)',
        '<b>Uzun metin</b><div><i>Uzun metin</i></div><div><a href="https://example.com">Uzun metin</a></div>',
        '<b>Uzun metin</b><br><i>Uzun metin</i><br><a href="https://example.com"' . $link . '>Uzun metin</a>'),
    array('duz metin + div',
        'A<div>B</div><div>C</div>',
        'A<br>B<br>C'),
    array('arada bos satir (<div><br></div>) korunuyor',
        'A<div><br></div><div>B</div>',
        'A<br><br>B'),
    array('satir sonu inline etiketin icindeyse cift <br> eklenmiyor',
        '<b>X<br></b><div>Y</div>',
        '<b>X<br></b>Y'),
    array('ic ice div',
        '<div><i>I</i><div><a href="https://e.com">L</a><br></div></div>',
        '<i>I</i><br><a href="https://e.com"' . $link . '>L</a>'),
    array('Chromium: kaydedilmis nota sona satir eklemek',
        '<b>Uzun metin</b><br><i>Uzun metin</i><div>Dorduncu</div>',
        '<b>Uzun metin</b><br><i>Uzun metin</i><br>Dorduncu'),
    array('Chromium: kaydedilmis notun arasina satir eklemek',
        '<b>Uzun metin</b><div><b>Araya<br></b><i>Uzun metin</i></div>',
        '<b>Uzun metin</b><br><b>Araya<br></b><i>Uzun metin</i>'),
    array('yapistirilan paragraflar',
        "<p>a</p>\n<p>b</p>",
        "a<br>\nb"),
    array('yapistirilan liste',
        'Once<ul><li>x</li><li>y</li></ul>Sonra',
        'Once<br>x<br>y<br>Sonra'),
);
foreach ($ornekler as $o) {
    $sonuc = bcc_sanitize_rich_text($o[1]);
    check('A) ' . $o[0], $sonuc === $o[2], var_export($sonuc, true));
}

echo "\nB) Kaydedilen deger tekrar kaydedilince DEGISMIYOR\n";

foreach ($ornekler as $o) {
    $bir = bcc_sanitize_rich_text($o[1]);
    check('B) ' . $o[0], bcc_sanitize_rich_text($bir) === $bir, var_export(bcc_sanitize_rich_text($bir), true));
}

echo "\nC) Eski bicim ve guvenlik davranisi degismedi\n";

check('C) Satir1<br>Satir2 aynen', bcc_sanitize_rich_text('Satir1<br>Satir2') === 'Satir1<br>Satir2');
check('C) sondaki dolgu <br> atiliyor', bcc_sanitize_rich_text('A<div>B</div>') === 'A<br>B');
check('C) div icindeki script siliniyor', bcc_sanitize_rich_text('A<div><script>alert(1)</script>B</div>') === 'A<br>B',
    var_export(bcc_sanitize_rich_text('A<div><script>alert(1)</script>B</div>'), true));
check('C) div icindeki javascript: linki metne dusuyor', bcc_sanitize_rich_text('A<div><a href="javascript:alert(1)">x</a></div>') === 'A<br>x');
check('C) yalnizca bos div null', bcc_sanitize_rich_text('<div><br></div>') === null,
    var_export(bcc_sanitize_rich_text('<div><br></div>'), true));

echo "\n" . str_repeat('-', 56) . "\n";
echo "GECTI: $gecti   KALDI: $kaldi\n";
exit($kaldi === 0 ? 0 : 1);
