@extends('staff.layout')
@php($t = fn ($k, $r = []) => \App\Support\StaffCopy::get($vm['lang'], $k, $r))
@section('content')
<h1 class="p-h1">{{ $t('hello', ['name' => $vm['first_name']]) }}</h1>

<section class="s-section" aria-labelledby="inv">
  <h2 id="inv" class="s-h2">{{ $t('invites') }}</h2>
  @forelse ($vm['invites'] as $i)
    <article class="p-card s-job s-invite">
      <p class="s-when">{{ $i['when'] }}</p>
      <dl class="s-dl">
        <div><dt>{{ $t('role') }}</dt><dd>{{ $i['role'] }}</dd></div>
        <div><dt>{{ $t('arrive') }}</dt><dd>{{ $i['arrive'] }}</dd></div>
        <div><dt>{{ $t('party') }}</dt><dd>{{ $i['party'] }}</dd></div>
        <div><dt>{{ $t('area') }}</dt><dd>{{ $i['area'] }}</dd></div>
      </dl>
      <p class="p-hint">{{ $t('address_after') }} {{ $t('answer_by', ['date' => $i['answer_by']]) }}</p>
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
  @forelse ($vm['upcoming'] as $u)
    <a class="p-card s-job s-link" href="{{ $u['url'] }}">
      <p class="s-when">{{ $u['when'] }}</p>
      <p class="s-line"><b>{{ $u['role'] }}</b> · {{ $t('arrive') }} {{ $u['arrive'] }} · {{ $u['party'] }}</p>
      <span class="s-more">{{ $t('open') }} →</span>
    </a>
  @empty
    <p class="p-hint">{{ $t('upcoming_none') }}</p>
  @endforelse
</section>
@endsection
