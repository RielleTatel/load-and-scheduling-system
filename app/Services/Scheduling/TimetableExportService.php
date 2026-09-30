<?php

namespace App\Services\Scheduling;

use App\Enums\TimetableStatus;
use App\Models\TimetableVersion;

/** Dependency-free PDF and OpenXML exports for the timetable master. */
class TimetableExportService
{
    private const PAGE_WIDTH = 842;
    private const PAGE_HEIGHT = 595;

    private TimetableLayout $layout;

    public function __construct(?TimetableLayout $layout = null)
    {
        $this->layout = $layout ?? new TimetableLayout;
    }

    public function pdf(TimetableVersion $timetable, array $data): string
    {
        $sections = $data['sections'];
        $entriesByGrade = [];
        foreach ($sections as $section) {
            $entriesByGrade[$section->grade_level->value][] = $section;
        }

        $pages = [];
        foreach ($this->layout->gradeOrder() as $grade) {
            if (! isset($entriesByGrade[$grade])) {
                continue;
            }
            foreach ($data['days'] as $day) {
                $pages[] = $this->pdfPage($timetable, $data, $grade, (int) $day, $entriesByGrade[$grade]);
            }
        }
        if ($pages === []) {
            $pages[] = $this->pdfMessagePage('No sections match the selected filters.');
        }

        return $this->assemblePdf($pages);
    }

    public function xlsx(TimetableVersion $timetable, array $data): string
    {
        $gradeSheets = [];
        foreach (['G7', 'G8', 'G9', 'G10'] as $grade) {
            $sections = $data['sections']->filter(fn ($section) => $section->grade_level->value === $grade)->values();
            $gradeSheets[] = [
                'name' => 'Grade '.substr($grade, 1),
                'rows' => $this->sheetRows($timetable, $data, $grade, $sections),
                'section_count' => $sections->count(),
            ];
        }

        return $this->buildXlsx($gradeSheets);
    }

    private function sheetRows(TimetableVersion $timetable, array $data, string $grade, $sections): array
    {
        $status = $timetable->status === TimetableStatus::Approved ? 'APPROVED' : 'DRAFT - NOT APPROVED';
        $rows = [["JHS Normal Timetable | SY {$timetable->school_year} | Quarter {$timetable->quarter} | Version {$timetable->version} | {$status}"]];
        $rows[] = [$this->filterDescription($data['filters'])];
        $rows[] = [];

        if ($sections->isEmpty()) {
            $rows[] = ["No Grade {$grade} sections match the selected filters."];
            return $rows;
        }

        foreach ($data['days'] as $day) {
            $rows[] = ["DAY {$day}"];
            $rows[] = array_merge(
                ['TIME'],
                $sections->map(fn ($section) => substr($grade, 1).' - '.$section->name)->all(),
            );
            foreach ($this->layout->rows((int) $day) as $layoutRow) {
                if ($layoutRow['type'] === 'subject') {
                    $row = [$layoutRow['time']];
                    foreach ($sections as $section) {
                        $entry = $data['grid'][$section->id][$day][$layoutRow['period']] ?? null;
                        $row[] = $entry ? $this->cellText($entry) : '';
                    }
                    $rows[] = $row;
                } else {
                    $rows[] = [$layoutRow['time'], $layoutRow['label']];
                }
            }
            $rows[] = [];
        }

        return $rows;
    }

