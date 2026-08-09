<?php
// Chrome'un urettigi PDF'i incelemek icin kucuk yardimci (yalnizca test amacli,
// uygulama kodu DEGIL): sayfa sayisi, sayfa yonu (MediaBox) ve SAYFA SAYFA metin.
//
// Neden gerekli: Chrome PDF'i altkume (subset) Type0/CID fontlarla yaziyor --
// icerik akislarindaki <0003 0024 ...> dizileri harf DEGIL glif numarasi. Duz
// bir "(...)" taramasi hicbir sey bulmaz (ilk denemede tam olarak bu oldu).
// Burada fontlarin /ToUnicode CMap'leri (beginbfchar/beginbfrange) okunup glif
// -> unicode haritasi kuruluyor, sonra her sayfanin icerik akisi ayri ayri
// cozuluyor. "Baslik HER sayfada tekrarliyor mu" ancak boyle kanitlanabiliyor.
//
// Calistirma:
//   C:\php73\php.exe scripts\_pdf_inspect.php <dosya.pdf> [aranacak-metin ...]

if (PHP_SAPI !== 'cli') { http_response_code(403); die("CLI only\n"); }

$path = isset($argv[1]) ? $argv[1] : '';
if (!is_file($path)) { echo "Dosya yok: {$path}\n"; exit(1); }

$raw = file_get_contents($path);

// --- Tum stream'leri AYRI AYRI ac (sayfa ayrimi icin birlestirilmiyor) ------
$streams = array();
$off = 0;
while (($s = strpos($raw, 'stream', $off)) !== false) {
    $st = $s + 6;
    if (substr($raw, $st, 2) === "\r\n") { $st += 2; }
    elseif (substr($raw, $st, 1) === "\n" || substr($raw, $st, 1) === "\r") { $st += 1; }
    $e = strpos($raw, 'endstream', $st);
    if ($e === false) { break; }
    $chunk = substr($raw, $st, $e - $st);
    $inf = @gzuncompress($chunk);
    if ($inf === false) { $inf = @gzinflate($chunk); }
    if ($inf !== false) { $streams[] = $inf; }
    $off = $e + 9;
}

// --- ToUnicode CMap'leri: glif kodu -> unicode -----------------------------
// DIKKAT: her font altkumesinin KENDI CMap'i var ve glif numaralari fontlar
// arasinda CAKISIYOR (normal metin F64, kalin tablo basligi F65). Ilk surumde
// hepsi TEK bir haritada birlestirilmisti; kalin font normalin uzerine yazdigi
// icin "Departman"/"Telefon" basliklari HIC bulunamiyordu (yanlis "baslik
// tekrarlamıyor" sonucu). Bu yuzden CMap'ler AYRI tutuluyor ve her sayfa her
// CMap ile ayri ayri cozuluyor; aranan metin herhangi birinde gecerse sayilir.
$cmaps = array();
foreach ($streams as $s) {
    if (strpos($s, 'beginbfchar') === false && strpos($s, 'beginbfrange') === false) { continue; }
    $cmap = array();

    if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $s, $bc)) {
        foreach ($bc[1] as $block) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER);
            foreach ($pairs as $p) {
                $cmap[strtoupper($p[1])] = hex_to_utf8($p[2]);
            }
        }
    }

    if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $s, $br)) {
        foreach ($br[1] as $block) {
            // <start> <end> <dst>
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $r, PREG_SET_ORDER);
            foreach ($r as $m) {
                $start = hexdec($m[1]); $end = hexdec($m[2]); $dst = hexdec($m[3]);
                if ($end - $start > 65535) { continue; }
                for ($i = $start; $i <= $end; $i++) {
                    $cmap[strtoupper(str_pad(dechex($i), 4, '0', STR_PAD_LEFT))] = code_to_utf8($dst + ($i - $start));
                }
            }
            // <start> <end> [ <d1> <d2> ... ]
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*\[(.*?)\]/s', $block, $r2, PREG_SET_ORDER);
            foreach ($r2 as $m) {
                $start = hexdec($m[1]);
                preg_match_all('/<([0-9A-Fa-f]+)>/', $m[3], $dsts);
                foreach ($dsts[1] as $k => $d) {
                    $cmap[strtoupper(str_pad(dechex($start + $k), 4, '0', STR_PAD_LEFT))] = hex_to_utf8($d);
                }
            }
        }
    }

    if (!empty($cmap)) { $cmaps[] = $cmap; }
}

function code_to_utf8($cp)
{
    return html_entity_decode('&#' . $cp . ';', ENT_QUOTES, 'UTF-8');
}

function hex_to_utf8($hex)
{
    $out = '';
    for ($i = 0; $i + 3 < strlen($hex) + 1; $i += 4) {
        $part = substr($hex, $i, 4);
        if (strlen($part) < 4) { break; }
        $out .= code_to_utf8(hexdec($part));
    }
    return $out;
}

