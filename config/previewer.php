<?php
declare(strict_types=1);

/**
 * Pure-PHP document preview helpers for Office (DOCX, XLSX, PPTX, DOC, XLS, PPT),
 * CSV, and ZIP files without requiring php_zip or external binaries.
 */

function loadSafeXml(string $xmlString): ?DOMDocument
{
    if ($xmlString === '') {
        return null;
    }
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $ok = @$dom->loadXML($xmlString, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $ok ? $dom : null;
}

function readZipIndex(string $filePath): array
{
    $size = @filesize($filePath);
    if (!is_int($size) || $size < 22) {
        return [];
    }

    $fh = @fopen($filePath, 'rb');
    if ($fh === false) {
        return [];
    }

    try {
        $tailLen = min($size, 65557);
        fseek($fh, $size - $tailLen);
        $tail = fread($fh, $tailLen);
        if ($tail === false) {
            return [];
        }

        $eocdPos = strrpos($tail, "\x50\x4b\x05\x06");
        if ($eocdPos === false || strlen($tail) - $eocdPos < 22) {
            return [];
        }

        $eocd = unpack('vtotal/Vcd_size/Vcd_offset', substr($tail, $eocdPos + 10, 10));
        if (!is_array($eocd)) {
            return [];
        }

        $cdOffset = (int) $eocd['cd_offset'];
        $cdSize = (int) $eocd['cd_size'];
        if ($cdOffset < 0 || $cdSize <= 0 || $cdOffset + $cdSize > $size || $cdSize > 32 * 1024 * 1024) {
            return [];
        }

        fseek($fh, $cdOffset);
        $cdData = fread($fh, $cdSize);
        if ($cdData === false) {
            return [];
        }

        $entries = [];
        $pos = 0;
        $len = strlen($cdData);
        while ($pos + 46 <= $len) {
            if (substr($cdData, $pos, 4) !== "\x50\x4b\x01\x02") {
                break;
            }
            $hdr = unpack(
                'vmethod/vmtime/vmdate/Vcrc/Vcomp/Vuncomp/vfn_len/vex_len/vcm_len/vdisk/vint_attr/Vext_attr/Vlocal_off',
                substr($cdData, $pos + 10, 36)
            );
            if (!is_array($hdr)) {
                break;
            }

            $fnLen = (int) $hdr['fn_len'];
            $exLen = (int) $hdr['ex_len'];
            $cmLen = (int) $hdr['cm_len'];
            if ($pos + 46 + $fnLen + $exLen + $cmLen > $len) {
                break;
            }

            $name = substr($cdData, $pos + 46, $fnLen);
            $dosTime = (int) $hdr['mtime'];
            $dosDate = (int) $hdr['mdate'];
            $year = (($dosDate >> 9) & 0x7f) + 1980;
            $month = ($dosDate >> 5) & 0x0f;
            $day = $dosDate & 0x1f;
            $hour = ($dosTime >> 11) & 0x1f;
            $min = ($dosTime >> 5) & 0x3f;
            $sec = ($dosTime & 0x1f) * 2;
            $modified = ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31)
                ? sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $min, $sec)
                : '';

            $entries[$name] = [
                'name' => $name,
                'method' => (int) $hdr['method'],
                'compressed_size' => (int) $hdr['comp'],
                'uncompressed_size' => (int) $hdr['uncomp'],
                'local_offset' => (int) $hdr['local_off'],
                'is_dir' => str_ends_with($name, '/'),
                'modified_at' => $modified,
            ];

            $pos += 46 + $fnLen + $exLen + $cmLen;
        }

        return $entries;
    } finally {
        fclose($fh);
    }
}

function readZipEntryContent(string $filePath, array $entries, string $entryName, int $maxBytes = 12 * 1024 * 1024): ?string
{
    if (!isset($entries[$entryName])) {
        return null;
    }
    $entry = $entries[$entryName];
    if (!empty($entry['is_dir']) || $entry['uncompressed_size'] > $maxBytes) {
        return null;
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($filePath) === true) {
            $data = $zip->getFromName($entryName);
            $zip->close();
            if (is_string($data)) {
                return $data;
            }
        }
    }

    $fh = @fopen($filePath, 'rb');
    if ($fh === false) {
        return null;
    }

    try {
        fseek($fh, (int) $entry['local_offset']);
        $localHdr = fread($fh, 30);
        if ($localHdr === false || strlen($localHdr) < 30 || substr($localHdr, 0, 4) !== "\x50\x4b\x03\x04") {
            return null;
        }
        $lh = unpack('vfn_len/vex_len', substr($localHdr, 26, 4));
        if (!is_array($lh)) {
            return null;
        }
        $skip = (int) $lh['fn_len'] + (int) $lh['ex_len'];
        if ($skip > 0) {
            fseek($fh, $skip, SEEK_CUR);
        }

        $compSize = (int) $entry['compressed_size'];
        if ($compSize === 0) {
            return '';
        }
        $raw = fread($fh, $compSize);
        if ($raw === false) {
            return null;
        }

        $method = (int) $entry['method'];
        if ($method === 0) {
            return $raw;
        }
        if ($method === 8 && function_exists('gzinflate')) {
            $inflated = @gzinflate($raw);
            return is_string($inflated) ? $inflated : null;
        }
        return null;
    } finally {
        fclose($fh);
    }
}

