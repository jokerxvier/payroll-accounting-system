{{-- Audit log, rendered by dompdf (Phase 4 W13/W14 export parity).

     The before/after JSON blobs the CSV and xlsx exports carry are
     deliberately omitted here. They are unbounded in width, and including
     them would either overflow the page or force the type down past
     readability. This PDF is the review-and-sign artefact; the spreadsheet
     formats remain the ones that carry the full diff payload. --}}
@php
    /** App\Models\Pas\EmployeeProfile → EmployeeProfile. */
    $shortType = static function (?string $type): string {
        if ($type === null || $type === '') {
            return '—';
        }

        $parts = explode('\\', $type);

        return (string) end($parts);
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Audit log</title>
    @include('reports.partials.pdf-styles')
</head>
<body>
<div class="doc">
    <p class="eyebrow">Report</p>
    <h1>Audit log</h1>
    <div class="meta">
        <div>
            Range:
            {{ $filters['from'] ?? 'earliest' }} &ndash; {{ $filters['to'] ?? 'latest' }}
        </div>
        @if (! empty($filters['action']))
            <div>Action: {{ $filters['action'] }}</div>
        @endif
        @if (! empty($filters['actor_id']))
            <div>Actor: {{ $actorNames->get($filters['actor_id'])?->name ?? ('#'.$filters['actor_id']) }}</div>
        @endif
        <div>Generated: {{ $generatedAt->toDayDateTimeString() }}</div>
        <div>Before/after payloads are available in the CSV and Excel exports.</div>
    </div>

    @if ($entries->isEmpty())
        <p class="empty">No audit entries matched these filters.</p>
    @else
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Timestamp</th>
                <th>Actor</th>
                <th>Action</th>
                <th>Target</th>
                <th>Target ID</th>
                <th>IP</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($entries as $entry)
                <tr>
                    <td class="code">{{ $entry->id }}</td>
                    <td>{{ $entry->created_at?->toDayDateTimeString() ?? '—' }}</td>
                    <td>
                        {{ $entry->actor_id ? ($actorNames->get($entry->actor_id)?->name ?? ('#'.$entry->actor_id)) : 'system' }}
                    </td>
                    <td>{{ $entry->action }}</td>
                    <td>{{ $shortType($entry->auditable_type) }}</td>
                    <td class="code">{{ $entry->auditable_id ?? '—' }}</td>
                    <td class="code">{{ $entry->ip ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <p class="footnote">
        {{ config('app.name') }} · Audit log · {{ $entries->count() }} {{ Str::plural('entry', $entries->count()) }}
    </p>
</div>
</body>
</html>
