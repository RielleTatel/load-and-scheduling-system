<?php

namespace Tests\Feature;

use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionYearScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_section_name_may_exist_in_two_school_years(): void
    {
        Section::create(['school_year' => '2026-2027', 'grade_level' => 'G7', 'name' => 'Arrowsmith']);
        Section::create(['school_year' => '2027-2028', 'grade_level' => 'G7', 'name' => 'Arrowsmith']);

        $this->assertSame(2, Section::where('name', 'Arrowsmith')->count());
    }

    public function test_same_section_name_collides_within_one_year(): void
    {
        Section::create(['school_year' => '2026-2027', 'grade_level' => 'G7', 'name' => 'Arrowsmith']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Section::create(['school_year' => '2026-2027', 'grade_level' => 'G7', 'name' => 'Arrowsmith']);
    }
}
