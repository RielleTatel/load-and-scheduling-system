<?php

namespace App\Services\Roster;

use App\Services\Plantilla\ExtractionFailedException;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Extracts the registrar's "List of Class Moderators" into one row per section.
 *
 * The document is a clean 4-column table (Section | Moderator | Teacher-Partner
 * | Room), but PDF text extraction flattens its geometry: grade blocks come out
 * of order, the Grade Level Leader lines collapse together, and long names wrap
 * mid-cell. So this parses on markers, never on reading order — the literal
 * "GRADE n" label carries the grade, a section begins "Saint"/"Blessed", people
 * begin with an honorific, and a bare 3-digit room terminates each row.
 *
 * Grade Level Leader lines are ignored: the schema models GLL as an
 * OtherAssignmentRole, not a section field.
 *
 * Short names are never derived here — that call is editorial (the registrar's
 * "Saint John de Britto" is "De Britto" but "Saint Jose de Anchieta" is
 * "Anchieta"), so a proposal is offered and the Admin confirms it on review.
 */
class RosterExtractionService
{
    private const HONORIFICS = '(?:Ms|Mr|Mrs|Bb|Gng|Br|Sch|Fr|Rev|Dr)\.?';

    /**
     * @return array<int, array{grade_level:string, full_name:string, name:?string, room:?string, is_magis:bool, moderator_name:?string, teacher_partner_name:?string, flagged:bool, flags:array<string,string>}>
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

        $lines = $this->lines($text);
        $rows = [];
        $grade = null;
        $buffer = [];

        foreach ($lines as $line) {
            if ($found = $this->gradeLabel($line)) {
                $rows = array_merge($rows, $this->flush($buffer, $grade));
                $buffer = [];
                $grade = $found;
                continue;
            }

            if ($grade === null || $this->isNoise($line)) {
                continue;
            }

            // A new section name closes the previous row.
            if ($this->isSectionStart($line)) {
                $rows = array_merge($rows, $this->flush($buffer, $grade));
                $buffer = [];
            }

            $buffer[] = $line;
        }

        return array_merge($rows, $this->flush($buffer, $grade));
    }

    /** @return array<int, string> */
    private function lines(string $text): array
    {
        return array_values(array_filter(array_map(
            fn ($line) => trim(preg_replace('/\s+/u', ' ', str_replace(["\u{200B}", "\t"], ' ', $line))),
            explode("\n", $text),
        ), fn ($line) => $line !== ''));
    }

    private function gradeLabel(string $line): ?string
    {
        return preg_match('/^GRADE\s*(7|8|9|10)\b/i', $line, $m) ? 'G' . $m[1] : null;
    }

    /**
     * Column headers, the Grade Level Leader lines, and the signature block.
     * "Section" survives extraction as "Sec tion" in one header.
     */
    private function isNoise(string $line): bool
    {
        return (bool) preg_match('/^(?:sec\s*tion|moderator|teacher-partners?|room)\b/i', $line)
            || (bool) preg_match('/^grade level leader/i', $line)
            || (bool) preg_match('/^prepared by/i', $line)
            || (bool) preg_match('/^(?:ateneo|junior high|accredited|academic year|list of class)/i', $line)
            // The signatory, printed in caps after an honorific. Hyphens and
            // apostrophes occur in real surnames ("SUPILANAS-SEÑO"); leaving
            // them out let the signature line fall through into the last
            // section's buffer, which pushed its room off the end of the row.
            || preg_match('/^' . self::HONORIFICS . '\s+[A-ZÑ][A-ZÑ\s.\'\-]+$/u', $line) === 1;
    }

    private function isSectionStart(string $line): bool
    {
        return (bool) preg_match('/^(?:Saint|St\.|Blessed)\s+/u', $line);
    }

    /**
     * Turn one section's buffered lines into a row. Everything is recovered by
     * marker: the room is the trailing 3-digit number, the two people are the
     * honorific-led fragments in order, and the section is what remains.
     *
     * @param  array<int, string>  $buffer
     * @return array<int, array<string, mixed>>
     */
    private function flush(array $buffer, ?string $grade): array
    {
        if ($buffer === [] || $grade === null || ! $this->isSectionStart($buffer[0] ?? '')) {
            return [];
        }

        $joined = implode(' ', $buffer);
        $flags = [];

        $isMagis = (bool) preg_match('/\(\s*Magis\s+Class\s*\)/i', $joined);
        $joined = preg_replace('/\(\s*Magis\s+Class\s*\)/i', ' ', $joined);

        $room = null;
        if (preg_match('/\b(\d{3})\s*$/', trim($joined), $m)) {
            $room = $m[1];
            $joined = preg_replace('/\b\d{3}\s*$/', ' ', trim($joined));
        } else {
            $flags['room'] = 'No room number found on this row.';
        }

        // Split on the honorifics: everything before the first is the section,
        // then the moderator, then the teacher-partner.
        $parts = preg_split('/\s+(?=' . self::HONORIFICS . '\s+\p{Lu})/u', trim($joined));

        $fullName = trim($parts[0] ?? '');
        $moderator = isset($parts[1]) ? $this->person($parts[1]) : null;
        $partner = isset($parts[2]) ? $this->person($parts[2]) : null;

        if ($moderator === null) {
            $flags['moderator_name'] = 'No moderator found for this section.';
        }
        if ($partner === null) {
            $flags['teacher_partner_name'] = 'No teacher-partner found for this section.';
        }

        [$proposed, $nameFlag] = $this->proposeShortName($fullName);
        if ($nameFlag) {
            $flags['name'] = $nameFlag;
        }

        return [[
            'grade_level' => $grade,
            'full_name' => $fullName,
            'name' => $proposed,
            'room' => $room,
            'is_magis' => $isMagis,
            'moderator_name' => $moderator,
            'teacher_partner_name' => $partner,
            'flagged' => $flags !== [],
            'flags' => $flags,
        ]];
    }

    /**
     * Strip the leading honorific and a trailing "(GLL)" marker.
     *
     * A ", SJ" suffix is kept: it is part of how the person is named, and the
     * verified roster stores it ("James Ryan C. Seneriches, SJ"). Teacher::
     * normalize() already drops it when matching, so keeping it costs nothing.
     */
    private function person(string $raw): ?string
    {
        $name = preg_replace('/^' . self::HONORIFICS . '\s+/u', '', trim($raw));
        $name = preg_replace('/\(\s*GLL\s*\)/i', ' ', $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name));

        return $name === '' ? null : $name;
    }

    /**
     * Propose a short name, and say so when the call is not ours to make.
     * "Saint John de Britto" is stored as "De Britto" but "Saint Jose de
     * Anchieta" as "Anchieta" — a connective means a human decides.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function proposeShortName(string $fullName): array
    {
        $stripped = trim(preg_replace('/^(?:Saint|St\.|Blessed)\s+/u', '', $fullName));

        if ($stripped === '') {
            return [null, 'Could not read a section name on this row.'];
        }

        $tokens = preg_split('/\s+/u', $stripped) ?: [];

        foreach ($tokens as $i => $token) {
            if (in_array(mb_strtolower($token), ['de', 'la', 'of', 'del'], true)) {
                $proposal = implode(' ', array_slice($tokens, $i));

                return [$proposal, "\"{$fullName}\" contains \"{$token}\" — confirm the short name; the roster is inconsistent about keeping it."];
            }
        }

        return [end($tokens), null];
    }
}
