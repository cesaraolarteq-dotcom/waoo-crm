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
  var main = document.querySelector('.p-main'), csrf = document.querySelector('meta[name=csrf-token]');
  var landing = document.getElementById('landing'), pending = null, exchanging = false, latest = 0;
  function clearFragment() {
    try { history.replaceState(null, '', location.pathname + location.search); } catch (e) {}
  }
  function unavailable() {
    main.innerHTML = '<section class="p-card"><h1 class="p-h1"></h1><p class="p-hint"></p></section>';
    main.querySelector('h1').textContent = @js($t('link_expired'));
    main.querySelector('p').textContent = @js($t('link_expired_hint'));
    main.inert = false; main.style.visibility = '';
  }
  async function exchange() {
    exchanging = true;
    try {
      while (pending) {
        var intent = pending; pending = null;
        var response = await fetch('/portal/api/session', {method: 'POST', credentials: 'same-origin',
          headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf.content},
          body: JSON.stringify({token: intent.token})});
        intent.token = '';
        if (!response.ok) throw new Error('unavailable');
        var data = await response.json();
        if (!Array.isArray(data.scopes) || typeof data.csrf_token !== 'string' || !data.csrf_token) throw new Error('unavailable');
        // Every successful exchange rotates CSRF. A queued link must use that new token, not the old page's token.
        csrf.content = data.csrf_token;
        if (pending) continue;
        if (intent.generation !== latest) return;
        location.replace((data.scopes.indexOf('quote.read') >= 0 ? '/portal/quote' : '/portal') + intent.search);
        return;
      }
    } catch (e) {
      pending = null; unavailable();
    } finally { exchanging = false; }
  }
  function openedLink() {
    var fragment = location.hash || '';
    if (!/(?:^|[#&])token=/.test(fragment)) {
      if (landing && latest === 0) {
        clearFragment();
        document.getElementById('landing-title').textContent = @js($t('link_missing'));
        document.getElementById('landing-text').textContent = @js($t('link_missing_hint'));
      }
      return;
    }
    var match = /(?:^|[#&])token=([A-Za-z0-9_-]{43})(?=$|&)/.exec(fragment);
    clearFragment(); latest++;
    main.style.visibility = 'hidden'; main.inert = true;
    if (!match) { pending = null; unavailable(); return; }
    pending = {token: match[1], search: location.search, generation: latest};
    if (!exchanging) exchange();
  }
  window.addEventListener('hashchange', openedLink);
  openedLink();
})();
</script>
</body>
</html>
