@php
    $vm = ['lang' => $lang, 'test_mode' => true, 'lang_url' => ['es' => url('/portal?lang=es'), 'en' => url('/portal?lang=en')]];
    $t = fn ($k, $r = []) => \App\Support\PortalCopy::get($lang, $k, $r);
@endphp
@extends('portal.layout')
@section('content')
<section class="p-card" id="landing">
  <h1 class="p-h1" id="landing-title">{{ $t('opening') }}</h1>
  <p class="p-hint" id="landing-text">{{ $t('opening_hint') }}</p>
</section>
@endsection
