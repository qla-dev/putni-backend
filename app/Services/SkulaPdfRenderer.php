<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Renders the generated SKULA workbook (xl/worksheets/sheet1.xml) as a print ready PDF,
 * so the PDF export shows the exact same table, values and layout as the Excel export.
 */
class SkulaPdfRenderer
{
    private const NAMESPACE_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const PAGE_WIDTH = 595.28;

    private const PAGE_HEIGHT = 841.89;

    private const MARGIN = 36.0;

    private const CELL_PADDING = 2.5;

    private const DEFAULT_ROW_HEIGHT = 15.0;

    private const DEFAULT_COLUMN_WIDTH = 48.0;

    /** Latin extended glyphs that WinAnsiEncoding does not carry, mapped onto its unused codes. */
    private const GLYPHS = [
        'č' => "\x81", 'Č' => "\x8D", 'ć' => "\x8F", 'Ć' => "\x90", 'đ' => "\x9D", 'Đ' => "\x7F",
    ];

    public function render(string $workbook): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'skula_pdf_');
        throw_unless($temporary !== false && file_put_contents($temporary, $workbook) !== false, new RuntimeException('PDF export could not be prepared.'));

        try {
            $zip = new ZipArchive;
            throw_unless($zip->open($temporary) === true, new RuntimeException('PDF export source is invalid.'));
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            $styles = $zip->getFromName('xl/styles.xml');
            throw_unless(is_string($sheet) && is_string($styles), new RuntimeException('PDF export source is incomplete.'));
            $strings = $this->sharedStrings($zip->getFromName('xl/sharedStrings.xml'));
            $links = $this->hyperlinks($sheet, $zip->getFromName('xl/worksheets/_rels/sheet1.xml.rels'));
            $zip->close();

            return $this->paint($this->grid($sheet, $strings), $this->styles($styles), $links);
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(string|false $xml): array
    {
        if (! is_string($xml)) {
            return [];
        }
        $document = new DOMDocument;
        if (! $document->loadXML($xml)) {
            return [];
        }
        $strings = [];
        foreach ($document->getElementsByTagNameNS(self::NAMESPACE_MAIN, 'si') as $item) {
            $strings[] = $item->textContent;
        }

        return $strings;
    }

    /**
     * @return array<string, string> cell reference => target url
     */
    private function hyperlinks(string $sheet, string|false $relations): array
    {
        if (! is_string($relations) || ! preg_match_all('/<hyperlink ref="([A-Z]+\d+)(?::[A-Z]+\d+)?"[^>]*r:id="([^"]+)"/', $sheet, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $targets = [];
        if (preg_match_all('/<Relationship Id="([^"]+)"[^>]*Target="([^"]+)"[^>]*TargetMode="External"/', $relations, $found, PREG_SET_ORDER)) {
            foreach ($found as $relation) {
                $targets[$relation[1]] = $relation[2];
            }
        }
        $links = [];
        foreach ($matches as $match) {
            if (isset($targets[$match[2]])) {
                $links[$match[1]] = $targets[$match[2]];
            }
        }

        return $links;
    }

    /**
     * @param  array<int, string>  $strings
     * @return array{columns: array<int, float>, heights: array<int, float>, cells: array<int, array<int, array{value: string, style: int, numeric: bool, columns: int, rows: int}>>, lastRow: int}
     */
    private function grid(string $xml, array $strings): array
    {
        $document = new DOMDocument;
        throw_unless($document->loadXML($xml), new RuntimeException('PDF export worksheet is invalid.'));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', self::NAMESPACE_MAIN);

        $columns = [];
        foreach ($xpath->query('/x:worksheet/x:cols/x:col') as $column) {
            if (! $column instanceof DOMElement) {
                continue;
            }
            $width = (float) $column->getAttribute('width');
            for ($index = (int) $column->getAttribute('min'); $index <= min((int) $column->getAttribute('max'), 5); $index++) {
                $columns[$index] = $column->getAttribute('hidden') === '1' ? 0.0 : ((int) round($width * 7) + 5) * 0.75;
            }
        }
        for ($index = 1; $index <= 5; $index++) {
            $columns[$index] ??= self::DEFAULT_COLUMN_WIDTH;
        }
        ksort($columns);

        $spans = [];
        $covered = [];
        foreach ($xpath->query('/x:worksheet/x:mergeCells/x:mergeCell') as $merge) {
            if (! $merge instanceof DOMElement || ! preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $merge->getAttribute('ref'), $match)) {
                continue;
            }
            [$firstColumn, $firstRow] = [$this->column($match[1]), (int) $match[2]];
            [$lastColumn, $lastRow] = [$this->column($match[3]), (int) $match[4]];
            $spans[$firstRow][$firstColumn] = ['columns' => $lastColumn - $firstColumn + 1, 'rows' => $lastRow - $firstRow + 1];
            for ($row = $firstRow; $row <= $lastRow; $row++) {
                for ($column = $firstColumn; $column <= $lastColumn; $column++) {
                    if ($row !== $firstRow || $column !== $firstColumn) {
                        $covered[$row][$column] = true;
                    }
                }
            }
        }

        $heights = [];
        $cells = [];
        $lastRow = 0;
        foreach ($xpath->query('/x:worksheet/x:sheetData/x:row') as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }
            $index = (int) $row->getAttribute('r');
            $lastRow = max($lastRow, $index);
            $heights[$index] = $row->getAttribute('ht') !== '' ? (float) $row->getAttribute('ht') : self::DEFAULT_ROW_HEIGHT;
            foreach ($xpath->query('x:c', $row) as $cell) {
                if (! $cell instanceof DOMElement || ! preg_match('/^([A-Z]+)(\d+)$/', $cell->getAttribute('r'), $match)) {
                    continue;
                }
                $column = $this->column($match[1]);
                if ($column > 5 || isset($covered[$index][$column])) {
                    continue;
                }
                $type = $cell->getAttribute('t');
                $value = $type === 's' ? ($strings[(int) $cell->textContent] ?? '') : $cell->textContent;
                $cells[$index][$column] = [
                    'value' => $value,
                    'style' => (int) $cell->getAttribute('s'),
                    'numeric' => $type === '' && is_numeric($value),
                    'columns' => $spans[$index][$column]['columns'] ?? 1,
                    'rows' => $spans[$index][$column]['rows'] ?? 1,
                ];
            }
        }

        return ['columns' => $columns, 'heights' => $heights, 'cells' => $cells, 'lastRow' => $lastRow];
    }

    /**
     * @return array<int, array{size: float, bold: bool, underline: bool, color: string, horizontal: string, vertical: string, wrap: bool, borders: array<string, float>}>
     */
    private function styles(string $xml): array
    {
        $document = new DOMDocument;
        throw_unless($document->loadXML($xml), new RuntimeException('PDF export styles are invalid.'));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', self::NAMESPACE_MAIN);

        $fonts = [];
        foreach ($xpath->query('/x:styleSheet/x:fonts/x:font') as $font) {
            $size = $xpath->query('x:sz', $font)->item(0);
            $color = $xpath->query('x:color[@rgb]', $font)->item(0);
            $fonts[] = [
                'size' => $size instanceof DOMElement ? (float) $size->getAttribute('val') : 11.0,
                'bold' => $xpath->query('x:b', $font)->length > 0,
                'underline' => $xpath->query('x:u', $font)->length > 0,
                'color' => $color instanceof DOMElement ? substr($color->getAttribute('rgb'), 2) : '000000',
            ];
        }

        $borders = [];
        foreach ($xpath->query('/x:styleSheet/x:borders/x:border') as $border) {
            $edges = [];
            foreach (['left', 'right', 'top', 'bottom'] as $edge) {
                $node = $xpath->query('x:'.$edge, $border)->item(0);
                $style = $node instanceof DOMElement ? $node->getAttribute('style') : '';
                if ($style !== '' && $style !== 'none') {
                    $edges[$edge] = in_array($style, ['medium', 'thick', 'double'], true) ? 1.2 : 0.5;
                }
            }
            $borders[] = $edges;
        }

        $styles = [];
        foreach ($xpath->query('/x:styleSheet/x:cellXfs/x:xf') as $format) {
            if (! $format instanceof DOMElement) {
                continue;
            }
            $font = $fonts[(int) $format->getAttribute('fontId')] ?? ['size' => 11.0, 'bold' => false, 'underline' => false, 'color' => '000000'];
            $alignment = $xpath->query('x:alignment', $format)->item(0);
            $styles[] = $font + [
                'horizontal' => $alignment instanceof DOMElement ? $alignment->getAttribute('horizontal') : '',
                'vertical' => $alignment instanceof DOMElement ? $alignment->getAttribute('vertical') : '',
                'wrap' => $alignment instanceof DOMElement && $alignment->getAttribute('wrapText') === '1',
                'borders' => $borders[(int) $format->getAttribute('borderId')] ?? [],
            ];
        }

        return $styles;
    }

    /**
     * @param  array{columns: array<int, float>, heights: array<int, float>, cells: array<int, array<int, array{value: string, style: int, numeric: bool, columns: int, rows: int}>>, lastRow: int}  $grid
     * @param  array<int, array{size: float, bold: bool, underline: bool, color: string, horizontal: string, vertical: string, wrap: bool, borders: array<string, float>}>  $styles
     * @param  array<string, string>  $links
     */
    private function paint(array $grid, array $styles, array $links): string
    {
        $width = array_sum($grid['columns']);
        $usableWidth = self::PAGE_WIDTH - 2 * self::MARGIN;
        $scale = $width > 0 ? min(1.0, $usableWidth / $width) : 1.0;

        $offsets = [];
        $offset = self::MARGIN;
        foreach ($grid['columns'] as $column => $columnWidth) {
            $offsets[$column] = $offset;
            $offset += $columnWidth * $scale;
        }

        $pages = [];
        $stream = '';
        $annotations = [];
        $top = self::PAGE_HEIGHT - self::MARGIN;
        for ($row = 1; $row <= $grid['lastRow']; $row++) {
            $height = ($grid['heights'][$row] ?? self::DEFAULT_ROW_HEIGHT) * $scale;
            if ($top - $height < self::MARGIN && $top < self::PAGE_HEIGHT - self::MARGIN) {
                $pages[] = ['stream' => $stream, 'annotations' => $annotations];
                $stream = '';
                $annotations = [];
                $top = self::PAGE_HEIGHT - self::MARGIN;
            }
            foreach ($grid['cells'][$row] ?? [] as $column => $cell) {
                $style = $styles[$cell['style']] ?? null;
                if ($style === null) {
                    continue;
                }
                $cellWidth = 0.0;
                for ($span = 0; $span < $cell['columns']; $span++) {
                    $cellWidth += ($grid['columns'][$column + $span] ?? 0.0) * $scale;
                }
                $cellHeight = 0.0;
                for ($span = 0; $span < $cell['rows']; $span++) {
                    $cellHeight += ($grid['heights'][$row + $span] ?? self::DEFAULT_ROW_HEIGHT) * $scale;
                }
                $left = $offsets[$column] ?? self::MARGIN;
                $stream .= $this->borders($left, $top, $cellWidth, $cellHeight, $style['borders']);
                $stream .= $this->text($cell, $style, $left, $top, $cellWidth, $cellHeight, $scale);
                $reference = $this->reference($column).$row;
                if (isset($links[$reference])) {
                    $annotations[] = ['url' => $links[$reference], 'rect' => [$left, $top - $cellHeight, $left + $cellWidth, $top]];
                }
            }
            $top -= $height;
        }
        $pages[] = ['stream' => $stream, 'annotations' => $annotations];

        return $this->document($pages);
    }

    /**
     * @param  array<string, float>  $borders
     */
    private function borders(float $left, float $top, float $width, float $height, array $borders): string
    {
        $edges = [
            'left' => [$left, $top - $height, $left, $top],
            'right' => [$left + $width, $top - $height, $left + $width, $top],
            'top' => [$left, $top, $left + $width, $top],
            'bottom' => [$left, $top - $height, $left + $width, $top - $height],
        ];
        $stream = '';
        foreach ($borders as $edge => $lineWidth) {
            [$x1, $y1, $x2, $y2] = $edges[$edge];
            $stream .= sprintf("%.2F w %.2F %.2F m %.2F %.2F l S\n", $lineWidth, $x1, $y1, $x2, $y2);
        }

        return $stream;
    }

    /**
     * @param  array{value: string, style: int, numeric: bool, columns: int, rows: int}  $cell
     * @param  array{size: float, bold: bool, underline: bool, color: string, horizontal: string, vertical: string, wrap: bool, borders: array<string, float>}  $style
     */
    private function text(array $cell, array $style, float $left, float $top, float $width, float $height, float $scale): string
    {
        if (trim($cell['value']) === '') {
            return '';
        }
        $size = $style['size'] * $scale;
        $font = $style['bold'] ? 'F2' : 'F1';
        $available = $width - 2 * self::CELL_PADDING;
        $lines = [];
        foreach (preg_split('/\R/u', $cell['value']) ?: [] as $paragraph) {
            $lines = array_merge($lines, $style['wrap'] ? $this->wrap($paragraph, $size, $style['bold'], $available) : [$this->encode($paragraph)]);
        }
        $leading = $size * 1.2;
        $block = count($lines) * $leading;
        $vertical = $style['vertical'] === 'center' ? $top - ($height - $block) / 2 : $top - $height + $block + self::CELL_PADDING;
        $horizontal = $style['horizontal'] !== '' ? $style['horizontal'] : ($cell['numeric'] ? 'right' : 'left');

        $stream = "BT\n/{$font} ".sprintf('%.2F', $size)." Tf\n".$this->color($style['color']);
        $underlines = '';
        foreach ($lines as $index => $line) {
            $lineWidth = $this->width($line, $size, $style['bold']);
            $x = match ($horizontal) {
                'center' => $left + ($width - $lineWidth) / 2,
                'right' => $left + $width - self::CELL_PADDING - $lineWidth,
                default => $left + self::CELL_PADDING,
            };
            $baseline = $vertical - $leading * $index - $size * 0.85;
            $stream .= sprintf("1 0 0 1 %.2F %.2F Tm\n", $x, $baseline).'('.$this->escape($line).") Tj\n";
            if ($style['underline']) {
                $underlines .= sprintf("0.5 w %.2F %.2F m %.2F %.2F l S\n", $x, $baseline - $size * 0.14, $x + $lineWidth, $baseline - $size * 0.14);
            }
        }

        return $stream."ET\n".$underlines.($style['color'] !== '000000' ? "0 0 0 RG 0 0 0 rg\n" : '');
    }

    /**
     * @return array<int, string>
     */
    private function wrap(string $text, float $size, bool $bold, float $available): array
    {
        $lines = [];
        $current = '';
        foreach (explode(' ', $text) as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if ($current !== '' && $this->width($this->encode($candidate), $size, $bold) > $available) {
                $lines[] = $this->encode($current);
                $current = $word;

                continue;
            }
            $current = $candidate;
        }
        $lines[] = $this->encode($current);

        return $lines;
    }

    private function color(string $rgb): string
    {
        if ($rgb === '000000' || strlen($rgb) !== 6) {
            return "0 0 0 rg\n";
        }

        return sprintf("%.3F %.3F %.3F rg\n", hexdec(substr($rgb, 0, 2)) / 255, hexdec(substr($rgb, 2, 2)) / 255, hexdec(substr($rgb, 4, 2)) / 255)
            .sprintf("%.3F %.3F %.3F RG\n", hexdec(substr($rgb, 0, 2)) / 255, hexdec(substr($rgb, 2, 2)) / 255, hexdec(substr($rgb, 4, 2)) / 255);
    }

    private function encode(string $text): string
    {
        $placeholders = [];
        $restore = [];
        $index = 1;
        foreach (self::GLYPHS as $glyph => $code) {
            $placeholders[$glyph] = chr($index);
            $restore[chr($index)] = $code;
            $index++;
        }
        $converted = iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', strtr($text, $placeholders));

        return strtr(is_string($converted) ? $converted : $text, $restore);
    }

    private function width(string $encoded, float $size, bool $bold): float
    {
        $widths = $bold ? $this->boldWidths() : $this->regularWidths();
        $total = 0;
        for ($index = 0; $index < strlen($encoded); $index++) {
            $total += $widths[ord($encoded[$index])] ?? 556;
        }

        return $total / 1000 * $size;
    }

    /**
     * @return array<int, int>
     */
    private function regularWidths(): array
    {
        static $widths = null;

        return $widths ??= $this->widths([
            278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
            1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
            333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
            556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function boldWidths(): array
    {
        static $widths = null;

        return $widths ??= $this->widths([
            278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
            975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
            333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
            611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
        ]);
    }

    /**
     * @param  array<int, int>  $ascii  glyph widths for codes 32 through 126
     * @return array<int, int>
     */
    private function widths(array $ascii): array
    {
        $widths = [];
        foreach ($ascii as $index => $width) {
            $widths[32 + $index] = $width;
        }
        foreach (['č' => 'c', 'Č' => 'C', 'ć' => 'c', 'Ć' => 'C', 'đ' => 'd', 'Đ' => 'D'] as $glyph => $base) {
            $widths[ord(self::GLYPHS[$glyph])] = $widths[ord($base)];
        }
        foreach ([0x9A => 's', 0x8A => 'S', 0x9E => 'z', 0x8E => 'Z'] as $code => $base) {
            $widths[$code] = $widths[ord($base)];
        }

        return $widths;
    }

    private function escape(string $text): string
    {
        $escaped = '';
        for ($index = 0; $index < strlen($text); $index++) {
            $byte = ord($text[$index]);
            $escaped .= match (true) {
                $byte > 126 => sprintf('\\%03o', $byte),
                in_array($text[$index], ['\\', '(', ')'], true) => '\\'.$text[$index],
                default => $text[$index],
            };
        }

        return $escaped;
    }

    private function column(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index;
    }

    private function reference(int $column): string
    {
        $letters = '';
        while ($column > 0) {
            $letters = chr(65 + ($column - 1) % 26).$letters;
            $column = intdiv($column - 1, 26);
        }

        return $letters;
    }

    /**
     * @param  array<int, array{stream: string, annotations: array<int, array{url: string, rect: array<int, float>}>}>  $pages
     */
    private function document(array $pages): string
    {
        $objects = ['', ''];
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding 5 0 R >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding 5 0 R >>';
        $objects[] = '<< /Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences [127 /Dcroat 129 /ccaron 141 /Ccaron 143 /cacute 144 /Cacute 157 /dcroat] >>';

        $kids = [];
        foreach ($pages as $page) {
            $references = [];
            foreach ($page['annotations'] as $annotation) {
                $objects[] = '<< /Type /Annot /Subtype /Link /Border [0 0 0] /Rect ['
                    .implode(' ', array_map(fn (float $value): string => sprintf('%.2F', $value), $annotation['rect']))
                    .'] /A << /Type /Action /S /URI /URI ('.$this->escape($annotation['url']).') >> >>';
                $references[] = count($objects).' 0 R';
            }
            $objects[] = '<< /Length '.strlen($page['stream'])." >>\nstream\n".$page['stream'].'endstream';
            $contents = count($objects);
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.sprintf('%.2F %.2F', self::PAGE_WIDTH, self::PAGE_HEIGHT)
                .'] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$contents.' 0 R'
                .($references === [] ? '' : ' /Annots ['.implode(' ', $references).']').' >>';
            $kids[] = count($objects).' 0 R';
        }
        $objects[0] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[1] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n".'0000000000 65535 f '."\n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n".'<< /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\n".'startxref'."\n".$xref."\n".'%%EOF';
    }
}
