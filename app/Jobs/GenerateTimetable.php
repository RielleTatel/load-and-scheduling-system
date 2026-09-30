<?php

namespace App\Jobs;

use App\Models\Section;
use App\Models\TeacherSectionAssignment;
use App\Models\TeacherUnavailability;
use App\Models\TimetableVersion;
use App\Services\Audit\AuditLogService;
use App\Services\Scheduling\ScheduleGenerationEngine;
use App\Services\Scheduling\ScheduleReadinessService;
use App\Services\Scheduling\TimetableValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateTimetable implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public readonly int $timetableVersionId) {}

    public function handle(
        ScheduleGenerationEngine $engine,
        TimetableValidationService $validation,
        ScheduleReadinessService $readiness,
        AuditLogService $audit,
    ): void {
        $timetable = TimetableVersion::query()->find($this->timetableVersionId);
        if (! $timetable || $timetable->status->value !== 'generating') {
            return;
        }

        try {
            $ready = $readiness->check($timetable->school_year);
            if (! $ready['ready']) {
                throw new \RuntimeException('Scheduling data is no longer ready: '.implode(' ', array_column($ready['blockers'], 'message')));
            }

            $sections = Section::query()
                ->where('school_year', $timetable->school_year)
                ->orderBy('grade_level')->orderBy('name')->get();
            $assignmentRows = TeacherSectionAssignment::query()
                ->where('school_year', $timetable->school_year)
                ->with(['teacher:id,department_id', 'department:id,code'])
                ->get();
            $assignments = [];
            foreach ($assignmentRows as $assignment) {
                if (! $assignment->teacher || ! $assignment->department) {
                    continue;
                }
                $assignments[$assignment->section_id][$assignment->department->code] = [
                    'department_id' => $assignment->department_id,
                    'teacher_id' => $assignment->teacher_id,
                ];
            }

            $generatorSections = $sections->map(fn (Section $section) => [
                'id' => $section->id,
                'grade_level' => $section->grade_level->value,
                'name' => $section->name,
                'assignments' => $assignments[$section->id] ?? [],
            ])->all();

            $unavailable = TeacherUnavailability::query()
                ->where('school_year', $timetable->school_year)
                ->where('quarter', $timetable->quarter)
                ->get(['teacher_id', 'day', 'period'])
                ->map(fn ($slot) => $slot->toArray())->all();

            $result = $engine->generate($generatorSections, $unavailable, $timetable->generation_seed, 110);
            if ($result['issues'] !== []) {
                throw new \RuntimeException('The timetable search returned hard validation errors. No draft was created.');
            }

            DB::transaction(function () use ($timetable, $result, $validation, $audit): void {
                $locked = TimetableVersion::query()->lockForUpdate()->findOrFail($timetable->id);
                if ($locked->status->value !== 'generating') {
                    return;
                }

                $now = now();
                $rows = array_map(fn (array $entry) => [
                    'timetable_version_id' => $locked->id,
                    'section_id' => $entry['section_id'],
                    'department_id' => $entry['department_id'],
                    'teacher_id' => $entry['teacher_id'],
                    'day' => $entry['day'],
                    'period' => $entry['period'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $result['entries']);

                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('timetable_entries')->insert($chunk);
                }

                $locked->issues()->delete();
                $issues = $validation->validate($locked);
                $hardIssues = array_filter(
                    $issues,
                    fn (array $issue): bool => ($issue['severity'] ?? 'hard') === 'hard',
                );
                if ($hardIssues !== []) {
                    throw new \RuntimeException('Authoritative validation rejected the generated timetable. No draft was created.');
                }
                $validation->persist($locked, $issues);
                TimetableVersion::query()
                    ->where('school_year', $locked->school_year)
                    ->where('quarter', $locked->quarter)
                    ->where('status', 'draft')
                    ->where('id', '<>', $locked->id)
                    ->update(['status' => 'superseded', 'updated_at' => $now]);

                $locked->status = 'draft';
                $locked->completed_at = $now;
                $locked->failure_message = null;
                $locked->save();
                $audit->log('timetable.generated', $locked, null, [
                    'school_year' => $locked->school_year,
                    'quarter' => $locked->quarter,
                    'version' => $locked->version,
                    'issues' => count($issues),
                    'attempts' => $result['attempts'],
                    'seed' => $locked->generation_seed,
                ]);
            });
        } catch (Throwable $exception) {
            DB::transaction(function () use ($timetable, $exception, $audit): void {
                $locked = TimetableVersion::query()->lockForUpdate()->find($timetable->id);
                if (! $locked || $locked->status->value !== 'generating') {
                    return;
                }

                $locked->status = 'failed';
                $locked->completed_at = now();
                $locked->failure_message = mb_substr($exception->getMessage(), 0, 2000);
                $locked->save();
                $audit->log('timetable.generation_failed', $locked, null, [
                    'school_year' => $locked->school_year,
                    'quarter' => $locked->quarter,
                    'version' => $locked->version,
                    'message' => $locked->failure_message,
                ]);
            });
        }
    }
}