    private function pdfPage(TimetableVersion $timetable, array $data, string $grade, int $day, array $sections): string
    {
        $lines = '';
        $isDraft = $timetable->status !== TimetableStatus::Approved;
        $this->pdfText($lines, 3, 15, 17, 568, "JHS NORMAL TIMETABLE - SY {$timetable->school_year} - QUARTER {$timetable->quarter}");
        $this->pdfText($lines, 4, 10, 17, 546, 'GRADE '.substr($grade, 1).'  |  DAY '.$day.'  |  VERSION '.$timetable->version);
        $this->pdfText($lines, 4, 8, 700, 568, $isDraft ? 'DRAFT - NOT APPROVED' : 'APPROVED');
        $this->pdfText($lines, 3, 7, 17, 530, $this->filterDescription($data['filters']));

        $left = 18;
        $tableTop = 512;
        $timeWidth = 74;
        $dayHeight = 20;
        $headerHeight = 25;
        $tableWidth = self::PAGE_WIDTH - 2 * $left;
        $sectionWidth = ($tableWidth - $timeWidth) / max(count($sections), 1);
        $cursor = $tableTop;

        $cursor -= $dayHeight;
        $this->pdfCell($lines, $left, $cursor, $tableWidth, $dayHeight, [0.07, 0.58, 0.25]);
        $this->pdfText($lines, 4, 9, $left + ($tableWidth / 2) - 14, $cursor + 6, 'DAY '.$day, 1.0);

        $cursor -= $headerHeight;
        $this->pdfCell($lines, $left, $cursor, $timeWidth, $headerHeight, [0.08, 0.14, 0.23]);
        $this->pdfText($lines, 4, 8, $left + 24, $cursor + 9, 'TIME', 1.0);
        foreach ($sections as $index => $section) {
            $x = $left + $timeWidth + $index * $sectionWidth;
            $this->pdfCell($lines, $x, $cursor, $sectionWidth, $headerHeight, [0.08, 0.14, 0.23]);
            $this->pdfWrappedText($lines, 4, 6.5, $x + 3, $cursor + 15, $sectionWidth - 6, substr($grade, 1).' - '.$section->name, 1.0);
        }

        foreach ($this->layout->rows($day) as $row) {
            $rowHeight = $row['type'] === 'subject' ? 44 : 18;
            $cursor -= $rowHeight;
            $fill = match ($row['type']) {
                'break' => [1.0, 0.91, 0.91],
                'post_class' => [0.94, 0.96, 0.98],
                default => [0.92, 0.95, 0.98],
            };
            $this->pdfCell($lines, $left, $cursor, $timeWidth, $rowHeight, $fill);
            $this->pdfWrappedText($lines, 4, 6.2, $left + 4, $cursor + ($rowHeight / 2) + 2, $timeWidth - 8, $row['time']);

            if ($row['type'] !== 'subject') {
                $this->pdfCell($lines, $left + $timeWidth, $cursor, $tableWidth - $timeWidth, $rowHeight, $fill);
                $this->pdfText($lines, 4, 7, $left + $timeWidth + 5, $cursor + 6, $row['label']);
                continue;
            }

            foreach ($sections as $index => $section) {
                $x = $left + $timeWidth + $index * $sectionWidth;
                $entry = $data['grid'][$section->id][$day][$row['period']] ?? null;
                $this->pdfCell($lines, $x, $cursor, $sectionWidth, $rowHeight, [1, 1, 1]);
                if ($entry) {
                    $subject = $this->layout->subjectName($entry->department->code, $entry->department->name);
                    $subjectLines = $this->pdfWrappedText($lines, 4, 5.4, $x + 3, $cursor + $rowHeight - 10, $sectionWidth - 6, $subject);
                    $teacherY = $cursor + $rowHeight - 10 - ($subjectLines * 6.2) - 1;
                    $this->pdfWrappedText($lines, 3, 5.0, $x + 3, $teacherY, $sectionWidth - 6, $entry->teacher->full_name);
                }
            }
        }

        $this->pdfText($lines, 3, 6, $left, 18, 'Normal Day 1-5 timetable. Recess and lunch are non-instructional breaks.');
        if ($isDraft) {
            $this->pdfText($lines, 3, 36, 280, 280, 'DRAFT', 0.92);
        }

        return $lines;
    }

