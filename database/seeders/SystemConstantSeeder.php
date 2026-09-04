<?php

namespace Database\Seeders;

use App\Models\SystemConstant;
use Illuminate\Database\Seeder;

class SystemConstantSeeder extends Seeder
{
    public function run(): void
    {
        $constants = [
            ['key' => 'full_load_hours',      'value' => '21', 'description' => 'Weekly full teaching load (hours).'],
            ['key' => 'overload_divisor',     'value' => '3',  'description' => 'UNCONFIRMED — inferred from observed data; confirm with the registrar before relying on overload figures.'],
            ['key' => 'service_load_default', 'value' => '3',  'description' => 'Default Service Load hours per teacher.'],
            ['key' => 'class_moderator_hours','value' => '4',  'description' => 'Hours credited for a Class Moderator assignment. Confirmed against all 7 plantilla sheets\' own arithmetic — 5 of 7 header labels still print "(3 hours)" and are stale.'],
            ['key' => 'honors_class_hours',   'value' => '8',  'description' => "Hours credited for an Honor's Class assignment."],
            ['key' => 'current_school_year',  'value' => '2026-2027', 'description' => 'The active school year the app operates on.'],
        ];

        foreach ($constants as $constant) {
            SystemConstant::updateOrCreate(['key' => $constant['key']], $constant);
        }
    }
}
