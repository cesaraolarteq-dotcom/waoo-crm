@extends('portal.layout')
{{-- QUOTE-01 B client page. $vm is the WHITELISTED client projection only (number, revision, validity, services with
     character labels, event details, discount labels + amounts, totals, state, consent from approved config).
     Never owner notes, discount reasons, route evidence, recipes or audit data. --}}
@php
    $t = fn ($k, $r = []) => \App\Support\QuoteCopy::get($vm['lang'], $k, $r);
    $q = $vm['quote'];
@endphp
@section('content')
<h1 class="p-h1">{{ $t('hello', ['name' => $vm['first_name']]) }}</h1>
<p class="p-sub">{{ $t('number', ['number' => $q['number'], 'rev' => $q['revision']]) }} · {{ $t('valid', ['date' => $q['valid_until']]) }}</p>

@if ($q['state'] === 'open')<p class="p-hint">{{ $t('intro') }}</p>@endif

<section class="p-card p-party" aria-label="{{ $t('party') }}">
  <h2>{{ $t('party') }}</h2>
  <dl>
    <div><dt>{{ $t('when') }}</dt><dd>{{ $q['when'] }}</dd></div>
    @if ($q['where'])<div><dt>{{ $t('where') }}</dt><dd>{{ $q['where'] }}</dd></div>@endif
  </dl>
</section>

<section class="p-card" aria-label="{{ $t('services') }}">
  <h2>{{ $t('services') }}</h2>
  <ul class="q-lines">
    @foreach ($q['lines'] as $l)
      <li><span><b>{{ $l['name'] }}</b>@if ($l['duration']) <small>· {{ $l['duration'] }}</small>@endif
        @if ($l['characters'])<br><small>{{ $l['characters'] }}</small>@endif</span><b>{{ $l['amount'] }}</b></li>
    @endforeach
    <li><span>{{ $t('travel') }}</span><b>{{ $q['travel'] }}</b></li>
    @foreach ($q['discounts'] as $d)
      <li class="q-disc"><span>{{ $d['label'] }}</span><b>−{{ $d['amount'] }}</b></li>
    @endforeach
    <li class="q-total"><span>{{ $t('total') }}</span><b>{{ $q['total'] }}</b></li>
  </ul>
  <p class="p-hint"><b>{{ $t('deposit') }}:</b> {{ $t('deposit_hint', ['amount' => $q['deposit']]) }}</p>
  @if ($q['pdf_url'])<a class="p-btn p-btn-quiet" href="{{ $q['pdf_url'] }}" target="_blank" rel="noopener noreferrer">{{ $t('pdf') }}</a>@endif
</section>

@if ($q['state'] === 'open')
<form class="p-card p-form" id="quote-accept" method="POST" action="{{ $vm['actions']['accept'] }}" data-revision="{{ $q['revision'] }}" data-consent-version="{{ $vm['consent']['version'] ?? '' }}">
  @csrf
  <h2>{{ $t('accept_title') }}</h2>
  <label class="p-field"><span>{{ $t('name') }}</span><input name="signer_name" autocomplete="name" required value="{{ $vm['prefill']['name'] ?? '' }}"></label>
  <label class="p-field"><span>{{ $t('email') }}</span><input name="email" type="email" autocomplete="email" required value="{{ $vm['prefill']['email'] ?? '' }}"></label>
  @if (! empty($vm['consent']['text']))
    <label class="p-check"><input type="checkbox" name="consent" value="1" required> <span>{{ $vm['consent']['text'] }}</span></label>
  @endif
  <p class="p-hint">{{ $t('accept_note') }}</p>
  <p class="p-test" id="quote-error" role="alert" hidden></p>
  <button class="p-btn p-btn-pink" type="submit" @disabled(empty($vm['consent']['text']))>{{ $t('accept') }}</button>
  <p class="p-hint">{{ $t('changes') }}</p>
  <details class="q-reject"><summary>{{ $t('reject') }}</summary>
    <label class="p-field"><span>{{ $t('reject_reason') }}</span><textarea name="reason" rows="2" maxlength="1000"></textarea></label>
    <button class="p-btn p-btn-quiet" type="button" id="quote-reject" data-action="{{ $vm['actions']['reject'] }}">{{ $t('reject_send') }}</button>
  </details>
</form>
@else
<section class="p-card p-done"><h2>{{ $t($q['state'].'_title') }}</h2><p>{{ $t($q['state']) }}</p></section>
@endif

@if (! ($vm['preview'] ?? false))
<script>
(function () {
  var f = document.getElementById('quote-accept'); if (!f) return;
  var csrf = document.querySelector('meta[name=csrf-token]').content;
  function key(name) { try { var k = sessionStorage.getItem(name); if (!k) { k = crypto.randomUUID(); sessionStorage.setItem(name, k); } return k; } catch (e) { return crypto.randomUUID(); } }
  function post(url, body, idem) {
    return fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'Idempotency-Key': idem}, body: JSON.stringify(body)})
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw new Error(j.message || @js($t('error'))); return j; }); });
  }
  var err = document.getElementById('quote-error');
  f.addEventListener('submit', function (ev) {
    ev.preventDefault(); err.hidden = true; var b = f.querySelector('button[type=submit]'); b.disabled = true;
    post(f.action, {expected_revision: Number(f.dataset.revision), signer_name: f.signer_name.value.trim(), email: f.email.value.trim(),
      consent: f.consent ? f.consent.checked : false, consent_version: f.dataset.consentVersion}, key('waoo-quote-accept-' + f.dataset.revision))
      .then(function () { try { sessionStorage.removeItem('waoo-quote-accept-' + f.dataset.revision); } catch (e) {} location.reload(); })
      .catch(function (e) { err.textContent = e.message; err.hidden = false; b.disabled = false; });
  });
  var r = document.getElementById('quote-reject');
  if (r) r.addEventListener('click', function () {
    r.disabled = true;
    post(r.dataset.action, {expected_revision: Number(f.dataset.revision), reason: f.reason.value.trim() || null}, key('waoo-quote-reject-' + f.dataset.revision))
      .then(function () { location.reload(); }).catch(function (e) { err.textContent = e.message; err.hidden = false; r.disabled = false; });
  });
})();
</script>
@endif
@endsection
