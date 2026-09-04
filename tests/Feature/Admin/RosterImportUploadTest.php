<?php

namespace Tests\Feature\Admin;

use App\Models\RosterImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RosterImportUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_uploads_the_roster_and_rows_are_staged(): void
    {
        Storage::fake('local');
        $this->seed();
        $admin = User::where('email', 'admin@jhs.test')->first();

        $pdf = new UploadedFile(base_path('tests/Fixtures/class-moderators.pdf'), 'roster.pdf', 'application/pdf', null, true);

        $this->actingAs($admin)
            ->post(route('admin.roster.store'), ['pdf' => $pdf])
            ->assertRedirect(route('admin.roster.review'));

        $import = RosterImport::latest('id')->first();
        $this->assertNotNull($import);
        $this->assertSame('extracted', $import->extraction_status);
        $this->assertSame(36, $import->rows()->count());
    }

    public function test_a_chair_may_not_upload_a_roster(): void
    {
        $this->seed();
        $chair = User::where('email', 'chair.fil@jhs.test')->first();

        $this->actingAs($chair)->get(route('admin.roster.create'))->assertForbidden();
    }
}
