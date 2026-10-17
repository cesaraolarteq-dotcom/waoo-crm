@extends('staff.layout')
@php($t = fn ($k, $r = []) => \App\Support\StaffCopy::get($vm['lang'], $k, $r))
@php($j = $vm['job'])
@section('content')
<a class="s-back" href="{{ $vm['nav']['inicio'] }}">{{ $t('back') }}</a>
<h1 class="p-h1">{{ $t('job') }}</h1>
<section class="p-card">
  <dl class="s-dl">
    <div><dt>{{ $t('when') }}</dt><dd>{{ $j['when'] }}</dd></div>
    <div><dt>{{ $t('times') }}</dt><dd>{{ $t('times_line', $j['times']) }}</dd></div>
    <div><dt>{{ $t('role') }}</dt><dd>{{ $j['role'] }}</dd></div>
    @if ($j['character'])<div><dt>{{ $t('character') }}</dt><dd>{{ $j['character'] }}</dd></div>@endif
  </dl>
</section>
<section class="p-card">
  <h2>{{ $t('where') }}</h2>
  <p class="s-line"><b>{{ $j['address'] }}</b></p>
  <a class="p-btn" href="{{ $j['maps'] }}" target="_blank" rel="noopener noreferrer">{{ $t('maps') }}</a>
  <p class="p-hint"><b>{{ $t('parking') }}:</b> {{ $j['parking'] }}</p>
</section>
<section class="p-card">
  <h2>{{ $t('team') }}</h2>
  <ul class="s-list">
    @foreach ($j['team'] as $m)<li><b>{{ $m['name'] }}</b> · {{ $m['role'] }}@if ($m['driver']) · <span class="s-badge">{{ $t('driver') }}</span>@endif</li>@endforeach
  </ul>
</section>
<section class="p-card">
  <h2>{{ $t('bring') }}</h2>
  <ul class="s-list">@foreach ($j['bring'] as $b)<li>{{ $b }}</li>@endforeach</ul>
  <h2>{{ $t('notes') }}</h2>
  <p class="s-line">{{ $j['notes'] }}</p>
</section>
<section class="p-card">
  <h2>{{ $t('contact') }}</h2>
  <p class="p-hint">{{ $j['contact'] ?? $t('contact_later') }}</p>
</section>
@endsection
