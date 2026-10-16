@extends('portal.layout')
@php
    $t = fn ($k, $r = []) => \App\Support\PortalCopy::get($vm['lang'], $k, $r);
    $e = $vm['event'];
@endphp
@section('content')
<h1 class="p-h1">{{ $t('hello', ['name' => $vm['first_name']]) }}</h1>
@if ($vm['step'] !== 'done')<p class="p-sub">{{ $t('intro') }}</p>@endif

<ol class="p-steps" aria-label="Pasos">
  @foreach ($t('steps') as $key => $label)
    @php
        $i = array_search($key, ['details', 'contract', 'pay']);
        $cur = array_search($vm['step'], ['details', 'contract', 'pay', 'done']);
    @endphp
    <li @class(['done' => $cur > $i, 'on' => $cur === $i])><span>{{ $cur > $i ? '✓' : $i + 1 }}</span>{{ $label }}</li>
  @endforeach
</ol>

<section class="p-card p-party" aria-label="{{ $t('your_party') }}">
  <h2>{{ $t('your_party') }}</h2>
  <dl>
    <div><dt>{{ $t('package') }}</dt><dd>{{ $e['package'] }}</dd></div>
    <div><dt>{{ $t('when') }}</dt><dd>{{ $e['when'] }}</dd></div>
    <div><dt>{{ $t('total') }}</dt><dd>{{ $e['total'] }} <small>({{ $t('travel') }} {{ $e['travel'] }})</small></dd></div>
    <div><dt>{{ $t('paid') }}</dt><dd class="ok">{{ $e['paid'] }}</dd></div>
    <div><dt>{{ $t('balance') }}</dt><dd>{{ $e['balance'] }} <small>· {{ $t('due') }} {{ $e['due'] }}</small></dd></div>
  </dl>
</section>

@if ($vm['step'] === 'details')
<form class="p-card p-form" method="POST" action="{{ $vm['actions']['details'] }}">
  @csrf
  <h2>{{ $t('details_title') }}</h2>
  <p class="p-hint">{{ $t('details_hint') }}</p>
  <div class="p-row">
    <label class="p-field"><span>{{ $t('first_name') }}</span><input name="first_name" autocomplete="given-name" required value="{{ old('first_name', $vm['prefill']['first_name'] ?? '') }}"></label>
    <label class="p-field"><span>{{ $t('last_name') }}</span><input name="last_name" autocomplete="family-name" required value="{{ old('last_name', $vm['prefill']['last_name'] ?? '') }}"></label>
  </div>
  <div class="p-row">
    <label class="p-field"><span>{{ $t('phone') }}</span><input name="phone" type="tel" autocomplete="tel" inputmode="tel" required value="{{ old('phone', $vm['prefill']['phone'] ?? '') }}"></label>
    <label class="p-field"><span>{{ $t('email') }}</span><input name="email" type="email" autocomplete="email" inputmode="email" required value="{{ old('email') }}"></label>
  </div>
  <label class="p-field"><span>{{ $t('billing_address') }}</span><input name="billing_address" autocomplete="street-address" required value="{{ old('billing_address') }}"></label>
  <label class="p-check"><input type="checkbox" name="same_address" value="1" checked> {{ $t('same_address') }}</label>
  <label class="p-field"><span>{{ $t('event_address') }}</span><input name="event_address" autocomplete="off" value="{{ old('event_address') }}"></label>
  <fieldset class="p-field">
    <legend>{{ $t('start_confirm', ['time' => $e['start_time']]) }}</legend>
    <label class="p-check"><input type="radio" name="start_time_confirmed" value="1" checked> {{ $t('start_yes') }}</label>
    <label class="p-check"><input type="radio" name="start_time_confirmed" value="0"> {{ $t('start_no') }}</label>
    <label class="p-field p-sub-field"><span>{{ $t('requested_time') }}</span><input type="time" name="requested_time"><small>{{ $t('requested_hint') }}</small></label>
  </fieldset>
  <div class="p-row">
    <label class="p-field"><span>{{ $t('child_name') }} <em>({{ $t('optional') }})</em></span><input name="child_name"></label>
    <label class="p-field p-narrow"><span>{{ $t('child_age') }} <em>({{ $t('optional') }})</em></span><input name="child_age" inputmode="numeric" pattern="[0-9]*" maxlength="2"></label>
  </div>
  <label class="p-field"><span>{{ $t('theme') }} <em>({{ $t('optional') }})</em></span><input name="theme"></label>
  <label class="p-field"><span>{{ $t('notes') }} <em>({{ $t('optional') }})</em></span><textarea name="notes" rows="3"></textarea></label>
  <label class="p-check p-small"><input type="checkbox" name="sms_consent" value="1"> {{ $t('sms') }}</label>
  <button class="p-btn" type="submit">{{ $t('save_details') }}</button>
</form>
@elseif ($vm['step'] === 'contract')
<form class="p-card p-form" method="POST" action="{{ $vm['actions']['sign'] }}">
  @csrf
  <h2>{{ $t('contract_title') }}</h2>
  <p class="p-hint">{{ $t('contract_hint') }}</p>
  <a class="p-btn p-btn-quiet" href="{{ $vm['contract']['pdf_url'] }}" target="_blank" rel="noopener noreferrer">{{ $t('open_pdf') }}</a>
  <input type="hidden" name="presented_pdf_sha256" value="{{ $vm['contract']['sha256'] }}">
  <label class="p-field"><span>{{ $t('sign_name') }}</span><input name="signer_name" autocomplete="name" required class="p-sign"></label>
  <label class="p-check"><input type="checkbox" name="consent" value="1" required> {{ $t('consent') }}</label>
  <label class="p-check"><input type="checkbox" name="intent_to_sign" value="1" required> {{ $t('intent') }}</label>
  <button class="p-btn p-btn-pink" type="submit">{{ $t('sign') }}</button>
</form>
@elseif ($vm['step'] === 'pay')
<section class="p-card">
  <h2>{{ $t('pay_title') }}</h2>
  <p class="p-hint">{{ $t('pay_hint') }}</p>
  <div class="p-pay">
    <div class="p-opt">
      <h3>{{ $t('zelle') }}</h3>
      <dl>
        <div><dt>{{ $t('zelle_to') }}</dt><dd>Waoo Party LLC · 346-216-8739</dd></div>
        <div><dt>{{ $t('zelle_amount') }}</dt><dd><b>{{ $e['balance'] }}</b></dd></div>
        <div><dt>{{ $t('zelle_memo') }}</dt><dd><code>{{ $vm['zelle_memo'] }}</code></dd></div>
      </dl>
      <p class="p-hint">{{ $t('zelle_note') }}</p>
    </div>
    <form class="p-opt" method="POST" action="{{ $vm['actions']['checkout'] }}">
      @csrf
      <h3>{{ $t('card') }}</h3>
      <p class="p-hint">{{ $t('card_hint') }}</p>
      <button class="p-btn" type="submit">{{ $t('card_btn', ['amount' => $e['balance']]) }}</button>
    </form>
  </div>
</section>
@else
<section class="p-card p-done">
  <h2>{{ $t('done_title') }}</h2>
  <p>{{ $t('done', ['when' => $e['when']]) }}</p>
</section>
@endif
@endsection