function parseOoxmlRels(string $filePath, array $entries, string $relsPath): array
{
    $xml = readZipEntryContent($filePath, $entries, $relsPath, 2 * 1024 * 1024);
    if ($xml === null) {
        return [];
    }
    $dom = loadSafeXml($xml);
    if ($dom === null) {
        return [];
    }

    $rels = [];
    foreach ($dom->getElementsByTagName('Relationship') as $rel) {
        if (!$rel instanceof DOMElement) {
            continue;
        }
        $id = $rel->getAttribute('Id');
        $target = $rel->getAttribute('Target');
        $mode = $rel->getAttribute('TargetMode');
        if ($id !== '' && $target !== '') {
            $rels[$id] = [
                'target' => $target,
                'external' => strcasecmp($mode, 'External') === 0,
            ];
        }
    }
    return $rels;
}

function normalizeZipPath(string $baseDir, string $target): string
{
    $combined = str_starts_with($target, '/')
        ? ltrim($target, '/')
        : ($baseDir !== '' ? ($baseDir . '/' . $target) : $target);

    $parts = explode('/', str_replace('\\', '/', $combined));
    $stack = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($stack);
        } else {
            $stack[] = $part;
        }
    }
    return implode('/', $stack);
}

function parseDocxNumberingFormats(string $filePath, array $entries): array
{
    $xml = readZipEntryContent($filePath, $entries, 'word/numbering.xml', 2 * 1024 * 1024);
    if ($xml === null) {
        return [];
    }
    $dom = loadSafeXml($xml);
    if ($dom === null) {
        return [];
    }

    $abstractFormats = [];
    foreach ($dom->getElementsByTagNameNS('*', 'abstractNum') as $abs) {
        if (!$abs instanceof DOMElement) {
            continue;
        }
        $absId = $abs->getAttributeNS('*', 'abstractNumId') ?: $abs->getAttribute('w:abstractNumId');
        if ($absId === '') {
            continue;
        }
        foreach ($abs->getElementsByTagNameNS('*', 'lvl') as $lvl) {
            if (!$lvl instanceof DOMElement) {
                continue;
            }
            $ilvl = $lvl->getAttributeNS('*', 'ilvl') ?: $lvl->getAttribute('w:ilvl');
            $fmtNode = $lvl->getElementsByTagNameNS('*', 'numFmt')->item(0);
            if ($fmtNode instanceof DOMElement) {
                $fmt = $fmtNode->getAttributeNS('*', 'val') ?: $fmtNode->getAttribute('w:val');
                $abstractFormats[$absId][$ilvl !== '' ? $ilvl : '0'] = $fmt;
            }
        }
    }

    $numMap = [];
    foreach ($dom->getElementsByTagNameNS('*', 'num') as $num) {
        if (!$num instanceof DOMElement) {
            continue;
        }
        $numId = $num->getAttributeNS('*', 'numId') ?: $num->getAttribute('w:numId');
        $absRef = $num->getElementsByTagNameNS('*', 'abstractNumId')->item(0);
        if ($numId !== '' && $absRef instanceof DOMElement) {
            $absId = $absRef->getAttributeNS('*', 'val') ?: $absRef->getAttribute('w:val');
            if ($absId !== '' && isset($abstractFormats[$absId])) {
                $numMap[$numId] = $abstractFormats[$absId];
            }
        }
    }
    return $numMap;
}

function getWAttr(DOMElement $el, string $name): string
{
    $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $val = $el->getAttributeNS($wNs, $name);
    if ($val !== '') {
        return $val;
    }
    $val = $el->getAttribute('w:' . $name);
    return $val !== '' ? $val : $el->getAttribute($name);
}

