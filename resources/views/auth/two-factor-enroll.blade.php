@extends('auth.layout')
@section('title', 'Verificación en dos pasos')
@section('content')
<ol class="steps" aria-label="Pasos">
  <li @class(['on' => $step === 'start', 'done' => $step !== 'start'])>Activar</li>
  <li @class(['on' => $step === 'scan', 'done' => $step === 'codes'])>Escanear</li>
  <li @class(['on' => $step === 'codes'])>Guardar códigos</li>
</ol>

@if ($step === 'start')
  <h1 class="auth-title">Protege tu <em>cuenta</em></h1>
  <p class="auth-sub">El CRM guarda datos de clientas y pagos. Para entrar vas a necesitar tu contraseña y un código de 6 dígitos que cambia cada 30 segundos en tu teléfono.</p>
  <div class="note">Necesitas una app de autenticación en tu teléfono: Google Authenticator, Microsoft Authenticator o 1Password.</div>
  <form method="POST" action="{{ url('/user/two-factor-authentication') }}" class="auth-form">
    @csrf
    <button type="submit" class="btn btn-primary">Empezar</button>
  </form>

@elseif ($step === 'scan')
  <h1 class="auth-title">Escanea el <em>código</em></h1>
  <p class="auth-sub">En tu app de autenticación toca “+” o “Agregar cuenta” y escanea este código.</p>
  <div class="qr" aria-label="Código QR para tu app de autenticación">{!! $qr !!}</div>
  <details class="auth-more">
    <summary>¿No puedes escanear? Escribe la clave a mano</summary>
    <p class="setup-key"><code>{{ trim(chunk_split($setupKey, 4, ' ')) }}</code></p>
  </details>
  @php($codeError = $errors->confirmTwoFactorAuthentication->first('code'))
  @if ($codeError)
    <div class="auth-alert" role="alert">{{ $codeError }}</div>
  @endif
  <form method="POST" action="{{ url('/user/confirmed-two-factor-authentication') }}" class="auth-form" novalidate>
    @csrf
    <label class="field">
      <span class="field-label">Código de 6 dígitos que muestra la app</span>
      <input class="code-input" type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required autofocus>
    </label>
    <button type="submit" class="btn btn-primary">Confirmar</button>
  </form>

@else
  <h1 class="auth-title">Guarda estos <em>códigos</em></h1>
  <p class="auth-sub">Si pierdes el teléfono, cada uno de estos códigos te deja entrar una vez. Guárdalos en un lugar privado (por ejemplo, tu gestor de contraseñas). No los vuelves a ver aquí.</p>
  <ul class="codes" id="recoveryCodes">
    @foreach ($codes as $code)
      <li><code>{{ $code }}</code></li>
    @endforeach
  </ul>
  <button type="button" class="btn btn-quiet" id="copyCodes">Copiar los códigos</button>
  <a class="btn btn-primary" href="{{ url('/admin') }}">Ya los guardé, entrar al CRM</a>
@endif

<form method="POST" action="{{ url('/logout') }}" class="auth-logout">
  @csrf
  <button type="submit" class="link">Salir</button>
</form>
@endsection

@push('scripts')
<script>
(function () {
  var b = document.getElementById('copyCodes');
  if (!b || !navigator.clipboard) { if (b) b.hidden = true; return; }
  b.addEventListener('click', function () {
    var t = Array.prototype.map.call(document.querySelectorAll('#recoveryCodes code'), function (c) { return c.textContent; }).join('\n');
    navigator.clipboard.writeText(t).then(function () { b.textContent = 'Copiados'; });
  });
})();
</script>
@endpush
