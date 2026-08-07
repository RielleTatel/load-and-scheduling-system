<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\OtherAssignmentRole;
use App\Models\Section;
use App\Models\SystemConstant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_structure(): void
    {
        $this->seed();

        $this->assertSame(7, Department::count());
        $this->assertSame(8, User::count()); // 1 admin + 7 chairs
        $this->assertSame(85, Section::count()); // 19 G7 + 36 G8 + 20 G9 + 10 G10

        $this->assertTrue(Department::where('code', 'SCI')->first()->has_honors_class);
        $this->assertSame(5, Department::where('code', 'MATH')->first()->hours_per_section);
        $this->assertSame('2026-2027', SystemConstant::get('current_school_year'));

        $this->assertEquals(15, OtherAssignmentRole::where('name', 'Department Chair')->first()->equivalent_hours);
        $this->assertTrue((bool) OtherAssignmentRole::where('name', 'Sports Club Moderator')->first()->is_honorarium);
    }

    public function test_demo_accounts_exist(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@jhs.test')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->isAdmin());

        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->assertNotNull($chair);
        $this->assertTrue($chair->isChair());
        $this->assertSame('FIL', $chair->department->code);
    }
}
