@extends('layouts.public')

@section('title', $component->name.' – '.$day->locale(app()->getLocale())->isoFormat('LL').' – '.$siteName)

@section('content')
<section class="block">
    <p><a href="{{ route('status') }}">← {{ __('Current status') }}</a></p>
    @include('status.partials.day-detail')
</section>
@endsection
