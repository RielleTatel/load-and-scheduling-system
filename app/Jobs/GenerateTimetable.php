<?php

namespace App\Jobs;

use App\Enums\TimetableKind;
use App\Models\TimetableVersion;
use App\Services\Audit\AuditLogService;
use App\Services\Scheduling\ScheduleGenerationStoppedException;
use App\Services\Scheduling\ScheduleInputsChangedException;
use App\Services\Scheduling\ScheduleReadinessService;
use App\Services\Scheduling\ScheduleSearchExhaustedException;
use App\Services\Scheduling\SpecialScheduleGenerator;
use App\Services\Scheduling\TimetableGenerationInput;
use App\Services\Scheduling\TimetableGenerationProgress;
use App\Services\Scheduling\TimetableGenerator;
use App\Services\Scheduling\TimetableValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateTimetable implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    public function __construct(public readonly int $timetableVersionId, public readonly int $round = 0) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('timetable:'.$this->timetableVersionId))->releaseAfter(5)->expireAfter(150)];
    }

    public function handle(
        TimetableGenerator $engine,
        TimetableValidationService $validation,
        ScheduleReadinessService $readiness,
        AuditLogService $audit,
    ): void {
        $timetable = TimetableVersion::query()->find($this->timetableVersionId);
        if (! $timetable || $timetable->status->value !== 'generating'
            || ($timetable->generation_checkpoint['round'] ?? 0) !== $this->round) {
            return;
        }

        try {
            if ($timetable->kind === TimetableKind::Special) {
                $engine = app(SpecialScheduleGenerator::class);
            }
            $progress = app(TimetableGenerationProgress::class);
            $progress->record($timetable->id, 'Preparing your schedule');
            $ready = $readiness->check($timetable->school_year);
            if (! $ready['ready']) {
                throw new \RuntimeException('Scheduling data is no longer ready: '.implode(' ', array_column($ready['blockers'], 'message')));
            }

            $inputLoader = app(TimetableGenerationInput::class);
            $input = $inputLoader->load($timetable);
            $starting = $inputLoader->startingEntries($timetable, $input['sections']);
            $saved = $timetable->generation_checkpoint ?? [];
            $checkpoint = [
                'round' => $this->round,
                'fingerprint' => $input['fingerprint'],
                'entries' => ($saved['fingerprint'] ?? null) === $input['fingerprint']
                    ? ($saved['entries'] ?? $starting['entries']) : $starting['entries'],
                'penalty' => ($saved['fingerprint'] ?? null) === $input['fingerprint'] ? ($saved['penalty'] ?? null) : null,
                'source_version_id' => $starting['source_version_id'],
                'reference' => $starting['reference'],
            ];
            if (! $this->saveCheckpoint($checkpoint)) {
                return;
            }
            $progress->record($timetable->id, 'Arranging classes', $progress->checkPercent($input['sections'], $checkpoint['entries'], $input['unavailable'], $timetable->kind));
            $lastCheckpointAt = 0;

            // Each short work session yields to the queue, without a total search
            // deadline. Saved progress survives worker restarts and laptop shutdowns.
            $seed = (int) (($timetable->generation_seed + $this->round * 104729) % 2147483647);
            $result = $engine->generate($input['sections'], $input['unavailable'], $seed, 45,
                function () use ($progress, $timetable): bool {
                    $current = $timetable->fresh();
                    if (! $current || $current->status->value !== 'generating'
                        || ($current->generation_checkpoint['round'] ?? 0) !== $this->round) {
                        return false;
                    }
                    $progress->record($timetable->id, 'Arranging classes');

                    return true;
                },
                $checkpoint['entries'],
                function (array $entries, int $penalty) use (&$checkpoint, &$lastCheckpointAt, $progress, $timetable, $input): void {
                    if ($checkpoint['penalty'] === null || $penalty < $checkpoint['penalty']) {
                        $checkpoint['entries'] = $entries;
                        $checkpoint['penalty'] = $penalty;
                        if (microtime(true) - $lastCheckpointAt >= 3 || $penalty === 0) {
                            $lastCheckpointAt = microtime(true);
                            if (! $this->saveCheckpoint($checkpoint)) {
                                throw new ScheduleGenerationStoppedException;
                            }
                            $progress->record($timetable->id, 'Arranging classes', $progress->checkPercent($input['sections'], $entries, $input['unavailable'], $timetable->kind));
                        }
                    }
                },
                $starting['fallback_entries'],
            );
            if ($result['issues'] !== []) {
                throw new \RuntimeException('The timetable search returned hard validation errors. No draft was created.');
            }

            $progress->record($timetable->id, 'Checking and saving your schedule');
            DB::transaction(function () use ($timetable, $result, $validation, $audit, $inputLoader, $input, $checkpoint): void {
                $locked = TimetableVersion::query()->lockForUpdate()->findOrFail($timetable->id);
                if ($locked->status->value !== 'generating'
                    || ($locked->generation_checkpoint['round'] ?? 0) !== $this->round) {
                    return;
                }
                if ($inputLoader->load($locked)['fingerprint'] !== $input['fingerprint']) {
                    throw new ScheduleInputsChangedException;
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
                    ->where('kind', $locked->kind->value)
                    ->where('status', 'draft')
                    ->where('id', '<>', $locked->id)
                    ->update(['status' => 'superseded', 'updated_at' => $now]);

                $locked->status = 'draft';
                $locked->completed_at = $now;
                $locked->failure_message = null;
                $locked->generation_checkpoint = null;
                $locked->save();
                $audit->log('timetable.generated', $locked, null, [
                    'school_year' => $locked->school_year,
                    'quarter' => $locked->quarter,
                    'version' => $locked->version,
                    'kind' => $locked->kind->value,
                    'issues' => count($issues),
                    'attempts' => $result['attempts'],
                    'seed' => $locked->generation_seed,
                    'search_sessions' => $this->round + 1,
                    'reference' => $checkpoint['reference'],
                    'source_version_id' => $checkpoint['source_version_id'],
                    'used_existing_schedule_fallback' => $result['used_fallback'] ?? false,
                ]);
            });
        } catch (ScheduleGenerationStoppedException) {
            return;
        } catch (ScheduleSearchExhaustedException|ScheduleInputsChangedException $exception) {
            // Search exhaustion is not a failed schedule. Continue the same version.
            DB::transaction(function () use ($exception): void {
                $locked = TimetableVersion::query()->lockForUpdate()->find($this->timetableVersionId);
                if (! $locked || $locked->status->value !== 'generating'
                    || ($locked->generation_checkpoint['round'] ?? 0) !== $this->round) {
                    return;
                }
                $checkpoint = $locked->generation_checkpoint ?? [];
                if ($exception instanceof ScheduleInputsChangedException) {
                    $checkpoint = [];
                } elseif ($exception->bestEntries !== [] && (! isset($checkpoint['penalty'])
                    || ($exception->bestPenalty !== null && $exception->bestPenalty < $checkpoint['penalty']))) {
                    $checkpoint['entries'] = $exception->bestEntries;
                    $checkpoint['penalty'] = $exception->bestPenalty;
                }
                $checkpoint['round'] = $this->round + 1;
                $locked->generation_checkpoint = $checkpoint;
                $locked->save();
                self::dispatch($locked->id, $this->round + 1)->delay(now()->addSecond())->afterCommit();
                app(TimetableGenerationProgress::class)->record($locked->id, 'Continuing to arrange classes');
            });
        } catch (Throwable $exception) {
            $this->failGeneration($exception, $audit);
        }
    }

    private function saveCheckpoint(array $checkpoint): bool
    {
        return DB::transaction(function () use ($checkpoint): bool {
            $locked = TimetableVersion::query()->lockForUpdate()->find($this->timetableVersionId);
            if (! $locked || $locked->status->value !== 'generating'
                || ($locked->generation_checkpoint['round'] ?? 0) !== $this->round) {
                return false;
            }
            $locked->generation_checkpoint = $checkpoint;
            $locked->save();

            return true;
        });
    }

    public function failed(?Throwable $exception): void
    {
        $this->failGeneration($exception ?? new \RuntimeException('The background process was interrupted.'), app(AuditLogService::class));
    }

    private function failGeneration(Throwable $exception, AuditLogService $audit): void
    {
        DB::transaction(function () use ($exception, $audit): void {
            $locked = TimetableVersion::query()->lockForUpdate()->find($this->timetableVersionId);
            if (! $locked || $locked->status->value !== 'generating'
                || ($locked->generation_checkpoint['round'] ?? 0) !== $this->round) {
                return;
            }

            $locked->status = 'failed';
            $locked->completed_at = now();
            $locked->failure_message = mb_substr($exception->getMessage(), 0, 2000);
            $locked->generation_checkpoint = null;
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