function renderDocxInlineNodes(DOMElement $parent, string $filePath, array $entries, array $rels, array &$mediaBudget): string
{
    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '';

    foreach ($parent->childNodes as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }
        $local = $child->localName;

        if ($local === 'hyperlink') {
            $rId = $child->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')
                ?: $child->getAttribute('r:id');
            $inner = renderDocxInlineNodes($child, $filePath, $entries, $rels, $mediaBudget);
            $href = '';
            if ($rId !== '' && isset($rels[$rId]) && $rels[$rId]['external']) {
                $candidate = $rels[$rId]['target'];
                if (preg_match('/^(https?:|mailto:)/i', $candidate)) {
                    $href = $candidate;
                }
            }
            $html .= $href !== ''
                ? '<a href="' . $esc($href) . '" target="_blank" rel="noopener noreferrer">' . $inner . '</a>'
                : $inner;
            continue;
        }

        if ($local === 'smartTag' || $local === 'ins' || $local === 'sdt') {
            $target = $local === 'sdt'
                ? ($child->getElementsByTagNameNS('*', 'sdtContent')->item(0) ?? $child)
                : $child;
            if ($target instanceof DOMElement) {
                $html .= renderDocxInlineNodes($target, $filePath, $entries, $rels, $mediaBudget);
            }
            continue;
        }

        if ($local !== 'r') {
            continue;
        }

        $rPr = null;
        foreach ($child->childNodes as $sub) {
            if ($sub instanceof DOMElement && $sub->localName === 'rPr') {
                $rPr = $sub;
                break;
            }
        }

        $isBold = false;
        $isItalic = false;
        $isUnderline = false;
        $isStrike = false;
        if ($rPr instanceof DOMElement) {
            foreach ($rPr->childNodes as $prop) {
                if (!$prop instanceof DOMElement) {
                    continue;
                }
                $val = strtolower(getWAttr($prop, 'val'));
                $enabled = $val !== '0' && $val !== 'false' && $val !== 'none';
                if ($prop->localName === 'b' && $enabled) {
                    $isBold = true;
                } elseif ($prop->localName === 'i' && $enabled) {
                    $isItalic = true;
                } elseif ($prop->localName === 'u' && $enabled) {
                    $isUnderline = true;
                } elseif ($prop->localName === 'strike' && $enabled) {
                    $isStrike = true;
                }
            }
        }

        $runHtml = '';
        foreach ($child->childNodes as $sub) {
            if (!$sub instanceof DOMElement) {
                continue;
            }
            switch ($sub->localName) {
                case 't':
                    $runHtml .= $esc($sub->textContent);
                    break;
                case 'br':
                    $runHtml .= '<br>';
                    break;
                case 'tab':
                    $runHtml .= '&emsp;';
                    break;
                case 'drawing':
                case 'pict':
                    $blip = $sub->getElementsByTagNameNS('*', 'blip')->item(0);
                    $rId = '';
                    if ($blip instanceof DOMElement) {
                        $rId = $blip->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed')
                            ?: $blip->getAttribute('r:embed');
                    } else {
                        $imgData = $sub->getElementsByTagNameNS('*', 'imagedata')->item(0);
                        if ($imgData instanceof DOMElement) {
                            $rId = $imgData->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')
                                ?: $imgData->getAttribute('r:id');
                        }
                    }
                    if ($rId !== '' && isset($rels[$rId]) && !$rels[$rId]['external']) {
                        $mediaPath = normalizeZipPath('word', $rels[$rId]['target']);
                        $ext = strtolower(pathinfo($mediaPath, PATHINFO_EXTENSION));
                        $mimeMap = [
                            'png' => 'image/png',
                            'jpg' => 'image/jpeg',
                            'jpeg' => 'image/jpeg',
                            'gif' => 'image/gif',
                            'webp' => 'image/webp',
                        ];
                        if (isset($mimeMap[$ext], $entries[$mediaPath])) {
                            $imgSize = (int) $entries[$mediaPath]['uncompressed_size'];
                            if ($imgSize > 0 && $imgSize <= 1500000 && ($mediaBudget['used'] + $imgSize) <= 4500000) {
                                $bytes = readZipEntryContent($filePath, $entries, $mediaPath, 1500000);
                                if (is_string($bytes) && $bytes !== '') {
                                    $mediaBudget['used'] += strlen($bytes);
                                    $runHtml .= '<img class="doc-inline-img" src="data:' . $mimeMap[$ext] . ';base64,' . base64_encode($bytes) . '" alt="Embedded image">';
                                    break;
                                }
                            }
                            $runHtml .= '<span class="doc-media-placeholder">[Image: ' . $esc(basename($mediaPath)) . ']</span>';
                        }
                    }
                    break;
            }
        }

        if ($runHtml === '') {
            continue;
        }
        if ($isBold) {
            $runHtml = '<strong>' . $runHtml . '</strong>';
        }
        if ($isItalic) {
            $runHtml = '<em>' . $runHtml . '</em>';
        }
        if ($isUnderline) {
            $runHtml = '<u>' . $runHtml . '</u>';
        }
        if ($isStrike) {
            $runHtml = '<s>' . $runHtml . '</s>';
        }
        $html .= $runHtml;
    }

    return $html;
}

