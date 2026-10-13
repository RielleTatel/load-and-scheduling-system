<?php

namespace App\Services\Roster;

use App\Models\RosterImport;
use App\Models\Section;
use App\Services\Audit\AuditLogService;
use App\Services\Teachers\IdentityChangedException;
use App\Services\Teachers\IdentityObservation;
use App\Services\Teachers\TeacherIdentityService;
use Illuminate\Support\Facades\DB;

/**
 * Commits a reviewed roster to the sections table for its school year, and
 * adopts the moderator/teacher-partner names into the teacher directory.
 *
 * The roster names only moderators and teacher-partners: it is a partial
 * staff source, so nobody's absence from it means anything.
 *
 * Validation is all-or-nothing: the roster defines the section list every
 * plantilla import resolves against, so a partial or internally inconsistent
 * roster must never land. The rules mirror the invariants SeedTest asserts.
 */
class RosterReviewService
{
    public function __construct(
        private AuditLogService $audit,
        private TeacherIdentityService $identities,
    ) {}

    /**
     * Any named person who needs an Admin identity decision keeps the whole
     * roster staged; `review` lists what is waiting.
     *
     * @return array{imported:int, errors:array<int,string>, review:array<int,string>}
     */
    public function confirmImport(RosterImport $import): array
    {
        $stagedRows = $import->rows()->orderBy('id')->get();
        $rows = $stagedRows->map(fn ($row) => $row->row_json)->all();

        if ($errors = $this->validate($rows)) {
            return ['imported' => 0, 'errors' => $errors, 'review' => []];
        }

        $observations = $this->observations($import, $stagedRows);
        $outcomes = $this->identities->preflight($observations);
        $review = collect($outcomes)->filter->needsReview()
            ->map(fn ($outcome) => $this->identities->describe($outcome->case))->unique()->values()->all();

        if ($review !== []) {
            return ['imported' => 0, 'errors' => [], 'review' => $review];
        }

        try {
            return DB::transaction(fn () => $this->import($import, $rows, $observations, $outcomes));
        } catch (IdentityChangedException $e) {
            return ['imported' => 0, 'errors' => [], 'review' => [$e->getMessage()]];
        }
    }

    /**
     * Each row names up to two people; each is its own observation. The
     * registrar never says which department someone belongs to.
     *
     * @return array<string, IdentityObservation>
     */
    private function observations(RosterImport $import, $stagedRows): array
    {
        $observations = [];

        foreach ($stagedRows as $row) {
            foreach (['moderator' => 'moderator_name', 'teacher_partner' => 'teacher_partner_name'] as $field => $column) {
                $name = trim((string) ($row->row_json[$column] ?? ''));
                if ($name === '') {
                    continue;
                }

                $key = "roster_row:{$row->id}:{$field}";
                $observations[$key] = new IdentityObservation(
                    IdentityObservation::REGISTRAR,
                    $key,
                    $name,
                    null,
                    "Registrar roster import #{$import->id} ({$import->original_filename})",
                    $import->school_year,
                    $row->row_json["extracted_{$column}"] ?? null,
                    'partial',
                );
            }
        }

        return $observations;
    }

    private function import(RosterImport $import, array $rows, array $observations, array $outcomes): array
    {
        $this->identities->commitAll($observations, $outcomes);
        $imported = 0;

        foreach ($rows as $row) {
            Section::updateOrCreate(
                [
                    'school_year' => $import->school_year,
                    'grade_level' => $row['grade_level'],
                    'name' => $row['name'],
                ],
                [
                    'full_name' => $row['full_name'],
                    'room' => $row['room'],
                    'is_magis' => (bool) ($row['is_magis'] ?? false),
                    'moderator_name' => $row['moderator_name'],
                    'teacher_partner_name' => $row['teacher_partner_name'],
                ],
            );

            $imported++;
        }

        $import->update(['extraction_status' => 'imported']);
        $this->audit->log('roster.imported', $import, after: [
            'school_year' => $import->school_year,
            'sections' => $imported,
        ]);

        return ['imported' => $imported, 'errors' => [], 'review' => []];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function validate(array $rows): array
    {
        $errors = [];

        if (count($rows) !== 36) {
            $errors[] = 'Expected 36 sections, found '.count($rows).'. The roster must be complete before importing.';
        }

        foreach (['G7', 'G8', 'G9', 'G10'] as $grade) {
            $count = count(array_filter($rows, fn ($r) => ($r['grade_level'] ?? null) === $grade));
            if ($count !== 9) {
                $errors[] = "{$grade} has {$count} sections; every grade must have exactly 9.";
            }
        }

        $seen = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                $errors[] = 'A section has no short name. Plantilla matching needs one for every section.';

                continue;
            }

            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                $errors[] = "Two sections are both named \"{$name}\". Section names must be unique school-wide, or plantilla matching cannot recover a grade from a name.";
            }
            $seen[$key] = true;

            if (! preg_match('/^\d+$/', trim((string) ($row['room'] ?? '')))) {
                $errors[] = "\"{$name}\" has no room number.";
            }
        }

        return array_values(array_unique($errors));
    }
}
