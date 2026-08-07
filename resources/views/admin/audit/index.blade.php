<x-app-layout>
    <x-page-header eyebrow="System Administration" title="Audit Log" />

    <form method="GET" class="card p-4 mb-5 flex flex-wrap items-end gap-3">
        <div class="min-w-[200px]">
            <label for="action" class="field-label">Action</label>
            <select id="action" name="action" class="field-input">
                <option value="">All actions</option>
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ $action }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="from" class="field-label">From</label>
            <input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="field-input">
        </div>
        <div>
            <label for="to" class="field-label">To</label>
            <input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="field-input">
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn-primary">Filter</button>
            <a href="{{ route('admin.audit.index') }}" class="btn-ghost">Reset</a>
        </div>
    </form>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse min-w-[760px]">
                <thead>
                    <tr class="bg-mist border-b border-line">
                        <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">When</th>
                        <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Actor</th>
                        <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Action</th>
                        <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Record</th>
                        <th class="text-left font-bold text-[11.5px] uppercase tracking-[0.06em] text-slate-brand px-4 py-3">Change</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr class="border-b border-line last:border-0 align-top">
                            <td class="px-4 py-3.5 font-data text-[13px] text-slate-brand whitespace-nowrap">{{ $log->created_at?->format('M j, Y H:i') }}</td>
                            <td class="px-4 py-3.5 text-[13.5px]">{{ $log->user?->name ?? 'System' }}</td>
                            <td class="px-4 py-3.5 font-data text-[13px]">{{ $log->action }}</td>
                            <td class="px-4 py-3.5 text-[13px] text-slate-brand">{{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</td>
                            <td class="px-4 py-3.5">
                                @if ($log->before_json || $log->after_json)
                                    <details class="text-[12.5px]">
                                        <summary class="cursor-pointer text-electric font-semibold">View</summary>
                                        <pre class="font-data text-[11.5px] bg-mist rounded-lg p-2 mt-2 overflow-x-auto max-w-md">{{ json_encode(['before' => $log->before_json, 'after' => $log->after_json], JSON_PRETTY_PRINT) }}</pre>
                                    </details>
                                @else
                                    <span class="text-slate-brand text-[13px]">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-slate-brand">No audit entries match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
</x-app-layout>