function renderDocxParagraph(DOMElement $p, string $filePath, array $entries, array $rels, array $numMap, array &$mediaBudget): array
{
    $pStyle = '';
    $align = '';
    $numId = '';
    $ilvl = '0';

    foreach ($p->childNodes as $child) {
        if (!$child instanceof DOMElement || $child->localName !== 'pPr') {
            continue;
        }
        foreach ($child->childNodes as $prop) {
            if (!$prop instanceof DOMElement) {
                continue;
            }
            if ($prop->localName === 'pStyle') {
                $pStyle = getWAttr($prop, 'val');
            } elseif ($prop->localName === 'jc') {
                $jc = strtolower(getWAttr($prop, 'val'));
                if ($jc === 'both') {
                    $jc = 'justify';
                }
                if (in_array($jc, ['left', 'center', 'right', 'justify'], true)) {
                    $align = $jc;
                }
            } elseif ($prop->localName === 'numPr') {
                foreach ($prop->childNodes as $np) {
                    if ($np instanceof DOMElement && $np->localName === 'numId') {
                        $numId = getWAttr($np, 'val');
                    } elseif ($np instanceof DOMElement && $np->localName === 'ilvl') {
                        $ilvl = getWAttr($np, 'val') ?: '0';
                    }
                }
            }
        }
    }

    $inner = renderDocxInlineNodes($p, $filePath, $entries, $rels, $mediaBudget);
    if ($inner === '') {
        $inner = '&nbsp;';
    }

    $styleAttr = $align !== '' ? ' style="text-align:' . $align . '"' : '';

    if ($numId !== '' && $numId !== '0') {
        $fmt = strtolower((string) ($numMap[$numId][$ilvl] ?? 'bullet'));
        $listTag = ($fmt === 'bullet' || $fmt === 'none') ? 'ul' : 'ol';
        return ['list' => $listTag, 'html' => '<li' . $styleAttr . '>' . $inner . '</li>'];
    }

    $styleLower = strtolower($pStyle);
    $tag = 'p';
    $extraClass = '';
    if (preg_match('/^heading([1-4])$/', $styleLower, $m)) {
        $tag = 'h' . $m[1];
    } elseif ($styleLower === 'title') {
        $tag = 'h1';
        $extraClass = ' class="doc-title"';
    } elseif ($styleLower === 'subtitle') {
        $tag = 'p';
        $extraClass = ' class="doc-subtitle"';
    }

    return ['list' => null, 'html' => '<' . $tag . $extraClass . $styleAttr . '>' . $inner . '</' . $tag . '>'];
}

function renderDocxTable(DOMElement $tbl, string $filePath, array $entries, array $rels, array $numMap, array &$mediaBudget): string
{
    $rowsHtml = '';
    foreach ($tbl->childNodes as $tr) {
        if (!$tr instanceof DOMElement || $tr->localName !== 'tr') {
            continue;
        }
        $cellsHtml = '';
        foreach ($tr->childNodes as $tc) {
            if (!$tc instanceof DOMElement || $tc->localName !== 'tc') {
                continue;
            }
            $colspan = 1;
            foreach ($tc->childNodes as $sub) {
                if ($sub instanceof DOMElement && $sub->localName === 'tcPr') {
                    $gs = $sub->getElementsByTagNameNS('*', 'gridSpan')->item(0);
                    if ($gs instanceof DOMElement) {
                        $colspan = max(1, (int) getWAttr($gs, 'val'));
                    }
                }
            }

            $cellParts = [];
            foreach ($tc->childNodes as $sub) {
                if (!$sub instanceof DOMElement) {
                    continue;
                }
                if ($sub->localName === 'p') {
                    $pRes = renderDocxParagraph($sub, $filePath, $entries, $rels, $numMap, $mediaBudget);
                    $cellParts[] = $pRes['html'];
                } elseif ($sub->localName === 'tbl') {
                    $cellParts[] = renderDocxTable($sub, $filePath, $entries, $rels, $numMap, $mediaBudget);
                }
            }
            $colAttr = $colspan > 1 ? ' colspan="' . $colspan . '"' : '';
            $cellsHtml .= '<td' . $colAttr . '>' . implode('', $cellParts) . '</td>';
        }
        if ($cellsHtml !== '') {
            $rowsHtml .= '<tr>' . $cellsHtml . '</tr>';
        }
    }

    return $rowsHtml !== '' ? '<div class="doc-table-wrap"><table>' . $rowsHtml . '</table></div>' : '';
}

