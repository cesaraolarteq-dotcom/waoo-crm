@extends('staff.layout')
@php($t = fn ($k, $r = []) => \App\Support\StaffCopy::get($vm['lang'], $k, $r))
@section('content')
<h1 class="p-h1">{{ $t('hello', ['name' => $vm['staff']['display_name']]) }}</h1>
<p class="p-sub">{{ $t('week') }} · {{ $t('week_range', ['from' => $vm['week']['from'], 'to' => $vm['week']['to']]) }}</p>

@forelse ($vm['jobs'] as $j)
  <article class="p-card s-job">
    <p class="s-when">{{ $j['date'] }}</p>
    <dl class="s-dl">
      <div><dt>{{ $t('arrive') }}</dt><dd><b>{{ $j['arrival'] }}</b></dd></div>
      <div><dt>{{ $t('party') }}</dt><dd>{{ $j['hours'] }}</dd></div>
      @if ($j['setup_minutes'])<div><dt>{{ $t('setup') }}</dt><dd>{{ $j['setup_minutes'] }} min</dd></div>@endif
      <div><dt>{{ $t('role') }}</dt><dd>{{ $j['role'] }}</dd></div>
      @if ($j['character'])<div><dt>{{ $t('character') }}</dt><dd>{{ $j['character'] }}@if (filled($j['outfit_version'])) ({{ $j['outfit_version'] }})@endif</dd></div>@endif
    </dl>

    <div class="s-block">
      <p class="s-line"><b>{{ $j['address'] }}</b></p>
      @if ($j['maps'])<a class="p-btn" href="{{ $j['maps'] }}" target="_blank" rel="noopener noreferrer">{{ $t('maps') }}</a>@endif
      @if ($j['parking'])<p class="p-hint"><b>{{ $t('parking') }}:</b> {{ $j['parking'] }}</p>@endif
      @if ($j['access'])<p class="p-hint"><b>{{ $t('access') }}:</b> {{ $j['access'] }}</p>@endif
    </div>

    <div class="s-block">
      @if ($j['is_driver'])<p class="s-line"><span class="s-badge">{{ $t('you_drive') }}</span></p>@endif
      @if ($j['team'])
        <p class="s-line"><b>{{ $t('team') }}:</b>
          @foreach ($j['team'] as $m){{ $m['first_name'] }}@if ($m['drives']) ({{ $t('drives') }})@endif{{ $loop->last ? '' : ', ' }}@endforeach
        </p>
      @endif
    </div>

    @if ($j['bring'] || $j['instructions'])
      <div class="s-block">
        @if ($j['bring'])<p class="s-line"><b>{{ $t('bring') }}:</b></p><ul class="s-list">@foreach ($j['bring'] as $b)<li>{{ $b }}</li>@endforeach</ul>@endif
        @if ($j['instructions'])<p class="s-line"><b>{{ $t('notes') }}:</b> {{ $j['instructions'] }}</p>@endif
      </div>
    @endif

    <p class="p-hint"><b>{{ $t('contact') }}:</b> @if ($j['day_contact']){{ $j['day_contact']['name'] }} · {{ $j['day_contact']['phone'] }}@else{{ $t('contact_later') }}@endif</p>
  </article>
@empty
  <section class="p-card"><p class="s-line">{{ $t('none') }}</p></section>
@endforelse

<p class="p-hint">{{ $t('saved_link') }} {{ $t('updated', ['when' => $vm['updated_at']]) }}</p>
@endsection
