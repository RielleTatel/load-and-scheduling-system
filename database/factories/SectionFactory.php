<?php

namespace Database\Factories;

use App\Enums\GradeLevel;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Section>
 */
class SectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'grade_level' => GradeLevel::G7,
            'name' => ucfirst(fake()->unique()->word()),
        ];
    }
}
