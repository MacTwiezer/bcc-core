<?php
// Gerçek .xlsx OKUMA — dış kütüphane (PhpSpreadsheet vb.) YOK, proje kuralı
// gereği yasak (bkz. xlsx_writer.php, aynı ilke, ters yön). ZipArchive ile
// paketi aç, SimpleXML ile içindeki 3 parçayı oku: sharedStrings.xml (paylaşılan
// metin havuzu), styles.xml (hangi hücre stilinin "tarih" biçimi olduğunu
// anlamak için), ve ilk sheet'in XML'i (workbook.xml + workbook.xml.rels
// üzerinden gerçek yolu çözülür, "sheet1.xml" sabit varsayılmaz).
//
// table_import_xlsx.php'nin ihtiyacı budur: satır 0 = başlık, sonrası veri —
// tıpkı fgetcsv()'in ürettiği diziye benzer bir çıktı, tek fark hücre
// pozisyonları (sparse <c r="C7">) sütun referansından çözülüp '' ile
// doldurularak yoğunlaştırılıyor (boş hücreler sonraki sütunları kaydırmasın).

// bcc_xlsx_col_letter()'ın (xlsx_writer.php) tersi: "AA" -> 26 (0-index).
function bcc_xlsx_col_letters_to_index($letters)
{
    $letters = strtoupper($letters);
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }

    return $index - 1;
}

// "C7" -> 2 (sütun kısmı, satır numarası burada kullanılmıyor — satırlar zaten
// belge sırasıyla işleniyor).
function bcc_xlsx_cell_ref_col_index($ref)
{
    if (preg_match('/^([A-Z]+)\d+$/', $ref, $m) === 1) {
        return bcc_xlsx_col_letters_to_index($m[1]);
    }

    return null;
}

// sharedStrings.xml: her <si> ya doğrudan <t> içerir ya da zengin metin
// parçalarına (<r><t>...) bölünmüştür — ikinci durumda parçalar birleştirilir.
// Kendi xlsx_writer.php'miz sharedStrings hiç üretmiyor (yalnızca inlineStr) —
// bu tablo yalnızca GERÇEK Excel'de oluşturulmuş dosyalarda devreye girer.
function bcc_xlsx_read_shared_strings(ZipArchive $zip)
{
    $xmlStr = $zip->getFromName('xl/sharedStrings.xml');
    if ($xmlStr === false) {
        return array();
    }

    $xml = @simplexml_load_string($xmlStr);
    if ($xml === false) {
        return array();
    }

    $strings = array();
    foreach ($xml->si as $si) {
        if (isset($si->t)) {
            $strings[] = (string) $si->t;
            continue;
        }

        $text = '';
        foreach ($si->r as $r) {
            $text .= (string) $r->t;
        }
        $strings[] = $text;
    }

    return $strings;
}

// Format kodunun (ör. "dd.mm.yyyy" ya da "0.00") tarih/saat mi olduğunu anlamak
// için: tırnak içi literal metinler ("gün " gibi) ve köşeli parantez bölümleri
// ([Red], [$-41F] gibi renk/locale kodları) atılır, kalanda y/m/d/h/s karakteri
// varsa tarih/saat sayılır.
function bcc_xlsx_format_code_looks_like_date($code)
{
    $stripped = preg_replace('/"[^"]*"/', '', (string) $code);
    $stripped = preg_replace('/\[[^\]]*\]/', '', $stripped);

    return preg_match('/[ymdhs]/i', $stripped) === 1;
}

// styles.xml -> cellXfs listesindeki HER stil index'i (hücrenin s="N" attribute'ü
// bu diziye göredir) için "bu bir tarih/saat biçimi mi" haritası. Built-in
// numFmtId 14-22 ve 45-47 Excel'in standart tarih/saat biçimleridir; 164+
// numFmtId'ler dosyaya özel (custom) biçimlerdir, <numFmts> içinde tanımlanır.
function bcc_xlsx_read_date_style_map(ZipArchive $zip)
{
    $xmlStr = $zip->getFromName('xl/styles.xml');
    if ($xmlStr === false) {
        return array();
    }

    $xml = @simplexml_load_string($xmlStr);
    if ($xml === false) {
        return array();
    }

    $customFormats = array();
    if (isset($xml->numFmts)) {
        foreach ($xml->numFmts->numFmt as $nf) {
            $customFormats[(int) $nf['numFmtId']] = (string) $nf['formatCode'];
        }
    }

    $builtinDateIds = array(14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47);

    $dateStyleMap = array();
    if (isset($xml->cellXfs)) {
        $i = 0;
        foreach ($xml->cellXfs->xf as $xf) {
            $numFmtId = isset($xf['numFmtId']) ? (int) $xf['numFmtId'] : 0;

            if (in_array($numFmtId, $builtinDateIds, true)) {
                $dateStyleMap[$i] = true;
            } elseif (isset($customFormats[$numFmtId])) {
                $dateStyleMap[$i] = bcc_xlsx_format_code_looks_like_date($customFormats[$numFmtId]);
            } else {
                $dateStyleMap[$i] = false;
            }

            $i++;
        }
    }

    return $dateStyleMap;
}

// Excel'in seri tarih sayısını (gün 1 = 1900-01-01, Excel'in bilinen "1900 artık
// yıl" hatasıyla birlikte) 'Y-m-d' metnine çevirir. 1899-12-30 epoch'u bu hatayı
// zaten telafi ediyor — yaygın kabul gören standart dönüşüm budur (saat kısmı
// varsa yok sayılır, bu alanların date tipi zaten yalnızca gün tutuyor).
function bcc_xlsx_serial_to_date($serial)
{
    $days = (int) floor((float) $serial);
    $date = new DateTime('1899-12-30');
    $date->modify('+' . $days . ' days');

    return $date->format('Y-m-d');
}

