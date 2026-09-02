<?php

namespace App\Services\Plantilla;

use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Extracts teacher rows from a plantilla PDF.
 *
 * The seven department sheets share no common layout, so this parses by *marker*
 * rather than by column geometry: section names are recognised against the
 * canonical 36-section roster (SectionResolver), and the Class Moderator and
 * Honor's Class cells are found by the keywords that introduce them.
 *
 * Grade is never read from the sheet — it comes from the roster, because no
 * section name is reused across grades. Where a sheet does state a grade inline
 * (TLE's "10Colombiere"), the resolver cross-checks it and flags disagreements.
 *
 * Nothing is invented: anything unrecognised lands in `flags` for the Chair.
 */
class PdfExtractionService
{
    public function __construct(private SectionResolver $resolver) {}

    /**
     * @return array<int, array{teacher_name:?string,employment_status:?string,sections:?string,cm:?string,hc:?string,service_load:?string,other_assignment:?string,flagged:bool,flags:array<string,string>}>
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
            if ($this->isSignatureBlock($line)) {
                continue;
            }
            // A data row begins with a small ordinal immediately followed by "." or ")".
            if (preg_match('/^(\d{1,2})[.\)]\s*(.*)$/u', $line, $m) && (int) $m[1] <= 30) {
                if ($buffer !== null) {
                    $rows[] = $this->finalize($buffer);
                }
                $buffer = [trim($m[2])];
            } elseif ($buffer !== null) {
                $buffer[] = $line;
            }
        }

        if ($buffer !== null) {
            $rows[] = $this->finalize($buffer);
        }

        return array_values(array_filter($rows, fn ($r) => $r['teacher_name'] !== null));
    }

    /**
     * Turn one row's lines into the seven staging fields.
     *
     * @param  array<int, string>  $lines
     */
    private function finalize(array $lines): array
    {
        $lines = $this->rejoinWrappedNames(array_values(array_filter($lines, fn ($l) => $l !== '')));

        $status = $this->status($lines);
        [$name, $rest] = $this->splitName($lines);

        $buckets = ['sections' => [], 'cm' => [], 'hc' => []];
        $flags = [];
        $mode = 'sections';
        $tail = [];

        foreach ($rest as $line) {
            // The status cell and bare department markers are not load data; if
            // they reach the trailing cells they drag the wrong number out as
            // the Service Load.
            // Only the parenthesised form is a department tag; a bare "TLE" is
            // the first word of "TLE Coordinator".
            if ($this->isStatusLine($line) || preg_match('/^\((?:MAPEH|TLE|CLE)\)$/i', trim($line))) {
                continue;
            }

            // "1 moderating class" states only that the teacher moderates
            // something. Reading the next section as the moderated one is wrong:
            // it is the teacher's next teaching section.
            if ($this->isCountOnlyModeratorMarker($line)) {
                $flags['cm'] = 'The sheet records a moderating class but never names the section — '
                    . 'taken from the registrar roster on import.';

                continue;
            }

            if ($this->isClassModeratorMarker($line)) {
                $mode = 'cm';
                // "G10 Class Moderator" can carry its own section on the same line.
                $this->resolveLine($this->stripModeratorMarker($line), $buckets, $flags, 'cm');

                continue;
            }

            // "(Magis)" on its own qualifies the section *before* it (MAPEH writes
            // "Ignatius / (Magis) / Ogilvie"), so it must not capture the next one.
            if ($this->isBareMagisMarker($line)) {
                // Only when the Honor's cell is still empty and the section it
                // qualifies really is a Magis section. Science trails a stray
                // "Magis" from its Research-Magis role, which must not steal the
                // teacher's last teaching section.
                $last = end($buckets['sections']) ?: null;
                if ($buckets['hc'] === [] && $last && $last->section->is_magis) {
                    array_pop($buckets['sections']);
                    $buckets['hc'][] = $last;
                }
                continue;
            }

            if ($this->isHonorsMarker($line)) {
                $mode = 'hc';
                $this->resolveLine($line, $buckets, $flags, 'hc');
                continue;
            }

            [$found, $queried] = $this->resolveLine($line, $buckets, $flags, $mode);

            // A line that only raised a question about a name is consumed, but it
            // is not a section, so it must not reset the numeric cluster below.
            if ($found === 0 && $queried > 0) {
                continue;
            }

            if ($found > 0) {
                // Everything before the last section name is the per-grade section
                // counts and column headers. Only what follows it is the numeric
                // cluster (Total Teaching Hours, Service Load, Other Assignment, …).
                // Once the cluster has been seen, keep it: some sheets print a
                // moderator's section name after the numbers (Science, Alvarez).
                if (! $this->containsNumericCluster($tail)) {
                    $tail = [];
                }

                // Some sheets print the numeric cells on the same line as the last
                // section ("Faber 18 3"). Keep the numbers when clearing the line.
                if ($residue = $this->numericResidue($line)) {
                    $tail[] = $residue;
                }

                // A moderator/honors cell names exactly one section; fall back after.
                if ($mode !== 'sections') {
                    $mode = 'sections';
                }
                continue;
            }

            // "Punlaan" / "Moderator" is one club role wrapped over two lines —
            // rejoin it. A bare "Moderator" with no role text before it is the
            // Class Moderator column header and is dropped.
            if ($this->isBareModeratorWord($line)) {
                $lastIndex = array_key_last($tail);
                if ($lastIndex !== null && preg_match('/[A-Za-z]/', $tail[$lastIndex])) {
                    $tail[$lastIndex] .= ' Moderator';
                }

                continue;
            }

            if (! $this->isColumnHeaderFragment($line)) {
                $tail[] = $line;
            }
        }

        [$serviceLoad, $otherAssignment] = $this->tailFields($tail);

        return [
            'teacher_name' => $name,
            'employment_status' => $status,
            'sections' => $this->format($buckets['sections']),
            'cm' => $this->format($buckets['cm']),
            'hc' => $this->format($buckets['hc']),
            'service_load' => $serviceLoad,
            'other_assignment' => $otherAssignment,
            'flagged' => $name === null || $flags !== [],
            'flags' => $flags,
        ];
    }

    /**
     * Resolve any section names on a line into the given bucket.
     * Unresolved-but-name-like text is recorded as a flag, never invented.
     *
     * @param  array<string, array<int, SectionResolution>>  $buckets
     * @param  array<string, string>  $flags
     * @return array{0:int,1:int} [sections resolved, questions raised]
     */
    private function resolveLine(string $line, array &$buckets, array &$flags, string $bucket): array
    {
        if (trim($line) === '' || $this->looksNumeric($line)) {
            return [0, 0];
        }

        $found = 0;
        $queried = 0;

        foreach ($this->resolver->resolveMany($line) as $resolution) {
            if ($resolution->isResolved()) {
                $buckets[$bucket][] = $resolution;
                $found++;
                if ($resolution->reason) {
                    $flags[$bucket] = trim(($flags[$bucket] ?? '') . ' ' . $resolution->reason);
                }
                continue;
            }

            // Only on a line that is purely a name — "20 3 Punlaan" is the numeric
            // cluster plus an assignment, not a mistyped section.
            if ($this->isRegistrarQuestion($resolution->reason) && ! preg_match('/\d/', $line)) {
                $flags[$bucket] = trim(($flags[$bucket] ?? '') . ' ' . $resolution->reason);
                $queried++;
            }
        }

        return [$found, $queried];
    }

    /**
     * Only names the registrar has to rule on ("Miki") are surfaced. Ordinary
     * non-section text on a row — a status, a club name — is not a failed
     * section lookup and must not be reported as one.
     */
    private function isRegistrarQuestion(?string $reason): bool
    {
        return $reason !== null
            && (str_contains($reason, 'confirm with the registrar') || str_contains($reason, 'Did you mean'));
    }

    /**
     * "Class Moderator", "G10 Class Moderator", "Magis Class Moderator",
     * "1 moderating class" — but NOT "Sports Club Moderator" or
     * "Punlaan Moderator", which are honorarium club roles.
     */
    private function isClassModeratorMarker(string $line): bool
    {
        if (preg_match('/moderating\s+class/i', $line)) {
            return true;
        }

        return (bool) preg_match('/(?:^|\s)class\s*$/i', $line)
            || (bool) preg_match('/(?:^|\s)class\s+moderator/i', $line);
    }

    private function isCountOnlyModeratorMarker(string $line): bool
    {
        return (bool) preg_match('/^\d+\s*moderating(?:\s+class(?:es)?)?$/i', trim($line));
    }

    private function stripModeratorMarker(string $line): string
    {
        return preg_replace('/(?:G\s*\d+\s*)?(?:magis\s+)?class\s*(?:moderator)?|moderating\s+class|^\d+\s*/i', ' ', $line) ?? '';
    }

    /** "G8 Magis" — a grade-qualified Honor's Class cell. */
    private function isHonorsMarker(string $line): bool
    {
        return (bool) preg_match('/\bG\s*(?:7|8|9|10)\s+magis\b/i', $line);
    }

    private function isBareMagisMarker(string $line): bool
    {
        return (bool) preg_match('/^\(?\s*magis(?:\s+class)?\s*\)?$/i', trim($line));
    }

    /**
     * The name is the leading run of lines before the status marker, the section
     * list, or the numeric cells. Some Social Studies rows have no "(Status)"
     * parenthetical at all, so the name must not be defined as "text before '('".
     *
     * @param  array<int, string>  $lines
     * @return array{0:?string,1:array<int,string>}
     */
    private function splitName(array $lines): array
    {
        $nameParts = [];
        $index = 0;

        foreach ($lines as $i => $line) {
            $index = $i;
            if ($this->isStatusLine($line) || $this->looksNumeric($line) || $this->startsSectionCell($line)) {
                break;
            }
            $nameParts[] = preg_replace('/\((?:MAPEH|TLE)\)/i', '', $line);
            $index = $i + 1;
        }

        $name = trim(preg_replace('/\s+/', ' ', implode(' ', $nameParts)));
        $name = preg_replace('/^(?:Ms|Mr|Mrs|Bb|Gng|Br|Sr|Fr)\.?\s+/i', '', $name);
        $name = trim($name, " .,\t");
        $name = preg_replace('/\s*\)$/', '', $name);

        return [$name !== '' ? $name : null, array_slice($lines, $index)];
    }

    private function isStatusLine(string $line): bool
    {
        return (bool) preg_match('/^\(?\s*(?:FT\s+)?(?:Permanent|Probationary|New Teacher|Substitute|Retiree)/i', $line);
    }

    private function startsSectionCell(string $line): bool
    {
        if (preg_match('/^\d+\s*(?:section|moderating)/i', $line)) {
            return true;
        }

        foreach ($this->resolver->resolveMany($line) as $r) {
            if ($r->isResolved()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Multi-word section names wrap mid-name in the source table — Science breaks
     * "Ignatius of Loyola" after "of". Rejoin before anything tries to resolve it.
     *
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function rejoinWrappedNames(array $lines): array
    {
        $out = [];
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            // The break can fall either side of the preposition: Science prints
            // both "Ignatius of" / "Loyola" and "Ignatius" / "of Loyola".
            while (isset($lines[$i + 1])
                && (preg_match('/\s(?:of|de|la|del)$/i', $line)
                    || preg_match('/\bmoderating$/i', $line)
                    // Lowercase only: "of Loyola" continues a name, but a
                    // capitalised "De Britto" starts a new section.
                    || preg_match('/^(?:of|de|la|del)\s/', $lines[$i + 1]))) {
                $line .= ' ' . $lines[++$i];
            }
            $out[] = $line;
        }

        return $out;
    }

    private function isBareModeratorWord(string $line): bool
    {
        return (bool) preg_match('/^\(?moderators?\)?$/i', trim($line));
    }

    /** The numeric cells sharing a line with a section name. */
    private function numericResidue(string $line): ?string
    {
        $numbers = array_values(array_filter(
            preg_split('/\s+/', trim($line)) ?: [],
            fn ($t) => preg_match('/^\d+(?:\.\d+)?$/', $t),
        ));

        return $numbers === [] ? null : implode(' ', $numbers);
    }

    /**
     * Column headers and cell labels that survive flattening. They are not load
     * data, and left in the trailing cells they displace the Service Load.
     */
    private function isColumnHeaderFragment(string $line): bool
    {
        $line = trim($line, " \t()");

        return (bool) preg_match(
            '/^(?:grade\s*\d+|moderator|magis(?:\s+class)?|class|honou?r.?s(?:\s+class)?|\d+\s*sections?|sections?|research|total|hours)$/i',
            $line,
        );
    }

    /** @param array<int, string> $tail */
    private function containsNumericCluster(array $tail): bool
    {
        foreach ($tail as $line) {
            $numbers = array_filter(preg_split('/\s+/', trim($line)) ?: [],
                fn ($t) => preg_match('/^-$|^\d+(?:\.\d+)?$/', $t));
            if (count($numbers) >= 3) {
                return true;
            }
        }

        return false;
    }

    private function looksNumeric(string $line): bool
    {
        return (bool) preg_match('/^[\d\s.,\-\/]+$/u', $line);
    }

    private function isSignatureBlock(string $line): bool
    {
        return (bool) preg_match('/prepared by|noted by|approved by|coordinator\s+AP|Principal$/i', $line)
            // The signatories are printed in caps with an honorific, e.g.
            // "MR. ERIC D. TUBO   BB. JESSA JANE SUPILANAS".
            // Honorifics are written inconsistently ("BB." and "Bb."), and the
            // signatories' titles trail them.
            || (bool) preg_match('/^(?:MR|MRS|MS|BB|FR|REV|GNG|SR)\.\s+[A-ZÑ]/ui', $line)
            || (bool) preg_match('/(?:AP|Asst\.? Principal) for (?:Academics|Admin)/i', $line)
            || (bool) preg_match('/^(?:Rev\.|Fr\.)\s/i', $line)
            || (bool) preg_match('/^[A-Z][a-z]+\s+Chairperson$/', $line);
    }

    private function status(array $lines): ?string
    {
        $joined = implode(' ', $lines);
        if (preg_match('/\(?\b((?:FT\s+)?(?:Permanent(?:\s+Teacher)?|Probationary\s*(?:III|II|I|[1-3])|New Teacher|Substitute[^)]*|Retiree))\)?/i', $joined, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * From the leftover cells, recover Service Load and Other Assignment.
     *
     * Reading order is Total Teaching Hours, Service Load, Other Assignment,
     * Equivalent Hours, … so the Service Load is the number immediately before
     * the assignment text. Column counts differ per sheet (CLE leads with an
     * Honor's column), which is why this is anchored on the text, not an index.
     *
     * @param  array<int, string>  $tail
     * @return array{0:?string,1:?string}
     */
    private function tailFields(array $tail): array
    {
        $tokens = [];
        foreach ($tail as $line) {
            foreach (preg_split('/\s+/', trim($line)) ?: [] as $token) {
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        $service = null;
        $words = [];
        $previousNumber = null;

        foreach ($tokens as $token) {
            if (preg_match('/^-$|^\d+(?:\.\d+)?$/', $token)) {
                if ($words === []) {
                    $previousNumber = $token;
                }
                continue;
            }
            if ($words === [] && $service === null) {
                $service = $previousNumber;
            }
            $words[] = $token;
        }

        // No assignment text at all (e.g. Filipino's "20 3  3 23 0.67", or
        // Science's cluster split over two lines). Service Load is the second
        // numeric cell; the section counts have already been stripped above.
        if ($service === null) {
            $numbers = array_values(array_filter($tokens, fn ($t) => preg_match('/^-$|^\d+(?:\.\d+)?$/', $t)));
            $service = $numbers[1] ?? null;
        }

        $other = trim(preg_replace('/\s+/', ' ', implode(' ', $words)));
        $other = preg_replace('/\s*\b\d+(?:\.\d+)?\b/', '', $other);
        $other = trim(preg_replace('/\bFULL\b|\bFull Load\b/i', '', $other));
        // "1 moderating class" is a count with no section named — Math's way of
        // saying the teacher moderates something. It is not an assignment.
        $other = trim(preg_replace('/\bmoderating(?:\s+class)?\b/i', '', $other));
        $other = trim(preg_replace('/\s+/', ' ', $other), " .,-");

        // "None" is an explicitly empty cell, not a role.
        if (preg_match('/^none$/i', $other)) {
            return [$service, null];
        }

        return [$service, $other !== '' ? $other : null];
    }

    /**
     * Render resolutions in the "G7: Arrowsmith, Jogues; G8: Xavier" shape that
     * PlantillaReviewService::parseSections() already consumes.
     *
     * @param  array<int, SectionResolution>  $resolutions
     */
    private function format(array $resolutions): ?string
    {
        $byGrade = [];
        foreach ($resolutions as $resolution) {
            $grade = $resolution->section->grade_level->value;
            $name = $resolution->section->name;
            if (! in_array($name, $byGrade[$grade] ?? [], true)) {
                $byGrade[$grade][] = $name;
            }
        }

        if ($byGrade === []) {
            return null;
        }

        uksort($byGrade, fn ($a, $b) => (int) substr($a, 1) <=> (int) substr($b, 1));

        $parts = [];
        foreach ($byGrade as $grade => $names) {
            $parts[] = $grade . ': ' . implode(', ', $names);
        }

        return implode('; ', $parts);
    }
}
