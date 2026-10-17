@extends('staff.layout')
@php($t = fn ($k, $r = []) => \App\Support\StaffCopy::get($vm['lang'], $k, $r))
@php($av = $vm['availability'])
@section('content')
<h1 class="p-h1">{{ $t('availability') }}</h1>
<p class="p-hint">{{ $t('availability_hint') }}</p>
<form class="p-card s-avail" onsubmit="return false">
  @foreach ($t('days') as $d => $day)
    <fieldset class="s-day">
      <legend><b>{{ $day }}</b></legend>
      @forelse ($av['weekly'][$d] ?? [] as $interval)
        <div class="s-interval">
          <span>{{ $t('from') }}</span><input type="time" value="{{ $interval[0] }}" aria-label="{{ $day }} {{ $t('from') }}">
          <span>{{ $t('to') }}</span><input type="time" value="{{ $interval[1] }}" aria-label="{{ $day }} {{ $t('to') }}">
        </div>
      @empty
        <p class="p-hint s-unknown">{{ $t('unknown') }}</p>
      @endforelse
      <button type="button" class="p-btn p-btn-quiet s-small" @if ($vm['preview'] ?? false) disabled @endif>{{ $t('add_interval') }}</button>
    </fieldset>
  @endforeach
  <h2>{{ $t('exceptions') }}</h2>
  <ul class="s-list">@foreach ($av['exceptions'] as $x)<li>{{ $x['date'] }} · {{ $x['available'] ? '' : $t('not_available') }}</li>@endforeach</ul>
  <button type="button" class="p-btn p-btn-quiet" @if ($vm['preview'] ?? false) disabled @endif>{{ $t('add_exception') }}</button>
  <button type="submit" class="p-btn p-btn-pink" @if ($vm['preview'] ?? false) disabled @endif>{{ $t('save') }}</button>
</form>
@endsection
