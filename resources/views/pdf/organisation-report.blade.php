<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $organisation->name }} — DMARC Report</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        h2 { font-size: 13px; margin: 20px 0 8px; border-bottom: 1px solid #d1d5db; padding-bottom: 4px; }
        .muted { color: #6b7280; }
        .header-meta { font-size: 10px; color: #6b7280; margin-bottom: 4px; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        th, td { text-align: left; padding: 5px 6px; font-size: 10px; }
        thead th { background: #f3f4f6; border-bottom: 1px solid #d1d5db; font-weight: bold; }
        tbody tr { border-bottom: 1px solid #e5e7eb; }
        .num { text-align: right; }

        .tiles { width: 100%; margin: 12px 0; }
        .tiles td { width: 20%; padding: 8px; border: 1px solid #e5e7eb; }
        .tile-label { font-size: 9px; text-transform: uppercase; color: #6b7280; }
        .tile-value { font-size: 16px; font-weight: bold; margin-top: 2px; }

        .pct-good { color: #15803d; }
        .pct-warning { color: #b45309; }
        .pct-critical { color: #b91c1c; }

        .footer { margin-top: 20px; font-size: 9px; color: #9ca3af; }
    </style>
</head>
<body>

@php
    $statusFor = fn (float $pct) => match (true) {
        $pct >= 95 => 'good',
        $pct >= 80 => 'warning',
        default => 'critical',
    };
@endphp

<h1>{{ $organisation->name }}</h1>
<div class="header-meta">
    DMARC Report &middot; {{ $from->toDateString() }} to {{ $to->toDateString() }}
    &middot; {{ $domainCount }} {{ \Illuminate\Support\Str::plural('domain', $domainCount) }}
    &middot; Generated {{ $generatedAt->format('Y-m-d H:i') }}
</div>

<table class="tiles">
    <tr>
        <td>
            <div class="tile-label">Messages</div>
            <div class="tile-value">{{ number_format($summary['total']) }}</div>
        </td>
        <td>
            <div class="tile-label">DMARC Pass</div>
            <div class="tile-value pct-{{ $statusFor($summary['dmarc_pass_pct']) }}">{{ $summary['dmarc_pass_pct'] }}%</div>
        </td>
        <td>
            <div class="tile-label">SPF Pass</div>
            <div class="tile-value pct-{{ $statusFor($summary['spf_pass_pct']) }}">{{ $summary['spf_pass_pct'] }}%</div>
        </td>
        <td>
            <div class="tile-label">DKIM Pass</div>
            <div class="tile-value pct-{{ $statusFor($summary['dkim_pass_pct']) }}">{{ $summary['dkim_pass_pct'] }}%</div>
        </td>
        <td>
            <div class="tile-label">Sending Sources</div>
            <div class="tile-value">{{ number_format($summary['distinct_sources']) }}</div>
        </td>
    </tr>
</table>

<h2>Why DMARC fails</h2>
@if ($failureBreakdown['total'] === 0)
    <p class="muted">No messages in this window.</p>
@else
    <table>
        <thead>
            <tr>
                <th>Category</th>
                <th class="num">Messages</th>
                <th class="num">Share</th>
            </tr>
        </thead>
        <tbody>
            @foreach ([
                ['label' => 'Both SPF and DKIM pass', 'value' => $failureBreakdown['both_pass']],
                ['label' => 'SPF fails, DKIM passes', 'value' => $failureBreakdown['spf_fail_only']],
                ['label' => 'DKIM fails, SPF passes', 'value' => $failureBreakdown['dkim_fail_only']],
                ['label' => 'Both fail (DMARC fails)', 'value' => $failureBreakdown['both_fail']],
            ] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ number_format($row['value']) }}</td>
                    <td class="num">{{ $failureBreakdown['total'] > 0 ? round($row['value'] / $failureBreakdown['total'] * 100, 1) : 0 }}%</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<h2>Daily pass-rate trend</h2>
@if ($trend->isEmpty())
    <p class="muted">No messages in this window.</p>
@else
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th class="num">Messages</th>
                <th class="num">DMARC</th>
                <th class="num">SPF</th>
                <th class="num">DKIM</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($trend as $day)
                <tr>
                    <td>{{ $day['date'] }}</td>
                    <td class="num">{{ number_format($day['total']) }}</td>
                    <td class="num pct-{{ $statusFor($day['dmarc_pass_pct']) }}">{{ $day['dmarc_pass_pct'] }}%</td>
                    <td class="num pct-{{ $statusFor($day['spf_pass_pct']) }}">{{ $day['spf_pass_pct'] }}%</td>
                    <td class="num pct-{{ $statusFor($day['dkim_pass_pct']) }}">{{ $day['dkim_pass_pct'] }}%</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<h2>Top sending sources</h2>
@if ($topSources->isEmpty())
    <p class="muted">No sending sources in this window.</p>
@else
    <table>
        <thead>
            <tr>
                <th>Domain</th>
                <th>Source</th>
                <th class="num">IPs</th>
                <th class="num">Volume</th>
                <th class="num">DMARC</th>
                <th class="num">SPF</th>
                <th class="num">DKIM</th>
                <th class="num">Enforced</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($topSources as $group)
                <tr>
                    <td>{{ $group['domain'] }}</td>
                    <td>{{ $group['label'] }}</td>
                    <td class="num">{{ $group['ip_count'] }}</td>
                    <td class="num">{{ number_format($group['total']) }}</td>
                    <td class="num pct-{{ $statusFor($group['dmarc_pass_pct']) }}">{{ $group['dmarc_pass_pct'] }}%</td>
                    <td class="num pct-{{ $statusFor($group['spf_pass_pct']) }}">{{ $group['spf_pass_pct'] }}%</td>
                    <td class="num pct-{{ $statusFor($group['dkim_pass_pct']) }}">{{ $group['dkim_pass_pct'] }}%</td>
                    <td class="num">{{ number_format($group['enforced']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<div class="footer">Generated by DMARC Monitor for {{ $organisation->name }}.</div>

</body>
</html>
