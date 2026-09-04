<?php

namespace App\Services\Roster;

use App\Models\RosterImport;
use App\Models\Section;
use App\Models\Teacher;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;

/**
 * Commits a reviewed roster to the sections table for its school year, and
 * adopts the moderator/teacher-partner names into the teacher directory.
 *
 * Validation is all-or-nothing: the roster defines the section list every
 * plantilla import resolves against, so a partial or internally inconsistent
 * roster must never land. The rules mirror the invariants SeedTest asserts.
 */
class RosterReviewService
{
    public function __construct(private AuditLogService $audit) {}

    /**
     * @return array{imported:int, errors:array<int,string>}
     */
    public function confirmImport(RosterImport $import): array
    {
        $rows = $import->rows()->orderBy('id')->get()->map(fn ($row) => $row->row_json)->all();

        if ($errors = $this->validate($rows)) {
            return ['imported' => 0, 'errors' => $errors];
        }

        return DB::transaction(function () use ($import, $rows) {
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

                foreach ([$row['moderator_name'] ?? null, $row['teacher_partner_name'] ?? null] as $person) {
                    $this->adopt($person);
                }

                $imported++;
            }

            $import->update(['extraction_status' => 'imported']);
            $this->audit->log('roster.imported', $import, after: [
                'school_year' => $import->school_year,
                'sections' => $imported,
            ]);

            return ['imported' => $imported, 'errors' => []];
        });
    }

    /**
     * Create the registrar-named person if they aren't already on file.
     *
     * Deliberately NOT TeacherResolver: with a null department that resolver
     * skips its adoption branch (`$verdict === Same && $department`) and falls
     * through to Teacher::create(), forking anyone already in the directory.
     * This is the same normalized-name check RegistrarStaffSeeder uses, and the
     * department is left null — only a plantilla says which department someone
     * belongs to, and TeacherResolver adopts these rows when that sheet lands.
     */
    private function adopt(?string $name): void
    {
        $name = trim((string) $name);

        if ($name === '') {
            return;
        }

        $key = Teacher::normalize($name);

        if (Teacher::where('normalized_name', $key)->exists()) {
            return;
        }

        Teacher::create(['full_name' => $name, 'department_id' => null, 'source' => 'registrar']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function validate(array $rows): array
    {
        $errors = [];

        if (count($rows) !== 36) {
            $errors[] = 'Expected 36 sections, found ' . count($rows) . '. The roster must be complete before importing.';
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
