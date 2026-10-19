@props(['provider'])

@if ($provider instanceof \App\Support\MailProvider)
    <span {{ $attributes->class('inline-flex h-4 w-4 shrink-0 items-center justify-center') }} title="{{ __('Mail hosted by :provider', ['provider' => $provider->label()]) }}">
        <span class="sr-only">{{ $provider->label() }}</span>
        @switch ($provider)
            @case (\App\Support\MailProvider::Microsoft365)
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 23 23" class="h-4 w-4" aria-hidden="true">
                    <rect x="1" y="1" width="10" height="10" fill="#f25022" />
                    <rect x="12" y="1" width="10" height="10" fill="#7fba00" />
                    <rect x="1" y="12" width="10" height="10" fill="#00a4ef" />
                    <rect x="12" y="12" width="10" height="10" fill="#ffb900" />
                </svg>
                @break
            @case (\App\Support\MailProvider::GoogleWorkspace)
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" class="h-4 w-4" aria-hidden="true">
                    <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z" />
                    <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z" />
                    <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z" />
                    <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z" />
                </svg>
                @break
            @case (\App\Support\MailProvider::ICloud)
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4 text-sky-500 dark:text-sky-400" aria-hidden="true">
                    <path d="M4.5 9.75a6 6 0 0 1 11.573-2.226 3.75 3.75 0 0 1 4.133 4.303A4.5 4.5 0 0 1 18 20.25H6.75a5.25 5.25 0 0 1-2.23-10.004 6.072 6.072 0 0 1-.02-.496Z" />
                </svg>
                @break
            @default
                <span @class([
                    'flex h-4 w-4 items-center justify-center rounded text-[10px] font-bold leading-none text-white',
                    'bg-violet-600' => $provider === \App\Support\MailProvider::ProtonMail,
                    'bg-blue-600' => $provider === \App\Support\MailProvider::Fastmail,
                ]) aria-hidden="true">{{ mb_substr($provider->label(), 0, 1) }}</span>
        @endswitch
    </span>
@endif
