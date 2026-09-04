<?php

namespace App\Services\Plantilla;

use App\Models\Section;
use App\Models\SystemConstant;
use Illuminate\Support\Collection;

/**
 * Resolves a section name as written on a plantilla sheet to a canonical Section.
 *
 * The plantillas carry grade only by table-column position, which does not survive
 * PDF text extraction. Because the registrar's 2026 roster gives all 36 sections
 * *unique* names school-wide (asserted in SeedTest), the grade is recoverable from
 * the name alone — no column geometry needed.
 *
 * The resolver never invents a section. Anything it cannot place comes back
 * Unresolved with a human-readable reason for the Chair's review screen.
 */
class SectionResolver
{
    /**
     * Names that are not sections but appear where section names do.
     * "Magis" is the important one: it is a modifier on three sections
     * (Loyola/Kostka/Faber), and reading it as a name is what produced the
     * false "one name spans three grades" conclusion in the old blocker doc.
     */
    private const STOPWORDS = [
        'magis', 'section', 'sections', 'class', 'classes', 'moderator', 'moderating',
        'honors', 'honor', 'none', 'and', 'of', 'grade', 'total', 'hours', 'hour',
        'chair', 'chairperson', 'coordinator', 'leader', 'adviser', 'teacher', 'research',
        // Department codes printed under the teacher's name on some sheets.
        'mapeh', 'tle', 'cle', 'filipino', 'science', 'math', 'mathematics', 'only',
    ];

    /** Known misspellings and short forms, mapped to the canonical section name. */
    private const ALIASES = [
        'de brito' => 'De Britto',
        'debritto' => 'De Britto',
        'anchietta' => 'Anchieta',
        'colombierre' => 'Colombiere',
        'colombiere' => 'Colombiere',
        'berchman' => 'Berchmans',
        'ignatius' => 'Ignatius of Loyola',
        'loyola' => 'Ignatius of Loyola',
        'ignatius of loyola' => 'Ignatius of Loyola',
        'stanislaus kostka' => 'Kostka',
        'peter faber' => 'Faber',
        'rupert mayer' => 'Mayer',
    ];

    /**
     * Names seen in the plantillas that are absent from the 2026 roster.
     * Deliberately NOT aliased — the registrar has to confirm before we rewrite
     * anyone's load. Move an entry into ALIASES once confirmed.
     */
    private const PENDING_REGISTRAR = [
        'miki' => '"Miki" (Saint Paul Miki) is not in the 2026 roster. It appears only in sheets that never mention Rubio, so it is most likely the former name of G7 Rubio — confirm with the registrar before importing.',
        'paul miki' => '"Paul Miki" is not in the 2026 roster; likely the former name of G7 Rubio — confirm with the registrar.',
        'paul' => '"Paul" (Saint Paul Miki) is not in the 2026 roster; likely the former name of G7 Rubio — confirm with the registrar.',
    ];

    private ?Collection $sections = null;

    /** normalized name => Section */
    private array $index = [];

    public function resolve(string $raw): SectionResolution
    {
        $this->boot();

        $trimmed = trim($raw);
        [$claimedGrade, $stripped] = $this->splitGradePrefix($trimmed);
        $key = $this->normalize($stripped);

        if ($key === '') {
            return new SectionResolution($raw, null, SectionMatch::Unresolved, 'Empty section name.');
        }

        // "G8 Magis" / "G10 Magis" — the honor's-class column names a grade, not a section.
        if ($claimedGrade !== null && $key === 'magis') {
            $magis = $this->sections->first(fn (Section $s) => $s->is_magis && $s->grade_level->value === $claimedGrade);

            return $magis
                ? new SectionResolution($raw, $magis, SectionMatch::Alias)
                : new SectionResolution($raw, null, SectionMatch::Unresolved, "No Magis section for {$claimedGrade}.");
        }

        if (in_array($key, self::STOPWORDS, true)) {
            return new SectionResolution($raw, null, SectionMatch::Unresolved, "\"{$trimmed}\" is not a section name.");
        }

        if (isset(self::PENDING_REGISTRAR[$key])) {
            return new SectionResolution($raw, null, SectionMatch::Unresolved, self::PENDING_REGISTRAR[$key]);
        }

        if ($hit = $this->index[$key] ?? null) {
            return $this->withGradeCheck($raw, $hit, SectionMatch::Exact, $claimedGrade);
        }

        if ($canonical = self::ALIASES[$key] ?? null) {
            $hit = $this->sections->firstWhere('name', $canonical);
            if ($hit) {
                return $this->withGradeCheck($raw, $hit, SectionMatch::Alias, $claimedGrade);
            }
        }

        return $this->fuzzy($raw, $key, $claimedGrade);
    }