// --- Icerik akislarini (sayfalari) coz ------------------------------------
function decode_content($content, $cmap)
{
    $text = '';
    preg_match_all('/<([0-9A-Fa-f]+)>\s*Tj/', $content, $m);
    foreach ($m[1] as $hex) {
        for ($i = 0; $i + 4 <= strlen($hex); $i += 4) {
            $code = strtoupper(substr($hex, $i, 4));
            $text .= isset($cmap[$code]) ? $cmap[$code] : '?';
        }
        $text .= "\n";
    }
    return $text;
}

// Her sayfa, HER CMap ile ayri cozulur -> sayfa basina metin varyantlari.
$pages = array();
foreach ($streams as $s) {
    if (strpos($s, 'Tj') === false || strpos($s, 'BT') === false) { continue; }
    $variants = array();
    foreach ($cmaps as $cm) {
        $t = decode_content($s, $cm);
        if (trim($t) !== '') { $variants[] = $t; }
    }
    if (!empty($variants)) { $pages[] = $variants; }
}

// --- MediaBox / sayfa sayisi ----------------------------------------------
$all = $raw . "\n" . implode("\n", $streams);
preg_match_all('#/Type\s*/Page(?![s])#', $all, $pm);
$pageCount = count($pm[0]);

$boxes = array();
if (preg_match_all('#/MediaBox\s*\[\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s*\]#', $all, $bm, PREG_SET_ORDER)) {
    foreach ($bm as $b) {
        $w = (float) $b[3] - (float) $b[1];
        $h = (float) $b[4] - (float) $b[2];
        $boxes[sprintf('%.0fx%.0f', $w, $h)] = ($w > $h) ? 'YATAY (landscape)' : 'DIKEY (portrait)';
    }
}

echo "Dosya        : " . basename($path) . " (" . filesize($path) . " bayt)\n";
echo "Sayfa sayisi : {$pageCount}\n";
foreach ($boxes as $dim => $orient) { echo "MediaBox     : {$dim} pt -> {$orient}\n"; }
echo "Cozulen icerik akisi (sayfa): " . count($pages) . "\n";
echo "Font CMap sayisi: " . count($cmaps) . " (girdiler: "
    . implode('/', array_map('count', $cmaps)) . ")\n";

// --dump=<regex>: her sayfada eslesenleri listeler. Satirlarin sayfa sinirinda
// BOLUNUP bolunmedigini kanitlamak icin: ayni kayit iki sayfada gorunuyorsa
// satir bolunmustur.
foreach ($argv as $arg) {
    if (strpos($arg, '--dump=') !== 0) { continue; }
    $re = substr($arg, 7);
    $seen = array();
    $dupPages = array();
    foreach ($pages as $idx => $variants) {
        $hits = array();
        foreach ($variants as $t) {
            if (preg_match_all('/' . $re . '/u', $t, $mm)) {
                foreach ($mm[0] as $h) { $hits[$h] = true; }
            }
        }
        $hits = array_keys($hits);
        sort($hits);
        echo 'Sayfa ' . ($idx + 1) . ': ' . count($hits) . ' eslesme'
            . (empty($hits) ? '' : '  ilk=' . $hits[0] . '  son=' . $hits[count($hits) - 1]) . "\n";
        foreach ($hits as $h) {
            if (isset($seen[$h])) { $dupPages[$h] = $seen[$h] . '+' . ($idx + 1); }
            $seen[$h] = $idx + 1;
        }
    }
    echo 'Toplam benzersiz: ' . count($seen) . "\n";
    echo 'BIRDEN FAZLA SAYFADA gorunen (satir bolunmesi belirtisi): '
        . (empty($dupPages) ? 'YOK' : implode(', ', array_map(function ($k, $v) { return $k . '(' . $v . ')'; }, array_keys($dupPages), $dupPages))) . "\n";
}

for ($i = 2; $i < count($argv); $i++) {
    $needle = $argv[$i];
    if (strpos($needle, '--dump=') === 0) { continue; }
    $counts = array();
    $totalHits = 0;
    foreach ($pages as $variants) {
        // Sayfadaki en yuksek eslesme (dogru fontun CMap'i).
        $best = 0;
        foreach ($variants as $t) { $best = max($best, substr_count($t, $needle)); }
        $counts[] = $best;
        $totalHits += $best;
    }
    $onEvery = count($counts) > 0 && !in_array(0, $counts, true);
    $perPage = array();
    foreach ($counts as $idx => $n) { $perPage[] = ($idx + 1) . ':' . $n; }
    echo "'{$needle}' -> toplam {$totalHits} | sayfa basina [" . implode(' ', $perPage) . "]"
        . ($onEvery ? '  >>> HER SAYFADA VAR' : ($totalHits === 0 ? '  >>> HIC YOK' : '')) . "\n";
}
