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

    <h1>{{ __('nightguardian::messages.title') }}</h1>
    <p>{{ __('nightguardian::messages.intro') }}</p>

    <div class="reference">
        <small>{{ __('nightguardian::messages.reference_label') }}</small>
        <div class="code-row">
            <div class="code" id="ng-reference">{{ $reference }}</div>
            <button type="button" class="copy" id="ng-copy" hidden>{{ __('nightguardian::messages.copy') }}</button>
        </div>
        <span class="hint">{{ __('nightguardian::messages.reference_hint') }}</span>
    </div>

    @if ($feedback)
        <form method="post" action="{{ $action }}">
            <input type="hidden" name="event_id" value="{{ $eventId }}">
            <input type="hidden" name="reference" value="{{ $reference }}">
            <input type="hidden" name="expires" value="{{ $expires }}">
            <input type="hidden" name="signature" value="{{ $signature }}">

            <label for="ng-comment">{{ __('nightguardian::messages.feedback_label') }}</label>
            <span class="hint">{{ __('nightguardian::messages.feedback_hint') }}</span>
            <textarea id="ng-comment" name="comment" maxlength="2000" placeholder="{{ __('nightguardian::messages.feedback_placeholder') }}"></textarea>
            <button type="submit" class="primary">{{ __('nightguardian::messages.feedback_send') }}</button>
        </form>
    @endif

    <footer>
        <a class="button" href="{{ $home }}">{{ __('nightguardian::messages.home') }}</a>
    </footer>
</main>

{{-- Optional enhancement: the page works without JavaScript; this only adds a copy button. --}}
<script>
    (function () {
        var button = document.getElementById('ng-copy');
        var code = document.getElementById('ng-reference');
        if (!button || !code || !navigator.clipboard) { return; }
        button.hidden = false;
        button.addEventListener('click', function () {
            navigator.clipboard.writeText(code.textContent.trim()).then(function () {
                var original = button.textContent;
                button.textContent = @json(__('nightguardian::messages.copied'));
                setTimeout(function () { button.textContent = original; }, 1500);
            });
        });
    })();
</script>
</body>
</html>
