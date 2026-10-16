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
    <input type="password" name="password" autocomplete="current-password" required>
  </label>
  <label class="check"><input type="checkbox" name="remember" value="1"> <span>Mantener la sesión abierta en este equipo</span></label>
  <button type="submit" class="btn btn-primary">Entrar</button>
</form>
<p class="auth-hint">Después te pediremos el código de 6 dígitos de tu app de autenticación.</p>
@endsection
