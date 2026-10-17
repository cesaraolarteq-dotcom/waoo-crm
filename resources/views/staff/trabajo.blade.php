@extends('staff.layout')
@php($t = fn ($k, $r = []) => \App\Support\StaffCopy::get($vm['lang'], $k, $r))
@php($e = $vm['job']['event'])
@php($a = $vm['job']['assignment'])
@section('content')
<a class="s-back" href="{{ $vm['nav']['inicio'] }}">{{ $t('back') }}</a>
<h1 class="p-h1">{{ $t('job') }}</h1>
<section class="p-card">
  <dl class="s-dl">
    <div><dt>{{ $t('when') }}</dt><dd>{{ $e['date'] }}</dd></div>
    <div><dt>{{ $t('times') }}</dt><dd>{{ $t('times_line', ['arrive' => $e['arrival'], 'hours' => $e['hours']]) }}</dd></div>
    <div><dt>{{ $t('setup') }}</dt><dd>{{ $e['setup_minutes'] }} min</dd></div>
    <div><dt>{{ $t('role') }}</dt><dd>{{ $a['role'] }}</dd></div>
    @if ($a['character'])<div><dt>{{ $t('character') }}</dt><dd>{{ $a['character'] }}@if ($a['outfit_version']) ({{ $a['outfit_version'] }})@endif</dd></div>@endif
  </dl>
</section>
<section class="p-card">
  <h2>{{ $t('where') }}</h2>
  <p class="s-line"><b>{{ $e['address'] }}</b></p>
  <a class="p-btn" href="{{ $e['maps'] }}" target="_blank" rel="noopener noreferrer">{{ $t('maps') }}</a>
  <p class="p-hint"><b>{{ $t('parking') }}:</b> {{ $e['parking'] }}</p>
  <p class="p-hint"><b>{{ $t('access') }}:</b> {{ $e['access'] }}</p>
</section>
<section class="p-card">
  <h2>{{ $t('driving') }}</h2>
  <p class="s-line">@if ($a['is_driver'])<span class="s-badge">{{ $t('you_drive') }}</span>@else {{ $t('other_drives') }} @endif</p>
</section>
<section class="p-card">
  <h2>{{ $t('bring') }}</h2>
  <ul class="s-list">@foreach ($a['bring'] as $b)<li>{{ $b }}</li>@endforeach</ul>
  <h2>{{ $t('notes') }}</h2>
  <p class="s-line">{{ $a['instructions'] }}</p>
</section>
<section class="p-card">
  <h2>{{ $t('contact') }}</h2>
  <p class="p-hint">@if ($vm['job']['day_contact']){{ $vm['job']['day_contact']['name'] }} · {{ $vm['job']['day_contact']['phone'] }}@else {{ $t('contact_later') }} @endif</p>
</section>
@endsection