// workbook.xml'deki İLK <sheet>'in r:id'sini workbook.xml.rels'te çözüp gerçek
// worksheet XML yolunu döndürür — "sheet1.xml" sabit varsayılmaz (Excel sheet'i
// silip yeniden eklerse ilk sheet'in dosya adı sheet2.xml olabilir).
function bcc_xlsx_first_sheet_path(ZipArchive $zip)
{
    $fallback = 'xl/worksheets/sheet1.xml';

    $workbookXmlStr = $zip->getFromName('xl/workbook.xml');
    if ($workbookXmlStr === false) {
        return $fallback;
    }

    $workbookXml = @simplexml_load_string($workbookXmlStr);
    if ($workbookXml === false || !isset($workbookXml->sheets->sheet[0])) {
        return $fallback;
    }

    $rNamespace = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $rAttrs = $workbookXml->sheets->sheet[0]->attributes($rNamespace);
    $rId = isset($rAttrs['id']) ? (string) $rAttrs['id'] : '';
    if ($rId === '') {
        return $fallback;
    }

    $relsXmlStr = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($relsXmlStr === false) {
        return $fallback;
    }

    $relsXml = @simplexml_load_string($relsXmlStr);
    if ($relsXml === false) {
        return $fallback;
    }

    foreach ($relsXml->Relationship as $rel) {
        if ((string) $rel['Id'] === $rId) {
            $target = (string) $rel['Target'];

            return (strpos($target, '/') === 0) ? ltrim($target, '/') : ('xl/' . $target);
        }
    }

    return $fallback;
}

// Sheet XML'indeki <row>/<c> hücrelerini okuyup satır dizisine çevirir. Her
// hücre tipine göre (inlineStr/paylaşılan string/formül sonucu/sayı/tarih)
// metne çözülür; sütun pozisyonu hücre referansından (r="C7") gelir ki Excel'in
// atladığı boş hücreler sonraki sütunları kaydırmasın — her satır, o dosyada
// görülen EN GENİŞ satırın uzunluğuna göre '' ile doldurularak yoğunlaştırılır.
function bcc_xlsx_parse_sheet_rows($sheetXmlStr, array $sharedStrings, array $dateStyleMap)
{
    $xml = @simplexml_load_string($sheetXmlStr);
    if ($xml === false || !isset($xml->sheetData)) {
        return array();
    }

    $sparseRows = array();
    $maxCol = -1;

    foreach ($xml->sheetData->row as $rowEl) {
        $cellMap = array();

        foreach ($rowEl->c as $cellEl) {
            $ref = (string) $cellEl['r'];
            $colIndex = ($ref !== '') ? bcc_xlsx_cell_ref_col_index($ref) : null;
            if ($colIndex === null) {
                continue;
            }

            $styleIndex = isset($cellEl['s']) ? (int) $cellEl['s'] : 0;
            $cellType = isset($cellEl['t']) ? (string) $cellEl['t'] : '';

            if ($cellType === 'inlineStr') {
                $value = isset($cellEl->is->t) ? (string) $cellEl->is->t : '';
            } elseif ($cellType === 's') {
                $idx = isset($cellEl->v) ? (int) $cellEl->v : -1;
                $value = ($idx >= 0 && isset($sharedStrings[$idx])) ? $sharedStrings[$idx] : '';
            } elseif ($cellType === 'str') {
                $value = isset($cellEl->v) ? (string) $cellEl->v : '';
            } elseif ($cellType === 'b') {
                $value = (isset($cellEl->v) && (string) $cellEl->v === '1') ? 'TRUE' : 'FALSE';
            } else {
                // t attribute yoksa ya da t="n" — düz sayı hücresi (formüllerin
                // önbelleklenmiş sonucu da buraya düşer).
                $raw = isset($cellEl->v) ? (string) $cellEl->v : '';
                $isDateStyle = isset($dateStyleMap[$styleIndex]) && $dateStyleMap[$styleIndex];
                $value = ($raw !== '' && $isDateStyle && is_numeric($raw)) ? bcc_xlsx_serial_to_date($raw) : $raw;
            }

            $cellMap[$colIndex] = $value;
            if ($colIndex > $maxCol) {
                $maxCol = $colIndex;
            }
        }

        $sparseRows[] = $cellMap;
    }

    $rows = array();
    foreach ($sparseRows as $cellMap) {
        $dense = array();
        for ($i = 0; $i <= $maxCol; $i++) {
            $dense[] = isset($cellMap[$i]) ? $cellMap[$i] : '';
        }
        $rows[] = $dense;
    }

    return $rows;
}

/**
 * $filePath: yüklenen .xlsx'in geçici yolu (tmp_name). Dönüş: satır dizisi,
 * her satır 0-index'li hücre değerleri (string) dizisi — ilk satır başlık.
 * Dosya açılamazsa/bozuksa boş dizi döner (çağıran taraf bunu "0 satır"
 * olarak ele alıp kullanıcıya hata gösterir, exception fırlatılmaz).
 */
function bcc_xlsx_read_first_sheet($filePath)
{
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return array();
    }

    $sharedStrings = bcc_xlsx_read_shared_strings($zip);
    $dateStyleMap = bcc_xlsx_read_date_style_map($zip);
    $sheetPath = bcc_xlsx_first_sheet_path($zip);

    $sheetXmlStr = $zip->getFromName($sheetPath);
    if ($sheetXmlStr === false) {
        $sheetXmlStr = $zip->getFromName('xl/worksheets/sheet1.xml');
    }

    $zip->close();

    if ($sheetXmlStr === false) {
        return array();
    }

    return bcc_xlsx_parse_sheet_rows($sheetXmlStr, $sharedStrings, $dateStyleMap);
}
