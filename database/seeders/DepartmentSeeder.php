<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        // The 7 departments with a plantilla on file — hours/section and
        // Honor's Class column per the plantilla analysis (docs/Documentation)
        // — plus English, seeded ahead of its plantilla per JHS Scheduling
        // Constraints §7.8: hours_per_section=5 is inferred from English's
        // 5x/cycle load matching Math/Science, and has_honors_class is an
        // unconfirmed default. Both should be corrected once English's
        // plantilla arrives.
        $departments = [
            ['code' => 'FIL',   'name' => 'Filipino',                             'hours_per_section' => 4, 'has_honors_class' => false],
            ['code' => 'CLE',   'name' => 'Christian Life Education',             'hours_per_section' => 4, 'has_honors_class' => false],
            ['code' => 'TLE',   'name' => 'Technology and Livelihood Education',  'hours_per_section' => 4, 'has_honors_class' => true],
            ['code' => 'SCI',   'name' => 'Science and Technology',               'hours_per_section' => 5, 'has_honors_class' => true],
            ['code' => 'MATH',  'name' => 'Mathematics',                          'hours_per_section' => 5, 'has_honors_class' => true],
            ['code' => 'MAPEH', 'name' => 'MAPEH',                                'hours_per_section' => 4, 'has_honors_class' => true],
            ['code' => 'SOC',   'name' => 'Social Studies',                       'hours_per_section' => 4, 'has_honors_class' => true],
            ['code' => 'ENG',   'name' => 'English',                              'hours_per_section' => 5, 'has_honors_class' => false],
        ];

        foreach ($departments as $department) {
            Department::updateOrCreate(['code' => $department['code']], $department);
        }
    }
}
