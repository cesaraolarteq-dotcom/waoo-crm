<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Waoo Party · Email preferences</title>
<style>
body{margin:0;background:#07091F;color:#F4F1FF;font-family:Arial,Helvetica,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px;box-sizing:border-box}
.card{max-width:440px;width:100%;background:#0B0E2B;border:1px solid #262A57;border-radius:20px;padding:28px;text-align:center}
img{width:110px;height:auto}
h1{font-size:24px;margin:18px 0 10px}
p{color:#BDBADB;line-height:1.55;margin:0 0 10px}
button{margin-top:12px;background:#F9C930;color:#07091F;border:0;border-radius:999px;padding:14px 26px;font-weight:700;font-size:16px;cursor:pointer;min-height:44px}
small{display:block;margin-top:16px;color:#9A97BF}
</style></head>
<body><main class="card">
  <img src="https://waooparty.com/waoo-logo.png" alt="Waoo Party">
  @if ($done)
    <h1>You’re unsubscribed</h1>
    <p>You won’t receive promotional emails from Waoo Party anymore. Messages about a party you book (contract, payments, reminders) still arrive.</p>
    <p lang="es">Ya no recibirás correos de promociones de Waoo Party.</p>
  @else
    <h1>Unsubscribe from promotions?</h1>
    <p>You’ll stop receiving promotional emails like “One year later”. Messages about a party you book still arrive.</p>
    <form method="post" action="{{ $action }}">@csrf<button type="submit">Yes, unsubscribe me</button></form>
    <small lang="es">¿Dejar de recibir promociones? Toca el botón.</small>
  @endif
</main></body></html>
