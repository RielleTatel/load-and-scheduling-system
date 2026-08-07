<?php

namespace Tests\Unit;

use App\Models\Department;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_writes_actor_and_diffs(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $dept = Department::factory()->create();

        $log = app(AuditLogService::class)->log('department.updated', $dept,
            ['name' => 'Old'], ['name' => 'New']);

        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(Department::class, $log->auditable_type);
        $this->assertSame($dept->id, $log->auditable_id);
        $this->assertSame(['name' => 'New'], $log->after_json);
        $this->assertNotNull($log->created_at);
    }

    public function test_log_tolerates_unauthenticated_actor(): void
    {
        $dept = Department::factory()->create();
        $log = app(AuditLogService::class)->log('department.created', $dept);

        $this->assertNull($log->user_id);
    }
}
