<?php
// Gerçek .xlsx üretimi — dış kütüphane (PhpSpreadsheet vb.) YOK, proje
// kuralı gereği yasak. Bunun yerine PHP'nin kendi ZipArchive eklentisiyle
// (xlsx aslında birkaç XML dosyasını içeren bir zip arşividir) minimal ama
// Excel'in sorunsuz açtığı geçerli bir .xlsx elle inşa ediliyor.
//
// Basitleştirme: tüm hücreler "inlineStr" (satır-içi metin) olarak yazılıyor
// — ayrı bir sharedStrings.xml tablosu YOK, sayı/tarih hücre tipi YOK (bu
// export'lardaki veri zaten metin: e-posta, isim, "Evet/Hayır", tarih
// string'i) — Excel'de düz metin olarak görünür, hesaplama gerekmiyor.

function bcc_xlsx_col_letter($index)
{
    $letter = '';
    $index++;

    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letter = chr(65 + $mod) . $letter;
        $index = (int) (($index - $mod) / 26);
    }

    return $letter;
}

function bcc_xlsx_escape($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function bcc_xlsx_sheet_xml(array $headers, array $rows)
{
    $allRows = array_merge(array($headers), $rows);

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

    foreach ($allRows as $rowIndex => $row) {
        $rowNum = $rowIndex + 1;
        $xml .= '<row r="' . $rowNum . '">';

        foreach (array_values($row) as $colIndex => $value) {
            $ref = bcc_xlsx_col_letter($colIndex) . $rowNum;
            $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . bcc_xlsx_escape($value) . '</t></is></c>';
        }

        $xml .= '</row>';
    }

    $xml .= '</sheetData></worksheet>';

    return $xml;
}

/**
 * $headers: sütun başlıkları düz dizi, örn. array('E-posta', 'Ad Soyad').
 * $rows: her biri $headers ile aynı sırada değerler içeren düz diziler.
 * Dönüş: geçici bir .xlsx dosyasının tam yolu (çağıran taraf gönderip silmeli).
 */
function bcc_xlsx_build_temp_file($sheetTitle, array $headers, array $rows)
{
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . bcc_xlsx_escape($sheetTitle) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    // Excel hücre stili tanımlamasa bile en az bir <xf> girişi bekler
    // (varsayılan stil index 0) — hiçbir hücreye stil uygulamıyoruz, bu
    // yüzden içerik sade ama geçerli.
    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
        . '<cellXfs count="1"><xf/></cellXfs>'
        . '</styleSheet>';

    $sheetXml = bcc_xlsx_sheet_xml($headers, $rows);

    $tmpPath = tempnam(sys_get_temp_dir(), 'bcc_xlsx_');

    $zip = new ZipArchive();
    $zip->open($tmpPath, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    return $tmpPath;
}

/**
 * $headers/$rows bcc_xlsx_build_temp_file() ile aynı. HTTP başlıklarını
 * ayarlar, dosyayı tarayıcıya gönderir, geçici dosyayı temizler.
 */
function bcc_send_xlsx($filename, $sheetTitle, array $headers, array $rows)
{
    $tmpPath = bcc_xlsx_build_temp_file($sheetTitle, $headers, $rows);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    header('Content-Length: ' . filesize($tmpPath));

    readfile($tmpPath);
    unlink($tmpPath);
}
