<?php

function bcc_xlsx_col_letters_to_index($letters)
{
    $letters = strtoupper($letters);
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }

    return $index - 1;
}

function bcc_xlsx_cell_ref_col_index($ref)
{
    if (preg_match('/^([A-Z]+)\d+$/', $ref, $m) === 1) {
        return bcc_xlsx_col_letters_to_index($m[1]);
    }

    return null;
}

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

function bcc_xlsx_format_code_looks_like_date($code)
{
    $stripped = preg_replace('/"[^"]*"/', '', (string) $code);
    $stripped = preg_replace('/\[[^\]]*\]/', '', $stripped);

    return preg_match('/[ymdhs]/i', $stripped) === 1;
}

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

function bcc_xlsx_serial_to_date($serial)
{
    $days = (int) floor((float) $serial);
    $date = new DateTime('1899-12-30');
    $date->modify('+' . $days . ' days');

    return $date->format('Y-m-d');
}

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

function bcc_xlsx_uncompressed_size($filePath)
{
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return -1;
    }

    $toplam = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat !== false && isset($stat['size'])) {
            $toplam += (int) $stat['size'];
        }
    }
    $zip->close();

    return $toplam;
}

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