function buildDocxPreview(string $filePath): array
{
    $entries = readZipIndex($filePath);
    $docXml = readZipEntryContent($filePath, $entries, 'word/document.xml', 10 * 1024 * 1024);
    if ($docXml === null) {
        return ['kind' => 'unsupported', 'message' => 'Unable to read DOCX contents. Please download the file to view it.'];
    }

    $dom = loadSafeXml($docXml);
    if ($dom === null) {
        return ['kind' => 'unsupported', 'message' => 'Invalid DOCX XML structure. Please download the file to view it.'];
    }

    $body = $dom->getElementsByTagNameNS('*', 'body')->item(0);
    if (!$body instanceof DOMElement) {
        return ['kind' => 'unsupported', 'message' => 'Document body is empty.'];
    }

    $rels = parseOoxmlRels($filePath, $entries, 'word/_rels/document.xml.rels');
    $numMap = parseDocxNumberingFormats($filePath, $entries);
    $mediaBudget = ['used' => 0];

    $chunks = [];
    $openList = null;

    $closeList = static function () use (&$chunks, &$openList): void {
        if ($openList !== null) {
            $chunks[] = '</' . $openList . '>';
            $openList = null;
        }
    };

    $walkBody = static function (DOMElement $container) use (&$walkBody, &$chunks, &$openList, $closeList, $filePath, $entries, $rels, $numMap, &$mediaBudget): void {
        foreach ($container->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            if ($node->localName === 'p') {
                $res = renderDocxParagraph($node, $filePath, $entries, $rels, $numMap, $mediaBudget);
                if ($res['list'] !== null) {
                    if ($openList !== $res['list']) {
                        $closeList();
                        $openList = $res['list'];
                        $chunks[] = '<' . $openList . '>';
                    }
                    $chunks[] = $res['html'];
                } else {
                    $closeList();
                    $chunks[] = $res['html'];
                }
            } elseif ($node->localName === 'tbl') {
                $closeList();
                $chunks[] = renderDocxTable($node, $filePath, $entries, $rels, $numMap, $mediaBudget);
            } elseif ($node->localName === 'sdt') {
                $sdtContent = $node->getElementsByTagNameNS('*', 'sdtContent')->item(0);
                if ($sdtContent instanceof DOMElement) {
                    $walkBody($sdtContent);
                }
            }
        }
    };

    $walkBody($body);
    $closeList();

    $bodyHtml = implode("\n", $chunks);
    $fullHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
        . 'body{margin:0;padding:28px 34px;font-family:"Segoe UI",Arial,sans-serif;font-size:14px;line-height:1.6;color:#0f172a;background:#ffffff;word-break:break-word;}'
        . 'h1,h2,h3,h4{color:#0f172a;margin:18px 0 8px;line-height:1.3;}'
        . 'h1{font-size:22px;border-bottom:1px solid #e2e8f0;padding-bottom:6px;}'
        . 'h1.doc-title{font-size:24px;border-bottom:none;}'
        . '.doc-subtitle{font-size:15px;color:#475569;margin-top:-4px;margin-bottom:16px;}'
        . 'h2{font-size:18px;} h3{font-size:15.5px;} h4{font-size:14px;}'
        . 'p{margin:0 0 10px;}'
        . 'ul,ol{margin:0 0 12px;padding-left:24px;}'
        . 'li{margin-bottom:4px;}'
        . 'a{color:#2563eb;text-decoration:underline;}'
        . '.doc-table-wrap{overflow-x:auto;margin:14px 0;}'
        . 'table{border-collapse:collapse;width:100%;font-size:13px;}'
        . 'td,th{border:1px solid #cbd5e1;padding:7px 10px;vertical-align:top;}'
        . 'tr:first-child td{background:#f8fafc;}'
        . '.doc-inline-img{max-width:100%;height:auto;display:inline-block;margin:6px 0;border-radius:4px;}'
        . '.doc-media-placeholder{display:inline-block;padding:2px 8px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#475569;}'
        . '</style></head><body>'
        . '<div class="docx-page">' . $bodyHtml . '</div>'
        . '</body></html>';

    return ['kind' => 'html', 'html' => $fullHtml];
}

