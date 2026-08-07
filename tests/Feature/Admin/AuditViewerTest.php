<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_lists_and_filters_actions(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@jhs.test')->first();
        $this->actingAs($admin);

        app(AuditLogService::class)->log('user.created', $admin);
        app(AuditLogService::class)->log('constant.updated', $admin);

        $this->get(route('admin.audit.index'))
            ->assertOk()
            ->assertViewHas('logs', fn ($logs) => $logs->pluck('action')->contains('user.created'));

        // Filtering narrows the result set (the action dropdown still lists every
        // action as an option, so assert on the data, not the whole page).
        $this->get(route('admin.audit.index', ['action' => 'constant.updated']))
            ->assertOk()
            ->assertViewHas('logs', fn ($logs) => $logs->count() === 1
                && $logs->first()->action === 'constant.updated');
    }

    public function test_chair_cannot_view_audit_log(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();
        $this->actingAs($chair)->get(route('admin.audit.index'))->assertForbidden();
    }
}
