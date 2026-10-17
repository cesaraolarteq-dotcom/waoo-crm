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
      $stepKey = $vm['step'] === 'details_sent' ? 'details' : ($vm['step'] === 'waiting' && in_array('details', $order, true) ? 'contract' : $vm['step']);
      $cur = $stepKey === 'done' ? count($order) : (array_search($stepKey, $order) === false ? -1 : array_search($stepKey, $order));
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
    @if ($e['characters'] ?? '')<div><dt>{{ $vm['lang'] === 'es' ? 'Personajes' : 'Characters' }}</dt><dd>{{ $e['characters'] }}</dd></div>@endif
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

@if ($vm['step'] === 'details_sent')
<section class="p-card"><h2>{{ $t('details_sent_title') }}</h2><p class="p-hint">{{ $t('details_sent') }}</p></section>
@elseif ($vm['step'] === 'waiting')
<section class="p-card"><h2>{{ $t('waiting_title') }}</h2><p class="p-hint">{{ $t('waiting') }}</p></section>
@elseif ($vm['step'] === 'details')
<form class="p-card p-form" method="POST" action="{{ $vm['details']['action'] ?? $vm['actions']['details'] }}" id="details-form"
  data-event-version="{{ $vm['details']['expected_event_version'] ?? '' }}" data-revision="{{ $vm['details']['expected_revision'] ?? '' }}"
  data-lang="{{ $vm['lang'] }}">
  @csrf
  <h2>{{ $t('details_title') }}</h2>
  <p class="p-hint">{{ $t('details_hint') }}</p>
  <div class="p-row">
    <label class="p-field"><span>{{ $t('first_name') }}</span><input name="first_name" autocomplete="given-name" required value="{{ old('first_name', $vm['prefill']['first_name'] ?? '') }}"></label>
    <label class="p-field"><span>{{ $t('last_name') }}</span><input name="last_name" autocomplete="family-name" required value="{{ old('last_name', $vm['prefill']['last_name'] ?? '') }}"></label>
  </div>
  <div class="p-row">
    <label class="p-field"><span>{{ $t('phone') }}</span><input name="phone" type="tel" autocomplete="tel" inputmode="tel" required value="{{ old('phone', $vm['prefill']['phone'] ?? '') }}"></label>
    <label class="p-field"><span>{{ $t('email') }}</span><input name="email" type="email" autocomplete="email" inputmode="email" required value="{{ old('email', $vm['prefill']['email'] ?? '') }}"></label>
  </div>
  <label class="p-field"><span>{{ $t('billing_address') }}</span><input name="billing_address" autocomplete="street-address" required value="{{ old('billing_address', $vm['prefill']['billing_address'] ?? '') }}"></label>
  <label class="p-check"><input type="checkbox" name="same_address" value="1" @if (empty($vm['prefill']['event_address'])) checked @endif> {{ $t('same_address') }}</label>
  <div class="p-row">
    <label class="p-field"><span>{{ $t('event_address') }}</span><input name="event_address" autocomplete="off" value="{{ old('event_address', $vm['prefill']['event_address'] ?? '') }}"></label>
    <label class="p-field p-narrow"><span>ZIP</span><input name="event_postal_code" inputmode="numeric" maxlength="10" value="{{ $vm['prefill']['event_postal_code'] ?? '' }}"></label>
  </div>
  <fieldset class="p-field">
    <legend>{{ $t('start_confirm', ['time' => $e['start_time']]) }}</legend>
    <label class="p-check"><input type="radio" name="start_time_confirmed" value="1" checked> {{ $t('start_yes') }}</label>
    <label class="p-check"><input type="radio" name="start_time_confirmed" value="0"> {{ $t('start_no') }}</label>
    <label class="p-field p-sub-field"><span>{{ $t('requested_time') }}</span><input type="time" name="requested_time"><small>{{ $t('requested_hint') }}</small></label>
    <label class="p-field p-sub-field"><span>{{ $t('requested_occurrence') }}</span><select name="requested_time_choice"><option value="">{{ $t('requested_occurrence_default') }}</option><option value="cdt">{{ $t('requested_occurrence_first') }}</option><option value="cst">{{ $t('requested_occurrence_second') }}</option></select><small>{{ $t('requested_occurrence_hint') }}</small></label>
  </fieldset>
  <div class="p-row">
    <label class="p-field"><span>{{ $t('child_name') }} <em>({{ $t('optional') }})</em></span><input name="child_name"></label>
    <label class="p-field p-narrow"><span>{{ $t('child_age') }} <em>({{ $t('optional') }})</em></span><input name="child_age" inputmode="numeric" pattern="[0-9]*" maxlength="2"></label>
  </div>
  <label class="p-field"><span>{{ $t('theme') }} <em>({{ $t('optional') }})</em></span><input name="theme"></label>
  <label class="p-field"><span>{{ $t('notes') }} <em>({{ $t('optional') }})</em></span><textarea name="notes" rows="3"></textarea></label>
  @if (! empty($vm['details']['sms_consent']))
  <label class="p-check p-small"><input type="checkbox" name="sms_consent" value="1" data-version="{{ $vm['details']['sms_consent']['version'] }}"> {{ $vm['details']['sms_consent']['text'] }}</label>
  @endif
  <p class="p-test" id="details-error" role="alert" hidden></p>
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
      @foreach ($vm['zelle']['notices'] ?? [] as $n)
        <p class="p-notice p-notice-{{ $n['state'] }}" role="status">{{ $t('zelle_state_'.$n['state'], ['amount' => $n['amount'], 'verified' => $n['verified'] ?? '', 'when' => $n['when']]) }}</p>
      @endforeach
      @if ($vm['zelle']['can_notify'] ?? false)
      @php($hasPending = collect($vm['zelle']['notices'])->contains('state', 'pending'))
      <details class="p-zelle" @if (! $hasPending) open @endif>
        <summary>{{ $hasPending ? $t('zelle_again') : $t('zelle_sent_btn') }}</summary>
        <form class="p-form p-zelle-form" method="POST" action="{{ $vm['zelle']['action'] }}" id="zelle-form" data-revision="{{ $vm['pay']['revision_id'] ?? '' }}" novalidate>
          @csrf
          <label class="p-field"><span>{{ $t('zelle_amount_sent') }} (USD)</span><input name="amount" inputmode="decimal" value="{{ $vm['zelle']['amount'] }}" required autocomplete="off"></label>
          <label class="p-field"><span>{{ $t('zelle_payer') }} <em>({{ $t('optional') }})</em></span><input name="payer_name" maxlength="120" autocomplete="name"></label>
          <label class="p-field"><span>{{ $t('zelle_ref') }} <em>({{ $t('optional') }})</em></span><input name="reference" maxlength="255" autocomplete="off"></label>
          <p class="p-test" id="zelle-error" role="alert" hidden></p>
          <button class="p-btn" type="submit">{{ $t('zelle_sent_btn') }}</button>
        </form>
      </details>
      @endif
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
  var det = document.getElementById('details-form');
  if (det) det.addEventListener('submit', function (ev) {
    ev.preventDefault(); var err = document.getElementById('details-error'); err.hidden = true;
    var f = det, b = f.querySelector('button[type=submit]'); b.disabled = true;
    var same = f.same_address.checked, confirmed = f.querySelector('input[name=start_time_confirmed]:checked').value === '1';
    var body = {expected_event_version: Number(f.dataset.eventVersion), expected_revision: Number(f.dataset.revision),
      first_name: f.first_name.value.trim(), last_name: f.last_name.value.trim(), phone: f.phone.value.trim(), email: f.email.value.trim(),
      billing_address: f.billing_address.value.trim(), event_address: same ? f.billing_address.value.trim() : f.event_address.value.trim(),
      locale: f.dataset.lang, start_time_confirmed: confirmed};
    if (f.event_postal_code.value.trim()) body.event_postal_code = f.event_postal_code.value.trim();
    // Send a Houston wall time; the server resolves the event date and DST occurrence.
    if (!confirmed) { if (!f.requested_time.value) { err.textContent = @js($t('requested_missing')); err.hidden = false; b.disabled = false; return; }
      body.requested_local_time = f.requested_time.value;
      if (f.requested_time_choice.value) body.requested_time_choice = f.requested_time_choice.value; }
    ['child_name', 'theme', 'notes'].forEach(function (k) { if (f[k] && f[k].value.trim()) body[k] = f[k].value.trim(); });
    if (f.child_age && f.child_age.value.trim()) body.child_age = Number(f.child_age.value);
    if (f.sms_consent && f.sms_consent.checked) { body.sms_consent = true; body.sms_consent_version = f.sms_consent.dataset.version; }
    post(f.action, body, key('waoo-details-' + f.dataset.revision))
      .then(function () { try { sessionStorage.removeItem('waoo-details-' + f.dataset.revision); } catch (e) {} location.reload(); })
      .catch(function (e) { err.textContent = e.message; err.hidden = false; b.disabled = false; });
  });
  var sign = document.getElementById('sign-form');
  if (sign) sign.addEventListener('submit', function (ev) {
    ev.preventDefault(); var err = document.getElementById('sign-error'); err.hidden = true;
    var b = sign.querySelector('button[type=submit]'); b.disabled = true;
    post(sign.action, {expected_version: Number(sign.dataset.version), presented_pdf_sha256: sign.presented_pdf_sha256.value, signer_name: sign.signer_name.value.trim(),
      consent: sign.consent.checked, intent_to_sign: sign.intent_to_sign.checked, consent_version: sign.dataset.consentVersion}, key('waoo-sign-' + sign.dataset.version))
      .then(function () { try { sessionStorage.removeItem('waoo-sign-' + sign.dataset.version); } catch (e) {} location.reload(); })
      .catch(function (e) { err.textContent = e.message; err.hidden = false; b.disabled = false; });
  });
  var zf = document.getElementById('zelle-form');
  if (zf) zf.addEventListener('submit', function (ev) {
    ev.preventDefault(); var err = document.getElementById('zelle-error'); err.hidden = true;
    var raw = zf.amount.value.replace(/[$,\s]/g, ''), b = zf.querySelector('button[type=submit]');
    if (!/^\d{1,7}(\.\d{1,2})?$/.test(raw) || Number(raw) <= 0) { err.textContent = @js($t('zelle_amount_bad')); err.hidden = false; return; }
    b.disabled = true;
    var body = {financial_revision_id: Number(zf.dataset.revision), amount_cents: Math.round(Number(raw) * 100)};
    if (zf.payer_name.value.trim()) body.payer_name = zf.payer_name.value.trim();
    if (zf.reference.value.trim().length >= 3) body.reference = zf.reference.value.trim();
    // One key per attempt: a double tap or retry after a timeout sends one notice. A notice is never a payment.
    post(zf.action, body, key('waoo-zelle-' + zf.dataset.revision))
      .then(function () { try { sessionStorage.removeItem('waoo-zelle-' + zf.dataset.revision); } catch (e) {} location.reload(); })
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
