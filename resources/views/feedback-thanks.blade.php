<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="light dark">
    <title>{{ __('nightguardian::messages.title') }}</title>
    @include('nightguardian::partials.styles')
</head>
<body>
<main>
    <svg class="moon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>

    {{-- $status is one of: sent, recorded, failed, empty, expired, invalid, too_many --}}
    <p class="message {{ in_array($status, ['sent', 'recorded'], true) ? 'ok' : 'warn' }}">
        {{ __('nightguardian::messages.'.($status === 'sent' ? 'thanks_sent' : ($status === 'recorded' ? 'thanks_recorded' : ($status === 'failed' ? 'thanks_failed' : $status)))) }}
    </p>

    @if ($reference)
        <div class="reference">
            <small>{{ __('nightguardian::messages.reference_label') }}</small>
            <div class="code-row"><div class="code">{{ $reference }}</div></div>
        </div>
    @endif

    <footer>
        @if (in_array($status, ['empty', 'expired', 'invalid', 'too_many'], true))
            <a class="button" href="javascript:history.back()">{{ __('nightguardian::messages.back') }}</a>
        @endif
        <a class="button primary" href="{{ $home }}">{{ __('nightguardian::messages.home') }}</a>
    </footer>
</main>
</body>
</html>