function colRefToIndex(string $cellRef): int
{
    if (!preg_match('/^([A-Z]+)/i', $cellRef, $m)) {
        return 0;
    }
    $letters = strtoupper($m[1]);
    $idx = 0;
    for ($i = 0, $n = strlen($letters); $i < $n; $i++) {
        $idx = ($idx * 26) + (ord($letters[$i]) - ord('A') + 1);
    }
    return max(0, $idx - 1);
}

function buildXlsxPreview(string $filePath): array
{
    $entries = readZipIndex($filePath);
    $wbXml = readZipEntryContent($filePath, $entries, 'xl/workbook.xml', 4 * 1024 * 1024);
    if ($wbXml === null) {
        return ['kind' => 'unsupported', 'message' => 'Unable to read XLSX workbook.'];
    }
    $wbDom = loadSafeXml($wbXml);
    if ($wbDom === null) {
        return ['kind' => 'unsupported', 'message' => 'Invalid XLSX workbook structure.'];
    }

    $sharedStrings = [];
    $ssXml = readZipEntryContent($filePath, $entries, 'xl/sharedStrings.xml', 10 * 1024 * 1024);
    if ($ssXml !== null) {
        $ssDom = loadSafeXml($ssXml);
        if ($ssDom !== null) {
            foreach ($ssDom->getElementsByTagNameNS('*', 'si') as $si) {
                $text = '';
                foreach ($si->getElementsByTagNameNS('*', 't') as $tNode) {
                    $text .= $tNode->textContent;
                }
                $sharedStrings[] = $text;
            }
        }
    }

    $rels = parseOoxmlRels($filePath, $entries, 'xl/_rels/workbook.xml.rels');
    $sheets = [];

    foreach ($wbDom->getElementsByTagNameNS('*', 'sheet') as $sheetEl) {
        if (!$sheetEl instanceof DOMElement) {
            continue;
        }
        $name = $sheetEl->getAttribute('name') ?: ('Sheet ' . (count($sheets) + 1));
        $rId = $sheetEl->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')
            ?: $sheetEl->getAttribute('r:id');

        if ($rId === '' || !isset($rels[$rId])) {
            continue;
        }
        $sheetPath = normalizeZipPath('xl', $rels[$rId]['target']);
        $sheetXml = readZipEntryContent($filePath, $entries, $sheetPath, 10 * 1024 * 1024);
        if ($sheetXml === null) {
            continue;
        }
        $sheetDom = loadSafeXml($sheetXml);
        if ($sheetDom === null) {
            continue;
        }

        $maxRows = 300;
        $maxCols = 40;
        $rows = [];
        $totalRows = 0;
        $truncated = false;
        $maxColSeen = 0;

        foreach ($sheetDom->getElementsByTagNameNS('*', 'row') as $rowEl) {
            if (!$rowEl instanceof DOMElement) {
                continue;
            }
            $totalRows++;
            if (count($rows) >= $maxRows) {
                $truncated = true;
                continue;
            }

            $rowCells = [];
            foreach ($rowEl->getElementsByTagNameNS('*', 'c') as $cellEl) {
                if (!$cellEl instanceof DOMElement) {
                    continue;
                }
                $ref = $cellEl->getAttribute('r');
                $colIdx = $ref !== '' ? colRefToIndex($ref) : count($rowCells);
                if ($colIdx >= $maxCols) {
                    $truncated = true;
                    continue;
                }
                $maxColSeen = max($maxColSeen, $colIdx);

                $type = $cellEl->getAttribute('t');
                $val = '';
                if ($type === 'inlineStr') {
                    foreach ($cellEl->getElementsByTagNameNS('*', 't') as $tNode) {
                        $val .= $tNode->textContent;
                    }
                } else {
                    $vNode = $cellEl->getElementsByTagNameNS('*', 'v')->item(0);
                    $rawVal = $vNode ? $vNode->textContent : '';
                    if ($type === 's') {
                        $val = $sharedStrings[(int) $rawVal] ?? '';
                    } elseif ($type === 'b') {
                        $val = $rawVal === '1' ? 'TRUE' : 'FALSE';
                    } else {
                        $val = $rawVal;
                    }
                }
                $rowCells[$colIdx] = $val;
            }

            if (!empty($rowCells)) {
                $rows[] = $rowCells;
            } else {
                $rows[] = [];
            }
        }

        $colCount = min($maxCols, $maxColSeen + 1);
        $normalizedRows = [];
        foreach ($rows as $rowMap) {
            $line = [];
            for ($c = 0; $c < $colCount; $c++) {
                $line[] = (string) ($rowMap[$c] ?? '');
            }
            $normalizedRows[] = $line;
        }

        $sheets[] = [
            'name' => $name,
            'rows' => $normalizedRows,
            'total_rows' => $totalRows,
            'truncated' => $truncated,
        ];

        if (count($sheets) >= 20) {
            break;
        }
    }

    if (empty($sheets)) {
        return ['kind' => 'unsupported', 'message' => 'No readable worksheets found in XLSX file.'];
    }

    return ['kind' => 'sheets', 'sheets' => $sheets];
}

