<?php

namespace App\Services\Plantilla;

use Smalot\PdfParser\Parser;
use Throwable;

class PdfExtractionService
{
    /**
     * Best-effort extraction of teacher rows from a plantilla PDF.
     *
     * Names and employment status parse reliably from the flat text; grade/section
     * column attribution does not survive flat extraction, so those fields are left
     * null and the row is flagged for the Chair to complete (SRS §6.2). Improving the
     * parser later never changes this return shape.
     *
     * @return array<int, array{teacher_name:?string,employment_status:?string,sections:?string,cm:?string,hc:?string,service_load:?string,other_assignment:?string,flagged:bool}>
     */
    public function extract(string $absolutePdfPath): array
    {
        try {
            $text = (new Parser())->parseFile($absolutePdfPath)->getText();
        } catch (Throwable $e) {
            throw new ExtractionFailedException('Unreadable PDF: ' . $e->getMessage(), previous: $e);
        }

        if (trim($text) === '') {
            throw new ExtractionFailedException('PDF contains no extractable text (scanned image?).');
        }

        $lines = array_values(array_filter(array_map(
            fn ($line) => trim(str_replace("\u{200B}", '', $line)),
            explode("\n", $text),
        ), fn ($line) => $line !== ''));

        $rows = [];
        $buffer = null;

        foreach ($lines as $line) {
            // A data row begins with a small ordinal immediately followed by "." or ")".
            if (preg_match('/^(\d{1,2})[.\)]\s*(.*)$/u', $line, $m) && (int) $m[1] <= 30) {
                if ($buffer !== null) {
                    $rows[] = $this->finalize($buffer);
                }
                $buffer = trim($m[2]);
            } elseif ($buffer !== null) {
                $buffer .= ' ' . $line;
            }
        }

        if ($buffer !== null) {
            $rows[] = $this->finalize($buffer);
        }

        return $rows;
    }

    /**
     * Pull the name and status out of one row's joined text.
     */
    private function finalize(string $joined): array
    {
        $joined = preg_replace('/\s+/', ' ', trim($joined));

        // Employment status is the first parenthesized status keyword.
        $status = null;
        if (preg_match('/\(((?:FT\s+)?(?:Permanent(?:\s+Teacher)?|Probationary\s*[1-3]|New Teacher|Substitute[^)]*|Retiree))\)/i', $joined, $sm)) {
            $status = trim($sm[1]);
        }

        // Name is the text before the first parenthesis (the status marker).
        $name = null;
        if (($paren = mb_strpos($joined, '(')) !== false) {
            $candidate = trim(mb_substr($joined, 0, $paren), " .,");
            $name = $candidate !== '' ? $candidate : null;
        }

        return [
            'teacher_name' => $name,
            'employment_status' => $status,
            'sections' => null,   // grade/section columns don't survive flat extraction
            'cm' => null,
            'hc' => null,
            'service_load' => null,
            'other_assignment' => null,
            'flagged' => $name === null,
        ];
    }
}
