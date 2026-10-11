<?php

namespace App\Services\Scheduling;

use App\Enums\TimetableKind;
use Closure;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** Arrange one review day, then repeat it. No normal block or teacher-break rules. */
final class SpecialScheduleGenerator implements TimetableGenerator
{
    public function generate(array $sections, array $unavailable, int $seed, int $seconds = 110, ?Closure $progress = null, array $startingEntries = [], ?Closure $checkpoint = null, array $fallbackEntries = []): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $edges = [];
        $teacherDegrees = [];
        $allowed = [];
        foreach ($sections as $section) {
            foreach (ScheduleReadinessService::SUBJECT_CODES as $code) {
                $assignment = $section['assignments'][$code] ?? null;
                if (! $assignment || ! ($assignment['teacher_id'] ?? null)) {
                    throw new ScheduleInfeasibleException('Scheduling cannot fit: a section is missing a subject teacher.');
                }
                $teacherId = (int) $assignment['teacher_id'];
                $edges[] = ['section_id' => (int) $section['id'], 'department_code' => $code,
                    'department_id' => (int) $assignment['department_id'], 'teacher_id' => $teacherId];
                $teacherDegrees[$teacherId] = ($teacherDegrees[$teacherId] ?? 0) + 1;
                $allowed[$teacherId] = 255;
            }
        }
        // Identical daily order means an unavailable period on ANY day excludes
        // that period from the teacher's repeating review day.
        foreach ($unavailable as $slot) {
            if (isset($allowed[$slot['teacher_id']]) && $slot['day'] >= 1 && $slot['day'] <= 5 && $slot['period'] >= 1 && $slot['period'] <= 8) {
                $allowed[$slot['teacher_id']] &= ~(1 << ($slot['period'] - 1));
            }
        }
        foreach ($teacherDegrees as $teacherId => $degree) {
            if ($degree > $this->bitCount($allowed[$teacherId])) {
                throw new ScheduleInfeasibleException("Scheduling cannot fit: teacher {$teacherId} needs {$degree} distinct review periods but has fewer available periods in the repeating daily order.");
            }
        }
        $edges = $random->shuffleArray($edges);
        $colors = $this->colorBipartiteGraph($edges);
        $attempts = count($edges);
        $needsSearch = false;
        foreach ($edges as $id => $edge) {
            if (! ($allowed[$edge['teacher_id']] & (1 << ($colors[$id] - 1)))) {
                $needsSearch = true;
                break;
            }
        }
        if ($needsSearch) {
            $preferred = $colors;
            $colors = [];
            $sectionUsed = $teacherUsed = [];
            $remaining = array_fill_keys(array_keys($edges), true);
            $deadline = microtime(true) + max(1, $seconds);
            $nextHeartbeat = 0.0;
            $bestCount = 0;
            $bestEntries = [];
            $search = function () use (&$search, &$colors, &$sectionUsed, &$teacherUsed, &$remaining, &$attempts, &$nextHeartbeat, &$bestCount, &$bestEntries, $edges, $allowed, $teacherDegrees, $preferred, $deadline, $random, $progress, $checkpoint): bool {
                if ($remaining === []) {
                    return true;
                }
                $now = microtime(true);
                if ($now > $deadline) {
                    throw new ScheduleSearchExhaustedException('Continuing to arrange review classes.', $bestEntries, [], count($edges) - $bestCount);
                }
                if ($now >= $nextHeartbeat) {
                    $nextHeartbeat = $now + 1;
                    if ($progress && $progress() === false) {
                        throw new ScheduleGenerationStoppedException;
                    }
                }
                if (count($colors) > $bestCount) {
                    $bestCount = count($colors);
                    $bestEntries = $this->entries($edges, $colors);
                    if ($checkpoint) {
                        $checkpoint($bestEntries, count($edges) - $bestCount);
                    }
                }
                $selected = null;
                $options = [];
                $bestRank = PHP_INT_MAX;
                foreach ($remaining as $id => $_) {
                    $edge = $edges[$id];
                    $mask = $allowed[$edge['teacher_id']] & ~(($sectionUsed[$edge['section_id']] ?? 0) | ($teacherUsed[$edge['teacher_id']] ?? 0));
                    $size = $this->bitCount($mask);
                    if ($size === 0) {
                        return false;
                    }
                    $rank = $size * 10 - $teacherDegrees[$edge['teacher_id']];
                    if ($rank < $bestRank) {
                        $bestRank = $rank;
                        $selected = $id;
                        $options = array_values(array_filter(range(1, 8), fn ($period) => $mask & (1 << ($period - 1))));
                    }
                }
                $options = $random->shuffleArray($options);
                usort($options, fn ($a, $b) => ($a === $preferred[$selected] ? 0 : 1) <=> ($b === $preferred[$selected] ? 0 : 1));
                $edge = $edges[$selected];
                unset($remaining[$selected]);
                foreach ($options as $period) {
                    $attempts++;
                    $bit = 1 << ($period - 1);
                    $colors[$selected] = $period;
                    $sectionUsed[$edge['section_id']] = ($sectionUsed[$edge['section_id']] ?? 0) | $bit;
                    $teacherUsed[$edge['teacher_id']] = ($teacherUsed[$edge['teacher_id']] ?? 0) | $bit;
                    if ($search()) {
                        return true;
                    }
                    $sectionUsed[$edge['section_id']] &= ~$bit;
                    $teacherUsed[$edge['teacher_id']] &= ~$bit;
                    unset($colors[$selected]);
                }
                $remaining[$selected] = true;

                return false;
            };
            if (! $search()) {
                throw new ScheduleInfeasibleException('Scheduling cannot fit: the unavailable times do not allow an identical conflict-free review day. Review teacher availability.');
            }
        }
        if ($progress && $progress() === false) {
            throw new ScheduleGenerationStoppedException;
        }
        $entries = $this->entries($edges, $colors);
        $issues = (new ScheduleConstraintValidator)->validate($sections, $entries, $unavailable, TimetableKind::Special);
        if ($checkpoint) {
            $checkpoint($entries, count($issues));
        }

