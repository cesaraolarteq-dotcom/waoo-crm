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
  @if ($q['pdf_url'] && $q['state'] !== 'open')<a class="p-btn p-btn-quiet" href="{{ $q['pdf_url'] }}" target="_blank" rel="noopener noreferrer">{{ $t('pdf') }}</a>@endif
</section>

@if ($q['state'] === 'open')
@php
    $d = $vm['decide'] ?? [];
    $canAccept = filled($vm['actions']['accept'] ?? null) && filled($d['accepted']['text'] ?? null);
    $canReject = filled($vm['actions']['reject'] ?? null) && filled($d['rejected']['text'] ?? null);
@endphp
{{-- Decision values are pinned from the same server read that rendered this page (never re-fetched on submit). --}}
<form class="p-card p-form" id="quote-decide" novalidate
      data-number="{{ $q['number'] }}" data-revision="{{ $q['revision'] }}"
      data-snapshot="{{ $d['snapshot_sha256'] ?? '' }}" data-pdf="{{ $d['pdf_sha256'] ?? '' }}"
      data-pdf-opened="{{ ($d['pdf_opened'] ?? false) ? '1' : '0' }}">
  <h2>{{ $t('accept_title') }}</h2>
  @if ($q['pdf_url'])
    <p class="p-hint" id="quote-pdf-first" @if ($d['pdf_opened'] ?? false) hidden @endif>{{ $t('pdf_first') }}</p>
    <a class="p-btn p-btn-quiet" id="quote-pdf" href="{{ $q['pdf_url'] }}" target="_blank" rel="noopener noreferrer">{{ $t('pdf') }}</a>
  @endif
  <label class="p-field"><span>{{ $t('name') }}</span><input name="name" autocomplete="name" required minlength="2" maxlength="255" value="{{ $vm['prefill']['name'] ?? '' }}" @disabled(! $canAccept && ! $canReject)></label>
  <label class="p-field"><span>{{ $t('email') }}</span><input name="email" type="email" autocomplete="email" required maxlength="255" value="{{ $vm['prefill']['email'] ?? '' }}" @disabled(! $canAccept && ! $canReject)></label>

  @if ($canAccept)
    <fieldset class="q-outcome" data-outcome="accepted" data-action="{{ $vm['actions']['accept'] }}"
              data-consent-version="{{ $d['accepted']['version'] }}" data-consent-sha="{{ $d['accepted']['sha256'] }}">
      <label class="p-check"><input type="checkbox" name="consent_accepted" value="1"> <span>{{ $d['accepted']['text'] }}</span></label>
      <p class="p-hint">{{ $t('accept_note') }}</p>
      <button class="p-btn p-btn-pink" type="button" data-submit disabled>{{ $t('accept') }}</button>
    </fieldset>
  @else
    <p class="p-hint" id="quote-locked">{{ $t('not_available') }}</p>
  @endif
  <p class="p-test" id="quote-error" role="alert" hidden></p>
  <p class="p-hint">{{ $t('changes') }}</p>

  @if ($canReject)
    <details class="q-reject"><summary>{{ $t('reject') }}</summary>
      <fieldset class="q-outcome" data-outcome="rejected" data-action="{{ $vm['actions']['reject'] }}"
                data-consent-version="{{ $d['rejected']['version'] }}" data-consent-sha="{{ $d['rejected']['sha256'] }}">
        <label class="p-check"><input type="checkbox" name="consent_rejected" value="1"> <span>{{ $d['rejected']['text'] }}</span></label>
        <button class="p-btn p-btn-quiet" type="button" data-submit disabled>{{ $t('reject_send') }}</button>
      </fieldset>
    </details>
  @endif
</form>
@else
<section class="p-card p-done"><h2>{{ $t($q['state'].'_title') }}</h2><p>{{ $t($q['state']) }}</p></section>
@endif

@if (! ($vm['preview'] ?? false))
<script>
(function () {
  var f = document.getElementById('quote-decide'); if (!f) return;
  var csrf = document.querySelector('meta[name=csrf-token]').content;
  var err = document.getElementById('quote-error'), pdfOpened = f.dataset.pdfOpened === '1';
  var pdf = document.getElementById('quote-pdf'), pdfFirst = document.getElementById('quote-pdf-first');
  var outcomes = Array.prototype.slice.call(f.querySelectorAll('.q-outcome'));
  // The server also refuses a decision until this link opened the PDF; this only avoids a useless attempt.
  if (pdf) pdf.addEventListener('click', function () { pdfOpened = true; if (pdfFirst) pdfFirst.hidden = true; refresh(); });
  function box(o) { return o.querySelector('input[type=checkbox]'); }
  function refresh() { outcomes.forEach(function (o) { o.querySelector('[data-submit]').disabled = !(pdfOpened && box(o).checked); }); }
  outcomes.forEach(function (o) { box(o).addEventListener('change', refresh); });
  // One key per quote number + revision + outcome, stored with the exact payload it was used for. An uncertain
  // failure keeps both, so a retry resends the same payload under the same key; any edit gets a new key.
  function slot(outcome) { return 'waoo-quote:' + f.dataset.number + ':' + f.dataset.revision + ':' + outcome; }
  function keyFor(outcome, body) {
    var json = JSON.stringify(body), saved = null;
    try { saved = JSON.parse(sessionStorage.getItem(slot(outcome)) || 'null'); } catch (e) {}
    if (saved && saved.body === json) return saved.key;
    var k = crypto.randomUUID(); try { sessionStorage.setItem(slot(outcome), JSON.stringify({key: k, body: json})); } catch (e) {}
    return k;
  }
  function forget(outcome) { try { sessionStorage.removeItem(slot(outcome)); } catch (e) {} }
  outcomes.forEach(function (o) {
    var b = o.querySelector('[data-submit]'), outcome = o.dataset.outcome;
    b.addEventListener('click', function () {
      err.hidden = true;
      var nm = f.elements['name'], em = f.elements['email'];
      if (!nm.value.trim() || !em.value.trim() || !em.checkValidity()) { err.textContent = @js($t('identity_required')); err.hidden = false; return; }
      var body = {expected_revision: Number(f.dataset.revision), snapshot_sha256: f.dataset.snapshot, pdf_sha256: f.dataset.pdf,
        consent_version: o.dataset.consentVersion, consent_sha256: o.dataset.consentSha, name: nm.value.trim(), email: em.value.trim(), consent: box(o).checked};
      var idem = keyFor(outcome, body); b.disabled = true;
      fetch(o.dataset.action, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
        'X-CSRF-TOKEN': csrf, 'Idempotency-Key': idem}, body: JSON.stringify(body)})
        .then(function (r) {
          return r.json().catch(function () { return {}; }).then(function (j) {
            if (r.ok) { forget(outcome); location.reload(); return; }
            // 4xx is a definite answer (nothing was recorded): a corrected attempt gets a fresh key. 5xx stays uncertain.
            if (r.status < 500) forget(outcome);
            var first = j.errors ? Object.values(j.errors)[0] : null;
            throw new Error((first && first[0]) || j.message || @js($t('error')));
          });
        })
        .catch(function (e) { err.textContent = e.message || @js($t('error')); err.hidden = false; refresh(); });
    });
  });
  refresh();
})();
</script>
@endif
@endsection
