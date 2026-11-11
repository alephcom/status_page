{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<feed xmlns="http://www.w3.org/2005/Atom">
    <title>{{ $siteName }}</title>
    <id>{{ route('status') }}</id>
    <link href="{{ route('status') }}"/>
    <link rel="self" href="{{ route('feed') }}"/>
    <updated>{{ ($entries->first()['updated'] ?? now())->toAtomString() }}</updated>
@foreach ($entries as $entry)
    <entry>
        <title>{{ $entry['title'] }}</title>
        <id>{{ $entry['id'] }}</id>
        <link href="{{ $entry['url'] }}"/>
        <updated>{{ $entry['updated']->toAtomString() }}</updated>
        <author><name>{{ $siteName }}</name></author>
        <content type="html">{{ $entry['content'] }}</content>
    </entry>
@endforeach
</feed>
