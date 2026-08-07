<?php

namespace Database\Factories;

use App\Enums\EmploymentStatus;
use App\Models\Department;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Teacher>
 */
class TeacherFactory extends Factory
{
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'employment_status' => EmploymentStatus::Permanent,
            'department_id' => Department::factory(),
        ];
    }
}
