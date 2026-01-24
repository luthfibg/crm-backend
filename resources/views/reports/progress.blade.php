<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Progress Sales - {{ $label }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size:11px; }
        table { width:100%; border-collapse:collapse; margin-top:10px }
        th, td { border:1px solid #ddd; padding:5px; text-align:left; vertical-align: top; }
        th { background:#f4f4f4; font-weight:700; font-size:10px; }
        .small { font-size:10px; color:#555 }
        .muted { color:#666; font-size:9px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
    </style>
</head>
<body>
    <h2>Laporan Progress Sales • {{ strtoupper($range) }} • {{ $label }}</h2>
    <p class="small muted">Periode: {{ $start->toDateString() }} — {{ $end->toDateString() }}</p>

    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:30px">No</th>
                <th style="width:80px">Sales</th>
                <th style="width:80px">Customer</th>
                <th style="width:60px">Product</th>
                <th style="width:70px">Status</th>
                <th style="width:150px">Kesimpulan</th>
                <th style="width:60px">Harga Penawaran</th>
                <th style="width:60px">Harga Deal</th>
                <th class="text-right" style="width:50px">KPI Progress</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $r)
                <tr>
                    <td class="text-center">{{ $r['no'] }}</td>
                    <td>{{ $r['sales_name'] }}</td>
                    <td>
                        {{ $r['customer_name'] }}
                        @if($r['institution'])
                            <br><span class="muted">{{ $r['institution'] }}</span>
                        @endif
                    </td>
                    <td>{{ $r['product'] }}</td>
                    <td>{{ $r['status'] }}</td>
                    <td class="small">{{ $r['kesimpulan'] }}</td>
                    <td>{{ $r['harga_penawaran'] }}</td>
                    <td>{{ $r['harga_deal'] }}</td>
                    <td class="text-right">{{ $r['kpi_progress'] }}%</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    
    <p class="small muted" style="margin-top:20px">
        Generated: {{ now()->toDateTimeString() }}
    </p>
</body>
</html>

