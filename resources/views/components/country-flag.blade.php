@props(['code'])

@if ($code && strlen($code) === 2)
    <span class="fi fi-{{ strtolower($code) }} align-middle" title="{{ strtoupper($code) }}"></span>
@endif
