@php
    $vm = ['lang' => $lang, 'test_mode' => true, 'lang_url' => ['es' => url('/portal/quote?lang=es'), 'en' => url('/portal/quote?lang=en')]];
    $t = fn ($k, $r = []) => \App\Support\QuoteCopy::get($lang, $k, $r);
@endphp
@extends('portal.layout')
@section('content')
<section class="p-card p-done"><h2>{{ $t('superseded_title') }}</h2><p>{{ $t('superseded') }}</p></section>
@endsection
