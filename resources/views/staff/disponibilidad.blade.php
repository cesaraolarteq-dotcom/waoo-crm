@extends('staff.layout')
@php($t = fn ($k, $r = []) => \App\Support\StaffCopy::get($vm['lang'], $k, $r))
@php($a = $vm['availability'])
@section('content')
<h1 class="p-h1">{{ $t('availability') }}</h1>
<p class="p-hint">{{ $t('availability_hint') }}</p>
<form class="p-card s-avail" onsubmit="return false">
  <div class="s-grid" role="table" aria-label="{{ $t('availability') }}">
    <div role="row" class="s-grid-head"><span></span>@foreach (['morning', 'afternoon', 'evening'] as $slot)<span role="columnheader">{{ $t($slot) }}</span>@endforeach</div>
    @foreach ($t('days') as $d => $day)
      <div role="row"><span role="rowheader"><b>{{ $day }}</b></span>
        @foreach (['morning', 'afternoon', 'evening'] as $slot)
          <label class="s-slot"><input type="checkbox" @checked(in_array($slot, $a['slots'][$d] ?? [], true)) aria-label="{{ $day }} {{ $t($slot) }}"><span></span></label>
        @endforeach
      </div>
    @endforeach
  </div>
  <h2>{{ $t('exceptions') }}</h2>
  <ul class="s-list">@foreach ($a['exceptions'] as $x)<li>{{ $x }}</li>@endforeach</ul>
  <button type="button" class="p-btn p-btn-quiet">{{ $t('add_exception') }}</button>
  <button type="submit" class="p-btn p-btn-pink" @if ($vm['preview'] ?? false) disabled @endif>{{ $t('save') }}</button>
</form>
@endsection
