{{-- Rendered server-side in the app timezone, then localised in the browser. --}}
<time datetime="{{ $at->toIso8601String() }}" @isset($format) data-format="{{ $format }}" @endisset>{{ $at->timezone(config('app.timezone'))->format(($format ?? 'datetime') === 'time' ? 'H:i' : 'Y-m-d H:i') }}</time>
