@php
    $vm = ['lang' => $lang, 'test_mode' => true, 'lang_url' => ['es' => url('/portal?lang=es'), 'en' => url('/portal?lang=en')]];
    $t = fn ($k, $r = []) => \App\Support\PortalCopy::get($lang, $k, $r);
@endphp
@extends('portal.layout')
@section('content')
<section class="p-card" id="landing">
  <h1 class="p-h1" id="landing-title">{{ $t('opening') }}</h1>
  <p class="p-hint" id="landing-text">{{ $t('opening_hint') }}</p>
</section>
<script>
(function () {
  var title = document.getElementById('landing-title'), text = document.getElementById('landing-text');
  var m = /(?:^|[#&])token=([A-Za-z0-9_-]{43})/.exec(location.hash || '');
  // Remove the secret from the address bar and history right away.
  try { history.replaceState(null, '', location.pathname + location.search); } catch (e) {}
  if (!m) { title.textContent = @js($t('link_missing')); text.textContent = @js($t('link_missing_hint')); return; }
  fetch('/portal/api/session', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content}, body: JSON.stringify({token: m[1]})})
    .then(function (r) { if (!r.ok) throw r; location.replace(location.pathname + location.search); })
    .catch(function () { title.textContent = @js($t('link_expired')); text.textContent = @js($t('link_expired_hint')); });
})();
</script>
@endsection
