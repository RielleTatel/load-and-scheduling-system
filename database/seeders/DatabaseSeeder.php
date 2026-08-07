<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DepartmentSeeder::class,
            SectionSeeder::class,
            OtherAssignmentRoleSeeder::class,
            SystemConstantSeeder::class,
            UserSeeder::class,
        ]);
    }
}
