<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Progress Report - {{ $label }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size:12px; }
        table { width:100%; border-collapse:collapse; margin-top:10px }
        th, td { border:1px solid #ddd; padding:6px; text-align:left }
        th { background:#f4f4f4; font-weight:700 }
        .small { font-size:11px; color:#555 }
        .muted { color:#666 }
    </style>
</head>
<body>
    <h2>Progress Report • {{ strtoupper($range) }} • {{ $label }}</h2>
    <p class="small muted">Periode: {{ $start->toDateString() }} — {{ $end->toDateString() }}</p>

    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Customer</th>
                <th>Institution</th>
                <th>Position</th>
                <th>Assigned</th>
                <th>Approved (period)</th>
                <th>KPI% (period)</th>
                <th>KPI% (overall)</th>
                <th>First Submitted</th>
                <th>Last Submitted</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $r)
                <tr>
                    <td>{{ $r['no'] }}</td>
                    <td>{{ $r['customer_name'] }}</td>
                    <td>{{ $r['institution'] }}</td>
                    <td>{{ $r['position'] }}</td>
                    <td>{{ $r['total_assigned'] }}</td>
                    <td>{{ $r['approved_in_period'] }}</td>
                    <td>{{ $r['kpi_percent_period'] }}%</td>
                    <td>{{ $r['kpi_percent_overall'] }}%</td>
                    <td>{{ $r['first_submission'] ?? '-' }}</td>
                    <td>{{ $r['last_submission'] ?? '-' }}</td>
                </tr>
                <tr>
                    <td colspan="10" class="small muted">
                        <strong>Daily goals:</strong>
                        @foreach($r['daily_details'] as $d)
                            • {{ $d['daily_goal'] }} — {{ ucfirst($d['status']) }} @if($d['last_submitted_at']) ({{ $d['last_submitted_at'] }}) @endif <br/>
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>