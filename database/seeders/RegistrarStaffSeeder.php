<?php

namespace Database\Seeders;

use App\Models\Section;
use App\Models\SystemConstant;
use App\Models\Teacher;
use Illuminate\Database\Seeder;

class RegistrarStaffSeeder extends Seeder
{
    /**
     * Every person named on the registrar's "List of Class Mods and
     * Teacher-Partners 2026" — each section's moderator and teacher-partner.
     *
     * They are created without a department: the registrar's list says who they
     * are, but only a department's plantilla says which department they belong
     * to. TeacherResolver adopts these records when that sheet is imported, which
     * is what stops a second row being created for the same person.
     *
     * Twelve of them have no plantilla row at all — they belong to the English
     * department, whose sheet has not been provided yet.
     *
     * Runs after SectionSeeder, which is where the names live.
     */
    public function run(): void
    {
        $names = Section::where('school_year', SystemConstant::get('current_school_year', '2026-2027'))
            ->get(['moderator_name', 'teacher_partner_name'])
            ->flatMap(fn (Section $s) => [$s->moderator_name, $s->teacher_partner_name])
            ->filter()
            ->unique(fn (string $name) => Teacher::normalize($name));

        foreach ($names as $name) {
            $key = Teacher::normalize($name);

            if (Teacher::where('normalized_name', $key)->exists()) {
                continue;
            }

            Teacher::create(['full_name' => $name, 'department_id' => null, 'source' => 'registrar']);
        }
    }
}
