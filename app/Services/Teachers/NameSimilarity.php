<?php

namespace App\Services\Teachers;

use App\Models\Teacher;

/**
 * How alike two written names are.
 *
 * The registrar's roster and the seven sheets disagree on honorifics, middle
 * initials, and outright spelling ("Fritzie" vs "Frizie B.", "Vendola" vs
 * "Vendiola"). These heuristics used to join records automatically; now they
 * only rank suggestions for an Admin, because similarity alone cannot prove
 * two names are one person.
 */
class NameSimilarity
{
    /**
     * Are two written names plausibly the same person?
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

    /**
     * One edit apart on the normalized form — a likely misspelling. Short
     * names are excluded because one edit changes them too much.
     */
    public function isNear(string $normalizedA, string $normalizedB): bool
    {
        if (min(mb_strlen($normalizedA), mb_strlen($normalizedB)) < 6) {
            return false;
        }

        return levenshtein($normalizedA, $normalizedB) <= 1;
    }

    /** Similar enough to suggest to an Admin. */
    public function resembles(string $name, string $other): bool
    {
        return $this->compare($name, $other) !== NameMatch::Different
            || $this->isNear(Teacher::normalize($name), Teacher::normalize($other));
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
}
