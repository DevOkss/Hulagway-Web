<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Extension Activities Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1b254b; }
        h1 { font-size: 18px; margin: 0; color: #ea580c; }
        .meta { color: #707eae; font-size: 10px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f97316; color: #fff; text-align: left; padding: 6px 7px; font-size: 9px; text-transform: uppercase; }
        td { border-bottom: 1px solid #eee; padding: 5px 7px; vertical-align: top; }
        .status { text-transform: capitalize; }
        footer { position: fixed; bottom: -20px; left: 0; right: 0; font-size: 9px; color: #a3aed0; }
    </style>
</head>
<body>
    <h1>HULAGWAY — Extension Activities Report</h1>
    <p class="meta">{{ $activities->count() }} activities · Generated on {{ $generatedAt }}</p>

    <table>
        <thead>
            <tr>
                <th>Title</th>
                <th>Program / Institute</th>
                <th>Barangay / Location</th>
                <th>SDGs</th>
                <th>Date Range / Days</th>
                <th>Status</th>
                <th>%</th>
                <th>Participants (F/S/B)</th>
                <th>Collaborators</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($activities as $activity)
                <tr>
                    <td><strong>{{ $activity->title }}</strong><br><span style="font-size:8px;color:#707eae;">{{ \Illuminate\Support\Str::limit($activity->description, 80) }}</span></td>
                    <td>{{ $activity->program?->institute?->name }}<br>{{ $activity->program?->name }}</td>
                    <td>{{ $activity->barangay?->name ?? 'City-wide' }}<br>{{ $activity->location }}</td>
                    <td>
                        @if($activity->relationLoaded('sdgs') && $activity->sdgs->count())
                            @foreach($activity->sdgs as $sdg)
                                <span style="font-size:7px;background:{{ $sdg->color }}15;color:{{ $sdg->color }};border:1px solid {{ $sdg->color }};border-radius:3px;padding:1px 3px;">SDG {{ $sdg->number }}</span>
                            @endforeach
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        {{ $activity->start_date?->format('M d, Y') }} – {{ $activity->end_date?->format('M d, Y') }}
                        @if($activity->relationLoaded('days') && $activity->days->count())
                            <br><span style="font-size:7px;color:#707eae;">{{ $activity->days->pluck('activity_date')->map(fn($d)=>$d->format('M d'))->implode(', ') }}</span>
                        @endif
                        @if($activity->relationLoaded('dailyTasks') && $activity->dailyTasks->count())
                            <br><span style="font-size:7px;color:#707eae;">Tasks: {{ $activity->dailyTasks->count() }}</span>
                        @endif
                    </td>
                    <td class="status">{{ $activity->status }}</td>
                    <td>{{ $activity->progress }}%</td>
                    <td>{{ $activity->faculty_participants }} / {{ $activity->student_participants }} / {{ number_format($activity->beneficiaries) }}</td>
                    <td style="font-size:8px;">{{ $activity->relationLoaded('collaborators') ? $activity->collaborators->pluck('name')->implode(', ') : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="9">No activities found for the selected filters.</td></tr>
            @endforelse
        </tbody>
    </table>

    <footer>HULAGWAY · Mapping Community Realities Toward Informed Extension Planning</footer>
</body>
</html>
