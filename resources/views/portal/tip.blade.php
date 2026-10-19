@extends('portal.layout')
{{-- TIP-01 client tip page. $vm is a whitelisted projection: first name, party date, team first names, party total and
     the server-computed tip options (5/10/15 % rounded to whole dollars). The server re-computes every amount; the
     browser only sends the chosen option or a custom amount. Card fees are never shown (Waoo absorbs them). --}}
@php($t = fn ($k, $r = []) => \App\Support\TipCopy::get($vm['lang'], $k, $r))
@php($tip = $vm['tip'])
@section('content')
@if (!($vm['preview'] ?? false))<script>if(location.hash){location.replace('/portal/tip?exchange=1'+location.hash);}</script>@endif
@if ($tip['state'] === 'paid')
  <section class="p-card p-done"><h2>{{ $t('thanks_title') }}</h2><p>{{ $t('thanks') }}</p>
    @if ($vm['review_url'])<a class="p-btn p-btn-pink" href="{{ $vm['review_url'] }}" target="_blank" rel="noopener noreferrer">{{ $t('review') }}</a>@endif
  </section>
@elseif ($tip['state'] !== 'open')
  <section class="p-card p-done"><h2>{{ $t('closed_title') }}</h2><p>{{ $t('closed') }}</p></section>
@else
<h1 class="p-h1">{{ $t('hello', ['name' => $vm['first_name']]) }}</h1>
<p class="p-sub">{{ $t('party', ['when' => $tip['when']]) }}@if ($tip['team']) · {{ $t('team', ['names' => $tip['team']]) }}@endif</p>
<p class="p-hint">{{ $t('intro') }}</p>

<form class="p-card p-form" id="tip-form" action="{{ $vm['actions']['checkout'] }}" method="post" novalidate
      data-preview="{{ ($vm['preview'] ?? false) ? '1' : '0' }}" data-revision="{{ $tip['financial_revision_id'] ?? '' }}" data-snapshot="{{ $tip['snapshot_sha256'] ?? '' }}">
  <h2>{{ $t('choose') }}</h2>
  <div class="t-opts" role="radiogroup" aria-label="{{ $t('choose') }}">
    @foreach ($tip['options'] as $o)
      <label class="t-opt"><input type="radio" name="option" value="{{ $o['key'] }}" data-amount="{{ $o['amount'] }}">
        <span><b>{{ $o['percent'] }} %</b><small>{{ $o['amount'] }}</small></span></label>
    @endforeach
    <label class="t-opt"><input type="radio" name="option" value="custom"><span><b>{{ $t('other') }}</b><small>$</small></span></label>
  </div>
  <p class="p-hint">{{ $t('pct_hint', ['total' => $tip['total']]) }}</p>
  <label class="p-field" id="tip-custom" hidden><span>{{ $t('other') }}</span>
    <input name="custom_amount" inputmode="decimal" autocomplete="off" placeholder="25" aria-describedby="tip-custom-hint">
    <small id="tip-custom-hint">{{ $t('other_hint') }}</small></label>
  <p class="p-test" id="tip-error" role="alert" hidden></p>
  <button class="p-btn p-btn-pink" type="submit" id="tip-pay" disabled>{{ $t('pay_none') }}</button>
  <p class="p-hint t-center">{{ $t('card') }}<br>{{ $t('optional') }}</p>
</form>

<script>
(function () {
  // TIP-01 API (docs/TIP-01-API.md): the browser sends only the option (or a canonical custom amount) with the pinned
  // revision + snapshot; the server computes and charges. A verified Stripe Checkout URL is the only redirect target.
  var f = document.getElementById('tip-form'), pay = document.getElementById('tip-pay'), custom = document.getElementById('tip-custom');
  var input = f.elements['custom_amount'], err = document.getElementById('tip-error');
  var label = @js($t('pay', ['amount' => '__A__'])), none = @js($t('pay_none'));
  function canonical() { var v = String(input.value).trim().replace(/^\$\s*/, ''); return /^\d{1,4}(\.\d{1,2})?$/.test(v) && Number(v) >= 1 && Number(v) <= 2000 ? v : null; }
  function chosen() { return f.querySelector('input[name=option]:checked'); }
  function amount() {
    var o = chosen(); if (!o) return null;
    if (o.value !== 'custom') return o.dataset.amount;
    var v = canonical(); return v ? '$' + Number(v).toLocaleString('en-US', {minimumFractionDigits: v.indexOf('.') > -1 ? 2 : 0, maximumFractionDigits: 2}) : null;
  }
  function refresh() {
    var o = chosen(); custom.hidden = !(o && o.value === 'custom');
    var a = amount(); pay.disabled = !a; pay.textContent = a ? label.replace('__A__', a) : none; err.hidden = true;
  }
  function fail(m) { refresh(); err.textContent = m; err.hidden = false; }
  // One key per exact payload, kept across an uncertain failure so a retry never opens a second checkout.
  function keyFor(json) {
    var slot = 'waoo-tip:' + f.dataset.revision, saved = null;
    try { saved = JSON.parse(sessionStorage.getItem(slot) || 'null'); } catch (e) {}
    if (saved && saved.body === json) return saved.key;
    var k = crypto.randomUUID(); try { sessionStorage.setItem(slot, JSON.stringify({key: k, body: json})); } catch (e) {}
    return k;
  }
  f.addEventListener('change', refresh); input.addEventListener('input', refresh);
  f.addEventListener('submit', function (e) {
    if (!amount()) { e.preventDefault(); return fail(@js($t('error_amount'))); }
    if (f.dataset.preview === '1') { pay.disabled = true; return; } // design preview: plain form post to the thanks screen
    e.preventDefault(); pay.disabled = true;
    var o = chosen(), body = {financial_revision_id: Number(f.dataset.revision), snapshot_sha256: f.dataset.snapshot, option: o.value};
    if (o.value === 'custom') body.custom_amount = canonical();
    var json = JSON.stringify(body), csrf = document.querySelector('meta[name=csrf-token]').content;
    fetch(f.action, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'Idempotency-Key': keyFor(json)}, body: json})
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) {
        if (r.ok) {
          if(j.status==='paid' && /^[a-f0-9-]{36}$/i.test(j.intent_id||'')){location.replace('/portal/tip?checkout='+encodeURIComponent(j.intent_id));return;}
          var u = null; try { u = new URL(j.checkout_url); } catch (x) {}
          if (u && u.protocol === 'https:' && u.hostname === 'checkout.stripe.com') { location.assign(u.href); return; }
          return fail(@js($t('error')));
        }
        if (r.status === 409) return fail(@js($t('stale')));
        if (r.status < 500) { try { sessionStorage.removeItem('waoo-tip:' + f.dataset.revision); } catch (x) {} }
        var first = j.errors ? Object.values(j.errors)[0] : null;
        fail((first && first[0]) || j.message || @js($t('error')));
      }); })
      .catch(function () { fail(@js($t('error'))); });
  });
  refresh();
})();
</script>
@endif
@endsection
