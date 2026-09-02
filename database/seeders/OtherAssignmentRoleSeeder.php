<?php

namespace Database\Seeders;

use App\Models\OtherAssignmentRole;
use Illuminate\Database\Seeder;

class OtherAssignmentRoleSeeder extends Seeder
{
    public function run(): void
    {
        // Equivalent-hours-bearing roles (docs/Documentation/JHS Scheduling Constraints §2).
        $equivalentHours = [
            'Department Chair' => 15,
            'Grade Level Leader' => 6,
            'AMEP' => 6,
            'Faculty Development' => 15,
            'Quality Assurance Officer' => 6,
            'HSR Coordinator' => 21,
            'Facilities Coordinator' => 15,
            'OPD' => 15,
            'Admission and Aid Coordinator' => 15,
            'TLE Coordinator' => 21,
            'SAO Coordinator' => 21,
            'Social Studies Subject Area Coordinator' => 15,
        ];

        foreach ($equivalentHours as $name => $hours) {
            OtherAssignmentRole::updateOrCreate(
                ['name' => $name],
                ['equivalent_hours' => $hours, 'is_honorarium' => false],
            );
        }

        // Honorarium-only roles — blank equivalent hours, excluded from load math (SRS FR-7).
        $honorarium = [
            'Sports Club Moderator', 'Culinaria Club Moderator', "Eagle's Eye Club Moderator",
            'Animo Aguila Moderator', 'Danzar Atenista Moderator', 'Artique Circle Moderator',
            'Musica de Aguilas Club Moderator', 'Youth for Christ Moderator', 'RCY Moderator',
            'JES Moderator', 'LLA Moderator', 'ITS Moderator', 'Punlaan Moderator',
            'Punlaan Asst. Moderator', 'LLA Asst. Moderator', 'Youth for Christ Asst. Moderator',
        ];

        foreach ($honorarium as $name) {
            OtherAssignmentRole::updateOrCreate(
                ['name' => $name],
                ['equivalent_hours' => null, 'is_honorarium' => true],
            );
        }
    }
}
