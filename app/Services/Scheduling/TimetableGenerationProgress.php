<?php

namespace App\Services\Scheduling;

use App\Enums\TimetableKind;
use App\Models\TimetableVersion;
use Illuminate\Support\Facades\Cache;

final class TimetableGenerationProgress
{
    public function record(int $id, string $stage, ?int $percent = null): void
    {
        $previous = Cache::get('timetable-progress:'.$id);
        Cache::put('timetable-progress:'.$id, [
            'stage' => $stage,
            'heartbeat_at' => now()->timestamp,
            'check_percent' => $percent === null ? ($previous['check_percent'] ?? null) : max(0, min(99, $percent)),
        ], now()->addHour());
    }

    public function read(TimetableVersion $timetable): array
    {
        $elapsed = $timetable->started_at
            ? max(0, (int) $timetable->started_at->diffInSeconds($timetable->completed_at ?? now())) : 0;
        if ($timetable->status->value !== 'generating') {
            $complete = in_array($timetable->status->value, ['draft', 'approved'], true)
                && $timetable->entries()->exists() && ! $timetable->issues()->where('severity', 'hard')->exists();

            return ['status' => $timetable->status->value, 'stage' => $timetable->status->label(), 'elapsed_seconds' => $elapsed,
                'check_percent' => $complete ? 100 : null];
        }

        $progress = Cache::get('timetable-progress:'.$timetable->id) ?? [
            'stage' => 'Getting ready to start',
        ];
        if (isset($progress['heartbeat_at']) && now()->timestamp - $progress['heartbeat_at'] > 125) {
            $progress['stage'] = 'Waiting to continue';
        }
        // Also translate cached messages from workers started before this update.
        $progress['stage'] = match ($progress['stage']) {
            'Waiting for the background worker' => 'Getting ready to start',
            'Checking assignments and preparing schedules' => 'Preparing your schedule',
            'Searching for a valid timetable' => 'Arranging classes',
            'Validating and saving the timetable' => 'Checking and saving your schedule',
            default => $progress['stage'],
        };

        $percent = $progress['check_percent'] ?? null;
        if ($percent === null) {
            $input = app(TimetableGenerationInput::class)->load($timetable);
            $percent = $this->checkPercent($input['sections'], $timetable->generation_checkpoint['entries'] ?? [], $input['unavailable'], $timetable->kind);
        }

        return ['status' => $timetable->status->value, 'stage' => $progress['stage'], 'elapsed_seconds' => $elapsed, 'check_percent' => $percent];
    }

    public function checkPercent(array $sections, array $entries, array $unavailable, TimetableKind $kind = TimetableKind::Normal): int
    {
        $total = count($sections) * SchedulePolicy::DAYS * $kind->periodsPerDay();
        if ($total === 0 || $entries === []) {
            return 0;
        }
        $sectionsById = array_column($sections, null, 'id');
        foreach ($entries as &$entry) {
            $entry['teacher_id'] = $sectionsById[$entry['section_id']]['assignments'][$entry['department_code']]['teacher_id'] ?? null;
        }
        unset($entry);
        $issues = app(ScheduleConstraintValidator::class)->validate($sections, $entries, $unavailable, $kind);
        $badTeachers = [];
        $badSections = [];
        foreach ($issues as $issue) {
            $day = $issue['day'] ?? '*';
            $period = $issue['period'] ?? '*';
            if (isset($issue['teacher_id'])) {
                $badTeachers[$issue['teacher_id'].':'.$day.':'.$period] = true;
            } elseif (isset($issue['section_id'])) {
                $badSections[$issue['section_id'].':'.$day.':'.$period] = true;
            }
        }
        $clearCells = [];
        foreach ($entries as $entry) {
            foreach (['*:*', $entry['day'].':*', $entry['day'].':'.$entry['period']] as $slot) {
                if (isset($badTeachers[$entry['teacher_id'].':'.$slot]) || isset($badSections[$entry['section_id'].':'.$slot])) {
                    continue 2;
                }
            }
            if ($entry['teacher_id']) {
                $clearCells[$entry['section_id'].':'.$entry['day'].':'.$entry['period']] = true;
            }
        }

        // 100% is reserved for a result that has been authoritatively checked and saved.
        return min(99, (int) floor(count($clearCells) / $total * 100));
    }
}
