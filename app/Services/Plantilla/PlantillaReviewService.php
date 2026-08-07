<?php

namespace App\Services\Plantilla;

use App\Enums\EmploymentStatus;
use App\Models\ClassModeratorAssignment;
use App\Models\Department;
use App\Models\HonorsClassAssignment;
use App\Models\OtherAssignmentRole;
use App\Models\PlantillaUpload;
use App\Models\Section;
use App\Models\ServiceLoad;
use App\Models\SystemConstant;
use App\Models\Teacher;
use App\Models\TeacherOtherAssignment;
use App\Models\TeacherSectionAssignment;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;

class PlantillaReviewService
{
    public function __construct(private AuditLogService $audit) {}

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

                $status = EmploymentStatus::fromLabel((string) ($data['employment_status'] ?? ''));
                if (! $status) {
                    $skipped[] = "{$name}: unrecognized employment status \"" . ($data['employment_status'] ?? '') . '".';
                    continue;
                }

                $teacher = Teacher::updateOrCreate(
                    ['full_name' => $name, 'department_id' => $department->id],
                    ['employment_status' => $status],
                );

                $this->importSections($teacher, $department, $schoolYear, $data['sections'] ?? null);
                $this->importModerator($teacher, $schoolYear, $data['cm'] ?? null);
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

    private function importSections(Teacher $teacher, Department $department, string $schoolYear, ?string $sections): void
    {
        foreach ($this->parseSections($sections) as [$grade, $name]) {
            $section = Section::firstOrCreate(['grade_level' => $grade, 'name' => $name]);
            TeacherSectionAssignment::updateOrCreate(
                ['section_id' => $section->id, 'department_id' => $department->id, 'school_year' => $schoolYear],
                ['teacher_id' => $teacher->id, 'hours' => $department->hours_per_section],
            );
        }
    }

    private function importModerator(Teacher $teacher, string $schoolYear, ?string $cm): void
    {
        $parsed = $this->parseSections($cm);
        if (empty($parsed)) {
            return;
        }
        [$grade, $name] = $parsed[0];
        $section = Section::firstOrCreate(['grade_level' => $grade, 'name' => $name]);
        ClassModeratorAssignment::updateOrCreate(
            ['section_id' => $section->id, 'school_year' => $schoolYear],
            ['teacher_id' => $teacher->id, 'hours' => SystemConstant::get('class_moderator_hours', 3)],
        );
    }

    private function importHonors(Teacher $teacher, Department $department, string $schoolYear, ?string $hc, string $name): array
    {
        $parsed = $this->parseSections($hc);
        if (empty($parsed)) {
            return [];
        }
        if (! $department->has_honors_class) {
            return ["{$name}: {$department->name} has no Honor's Class column — that entry was skipped."];
        }
        [$grade, $sectionName] = $parsed[0];
        $section = Section::firstOrCreate(['grade_level' => $grade, 'name' => $sectionName]);
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

        $role = OtherAssignmentRole::whereRaw('LOWER(name) = ?', [strtolower($value)])->first()
            ?? OtherAssignmentRole::whereRaw('? LIKE CONCAT(\'%\', LOWER(name), \'%\')', [strtolower($value)])->first();

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

    /**
     * Parse a "G7: Ignatius, Xavier; G9: Kostka" string into [grade, name] pairs.
     *
     * @return array<int, array{0:string,1:string}>
     */
    private function parseSections(?string $sections): array
    {
        $sections = trim((string) $sections);
        if ($sections === '') {
            return [];
        }

        $pairs = [];
        foreach (explode(';', $sections) as $segment) {
            if (! preg_match('/G\s*(7|8|9|10)\s*:?\s*(.+)/i', trim($segment), $m)) {
                continue;
            }
            $grade = 'G' . $m[1];
            foreach (explode(',', $m[2]) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $pairs[] = [$grade, $name];
                }
            }
        }

        return $pairs;
    }
}