function buildPptxPreview(string $filePath): array
{
    $entries = readZipIndex($filePath);
    $presXml = readZipEntryContent($filePath, $entries, 'ppt/presentation.xml', 4 * 1024 * 1024);
    if ($presXml === null) {
        return ['kind' => 'unsupported', 'message' => 'Unable to read PPTX presentation.'];
    }

    $rels = parseOoxmlRels($filePath, $entries, 'ppt/_rels/presentation.xml.rels');
    $slidePaths = [];
    $presDom = loadSafeXml($presXml);
    if ($presDom !== null) {
        foreach ($presDom->getElementsByTagNameNS('*', 'sldId') as $sldId) {
            if (!$sldId instanceof DOMElement) {
                continue;
            }
            $rId = $sldId->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')
                ?: $sldId->getAttribute('r:id');
            if ($rId !== '' && isset($rels[$rId])) {
                $slidePaths[] = normalizeZipPath('ppt', $rels[$rId]['target']);
            }
        }
    }

    if (empty($slidePaths)) {
        foreach (array_keys($entries) as $name) {
            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                $slidePaths[(int) $m[1]] = $name;
            }
        }
        ksort($slidePaths);
        $slidePaths = array_values($slidePaths);
    }

    $slides = [];
    $num = 0;
    foreach ($slidePaths as $path) {
        $num++;
        if ($num > 80) {
            break;
        }
        $sXml = readZipEntryContent($filePath, $entries, $path, 4 * 1024 * 1024);
        if ($sXml === null) {
            continue;
        }
        $sDom = loadSafeXml($sXml);
        if ($sDom === null) {
            continue;
        }

        $title = '';
        $blocks = [];
        foreach ($sDom->getElementsByTagNameNS('*', 'sp') as $sp) {
            if (!$sp instanceof DOMElement) {
                continue;
            }
            $ph = $sp->getElementsByTagNameNS('*', 'ph')->item(0);
            $phType = $ph instanceof DOMElement ? strtolower($ph->getAttribute('type')) : '';
            $isTitle = in_array($phType, ['title', 'ctrtitle'], true);

            $paras = [];
            foreach ($sp->getElementsByTagNameNS('*', 'p') as $p) {
                $line = '';
                foreach ($p->getElementsByTagNameNS('*', 't') as $t) {
                    $line .= $t->textContent;
                }
                $line = trim($line);
                if ($line !== '') {
                    $paras[] = $line;
                }
            }

            if (empty($paras)) {
                continue;
            }
            if ($isTitle && $title === '') {
                $title = implode(' ', $paras);
            } else {
                foreach ($paras as $line) {
                    $blocks[] = $line;
                }
            }
        }

        $tables = [];
        foreach ($sDom->getElementsByTagNameNS('*', 'tbl') as $tbl) {
            if (!$tbl instanceof DOMElement) {
                continue;
            }
            $tRows = [];
            foreach ($tbl->getElementsByTagNameNS('*', 'tr') as $tr) {
                if (!$tr instanceof DOMElement) {
                    continue;
                }
                $tCells = [];
                foreach ($tr->getElementsByTagNameNS('*', 'tc') as $tc) {
                    if (!$tc instanceof DOMElement) {
                        continue;
                    }
                    $cellText = [];
                    foreach ($tc->getElementsByTagNameNS('*', 't') as $t) {
                        $cellText[] = $t->textContent;
                    }
                    $tCells[] = trim(implode('', $cellText));
                }
                if (!empty($tCells)) {
                    $tRows[] = $tCells;
                }
            }
            if (!empty($tRows)) {
                $tables[] = $tRows;
            }
        }

        $slides[] = [
            'number' => $num,
            'title' => $title,
            'blocks' => $blocks,
            'tables' => $tables,
        ];
    }

    if (empty($slides)) {
        return ['kind' => 'unsupported', 'message' => 'No slides found in PPTX presentation.'];
    }

    return ['kind' => 'slides', 'slides' => $slides];
}

