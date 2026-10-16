@extends('auth.layout')
@section('title', 'Entrar')
@section('content')
<h1 class="auth-title">Hola de <em>nuevo</em></h1>
<p class="auth-sub">Entra con tu correo de Waoo Party.</p>
@if ($errors->any())
  <div class="auth-alert" role="alert">{{ $errors->first() }}</div>
@endif
<form method="POST" action="{{ url('/login') }}" class="auth-form" novalidate>
  @csrf
  <label class="field">
    <span class="field-label">Correo</span>
    <input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus inputmode="email">
  </label>
  <label class="field">
    <span class="field-label">Contraseña</span>
    <span class="pw-wrap"><input id="password" type="password" name="password" autocomplete="current-password" required><button type="button" class="pw-eye" data-pw-toggle aria-controls="password" aria-pressed="false" aria-label="Mostrar contraseña" hidden><svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="eye-shut" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A10.6 10.6 0 0 1 12 5c6.4 0 10 7 10 7a17.8 17.8 0 0 1-3.2 4.2M6.6 6.6C3.9 8.3 2 12 2 12s3.6 7 10 7a9.9 9.9 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg></button></span>
  </label>
  <label class="check"><input type="checkbox" name="remember" value="1"> <span>Mantener la sesión abierta en este equipo</span></label>
  <button type="submit" class="btn btn-primary">Entrar</button>
</form>
<p class="auth-hint">Después te pediremos el código de 6 dígitos de tu app de autenticación.</p>
@endsection
