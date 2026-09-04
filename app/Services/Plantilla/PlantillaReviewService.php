<?php

namespace App\Services\Plantilla;

use App\Enums\EmploymentStatus;
use App\Models\ClassModeratorAssignment;
use App\Models\Section;
use App\Models\Department;
use App\Models\HonorsClassAssignment;
use App\Models\OtherAssignmentRole;
use App\Models\PlantillaUpload;
use App\Models\ServiceLoad;
use App\Models\SystemConstant;
use App\Models\Teacher;
use App\Models\TeacherOtherAssignment;
use App\Models\TeacherSectionAssignment;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;

class PlantillaReviewService
{
    public function __construct(
        private AuditLogService $audit,
        private SectionResolver $resolver,
        private TeacherResolver $teachers,
    ) {}

    /**
     * Copy reviewed staging rows into the authoritative tables, in one
     * transaction. Rows that can't be imported cleanly are skipped with a
     * human-readable reason rather than aborting the whole import.
     *
     * @return array{imported:int, skipped:array<int,string>}
     */
    public function confirmImport(PlantillaUpload $upload): array
    {
        $department = $upload->submission->department;
        $schoolYear = SystemConstant::get('current_school_year');

        return DB::transaction(function () use ($upload, $department, $schoolYear) {
            $imported = 0;
            $skipped = [];

            foreach ($upload->rows as $row) {
                $data = $row->row_json;
                $name = trim((string) ($data['teacher_name'] ?? ''));

                if ($name === '') {
                    $skipped[] = 'A row with no teacher name was skipped.';
                    continue;
                }

                // A missing or unreadable status is reported, but never costs the
                // teacher their sections — Social Studies states none at all.
                $status = EmploymentStatus::fromLabel((string) ($data['employment_status'] ?? ''));
                if (! $status) {
                    $written = trim((string) ($data['employment_status'] ?? ''));
                    $skipped[] = $written === ''
                        ? "{$name}: no employment status on the sheet — set it before submitting."
                        : "{$name}: unrecognized employment status \"{$written}\" — set it before submitting.";
                }

                // Resolve against the existing directory rather than matching the
                // raw string: a corrected spelling used to create a second teacher
                // and move the load onto it.
                $resolved = $this->teachers->resolve($name, $department);
                $teacher = $resolved->teacher;

                if ($resolved->reason) {
                    $skipped[] = "{$name}: {$resolved->reason}";
                }

                if ($status) {
                    $teacher->update(['employment_status' => $status]);
                }

                $skipped = array_merge($skipped, $this->importSections($teacher, $department, $schoolYear, $data['sections'] ?? null, $name));
                $skipped = array_merge($skipped, $this->importModerator($teacher, $schoolYear, $data['cm'] ?? null, $name));
                $skipped = array_merge($skipped, $this->importHonors($teacher, $department, $schoolYear, $data['hc'] ?? null, $name));
                $this->importServiceLoad($teacher, $schoolYear, $data['service_load'] ?? null);
                $skipped = array_merge($skipped, $this->importOtherAssignment($teacher, $schoolYear, $data['other_assignment'] ?? null, $name));

                $row->update(['row_status' => \App\Enums\ExtractionRowStatus::Confirmed]);
                $imported++;
            }

            $upload->update(['extraction_status' => 'reviewed']);
            $this->audit->log('plantilla.imported', $upload, after: ['imported' => $imported, 'skipped' => count($skipped)]);

            return ['imported' => $imported, 'skipped' => $skipped];
        });
    }