    /**
     * Resolve every section name in a blob of extracted text.
     *
     * Handles the five list styles across the seven sheets: "(A, B, C)",
     * "A, B, C and D", newline-separated names, leading counts ("3 sections"),
     * and names wrapped across lines ("Ignatius" / "of Loyola").
     *
     * @return array<int, SectionResolution>
     */
    public function resolveMany(?string $blob): array
    {
        $this->boot();

        $fragments = $this->fragments((string) $blob);
        $out = [];

        for ($i = 0; $i < count($fragments); $i++) {
            // Longest-match window: a name split across lines rejoins here.
            $matched = false;
            for ($width = min(3, count($fragments) - $i); $width >= 2; $width--) {
                $joined = implode(' ', array_slice($fragments, $i, $width));
                $candidate = $this->resolve($joined);
                if ($candidate->isResolved() && $candidate->match !== SectionMatch::Fuzzy) {
                    $out[] = $candidate;
                    $i += $width - 1;
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                continue;
            }

            $one = $fragments[$i];
            if ($this->isNoise($one)) {
                continue;
            }
            $out[] = $this->resolve($one);
        }

        return $out;
    }

    private function boot(): void
    {
        if ($this->sections !== null) {
            return;
        }
        // Section names are unique within a year, not across years: the same
        // name recurs every SY. Indexing every year would let a later year's
        // row win the lookup and attach load to the wrong section.
        $this->sections = Section::where('school_year', SystemConstant::get('current_school_year'))->get();
        foreach ($this->sections as $section) {
            $this->index[$this->normalize($section->name)] = $section;
            if ($section->full_name) {
                $this->index[$this->normalize($section->full_name)] = $section;
            }
        }
    }

    /**
     * Accept a fuzzy match only at edit distance 1, and only when it is a clear
     * winner. Two canonical names are just 2 apart (Regis/Lewis, Faber/Mayer), so
     * a looser threshold could silently pick a different real section.
     */
    private function fuzzy(string $raw, string $key, ?string $claimedGrade): SectionResolution
    {
        $scored = [];
        foreach ($this->index as $name => $section) {
            $scored[] = [levenshtein($key, $name), $section];
        }
        usort($scored, fn ($a, $b) => $a[0] <=> $b[0]);

        $best = $scored[0] ?? null;
        $runnerUp = $scored[1][0] ?? PHP_INT_MAX;

        if ($best && $best[0] <= 1 && $runnerUp - $best[0] >= 2) {
            return $this->withGradeCheck($raw, $best[1], SectionMatch::Fuzzy, $claimedGrade,
                "Read as \"{$best[1]->name}\" — verify the spelling against the sheet.");
        }

        // Close but not close enough to accept silently — almost certainly a
        // mistyped section rather than a club or a role, so name the candidate.
        // Long enough that a 2-edit gap is a typo rather than a coincidence:
        // short words like "only" sit 3 edits from real names by chance.
        if ($best && $best[0] <= 2 && $runnerUp > $best[0] && mb_strlen($key) >= 6) {
            return new SectionResolution($raw, null, SectionMatch::Unresolved,
                "\"{$raw}\" is not in the 2026 roster. Did you mean {$best[1]->grade_level->value} {$best[1]->name}?");
        }

        return new SectionResolution($raw, null, SectionMatch::Unresolved,
            "\"{$raw}\" does not match any of the 36 sections in the 2026 roster.");
    }

    /** Where a sheet states a grade (TLE's "10Colombiere"), cross-check it. */
    private function withGradeCheck(string $raw, Section $section, SectionMatch $match, ?string $claimedGrade, ?string $reason = null): SectionResolution
    {
        $actual = $section->grade_level->value;

        if ($claimedGrade !== null && $claimedGrade !== $actual) {
            return new SectionResolution($raw, $section, $match,
                "Sheet says {$claimedGrade} but the 2026 roster places {$section->name} in {$actual} — confirm before importing.");
        }

        return new SectionResolution($raw, $section, $match, $reason);
    }

    /** @return array{0:?string,1:string} [claimed grade, remaining text] */
    private function splitGradePrefix(string $text): array
    {
        // "10Colombiere", "7Rubio", "G8 Magis", "G9 Kostka", "Grade 10 Faber"
        if (preg_match('/^(?:G(?:rade)?\s*)?(7|8|9|10)\s*[:.\-]?\s*(?=[A-Za-z])(.*)$/u', $text, $m)) {
            return ['G' . $m[1], trim($m[2])];
        }

        return [null, $text];
    }

    private function normalize(string $text): string
    {
        $text = str_replace(['’', '‘', '`'], "'", $text);
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text;
        $text = strtolower($text);
        $text = preg_replace('/\b(saint|sto|sta|st|bb|ms|mr|mrs|gng|br|sj)\b\.?/u', ' ', $text);
        $text = preg_replace('/[^a-z\s]/u', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /** Counts, numeric cells and blank cells are not attempts at a section name. */
    private function isNoise(string $fragment): bool
    {
        $key = $this->normalize($fragment);

        return $key === '' || in_array($key, self::STOPWORDS, true) || ! preg_match('/[a-z]{3}/', $key);
    }

    /** @return array<int, string> */
    private function fragments(string $blob): array
    {
        $blob = preg_replace('/\band\b/i', ',', $blob);
        $parts = preg_split('/[,;\n\r()]+/u', $blob) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
    }
}
