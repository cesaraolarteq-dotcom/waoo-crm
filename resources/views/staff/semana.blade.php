@extends('staff.layout')
@php($t = fn ($k, $r = []) => \App\Support\StaffCopy::get($vm['lang'], $k, $r))
@section('content')
<h1 class="p-h1">{{ $t('hello', ['name' => $vm['staff']['display_name']]) }}</h1>
<p class="p-sub">{{ $t('week') }} · {{ $t('week_range', ['from' => $vm['week']['from'], 'to' => $vm['week']['to']]) }}</p>

@php($ask = $vm['ask'] ?? null)
@if (session('staff_saved'))<p class="p-notice p-notice-verified" role="status">{{ $t('saved') }}</p>@endif
@if (isset($errors) && $errors->any())<p class="p-notice p-notice-pending" role="alert">{{ $errors->has('sat') ? $t('avail_need_sat') : $errors->first() }}</p>@endif
@if ($ask)
  {{-- Owner 2026-11-03: Saturday first and bigger; it is the day with most parties. --}}
  <form method="post" action="{{ url('/staff/disponibilidad') }}" class="p-card s-avail" @if ($ask['answered']) hidden id="s-avail-form" @endif>
    @csrf
    <input type="hidden" name="week" value="{{ $ask['week'] }}">
    <h2 class="s-when">{{ $t('avail_h') }}</h2>
    <p class="p-hint">{{ $t('avail_sub', ['from' => $ask['days']['fri']['label']]) }}</p>
    @foreach ($ask['days'] as $key => $d)
      <fieldset @class(['s-day', 's-day-main' => $key === 'sat'])>
        {{-- Owner 2026-11-03: only Saturday says how many parties there are (staff should not pick days by how busy they look). --}}
        <legend><b>{{ ucfirst($d['label']) }}</b>@if ($key === 'sat') · <span>{{ $d['parties'] === 0 ? $t('avail_none') : ($d['parties'] === 1 ? $t('avail_party') : $t('avail_parties', ['n' => $d['parties']])) }}</span>@endif</legend>
        @if ($key === 'sat')<p class="s-sat">{{ $t('avail_sat') }}</p>@endif
        <div class="s-yn">
          <label><input type="radio" name="days[{{ $key }}]" value="yes" @checked($d['answer'] === 'yes') @if ($key === 'sat') required @endif><span>{{ $t('yes') }}</span></label>
          <label><input type="radio" name="days[{{ $key }}]" value="no" @checked($d['answer'] === 'no')><span>{{ $t('no') }}</span></label>
        </div>
      </fieldset>
    @endforeach
    <label class="s-note"><span class="p-hint">{{ $t('avail_note') }}</span><input type="text" name="note" maxlength="300" value="{{ $ask['note'] }}"></label>
    <button type="submit" class="p-btn p-btn-pink">{{ $t('avail_send') }}</button>
  </form>
  @if ($ask['answered'])
    <section class="p-card s-avail-done">
      <p class="s-line">{{ $t('avail_done') }}</p>
      <p class="p-hint">@foreach ($ask['days'] as $key => $d)@if ($d['answer']){{ ucfirst($d['label']) }}: <b>{{ $d['answer'] === 'yes' ? $t('yes') : $t('no') }}</b>{{ $loop->last ? '' : ' · ' }}@endif @endforeach</p>
      <button type="button" class="p-btn p-btn-quiet" onclick="document.getElementById('s-avail-form').hidden=false;this.closest('section').hidden=true">{{ $t('change') }}</button>
    </section>
  @endif
@endif

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
        @if(!empty($j['honeybook']['fields']))
            <div class="s-line"><b>{{ $vm['lang'] === 'es' ? 'Fuente HoneyBook' : 'HoneyBook source' }}</b>
            @foreach($j['honeybook']['fields'] as $field=>$value)
                @if($value !== null && $value !== '')<p>{{ ['child_name'=>'Festejado/a / Celebrant','child_age'=>'Edad / Age','children_count'=>'Niños / Children','setup_area'=>'Área / Area','event_language'=>'Idioma / Language','notes'=>'Peticiones / Requests'][$field] }}: {{ $value }}</p>@endif
            @endforeach</div>
        @endif
        @if ($j['instructions'])<p class="s-line"><b>{{ $t('notes') }}:</b> {{ $j['instructions'] }}</p>@endif
      </div>
    @endif

    {{-- Owner 2026-11-03: each person confirms the party they were given; "No puedo" tells César and Maybeli to replace them. --}}
    @if (isset($j['event_id']))
    <div class="s-block s-confirm">
      @if (($j['answer'] ?? null) === 'yes')
        <p class="p-notice p-notice-verified">{{ $t('confirmed') }}</p>
      @elseif (($j['answer'] ?? null) === 'no')
        <p class="p-notice p-notice-pending">{{ $t('declined') }}</p>
      @else
        <p class="s-line"><b>{{ $t('confirm_q') }}</b></p>
      @endif
      <form method="post" action="{{ url('/staff/respuesta') }}" class="s-yn s-yn-btn" @if ($j['answer'] ?? null) hidden @endif>
        @csrf
        <input type="hidden" name="event_id" value="{{ $j['event_id'] }}">
        <button type="submit" name="answer" value="yes" class="p-btn p-btn-pink">{{ $t('confirm_yes') }}</button>
        <button type="submit" name="answer" value="no" class="p-btn p-btn-quiet">{{ $t('confirm_no') }}</button>
      </form>
      @if ($j['answer'] ?? null)<button type="button" class="s-link" onclick="this.previousElementSibling.hidden=false;this.hidden=true">{{ $t('change') }}</button>@endif
    </div>
    @endif

    <p class="p-hint"><b>{{ $t('contact') }}:</b> @if ($j['day_contact']){{ $j['day_contact']['name'] }} · {{ $j['day_contact']['phone'] }}@else{{ $t('contact_later') }}@endif</p>
  </article>
@empty
  <section class="p-card"><p class="s-line">{{ $t('none') }}</p></section>
@endforelse

<p class="p-hint">{{ $t('saved_link') }} {{ $t('updated', ['when' => $vm['updated_at']]) }}</p>
@endsection
