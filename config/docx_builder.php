<?php
declare(strict_types=1);

/**
 * Pure-PHP HTML-to-DOCX builder (no php_zip extension required).
 */

function buildZipBinary(array $files): string
{
    $data = '';
    $centralDir = '';
    $offset = 0;

    foreach ($files as $name => $content) {
        $name = str_replace('\\', '/', (string) $name);
        $raw = (string) $content;
        $crc = crc32($raw);
        $uncompSize = strlen($raw);
        $comp = gzdeflate($raw, 6);
        if ($comp === false) {
            $comp = $raw;
            $method = 0;
        } else {
            $method = 8;
        }
        $compSize = strlen($comp);
        $nameLen = strlen($name);

        $localHeader = "\x50\x4b\x03\x04"
            . pack('vvvvvVVVvv', 20, 0, $method, 0, 0, $crc, $compSize, $uncompSize, $nameLen, 0)
            . $name;

        $data .= $localHeader . $comp;

        $centralDir .= "\x50\x4b\x01\x02"
            . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, $method, 0, 0, $crc, $compSize, $uncompSize, $nameLen, 0, 0, 0, 0, 0, $offset)
            . $name;

        $offset += strlen($localHeader) + $compSize;
    }

    $count = count($files);
    $cdSize = strlen($centralDir);
    $eocd = "\x50\x4b\x05\x06"
        . pack('vvvvVVv', 0, 0, $count, $count, $cdSize, $offset, 0);

    return $data . $centralDir . $eocd;
}