    /**
     * Match the sheet's free text to a role. The sheets abbreviate
     * ("Faculty Dev." for Faculty Development) and append qualifiers
     * ("Youth for Christ (Honorarium only)"), so try the literal text first,
     * then again with trailing parentheticals removed.
     */
    private function matchRole(string $value): ?OtherAssignmentRole
    {
        // Abbreviations the sheets use in place of the full role name.
        $aliases = [
            'chairperson' => 'Department Chair',
            'chair' => 'Department Chair',
            "dep't chair" => 'Department Chair',
            'gll' => 'Grade Level Leader',
            'fdp' => 'Faculty Development',
        ];

        $candidates = [$value, trim(preg_replace('/\s*\([^)]*\)\s*/', ' ', $value))];
        // Roles are sometimes written with a grade prefix ("G9 GLL").
        $candidates[] = trim(preg_replace('/^G\s*(?:7|8|9|10)\s+/i', '', $value));

        foreach ($candidates as $candidate) {
            $key = strtolower(trim(preg_replace('/\s+/', ' ', (string) $candidate), " .,"));
            if (isset($aliases[$key])) {
                $role = OtherAssignmentRole::where('name', $aliases[$key])->first();
                if ($role) {
                    return $role;
                }
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = trim(preg_replace('/\s+/', ' ', (string) $candidate), " .,");
            if ($candidate === '') {
                continue;
            }

            $role = OtherAssignmentRole::whereRaw('LOWER(name) = ?', [strtolower($candidate)])->first()
                // The sheet's text contains the role name ("Punlaan Moderator").
                ?? OtherAssignmentRole::whereRaw('? LIKE CONCAT(LOWER(name), \'%\')', [strtolower($candidate)])->first()
                // The sheet abbreviates the role name ("Faculty Dev.").
                ?? OtherAssignmentRole::whereRaw('LOWER(name) LIKE CONCAT(?, \'%\')', [strtolower($candidate)])->first()
                // The sheet prefixes the role name ("Compania Musica de Aguilas
                // Club Moderator"), so look for it anywhere in the text.
                ?? OtherAssignmentRole::whereRaw('? LIKE CONCAT(\'%\', LOWER(name), \'%\')', [strtolower($candidate)])->first();

            if ($role) {
                return $role;
            }
        }

        return null;
    }

    /** @return array<int, string> skipped reasons */
    private function importSections(Teacher $teacher, Department $department, string $schoolYear, ?string $sections, string $name): array
    {
        $skipped = [];

        foreach ($this->resolver->resolveMany($sections) as $resolution) {
            if (! $resolution->isResolved()) {
                $skipped[] = "{$name}: {$resolution->reason}";
                continue;
            }
            TeacherSectionAssignment::updateOrCreate(
                ['section_id' => $resolution->section->id, 'department_id' => $department->id, 'school_year' => $schoolYear],
                ['teacher_id' => $teacher->id, 'hours' => $department->hours_per_section],
            );
        }

        return $skipped;
    }

    /**
     * The registrar's roster is the authority for moderators — CLE, Math, TLE and
     * Social Studies never record one, and Math records only a count. The sheet's
     * own Class Moderator cell is used as a cross-check, not as the source.
     *
     * @return array<int, string> skipped reasons
     */
    private function importModerator(Teacher $teacher, string $schoolYear, ?string $cm, string $name): array
    {
        $rostered = Section::whereNotNull('moderator_name')->get()
            ->first(fn (Section $s) => $this->sameTeacher($s->moderator_name, $name));

        if ($rostered) {
            ClassModeratorAssignment::updateOrCreate(
                ['section_id' => $rostered->id, 'school_year' => $schoolYear],
                ['teacher_id' => $teacher->id, 'hours' => SystemConstant::get('class_moderator_hours', 4)],
            );
        }

        $resolutions = $this->resolver->resolveMany($cm);
        if ($resolutions === []) {
            return [];
        }

        $resolution = $resolutions[0];
        if (! $resolution->isResolved()) {
            return ["{$name}: Class Moderator — {$resolution->reason}"];
        }

        if ($rostered && $resolution->section->id !== $rostered->id) {
            return ["{$name}: the sheet names {$resolution->section->grade_level->value} {$resolution->section->name} "
                . "as their moderated section, but the registrar roster says {$rostered->grade_level->value} {$rostered->name}. "
                . 'The roster was used — confirm which is correct.'];
        }

        if (! $rostered) {
            ClassModeratorAssignment::updateOrCreate(
                ['section_id' => $resolution->section->id, 'school_year' => $schoolYear],
                ['teacher_id' => $teacher->id, 'hours' => SystemConstant::get('class_moderator_hours', 4)],
            );
        }

        return [];
    }

    /**
     * Do two written forms name the same person? The sheets and the registrar
     * differ in middle initials and spelling ("Fritzie Dealagdon" vs
     * "Frizie B. Dealagdon"), so compare first and last name with a one-edit
     * tolerance rather than demanding an exact string match.
     */
    private function sameTeacher(?string $a, ?string $b): bool
    {
        $parts = function (?string $n): array {
            $n = mb_strtolower(trim((string) $n));
            $n = preg_replace('/,?\s*\b(sj|jr|iii|ii)\b\.?/u', '', $n);
            // Honorifics: "SCH. JAMES ... SENERICHES", "Bb. Cristie ...".
            $n = preg_replace('/^\s*(?:sch|br|sr|fr|rev|ms|mr|mrs|bb|gng)\.?\s+/u', '', $n);
            $n = preg_replace('/[^\p{L}\s]/u', ' ', $n);
            $t = array_values(array_filter(preg_split('/\s+/', trim($n)) ?: []));
            if ($t === []) {
                return [];
            }

            $last = array_pop($t);
            // Join the given names and drop middle initials, so "Marycris" and
            // "Mary Cris" — and "Frizie B." and "Fritzie" — compare equal.
            $given = implode('', array_filter($t, fn ($p) => mb_strlen($p) > 1));

            return [$given, $last];
        };

        [$aFirst, $aLast] = $parts($a) + [null, null];
        [$bFirst, $bLast] = $parts($b) + [null, null];

        if (! $aLast || ! $bLast || ! $aFirst || ! $bFirst) {
            return false;
        }

        $close = fn (string $x, string $y) => $x === $y
            || (min(mb_strlen($x), mb_strlen($y)) >= 4 && levenshtein($x, $y) <= 1);

        return $close($aLast, $bLast) && $close($aFirst, $bFirst);
    }

    private function importHonors(Teacher $teacher, Department $department, string $schoolYear, ?string $hc, string $name): array
    {
        $resolutions = $this->resolver->resolveMany($hc);
        if ($resolutions === []) {
            return [];
        }
        if (! $department->has_honors_class) {
            return ["{$name}: {$department->name} has no Honor's Class column — that entry was skipped."];
        }

        $resolution = $resolutions[0];
        if (! $resolution->isResolved()) {
            return ["{$name}: Honor's Class — {$resolution->reason}"];
        }

        $section = $resolution->section;

        // One Honor's Class teacher per section per year. The Science sheet lists
        // "G8 Magis / Ignatius of Loyola" against two teachers; the second is a
        // source-data conflict for the Chair, not something to import silently.
        $taken = HonorsClassAssignment::where('section_id', $section->id)
            ->where('school_year', $schoolYear)
            ->where('teacher_id', '!=', $teacher->id)
            ->exists();

        if ($taken) {
            return ["{$name}: {$section->grade_level->value} {$section->name} already has an Honor's Class teacher — confirm which row is correct."];
        }

        HonorsClassAssignment::updateOrCreate(
            ['teacher_id' => $teacher->id, 'section_id' => $section->id, 'school_year' => $schoolYear],
            ['hours' => SystemConstant::get('honors_class_hours', 8)],
        );

        return [];
    }

    private function importServiceLoad(Teacher $teacher, string $schoolYear, ?string $serviceLoad): void
    {
        $value = trim((string) $serviceLoad);
        // Blank or a dash means the Service Load was waived — don't create a row.
        if ($value === '' || $value === '-' || ! is_numeric($value)) {
            return;
        }
        ServiceLoad::updateOrCreate(
            ['teacher_id' => $teacher->id, 'school_year' => $schoolYear],
            ['hours' => (float) $value],
        );
    }

    private function importOtherAssignment(Teacher $teacher, string $schoolYear, ?string $other, string $name): array
    {
        $value = trim((string) $other);
        if ($value === '') {
            return [];
        }

        $role = $this->matchRole($value);

        if (! $role) {
            return ["{$name}: other assignment \"{$value}\" didn't match a known role — add it in the role lookup, then re-import."];
        }

        TeacherOtherAssignment::firstOrCreate([
            'teacher_id' => $teacher->id,
            'other_assignment_role_id' => $role->id,
            'school_year' => $schoolYear,
        ]);

        return [];
    }

}
