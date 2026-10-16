@extends('portal.layout')
@php
    $t = fn ($k, $r = []) => \App\Support\PortalCopy::get($vm['lang'], $k, $r);
    $e = $vm['event'];
@endphp
@section('content')
<h1 class="p-h1">{{ $t('hello', ['name' => $vm['first_name']]) }}</h1>
@if ($vm['step'] !== 'done')<p class="p-sub">{{ $t('intro', ['n' => count($vm['steps'] ?? ['details', 'contract', 'pay'])]) }}</p>@endif

<ol class="p-steps" aria-label="Pasos">
  @php
      $order = $vm['steps'] ?? ['details', 'contract', 'pay'];
      $cur = $vm['step'] === 'done' ? count($order) : (array_search($vm['step'], $order) === false ? -1 : array_search($vm['step'], $order));
  @endphp
  @foreach ($order as $i => $key)
    @php($label = $t('steps')[$key])
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

@if (($vm['notice'] ?? null) === 'processing')
<p class="p-test" role="status">{{ $t('return_processing') }}</p>
@elseif (($vm['notice'] ?? null) === 'cancelled')
<p class="p-test" role="status">{{ $t('return_cancelled') }}</p>
@endif

@if ($vm['step'] === 'waiting')
<section class="p-card"><h2>{{ $t('waiting_title') }}</h2><p class="p-hint">{{ $t('waiting') }}</p></section>
@elseif ($vm['step'] === 'details')
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
<form class="p-card p-form" method="POST" action="{{ $vm['actions']['sign'] }}" id="sign-form" data-version="{{ $vm['contract']['version'] ?? '' }}" data-consent-version="{{ $vm['consent']['version'] ?? '' }}">
  @csrf
  <h2>{{ $t('contract_title') }}</h2>
  <p class="p-hint">{{ $t('contract_hint') }}</p>
  <a class="p-btn p-btn-quiet" href="{{ $vm['contract']['pdf_url'] }}" target="_blank" rel="noopener noreferrer">{{ $t('open_pdf') }}</a>
  <input type="hidden" name="presented_pdf_sha256" value="{{ $vm['contract']['sha256'] }}">
  <label class="p-field"><span>{{ $t('sign_name') }}</span><input name="signer_name" autocomplete="name" required class="p-sign"></label>
  <label class="p-check"><input type="checkbox" name="consent" value="1" required> <span>{{ $vm['consent']['text'] ?? $t('consent') }}@if ($vm['lang'] === 'es' && isset($vm['consent'])) <small class="p-small">({{ $t('consent') }})</small>@endif</span></label>
  <label class="p-check"><input type="checkbox" name="intent_to_sign" value="1" required> {{ $t('intent') }}</label>
  <p class="p-test" id="sign-error" role="alert" hidden></p>
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
    <form class="p-opt" method="POST" action="{{ $vm['actions']['checkout'] }}" id="pay-form" data-revision="{{ $vm['pay']['revision_id'] ?? '' }}" @if (isset($vm['pay']) && ! $vm['pay']['can_checkout']) hidden @endif>
      @csrf
      <h3>{{ $t('card') }}</h3>
      <p class="p-hint">{{ $t('card_hint') }}</p>
      <p class="p-test" id="pay-error" role="alert" hidden></p>
      <button class="p-btn" type="submit">{{ $t('card_btn', ['amount' => $e['balance']]) }}</button>
    </form>
  </div>
</section>
@else
<section class="p-card p-done">
  <h2>{{ $t('done_title') }}</h2>
  <p>{{ $t('done', ['when' => $e['when']]) }}</p>
  @if (! empty($vm['contract']['final_url']))<a class="p-btn p-btn-quiet" href="{{ $vm['contract']['final_url'] }}" target="_blank" rel="noopener noreferrer">{{ $t('open_signed') }}</a>@endif
</section>
@endif
@if (! ($vm['preview'] ?? false))
<script>
(function () {
  var csrf = document.querySelector('meta[name=csrf-token]').content;
  function key(name) { try { var k = sessionStorage.getItem(name); if (!k) { k = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random()); sessionStorage.setItem(name, k); } return k; } catch (e) { return crypto.randomUUID ? crypto.randomUUID() : String(Date.now()); } }
  function post(url, body, idem) {
    return fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'Idempotency-Key': idem}, body: JSON.stringify(body)})
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) { var m = j.message || (j.errors && Object.values(j.errors)[0][0]) || 'Error'; throw new Error(m); } return j; }); });
  }
  var sign = document.getElementById('sign-form');
  if (sign) sign.addEventListener('submit', function (ev) {
    ev.preventDefault(); var err = document.getElementById('sign-error'); err.hidden = true;
    var b = sign.querySelector('button[type=submit]'); b.disabled = true;
    post(sign.action, {expected_version: Number(sign.dataset.version), presented_pdf_sha256: sign.presented_pdf_sha256.value, signer_name: sign.signer_name.value.trim(),
      consent: sign.consent.checked, intent_to_sign: sign.intent_to_sign.checked, consent_version: sign.dataset.consentVersion}, key('waoo-sign-' + sign.dataset.version))
      .then(function () { try { sessionStorage.removeItem('waoo-sign-' + sign.dataset.version); } catch (e) {} location.reload(); })
      .catch(function (e) { err.textContent = e.message; err.hidden = false; b.disabled = false; });
  });
  var pay = document.getElementById('pay-form');
  if (pay) pay.addEventListener('submit', function (ev) {
    ev.preventDefault(); var err = document.getElementById('pay-error'); err.hidden = true;
    var b = pay.querySelector('button[type=submit]'); b.disabled = true;
    post(pay.action, {financial_revision_id: Number(pay.dataset.revision), purpose: 'balance'}, key('waoo-pay-' + pay.dataset.revision))
      .then(function (j) { if (j.checkout_url) location.href = j.checkout_url; else throw new Error(@js($t('pay_retry'))); })
      .catch(function (e) { err.textContent = e.message; err.hidden = false; b.disabled = false; });
  });
})();
</script>
@endif
@endsection
