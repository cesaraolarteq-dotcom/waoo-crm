{{-- EXTRAS-01 (owner 2026-11-02/03): add an extra or an additional character; the client reads the annex and ticks the box. --}}
@if (!empty($vm['extras']))
@php($es = $vm['lang'] === 'es')
<section class="p-card p-extras" aria-labelledby="extras-h">
  <h2 id="extras-h">{{ $es ? 'Agrega algo a tu fiesta' : 'Add something to your party' }}</h2>
  <p class="p-hint">{{ $es ? 'Se hace al mismo tiempo que tu servicio; no extiende la hora. Se suma a tu saldo. Disponible hasta 7 días antes.' : 'It runs at the same time as your service; it does not extend the time. It is added to your balance. Available up to 7 days before.' }}</p>
  @foreach ($vm['extras']['offers'] as $o)
    <details class="p-extra">
      <summary><span>{{ $o['name'] }}</span><b>{{ $o['amount'] }}</b></summary>
      @if ($o['description'])<p class="p-hint">{{ $o['description'] }}</p>@endif
      <form class="p-form p-extra-form" method="POST" action="{{ $vm['extras']['action'] }}" data-offer="{{ $o['key'] }}" data-sha="{{ $o['sha'] }}">
        <p class="p-annex">{{ $o['annex'] }}</p>
        <label class="p-check"><input type="checkbox" name="accept" value="1" required> {{ $es ? 'Leí y acepto agregar este extra. Entiendo que no se puede cancelar ni reembolsar.' : 'I have read and accept adding this add-on. I understand it cannot be cancelled or refunded.' }}</label>
        <p class="p-test" role="alert" hidden></p>
        <button class="p-btn p-btn-pink" type="submit" disabled>{{ $es ? 'Agregar a mi fiesta' : 'Add to my party' }}</button>
      </form>
    </details>
  @endforeach
</section>
@if (! ($vm['preview'] ?? false))
<script>
document.querySelectorAll('.p-extra-form').forEach(function (f) {
  var box = f.querySelector('input[name=accept]'), b = f.querySelector('button'), err = f.querySelector('[role=alert]');
  box.addEventListener('change', function () { b.disabled = !box.checked; });
  f.addEventListener('submit', function (ev) {
    ev.preventDefault(); if (!box.checked) return; b.disabled = true; err.hidden = true;
    fetch(f.action, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content},
      body: JSON.stringify({offer: f.dataset.offer, annex_sha256: f.dataset.sha, accept: true, lang: @js($vm['lang'])})})
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw new Error(j.message || (j.errors && Object.values(j.errors)[0][0]) || 'Error'); location.reload(); }); })
      .catch(function (e) { err.textContent = e.message; err.hidden = false; b.disabled = false; });
  });
});
</script>
@endif
@endif