function buildCsvPreview(string $filePath): array
{
    $fh = @fopen($filePath, 'rb');
    if ($fh === false) {
        return ['kind' => 'unsupported', 'message' => 'Unable to read CSV file.'];
    }

    try {
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }

        $rows = [];
        $totalRows = 0;
        $truncated = false;
        $maxRows = 500;
        $maxCols = 50;

        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $totalRows++;
            if (count($rows) < $maxRows) {
                if (count($row) > $maxCols) {
                    $row = array_slice($row, 0, $maxCols);
                    $truncated = true;
                }
                $rows[] = array_map(static fn($v): string => (string) ($v ?? ''), $row);
            } else {
                $truncated = true;
            }
        }

        return [
            'kind' => 'table',
            'rows' => $rows,
            'total_rows' => $totalRows,
            'truncated' => $truncated,
        ];
    } finally {
        fclose($fh);
    }
}

function buildZipPreview(string $filePath): array
{
    $entries = readZipIndex($filePath);
    if (empty($entries)) {
        return ['kind' => 'unsupported', 'message' => 'Archive is empty or unreadable.'];
    }

    $items = [];
    $total = count($entries);
    $truncated = false;
    foreach ($entries as $e) {
        if (count($items) >= 500) {
            $truncated = true;
            break;
        }
        $items[] = [
            'name' => $e['name'],
            'size' => $e['uncompressed_size'],
            'compressed_size' => $e['compressed_size'],
            'is_dir' => $e['is_dir'],
            'modified_at' => $e['modified_at'],
        ];
    }

    return [
        'kind' => 'archive',
        'entries' => $items,
        'total_entries' => $total,
        'truncated' => $truncated,
    ];
}

function buildLegacyOfficePreview(string $filePath, string $ext): array
{
    $raw = @file_get_contents($filePath, false, null, 0, 2 * 1024 * 1024);
    if (!is_string($raw) || $raw === '') {
        return ['kind' => 'unsupported', 'message' => "Preview unavailable for .{$ext} file."];
    }

    $chunks = [];

    // Extract UTF-16LE readable runs (common in legacy Word/Excel/PowerPoint OLE streams)
    if (preg_match_all('/(?:[\x20-\x7e\r\n\t]\x00){8,}/', $raw, $uMatches)) {
        foreach ($uMatches[0] as $seg) {
            $decoded = @mb_convert_encoding($seg, 'UTF-8', 'UTF-16LE');
            if (is_string($decoded)) {
                $clean = trim(preg_replace('/\s{3,}/', "\n", $decoded) ?? '');
                if ($clean !== '' && !preg_match('/^(Root Entry|WordDocument|SummaryInformation|DocumentSummaryInformation|Workbook|PowerPoint Document|Times New Roman|Calibri|Arial)/i', $clean)) {
                    $chunks[] = $clean;
                }
            }
        }
    }

    // Fallback/supplement with ASCII readable runs if UTF-16LE yielded little
    if (strlen(implode("\n", $chunks)) < 80 && preg_match_all('/[\x20-\x7e\r\n\t]{12,}/', $raw, $aMatches)) {
        foreach ($aMatches[0] as $seg) {
            $clean = trim($seg);
            if ($clean !== '' && preg_match('/[A-Za-z]{3,}/', $clean) && !preg_match('/^(Root Entry|Microsoft|SummaryInformation|DocumentSummaryInformation)/i', $clean)) {
                $chunks[] = $clean;
            }
        }
    }

    $text = trim(implode("\n\n", array_unique($chunks)));
    if (strlen($text) > 40000) {
        $text = substr($text, 0, 40000) . "\n\n... [preview truncated]";
    }

    if ($text === '') {
        return [
            'kind' => 'unsupported',
            'message' => "Binary .{$ext} file cannot be rendered inline. Download the file to open it in Microsoft Office or LibreOffice.",
        ];
    }

    return [
        'kind' => 'text',
        'text' => $text,
        'note' => "Extracted text preview for legacy .{$ext} format. Download the file for full layout and formatting.",
    ];
}

function buildDocumentPreview(string $filePath, string $ext): array
{
    $ext = strtolower($ext);
    return match ($ext) {
        'docx' => buildDocxPreview($filePath),
        'xlsx' => buildXlsxPreview($filePath),
        'pptx' => buildPptxPreview($filePath),
        'csv' => buildCsvPreview($filePath),
        'zip' => buildZipPreview($filePath),
        'doc', 'xls', 'ppt' => buildLegacyOfficePreview($filePath, $ext),
        default => ['kind' => 'unsupported', 'message' => "Inline preview unavailable for .{$ext} files."],
    };
}
