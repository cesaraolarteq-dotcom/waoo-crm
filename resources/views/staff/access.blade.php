<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Waoo Party · Staff</title>
<link rel="icon" href="{{ asset('img/favicon.png') }}">
{{-- Same look as the staff week page; no third-party assets. The token lives only in the fragment and is exchanged once. --}}
<link rel="stylesheet" href="{{ \App\Support\AssetVersion::url('css/waoo-tokens.css') }}">
<link rel="stylesheet" href="{{ \App\Support\AssetVersion::url('css/waoo-portal.css') }}">
<link rel="stylesheet" href="{{ \App\Support\AssetVersion::url('css/waoo-staff.css') }}">
</head>
<body class="portal staff">
<header class="p-top"><img src="{{ asset('img/waoo-logo.png') }}" alt="Waoo Party" width="96" height="65"></header>
<main class="p-main">
  <section class="p-card">
    <h1 class="p-h1" id="staff-title">Tu semana en Waoo Party</h1>
    <p id="staff-status" role="status" class="s-line">Abre tu enlace personal para ver tu semana. / Open your personal link to see your week.</p>
  </section>
  <p class="p-help">¿Dudas? Escríbele a César o a Maybeli por WhatsApp. / Questions? Message César or Maybeli on WhatsApp.</p>
</main>
<script>
(function () {
  var token = location.hash.slice(1), status = document.getElementById('staff-status');
  history.replaceState(null, '', location.pathname);
  if (!token) return;
  status.textContent = 'Abriendo tu semana… / Opening your week…';
  fetch('/staff/session', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','Accept':'application/json',
    'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content}, body:JSON.stringify({token:token})})
    .then(function (r) { if (!r.ok) throw new Error(); location.replace('/staff'); })
    .catch(function () { status.textContent='No pudimos abrir este acceso. Pide a Waoo Party revisar tu enlace. / Please ask Waoo Party to check your link.'; });
})();
</script>
</body>
</html>