    private function pdfWrappedText(
        string &$stream,
        int $font,
        float $size,
        float $x,
        float $topY,
        float $width,
        string $text,
        float $gray = 0.12,
    ): int {
        $averageCharacterWidth = max($size * 0.5, 1);
        $charactersPerLine = max(4, (int) floor($width / $averageCharacterWidth));
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $wrapped = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;
            if ($line !== '' && mb_strlen($candidate) > $charactersPerLine) {
                $wrapped[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $wrapped[] = $line;
        }
        if ($wrapped === []) {
            $wrapped[] = '';
        }

        $lineHeight = $size + 1;
        foreach ($wrapped as $index => $wrappedLine) {
            $this->pdfText($stream, $font, $size, $x, $topY - ($index * $lineHeight), $wrappedLine, $gray);
        }

        return count($wrapped);
    }

    private function pdfMessagePage(string $message): string
    {
        $lines = '';
        $this->pdfText($lines, 3, 17, 40, 500, $message);
        return $lines;
    }

    private function pdfCell(string &$stream, float $x, float $y, float $width, float $height, array $fill): void
    {
        $stream .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", ...array_merge($fill, [$x, $y, $width, $height]));
        $stream .= sprintf("0.55 G 0.45 w %.2F %.2F %.2F %.2F re S\n", $x, $y, $width, $height);
    }

    private function pdfText(string &$stream, int $font, float $size, float $x, float $y, string $text, float $gray = 0.12): void
    {
        $encoded = function_exists('iconv') ? iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text) : $text;
        $encoded = $encoded === false ? $text : $encoded;
        $escaped = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $encoded);
        $stream .= sprintf("%.2F g BT /F%d %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n", $gray, $font === 4 ? 2 : 1, $size, $x, $y, $escaped);
    }

    private function assemblePdf(array $pages): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $pageIds = [];

        foreach ($pages as $content) {
            $pageId = count($objects) + 1;
            $contentId = $pageId + 1;
            $pageIds[] = $pageId.' 0 R';
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ".self::PAGE_WIDTH.' '.self::PAGE_HEIGHT."] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = '<< /Length '.strlen($content).">>\nstream\n{$content}\nendstream";
        }

        $objects[2] = '<< /Type /Pages /Count '.count($pageIds).' /Kids ['.implode(' ', $pageIds).'] >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        for ($id = 1; $id <= count($objects); $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    private function buildXlsx(array $gradeSheets): string
    {
        $sheetsXml = '';
        $relationships = '';
        $contentOverrides = '';
        $sheetFiles = [];

        foreach ($gradeSheets as $index => $sheet) {
            $sheetId = $index + 1;
            $sheetsXml .= '<sheet name="'.htmlspecialchars($sheet['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'" sheetId="'.$sheetId.'" r:id="rId'.$sheetId.'"/>';
            $relationships .= '<Relationship Id="rId'.$sheetId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$sheetId.'.xml"/>';
            $contentOverrides .= '<Override PartName="/xl/worksheets/sheet'.$sheetId.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheetFiles['xl/worksheets/sheet'.$sheetId.'.xml'] = $this->worksheetXml($sheet['rows'], $sheet['section_count']);
        }
        $relationships .= '<Relationship Id="rId'.(count($gradeSheets) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$contentOverrides.'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets>'.$sheetsXml.'</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relationships.'</Relationships>',
            'xl/styles.xml' => $this->stylesXml(),
        ] + $sheetFiles;

        return $this->zip($files);
    }

    private function worksheetXml(array $rows, int $sectionCount): string
    {
        $maxColumns = max(1, $sectionCount + 1);
        $lastColumn = $this->columnName($maxColumns);
        $xmlRows = '';
        $mergeRanges = [];
        foreach ($rows as $rowIndex => $cells) {
            $rowNumber = $rowIndex + 1;
            $first = (string) ($cells[0] ?? '');
            $second = (string) ($cells[1] ?? '');
            $isDay = preg_match('/^DAY [1-5]$/', $first) === 1;
            $isHeader = $first === 'TIME';
            $isBreak = in_array($second, ['RECESS', 'LUNCH'], true);
            $isPostClass = in_array($second, ['EXAMEN', 'CLASSROOM CLEAN-UP (CC)'], true)
                || str_starts_with($second, 'REMEDIATION (');

            if ($maxColumns > 1 && $first !== '' && ($rowNumber <= 2 || $isDay)) {
                $mergeRanges[] = 'A'.$rowNumber.':'.$lastColumn.$rowNumber;
            } elseif ($maxColumns > 2 && ($isBreak || $isPostClass)) {
                $mergeRanges[] = 'B'.$rowNumber.':'.$lastColumn.$rowNumber;
            }

            $xmlCells = '';
            foreach (array_values($cells) as $columnIndex => $value) {
                $reference = $this->columnName($columnIndex + 1).$rowNumber;
                $style = match (true) {
                    $rowNumber === 1 => 1,
                    $rowNumber === 2 => 2,
                    $isDay => 3,
                    $isHeader => 4,
                    $isBreak => 8,
                    $isPostClass => 7,
                    $columnIndex === 0 && $first !== '' => 5,
                    default => 6,
                };
                $xmlCells .= '<c r="'.$reference.'" t="inlineStr" s="'.$style.'"><is><t xml:space="preserve">'.htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $height = match (true) {
                $rowNumber === 1 => 24,
                $isDay, $isHeader => 22,
                $isBreak, $isPostClass => 20,
                $first !== '' => 34,
                default => 10,
            };
            $xmlRows .= '<row r="'.$rowNumber.'" ht="'.$height.'" customHeight="1">'.$xmlCells.'</row>';
        }

        $lastRow = max(count($rows), 1);
        $columns = '<cols><col min="1" max="1" width="16" customWidth="1"/>';
        if ($maxColumns > 1) {
            $columns .= '<col min="2" max="'.$maxColumns.'" width="19" customWidth="1"/>';
        }
        $columns .= '</cols>';

        $merges = $mergeRanges === []
            ? ''
            : '<mergeCells count="'.count($mergeRanges).'">'.implode('', array_map(fn (string $range) => '<mergeCell ref="'.$range.'"/>', $mergeRanges)).'</mergeCells>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><dimension ref="A1:'.$lastColumn.$lastRow.'"/>'.$columns.'<sheetFormatPr defaultRowHeight="18"/><sheetData>'.$xmlRows.'</sheetData>'.$merges.'<pageMargins left="0.2" right="0.2" top="0.4" bottom="0.4" header="0.2" footer="0.2"/><pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/><printOptions horizontalCentered="1"/></worksheet>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="4"><font><sz val="10"/><name val="Aptos"/></font><font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Aptos"/></font><font><b/><sz val="13"/><color rgb="FFFFFFFF"/><name val="Aptos Display"/></font><font><b/><sz val="10"/><color rgb="FF10243A"/><name val="Aptos"/></font></fonts><fills count="6"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF10243A"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF169447"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE9F0F6"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFE8E8"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD6DEE6"/></left><right style="thin"><color rgb="FFD6DEE6"/></right><top style="thin"><color rgb="FFD6DEE6"/></top><bottom style="thin"><color rgb="FFD6DEE6"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="9"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="0" fontId="2" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="0" applyAlignment="1"><alignment wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="3" borderId="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="3" fillId="4" borderId="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="3" fillId="4" borderId="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="3" fillId="5" borderId="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function zip(array $files): string
    {
        $local = '';
        $central = '';
        $date = getdate();
        $dosTime = ($date['hours'] << 11) | ($date['minutes'] << 5) | (int) ($date['seconds'] / 2);
        $dosDate = (($date['year'] - 1980) << 9) | ($date['mon'] << 5) | $date['mday'];

        foreach ($files as $name => $content) {
            $nameBytes = $name;
            $compressed = gzdeflate($content);
            if ($compressed === false) {
                throw new \RuntimeException('The workbook could not be compressed for download.');
            }
            $crc = crc32($content);
            $offset = strlen($local);
            $local .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, $dosTime, $dosDate, $crc, strlen($compressed), strlen($content), strlen($nameBytes), 0).$nameBytes.$compressed;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, $dosTime, $dosDate, $crc, strlen($compressed), strlen($content), strlen($nameBytes), 0, 0, 0, 0, 0, $offset).$nameBytes;
        }

        $count = count($files);
        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($local), 0);

        return $local.$central.$end;
    }

    private function columnName(int $column): string
    {
        $name = '';
        while ($column > 0) {
            $remainder = ($column - 1) % 26;
            $name = chr(65 + $remainder).$name;
            $column = intdiv($column - $remainder - 1, 26);
        }

        return $name;
    }

    private function cellText($entry): string
    {
        return $this->layout->subjectName($entry->department->code, $entry->department->name)
            ."\n".$entry->teacher->full_name;
    }

    private function filterDescription(array $filters): string
    {
        $parts = [];
        if (! empty($filters['section_id'])) {
            $parts[] = 'Filtered to selected section';
        } elseif (! empty($filters['grade_only']) && $filters['grade'] !== 'all') {
            $parts[] = 'Grade '.substr($filters['grade'], 1);
        } else {
            $parts[] = 'All grades';
        }
        if (! empty($filters['department_id'])) {
            $parts[] = 'Subject/department filter applied';
        }
        if (! empty($filters['teacher_id'])) {
            $parts[] = 'Teacher filter applied';
        }
        if (! empty($filters['day_only'])) {
            $parts[] = 'Day '.$filters['day'];
        } else {
            $parts[] = 'Days 1-5';
        }

        return implode(' | ', $parts);
    }
}