        return ['entries' => $entries, 'issues' => $issues, 'attempts' => $attempts];
    }

    /** Eight-color edge coloring guarantees a solution when there are no unavailable slots. */
    private function colorBipartiteGraph(array $edges): array
    {
        $incident = $colors = [];
        foreach ($edges as $id => $edge) {
            $left = 's'.$edge['section_id'];
            $right = 't'.$edge['teacher_id'];
            $free = function (string $vertex) use (&$incident, &$colors): int {
                $used = [];
                foreach ($incident[$vertex] ?? [] as $edgeId) {
                    $used[$colors[$edgeId]] = true;
                }
                foreach (range(1, 8) as $color) {
                    if (! isset($used[$color])) {
                        return $color;
                    }
                }
                throw new ScheduleInfeasibleException('Scheduling cannot fit: more than eight classes share a teacher or section.');
            };
            $alpha = $free($left);
            $beta = $free($right);
            // Follow the alternating beta/alpha path from the section. In a
            // bipartite graph it cannot reach the teacher where beta is missing.
            // Swapping this path frees beta at both ends without any conflicts.
            $vertex = $left;
            $wanted = $beta;
            $path = [];
            if ($alpha !== $beta) {
                while (true) {
                    $next = null;
                    foreach ($incident[$vertex] ?? [] as $edgeId) {
                        if ($colors[$edgeId] === $wanted) {
                            $next = $edgeId;
                            break;
                        }
                    }
                    if ($next === null) {
                        break;
                    }
                    $path[] = $next;
                    $other = $edges[$next];
                    $vertex = $vertex === 's'.$other['section_id'] ? 't'.$other['teacher_id'] : 's'.$other['section_id'];
                    $wanted = $wanted === $alpha ? $beta : $alpha;
                }
                foreach ($path as $edgeId) {
                    $colors[$edgeId] = $colors[$edgeId] === $alpha ? $beta : $alpha;
                }
            }
            $colors[$id] = $beta;
            $incident[$left][] = $id;
            $incident[$right][] = $id;
        }

        return $colors;
    }

    private function entries(array $edges, array $colors): array
    {
        $entries = [];
        foreach ($colors as $id => $period) {
            foreach (range(1, SchedulePolicy::DAYS) as $day) {
                $entries[] = $edges[$id] + ['day' => $day, 'period' => $period];
            }
        }
        usort($entries, fn ($a, $b) => [$a['section_id'], $a['day'], $a['period']] <=> [$b['section_id'], $b['day'], $b['period']]);

        return $entries;
    }

    private function bitCount(int $mask): int
    {
        return substr_count(decbin($mask), '1');
    }
}
