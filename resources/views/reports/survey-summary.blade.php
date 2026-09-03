<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Survey Summary Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1b254b; }
        h1 { font-size: 18px; margin: 0; color: #ea580c; }
        h2 { font-size: 13px; margin-top: 18px; }
        .meta { color: #707eae; font-size: 10px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th { background: #f97316; color: #fff; text-align: left; padding: 6px 8px; font-size: 10px; }
        td { border-bottom: 1px solid #eee; padding: 6px 8px; }
        .stat { display: inline-block; background: #ffedd5; color: #c2410c; padding: 8px 16px; border-radius: 6px; margin-right: 10px; }
        .stat strong { font-size: 16px; display: block; }
        footer { position: fixed; bottom: -20px; left: 0; right: 0; font-size: 9px; color: #a3aed0; }
    </style>
</head>
<body>
    <h1>HULAGWAY — Survey Responses Summary</h1>
    <p class="meta">{{ $summary['survey']['title'] }} · Generated on {{ $generatedAt }}</p>

    <div>
        <span class="stat"><strong>{{ number_format($summary['total_responses']) }}</strong>Total Responses</span>
    </div>

    <h2>Responses by Barangay</h2>
    <table>
        <thead><tr><th>Barangay</th><th>Responses</th></tr></thead>
        <tbody>
            @forelse ($summary['by_barangay'] as $barangay => $count)
                <tr><td>{{ $barangay }}</td><td>{{ number_format($count) }}</td></tr>
            @empty
                <tr><td colspan="2">No responses yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Responses by Source</h2>
    <table>
        <thead><tr><th>Source</th><th>Responses</th></tr></thead>
        <tbody>
            @forelse ($summary['by_source'] as $source => $count)
                <tr><td class="badge">{{ ucfirst($source) }}</td><td>{{ number_format($count) }}</td></tr>
            @empty
                <tr><td colspan="2">No responses yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <footer>HULAGWAY · Mapping Community Realities Toward Informed Extension Planning</footer>
</body>
</html>
