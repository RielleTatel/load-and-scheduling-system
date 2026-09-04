<?php

namespace Tests\Unit;

use App\Models\Section;
use App\Services\Plantilla\SectionResolver;
use Database\Seeders\SystemConstantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionResolverYearScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_to_the_active_years_section(): void
    {
        $this->seed(SystemConstantSeeder::class); // current_school_year = 2026-2027

        $active = Section::create(['school_year' => '2026-2027', 'grade_level' => 'G7', 'name' => 'Arrowsmith']);
        // A later year exists and would win a name-keyed index built over all rows.
        Section::create(['school_year' => '2027-2028', 'grade_level' => 'G7', 'name' => 'Arrowsmith']);

        $resolution = app(SectionResolver::class)->resolve('Arrowsmith');

        $this->assertTrue($resolution->isResolved());
        $this->assertSame($active->id, $resolution->section->id);
    }
}
