@extends('portal.layout')
{{-- TIP-01 client tip page. $vm is a whitelisted projection: first name, party date, team first names, party total and
     the server-computed tip options (5/10/15 % rounded to whole dollars). The server re-computes every amount; the
     browser only sends the chosen option or a custom amount. Card fees are never shown (Waoo absorbs them). --}}
@php($t = fn ($k, $r = []) => \App\Support\TipCopy::get($vm['lang'], $k, $r))
@php($tip = $vm['tip'])
@section('content')
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

<form class="p-card p-form" id="tip-form" action="{{ $vm['actions']['checkout'] }}" method="post" novalidate>
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
  var f = document.getElementById('tip-form'), pay = document.getElementById('tip-pay'), custom = document.getElementById('tip-custom');
  var input = f.elements['custom_amount'], err = document.getElementById('tip-error');
  var label = @js($t('pay', ['amount' => '__A__'])), none = @js($t('pay_none'));
  function amount() {
    var o = f.querySelector('input[name=option]:checked'); if (!o) return null;
    if (o.value !== 'custom') return o.dataset.amount;
    var v = Number(String(input.value).replace(/[$,\s]/g, ''));
    return v >= 1 && v <= 2000 ? '$' + (Math.round(v * 100) / 100).toLocaleString('en-US', {minimumFractionDigits: v % 1 ? 2 : 0, maximumFractionDigits: 2}) : null;
  }
  function refresh() {
    var o = f.querySelector('input[name=option]:checked');
    custom.hidden = !(o && o.value === 'custom');
    var a = amount(); pay.disabled = !a; pay.textContent = a ? label.replace('__A__', a) : none; err.hidden = true;
  }
  f.addEventListener('change', refresh); input.addEventListener('input', refresh);
  f.addEventListener('submit', function (e) { if (!amount()) { e.preventDefault(); err.textContent = @js($t('error_amount')); err.hidden = false; } else { pay.disabled = true; } });
  refresh();
})();
</script>
@endif
@endsection
