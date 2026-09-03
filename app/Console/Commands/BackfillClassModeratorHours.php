<?php

namespace App\Console\Commands;

use App\Models\ClassModeratorAssignment;
use App\Models\SystemConstant;
use App\Services\Audit\AuditLogService;
use Illuminate\Console\Command;

class BackfillClassModeratorHours extends Command
{
    /**
     * The registrar credits a class moderator 4 hours; every plantilla sheet's
     * own arithmetic confirms it (Social Studies and MAPEH print "4" directly in
     * the cell, contradicting their own "3 hours" column header). The system
     * seeded class_moderator_hours as 3, so every assignment imported before that
     * constant is corrected was stored 1 hour light. This backfill re-stamps
     * existing rows once, after the constant is fixed in Admin > Constants — it
     * does not touch the constant itself.
     */
    protected $signature = 'plantilla:backfill-cm-hours
        {--dry-run : Report what would change without writing}';

    protected $description = 'Correct class_moderator_assignments.hours to match the current class_moderator_hours constant';

    public function handle(AuditLogService $audit): int
    {
        $correctHours = (float) SystemConstant::get('class_moderator_hours', 3);

        $stale = ClassModeratorAssignment::where('hours', '!=', $correctHours)->get();

        if ($stale->isEmpty()) {
            $this->info("Nothing to backfill — every assignment already reads {$correctHours}h.");

            return self::SUCCESS;
        }

        $this->info("{$stale->count()} assignment(s) will move to {$correctHours}h:");

        foreach ($stale as $assignment) {
            $this->line("  #{$assignment->id}  teacher {$assignment->teacher_id}  section {$assignment->section_id}  {$assignment->hours}h -> {$correctHours}h");
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — no changes written.');

            return self::SUCCESS;
        }

        foreach ($stale as $assignment) {
            $before = ['hours' => $assignment->hours];
            $assignment->update(['hours' => $correctHours]);
            $audit->log('class_moderator_assignment.hours_backfilled', $assignment, $before, ['hours' => $assignment->hours]);
        }

        $this->info("Updated {$stale->count()} assignment(s).");

        return self::SUCCESS;
    }
}
