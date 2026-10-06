<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $organisation->name }} — Email Security Report</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; font-size: 10.5px; color: #111827; line-height: 1.45; }
        .page { padding: 0 44px 60px; }
        .accent-bar { height: 6px; background: #4f46e5; }
        .glyph { font-family: 'DejaVu Sans', sans-serif; }
        .muted { color: #6b7280; }

        .eyebrow { margin-top: 34px; font-size: 8.5px; letter-spacing: 1.6px; text-transform: uppercase; color: #4f46e5; font-weight: bold; }
        h1 { font-size: 26px; font-weight: bold; margin: 4px 0 2px; letter-spacing: -0.3px; }
        .meta { font-size: 10px; color: #6b7280; }

        .verdict { margin-top: 26px; width: 100%; border-collapse: collapse; }
        .verdict td { padding: 14px 18px; vertical-align: middle; }
        .verdict-healthy { background: #ecfdf5; border-left: 4px solid #10b981; }
        .verdict-attention { background: #fffbeb; border-left: 4px solid #f59e0b; }
        .verdict-at_risk { background: #fef2f2; border-left: 4px solid #ef4444; }
        .verdict-label { font-size: 15px; font-weight: bold; white-space: nowrap; width: 1%; padding-right: 0; }
        .verdict-healthy .verdict-label { color: #047857; }
        .verdict-attention .verdict-label { color: #b45309; }
        .verdict-at_risk .verdict-label { color: #b91c1c; }
        .verdict-sentence { font-size: 11.5px; color: #374151; }

        h2 { font-size: 8.5px; letter-spacing: 1.4px; text-transform: uppercase; color: #6b7280; font-weight: bold; margin: 30px 0 10px; }
        h2 .muted { letter-spacing: 0; text-transform: none; font-weight: normal; color: #9ca3af; }

        .kpis { width: 100%; border-collapse: collapse; }
        .kpis td { width: 25%; vertical-align: top; padding: 12px 14px 0 0; border-top: 2px solid #111827; }
        .kpi-label { font-size: 9px; color: #6b7280; }
        .kpi-value { font-size: 24px; font-weight: bold; margin-top: 4px; letter-spacing: -0.4px; }
        .kpi-caption { font-size: 8.5px; color: #9ca3af; margin-top: 1px; }
        .kpi-delta { font-size: 9px; margin-top: 6px; }
        .delta-good { color: #047857; }
        .delta-bad { color: #b91c1c; }
        .delta-neutral { color: #6b7280; }

        .chart img { width: 100%; }

        .scorecard { width: 100%; border-collapse: collapse; }
        .scorecard th { font-size: 8.5px; font-weight: normal; color: #9ca3af; text-align: left; padding: 0 8px 6px 0; border-bottom: 1px solid #e5e7eb; }
        .scorecard td { padding: 8px 8px 8px 0; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
        .scorecard .num { text-align: right; }
        .domain { font-weight: bold; }
        .dot { display: inline-block; width: 7px; height: 7px; border-radius: 4px; margin-right: 6px; background: #d1d5db; }
        .dot-good { background: #10b981; }
        .dot-warning { background: #f59e0b; }
        .dot-critical { background: #ef4444; }
        .pct-good { color: #047857; }
        .pct-warning { color: #b45309; }
        .pct-critical { color: #b91c1c; }
        .policy { font-size: 8.5px; padding: 2px 7px; border-radius: 8px; background: #f3f4f6; color: #4b5563; }
        .policy-reject { background: #ecfdf5; color: #047857; }
        .policy-quarantine { background: #eef2ff; color: #4338ca; }
        .policy-none { background: #fffbeb; color: #b45309; }
        .policy-missing { background: #fef2f2; color: #b91c1c; }
        .next-step { color: #4b5563; font-size: 9.5px; }

        .actions { width: 100%; border-collapse: collapse; }
        .actions td { padding: 5px 0; vertical-align: top; }
        .action-num { width: 26px; }
        .action-num span { display: inline-block; width: 16px; height: 16px; line-height: 16px; border-radius: 8px; background: #eef2ff; color: #4338ca; font-size: 8.5px; font-weight: bold; text-align: center; }
        .action-text { font-size: 11px; color: #1f2937; }
        .all-clear { font-size: 11px; color: #047857; }

        .footer { position: fixed; bottom: 0; left: 0; right: 0; height: 34px; padding: 0 44px; font-size: 8px; color: #9ca3af; }
        .footer table { width: 100%; border-top: 1px solid #e5e7eb; padding-top: 8px; }
        .footer td { padding-top: 8px; }
    </style>
</head>
<body>

@php
    $period = fn ($start, $end) => $start->format('j M Y').' – '.$end->format('j M Y');
    $arrow = fn (?string $direction) => match ($direction) {
        'up' => '▲',
        'down' => '▼',
        default => '■',
    };
@endphp

<div class="footer">
    <table>
        <tr>
            <td>{{ $organisation->name }} &middot; {{ __('Email security report') }} &middot; {{ $period($from, $to) }}</td>
            <td style="text-align: right;">{{ __('Generated :date by DMARC Monitor', ['date' => $generatedAt->format('j M Y')]) }}</td>
        </tr>
    </table>
</div>

<div class="accent-bar"></div>

<div class="page">
    <div class="eyebrow">{{ __('Email security report') }}</div>
    <h1>{{ $organisation->name }}</h1>
    <div class="meta">
        {{ $period($from, $to) }} &middot; {{ $domainCount }} {{ \Illuminate\Support\Str::plural('domain', $domainCount) }}
    </div>

    <table class="verdict verdict-{{ $verdict['status'] }}">
        <tr>
            <td class="verdict-label">{{ $verdict['label'] }}</td>
            <td class="verdict-sentence">{{ $verdict['sentence'] }}</td>
        </tr>
    </table>

    <h2>{{ __('At a glance') }} <span class="muted">&middot; {{ __('compared with :period', ['period' => $period($previousFrom, $previousTo)]) }}</span></h2>
    <table class="kpis">
        <tr>
            @foreach ($kpis as $kpi)
                <td>
                    <div class="kpi-label">{{ $kpi['label'] }}</div>
                    <div class="kpi-value">{{ $kpi['value'] }}</div>
                    <div class="kpi-caption">{{ $kpi['caption'] }}</div>
                    @if ($kpi['delta'] !== null)
                        <div class="kpi-delta delta-{{ $kpi['sentiment'] }}">
                            <span class="glyph">{{ $arrow($kpi['direction']) }}</span> {{ $kpi['delta'] }}
                        </div>
                    @endif
                </td>
            @endforeach
        </tr>
    </table>

    <h2>{{ __('DMARC pass rate over time') }}</h2>
    @if ($chartSvg === '')
        <p class="muted">{{ __('No DMARC reports were received in this period.') }}</p>
    @else
        <div class="chart">
            <img src="data:image/svg+xml;base64,{{ base64_encode($chartSvg) }}" alt="">
        </div>
    @endif

    <h2>{{ __('Recommended next steps') }}</h2>
    @if ($actions === [])
        <div class="all-clear"><span class="glyph">✓</span> {{ __('No action needed — keep monitoring.') }}</div>
    @else
        <table class="actions">
            @foreach ($actions as $index => $action)
                <tr>
                    <td class="action-num"><span>{{ $index + 1 }}</span></td>
                    <td class="action-text">{{ $action }}</td>
                </tr>
            @endforeach
        </table>
        @if ($moreActions > 0)
            <div class="muted" style="margin-top: 4px; font-size: 9px;">{{ __('+ :count more in DMARC Monitor', ['count' => $moreActions]) }}</div>
        @endif
    @endif

    <h2>{{ __('Domains') }}</h2>
    @if ($scorecard === [])
        <p class="muted">{{ __('No domains are configured for this organisation.') }}</p>
    @else
        <table class="scorecard">
            <thead>
                <tr>
                    <th>{{ __('Domain') }}</th>
                    <th class="num">{{ __('Pass rate') }}</th>
                    <th class="num">{{ __('Messages') }}</th>
                    <th style="padding-left: 14px;">{{ __('Policy') }}</th>
                    <th>{{ __('Next step') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($scorecard as $row)
                    <tr>
                        <td class="domain"><span class="dot dot-{{ $row['pass_status'] ?? 'none' }}"></span>{{ $row['fqdn'] }}</td>
                        <td class="num {{ $row['pass_status'] ? 'pct-'.$row['pass_status'] : 'muted' }}">{{ $row['dmarc_pass_pct'] !== null ? $row['dmarc_pass_pct'].'%' : '—' }}</td>
                        <td class="num muted">{{ number_format($row['total']) }}</td>
                        <td style="padding-left: 14px;">
                            <span class="policy policy-{{ $row['policy'] ?? 'missing' }}">{{ $row['policy'] ?? __('no record') }}{{ $row['partial'] ? ' '.__('(partial)') : '' }}</span>
                        </td>
                        <td class="next-step">{{ $row['next_step'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

</body>
</html>
