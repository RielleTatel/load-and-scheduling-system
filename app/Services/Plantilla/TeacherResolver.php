<?php

namespace App\Services\Plantilla;

use App\Models\Department;
use App\Models\Teacher;

/**
 * Finds or creates the Teacher a plantilla row refers to.
 *
 * The written form of a name varies between the registrar's roster and the seven
 * sheets — honorifics, middle initials, and outright misspellings ("Fritzie" vs
 * "Frizie B.", "Vendola" vs "Vendiola"). Matching on the raw string forked the
 * teacher on every re-import and migrated their load to the new row.
 *
 * Resolution order: exact normalized name in the department, then a registrar-
 * seeded teacher with no department yet, then a close variant already in the
 * department. Anything else is a new teacher.
 */
class TeacherResolver
{
    public function resolve(string $rawName, ?Department $department): TeacherResolution
    {
        $name = trim($rawName);
        $key = Teacher::normalize($name);

        if ($department) {
            $exact = Teacher::where('department_id', $department->id)
                ->where('normalized_name', $key)->first();

            if ($exact) {
                return new TeacherResolution($exact, false);
            }
        }

        // The registrar names moderators and teacher-partners before their
        // department's plantilla exists; adopt that record rather than duplicating.
        // The two sources spell the same person differently often enough that an
        // exact comparison is not sufficient — "Abigail Joyce L. Vendiola" and
        // "Abigail Joyce L. Vendola" are one teacher.
        $unclaimed = Teacher::whereNull('department_id')->get();
        $uncertain = null;

        foreach ($unclaimed as $candidate) {
            $verdict = $this->compare($name, $candidate->full_name);

            if ($verdict === NameMatch::Same && $department) {
                $candidate->update(['department_id' => $department->id, 'source' => 'plantilla']);

                return new TeacherResolution($candidate->fresh(), false);
            }

            $uncertain ??= $verdict === NameMatch::Possible ? $candidate : null;
        }

        if ($department && $close = $this->closeMatchIn($department, $key)) {
            return new TeacherResolution($close, false,
                "Read as the existing teacher \"{$close->full_name}\" — the sheet writes \"{$name}\". "
                . 'Correct one of them if they are different people.');
        }

        if ($department && $elsewhere = Teacher::whereNotNull('department_id')
            ->where('department_id', '!=', $department->id)
            ->where('normalized_name', $key)->first()) {
            $teacher = Teacher::create(['full_name' => $name, 'department_id' => $department->id]);

            return new TeacherResolution($teacher, true,
                "\"{$name}\" also appears in {$elsewhere->department->name}. A teacher belongs to one "
                . 'department — confirm which sheet is correct.');
        }

        $teacher = Teacher::create(['full_name' => $name, 'department_id' => $department?->id]);

        if ($uncertain) {
            return new TeacherResolution($teacher, true,
                "\"{$name}\" closely resembles \"{$uncertain->full_name}\" on the registrar's roster. "
                . 'If they are the same person, merge them in the teacher directory.');
        }

        return new TeacherResolution($teacher, true);
    }

    /**
     * Are two written names the same person?
     *
     * Compares the surname and the given names separately, because the two
     * sources disagree in predictable ways: dropped middle names
     * ("Mark Brian D. Gumandao" / "Mark Gumandao"), compound given names written
     * closed up ("Mary Cris" / "Marycris"), and one-letter surname variants
     * ("Vendiola" / "Vendola"). Never on surname alone — Cristie and Ivy
     * Delos Reyes are two people.
     */
    public function compare(?string $a, ?string $b): NameMatch
    {
        [$aGiven, $aFirst, $aLast] = $this->split($a);
        [$bGiven, $bFirst, $bLast] = $this->split($b);

        if ($aLast === '' || $bLast === '' || $aFirst === '' || $bFirst === '') {
            return NameMatch::Different;
        }

        $surnameDistance = levenshtein($aLast, $bLast);
        // A first given name that matches, or the whole given-name run written
        // closed up rather than spaced.
        $givenMatches = $this->within($aFirst, $bFirst, 1) || $this->within($aGiven, $bGiven, 1);

        if ($surnameDistance <= 1 && $givenMatches) {
            return NameMatch::Same;
        }

        if ($surnameDistance <= 3 && $givenMatches) {
            return NameMatch::Possible;
        }

        return NameMatch::Different;
    }

    private function within(string $a, string $b, int $max): bool
    {
        return $a === $b || (min(mb_strlen($a), mb_strlen($b)) >= 4 && levenshtein($a, $b) <= $max);
    }

    /** @return array{0:string,1:string,2:string} [given names joined, first given name, surname] */
    private function split(?string $name): array
    {
        $tokens = array_values(array_filter(explode(' ', Teacher::normalize($name))));

        if (count($tokens) < 2) {
            return ['', '', $tokens[0] ?? ''];
        }

        $last = array_pop($tokens);

        return [implode('', $tokens), $tokens[0], $last];
    }

    /**
     * One edit apart is a misspelling of the same person; more than that is a
     * different person. Compared on the normalized form so initials and
     * honorifics do not count as differences.
     */
    private function closeMatchIn(Department $department, string $key): ?Teacher
    {
        if (mb_strlen($key) < 6) {
            return null;
        }

        $candidates = Teacher::where('department_id', $department->id)->get()
            ->map(fn (Teacher $t) => [levenshtein($key, (string) $t->normalized_name), $t])
            ->sortBy(0)->values();

        $best = $candidates->first();
        $runnerUp = $candidates->get(1)[0] ?? PHP_INT_MAX;

        return $best && $best[0] <= 1 && $runnerUp > $best[0] ? $best[1] : null;
    }
}
