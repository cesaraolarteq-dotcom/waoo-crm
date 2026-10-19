<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><meta name="csrf-token" content="{{ csrf_token() }}">
<title>Waoo Party · Staff</title></head>
<body><main><p id="staff-status" role="status">Abre tu enlace personal para ver tu semana. / Open your personal link to see your week.</p></main>
<script>
(function () {
  var token = location.hash.slice(1), status = document.getElementById('staff-status');
  history.replaceState(null, '', location.pathname);
  if (!token) return;
  fetch('/staff/session', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','Accept':'application/json',
    'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content}, body:JSON.stringify({token:token})})
    .then(function (r) { if (!r.ok) throw new Error(); location.replace('/staff'); })
    .catch(function () { status.textContent='No pudimos abrir este acceso. Pide a Waoo Party revisar tu enlace. / Please ask Waoo Party to check your link.'; });
})();
</script></body></html>
