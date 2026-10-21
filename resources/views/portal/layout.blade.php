@php($t = fn ($k, $r = []) => \App\Support\PortalCopy::get($vm['lang'], $k, $r))
<!doctype html>
<html lang="{{ $vm['lang'] }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Waoo Party</title>
<link rel="icon" href="{{ asset('img/favicon.png') }}">
{{-- No third-party resources on portal pages (personal links are secrets). --}}
<link rel="stylesheet" href="{{ \App\Support\AssetVersion::url('css/waoo-tokens.css') }}">
<link rel="stylesheet" href="{{ \App\Support\AssetVersion::url('css/waoo-portal.css') }}">
</head>
<body class="portal">
<header class="p-top">
  <img src="{{ asset('img/waoo-logo.png') }}" alt="Waoo Party" width="96" height="65">
  <nav class="p-lang" aria-label="Idioma / Language">
    <a href="{{ $vm['lang_url']['es'] }}" @if ($vm['lang'] === 'es') aria-current="true" @endif>ES</a>
    <a href="{{ $vm['lang_url']['en'] }}" @if ($vm['lang'] === 'en') aria-current="true" @endif>EN</a>
  </nav>
</header>
<main class="p-main">
  @if ($vm['test_mode'])<p class="p-test">{{ $t('test') }}</p>@endif
  @yield('content')
  <p class="p-help">{{ $t('help') }}</p>
</main>
<script>
// Owner 2026-10-21: a new link opened while an older portal session is still in this browser must switch to the new booking.
(function () {
  if (document.getElementById('landing')) return;
  var m = /(?:^|[#&])token=([A-Za-z0-9_-]{43})/.exec(location.hash || '');
  if (!m) return;
  try { history.replaceState(null, '', location.pathname + location.search); } catch (e) {}
  var main = document.querySelector('.p-main'); main.style.visibility = 'hidden';
  fetch('/portal/api/session', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content}, body: JSON.stringify({token: m[1]})})
    .then(function (r) { if (!r.ok) throw r; return r.json(); })
    .then(function (j) { location.replace(((j.scopes || []).indexOf('quote.read') >= 0 ? '/portal/quote' : '/portal') + location.search); })
    .catch(function () {
      main.innerHTML = '<section class="p-card"><h1 class="p-h1"></h1><p class="p-hint"></p></section>';
      main.querySelector('h1').textContent = @js($t('link_expired')); main.querySelector('p').textContent = @js($t('link_expired_hint'));
      main.style.visibility = '';
    });
})();
</script>
</body>
</html>
