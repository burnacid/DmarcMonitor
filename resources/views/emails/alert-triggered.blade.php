<x-mail::message>
# {{ __('DMARC Alert Triggered') }}

**{{ __('Domain') }}:** {{ $event->domain->fqdn }}
**{{ __('Rule') }}:** {{ str_replace('_', ' ', $event->alertRule->type) }}
**{{ __('Fired at') }}:** {{ $event->fired_at->format('Y-m-d H:i') }}

@if (! empty($event->details))
<x-mail::table>
| {{ __('Detail') }} | {{ __('Value') }} |
| :--- | :--- |
@foreach ($event->details as $key => $value)
| {{ $key }} | {{ is_array($value) ? json_encode($value) : $value }} |
@endforeach
</x-mail::table>
@endif

<x-mail::button :url="route('dashboard')">
{{ __('View Dashboard') }}
</x-mail::button>

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>
