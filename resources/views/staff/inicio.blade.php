@extends('staff.layout')
@php($t = fn ($k, $r = []) => \App\Support\StaffCopy::get($vm['lang'], $k, $r))
@php($pending = array_values(array_filter($vm['upcoming'], fn ($i) => $i['status'] === 'pending')))
@php($accepted = array_values(array_filter($vm['upcoming'], fn ($i) => $i['status'] === 'accepted')))
@section('content')
<h1 class="p-h1">{{ $t('hello', ['name' => $vm['staff']['display_name']]) }}</h1>

<section class="s-section" aria-labelledby="inv">
  <h2 id="inv" class="s-h2">{{ $t('invites') }}</h2>
  @forelse ($pending as $i)
    <article class="p-card s-job s-invite" data-invitation="{{ $i['id'] }}" data-version="{{ $i['version'] }}" data-instruction-revision="{{ $i['instruction_revision'] }}">
      <p class="s-when">{{ $i['event']['date'] }} · {{ $i['event']['hours'] }}</p>
      <dl class="s-dl">
        <div><dt>{{ $t('role') }}</dt><dd>{{ $i['assignment']['role'] }}@if ($i['assignment']['character']): {{ $i['assignment']['character'] }}@if ($i['assignment']['outfit_version']) ({{ $i['assignment']['outfit_version'] }})@endif @endif</dd></div>
        <div><dt>{{ $t('arrive') }}</dt><dd>{{ $i['event']['arrival'] }}</dd></div>
        <div><dt>{{ $t('area') }}</dt><dd>{{ $i['event']['area'] }}</dd></div>
      </dl>
      <p class="p-hint">{{ $t('address_after') }} {{ $t('answer_by', ['date' => $i['deadline']]) }}</p>
      <p class="p-hint">{{ $t('accept_note') }}</p>
      <div class="s-actions">
        <button type="button" class="p-btn p-btn-pink" @if ($vm['preview'] ?? false) disabled @endif>{{ $t('accept') }}</button>
        <details class="s-decline"><summary class="p-btn p-btn-quiet">{{ $t('decline') }}</summary>
          <label class="p-field"><span>{{ $t('decline_why') }}</span><textarea rows="2" maxlength="500"></textarea></label>
          <button type="button" class="p-btn" @if ($vm['preview'] ?? false) disabled @endif>{{ $t('decline_send') }}</button>
        </details>
      </div>
    </article>
  @empty
    <p class="p-hint">{{ $t('invites_none') }}</p>
  @endforelse
</section>

<section class="s-section" aria-labelledby="up">
  <h2 id="up" class="s-h2">{{ $t('upcoming') }}</h2>
  @forelse ($accepted as $u)
    <a class="p-card s-job s-link" href="{{ $u['url'] }}">
      <p class="s-when">{{ $u['event']['date'] }} · {{ $u['event']['hours'] }}</p>
      <p class="s-line"><b>{{ $u['assignment']['role'] }}</b> · {{ $t('arrive') }} {{ $u['event']['arrival'] }} · {{ $u['event']['area'] }}</p>
      <span class="s-more">{{ $t('open') }} →</span>
    </a>
  @empty
    <p class="p-hint">{{ $t('upcoming_none') }}</p>
  @endforelse
</section>
@endsection