function xmlEscapeText(string $text): string
{
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function collectDocxRuns(DOMNode $node, array $style, array &$runs): void
{
    if ($node->nodeType === XML_TEXT_NODE) {
        $txt = str_replace(["\r\n", "\r", "\n"], ' ', $node->nodeValue ?? '');
        if ($txt !== '') {
            $runs[] = ['text' => $txt, 'style' => $style];
        }
        return;
    }
    if ($node->nodeType !== XML_ELEMENT_NODE) {
        return;
    }

    $tag = strtolower($node->nodeName);
    if ($tag === 'br') {
        $runs[] = ['break' => true, 'style' => $style];
        return;
    }

    $nextStyle = $style;
    if ($tag === 'b' || $tag === 'strong') {
        $nextStyle['bold'] = true;
    } elseif ($tag === 'i' || $tag === 'em') {
        $nextStyle['italic'] = true;
    } elseif ($tag === 'u') {
        $nextStyle['underline'] = true;
    } elseif ($tag === 's' || $tag === 'strike' || $tag === 'del') {
        $nextStyle['strike'] = true;
    } elseif ($tag === 'sub') {
        $nextStyle['vert'] = 'subscript';
    } elseif ($tag === 'sup') {
        $nextStyle['vert'] = 'superscript';
    }

    if ($node instanceof DOMElement && $node->hasAttribute('style')) {
        $css = strtolower($node->getAttribute('style'));
        if (str_contains($css, 'font-weight: bold') || str_contains($css, 'font-weight: 700')) {
            $nextStyle['bold'] = true;
        }
        if (str_contains($css, 'font-style: italic')) {
            $nextStyle['italic'] = true;
        }
        if (str_contains($css, 'text-decoration: underline')) {
            $nextStyle['underline'] = true;
        }
    }

    foreach ($node->childNodes as $child) {
        collectDocxRuns($child, $nextStyle, $runs);
    }
}

function renderDocxParagraphXml(array $runs, ?string $pStyle = null, bool $isBullet = false): string
{
    $pPr = '';
    if ($pStyle !== null || $isBullet) {
        $pPr .= '<w:pPr>';
        if ($pStyle !== null) {
            $pPr .= '<w:pStyle w:val="' . xmlEscapeText($pStyle) . '"/>';
        }
        if ($isBullet) {
            $pPr .= '<w:ind w:left="720" w:hanging="360"/>';
        }
        $pPr .= '</w:pPr>';
    }

    if ($isBullet) {
        array_unshift($runs, ['text' => "•  ", 'style' => []]);
    }

    $runsXml = '';
    foreach ($runs as $run) {
        if (!empty($run['break'])) {
            $runsXml .= '<w:r><w:br/></w:r>';
            continue;
        }
        $st = $run['style'] ?? [];
        $rPr = '';
        if (!empty($st['bold']) || !empty($st['italic']) || !empty($st['underline']) || !empty($st['strike']) || !empty($st['vert'])) {
            $rPr .= '<w:rPr>';
            if (!empty($st['bold'])) {
                $rPr .= '<w:b/>';
            }
            if (!empty($st['italic'])) {
                $rPr .= '<w:i/>';
            }
            if (!empty($st['underline'])) {
                $rPr .= '<w:u w:val="single"/>';
            }
            if (!empty($st['strike'])) {
                $rPr .= '<w:strike/>';
            }
            if (!empty($st['vert'])) {
                $rPr .= '<w:vertAlign w:val="' . xmlEscapeText((string) $st['vert']) . '"/>';
            }
            $rPr .= '</w:rPr>';
        }
        $runsXml .= '<w:r>' . $rPr . '<w:t xml:space="preserve">' . xmlEscapeText((string) $run['text']) . '</w:t></w:r>';
    }

    if ($runsXml === '') {
        $runsXml = '<w:r><w:t></w:t></w:r>';
    }

    return '<w:p>' . $pPr . $runsXml . '</w:p>';
}

function renderDocxTableXml(DOMElement $tableNode): string
{
    $rowsXml = '';
    foreach ($tableNode->getElementsByTagName('tr') as $tr) {
        $cellsXml = '';
        foreach ($tr->childNodes as $cell) {
            if ($cell->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $cTag = strtolower($cell->nodeName);
            if ($cTag !== 'td' && $cTag !== 'th') {
                continue;
            }
            $runs = [];
            collectDocxRuns($cell, $cTag === 'th' ? ['bold' => true] : [], $runs);
            $cellsXml .= '<w:tc><w:tcPr><w:tcW w:w="2400" w:type="dxa"/></w:tcPr>' . renderDocxParagraphXml($runs) . '</w:tc>';
        }
        if ($cellsXml !== '') {
            $rowsXml .= '<w:tr>' . $cellsXml . '</w:tr>';
        }
    }
    if ($rowsXml === '') {
        return '';
    }
    return '<w:tbl><w:tblPr><w:tblBorders>'
        . '<w:top w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>'
        . '<w:left w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>'
        . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>'
        . '<w:right w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>'
        . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>'
        . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="CBD5E1"/>'
        . '</w:tblBorders></w:tblPr>' . $rowsXml . '</w:tbl>';
}

function htmlToDocxBytes(string $html, string $title = 'Document', array $layout = []): string
{
    $cleanHtml = trim($html);
    if ($cleanHtml === '') {
        $cleanHtml = '<p></p>';
    }

    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $cleanHtml . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = $dom->getElementsByTagName('div')->item(0);
    $bodyXml = '';

    if ($root) {
        $inlineBuffer = [];
        $flushInline = static function () use (&$inlineBuffer, &$bodyXml): void {
            if (!empty($inlineBuffer)) {
                $bodyXml .= renderDocxParagraphXml($inlineBuffer);
                $inlineBuffer = [];
            }
        };

        foreach ($root->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $txt = trim($child->nodeValue ?? '');
                if ($txt !== '') {
                    $inlineBuffer[] = ['text' => $child->nodeValue, 'style' => []];
                }
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $tag = strtolower($child->nodeName);
            if ($tag === 'div' && $child instanceof DOMElement && str_contains($child->getAttribute('class'), 'word-page-break')) {
                $flushInline();
                $bodyXml .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
                continue;
            }
            if (in_array($tag, ['h1', 'h2', 'h3', 'p', 'div', 'blockquote'], true)) {
                $flushInline();
                $runs = [];
                collectDocxRuns($child, [], $runs);
                $pStyle = match ($tag) {
                    'h1' => 'Heading1',
                    'h2' => 'Heading2',
                    'h3' => 'Heading3',
                    default => null,
                };
                $bodyXml .= renderDocxParagraphXml($runs, $pStyle);
            } elseif ($tag === 'table') {
                $flushInline();
                $bodyXml .= renderDocxTableXml($child);
            } elseif ($tag === 'hr') {
                $flushInline();
                $bodyXml .= '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="CBD5E1"/></w:pBdr></w:pPr></w:p>';
            } elseif ($tag === 'ul' || $tag === 'ol') {
                $flushInline();
                $idx = 1;
                foreach ($child->childNodes as $li) {
                    if ($li->nodeType !== XML_ELEMENT_NODE || strtolower($li->nodeName) !== 'li') {
                        continue;
                    }
                    $runs = [];
                    collectDocxRuns($li, [], $runs);
                    if ($tag === 'ol') {
                        array_unshift($runs, ['text' => "{$idx}. ", 'style' => []]);
                        $bodyXml .= renderDocxParagraphXml($runs, null, false);
                        $idx++;
                    } else {
                        $bodyXml .= renderDocxParagraphXml($runs, null, true);
                    }
                }
            } else {
                collectDocxRuns($child, [], $inlineBuffer);
            }
        }
        $flushInline();
    }

    if ($bodyXml === '') {
        $bodyXml = '<w:p><w:r><w:t></w:t></w:r></w:p>';
    }

    $pageSizeKey = strtolower((string) ($layout['page_size'] ?? 'letter'));
    [$baseW, $baseH] = match ($pageSizeKey) {
        'legal' => [12240, 20160],
        'a4' => [11906, 16838],
        default => [12240, 15840], // Letter
    };
    $orientation = ($layout['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait';
    $pgW = $orientation === 'landscape' ? $baseH : $baseW;
    $pgH = $orientation === 'landscape' ? $baseW : $baseH;
    $orientAttr = $orientation === 'landscape' ? ' w:orient="landscape"' : '';
    $marginKey = $layout['margins'] ?? 'normal';
    $mar = match ($marginKey) {
        'narrow' => 720,
        'wide' => 2160,
        default => 1440,
    };

    $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>' . $bodyXml
        . "<w:sectPr><w:pgSz w:w=\"{$pgW}\" w:h=\"{$pgH}\"{$orientAttr}/><w:pgMar w:top=\"{$mar}\" w:right=\"{$mar}\" w:bottom=\"{$mar}\" w:left=\"{$mar}\"/></w:sectPr>"
        . '</w:body></w:document>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="22"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:pPr><w:spacing w:before="240" w:after="120"/></w:pPr><w:rPr><w:b/><w:sz w:val="36"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:pPr><w:spacing w:before="200" w:after="100"/></w:pPr><w:rPr><w:b/><w:sz w:val="28"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:pPr><w:spacing w:before="160" w:after="80"/></w:pPr><w:rPr><w:b/><w:sz w:val="24"/></w:rPr></w:style>'
        . '</w:styles>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '</Types>';

    $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';

    $docRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    return buildZipBinary([
        '[Content_Types].xml' => $contentTypesXml,
        '_rels/.rels' => $relsXml,
        'word/_rels/document.xml.rels' => $docRelsXml,
        'word/document.xml' => $documentXml,
        'word/styles.xml' => $stylesXml,
    ]);
}
