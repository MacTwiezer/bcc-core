<?php

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
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $text);

    return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function bcc_xlsx_sheet_xml(array $headers, array $rows, array $preamble = array())
{
    $allRows = array_merge($preamble, array($headers), $rows);

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

function bcc_xlsx_sanitize_sheet_title($title)
{
    $title = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', (string) $title);
    $title = trim($title);
    $title = mb_substr($title, 0, 31, 'UTF-8');

    return $title !== '' ? $title : 'Sayfa1';
}

function bcc_xlsx_build_temp_file($sheetTitle, array $headers, array $rows, array $preamble = array())
{
    $sheetTitle = bcc_xlsx_sanitize_sheet_title($sheetTitle);

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

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
        . '<cellXfs count="1"><xf/></cellXfs>'
        . '</styleSheet>';

    $sheetXml = bcc_xlsx_sheet_xml($headers, $rows, $preamble);

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

function bcc_send_xlsx($filename, $sheetTitle, array $headers, array $rows, array $preamble = array())
{
    $tmpPath = bcc_xlsx_build_temp_file($sheetTitle, $headers, $rows, $preamble);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    header('Content-Length: ' . filesize($tmpPath));

    readfile($tmpPath);
    unlink($tmpPath);
}
