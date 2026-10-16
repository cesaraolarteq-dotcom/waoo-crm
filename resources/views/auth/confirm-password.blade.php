@extends('auth.layout')
@section('title', 'Confirma tu contraseña')
@section('content')
<h1 class="auth-title">Confirma tu <em>contraseña</em></h1>
<p class="auth-sub">Por seguridad, escríbela de nuevo antes de cambiar la verificación en dos pasos.</p>
@if ($errors->any())
  <div class="auth-alert" role="alert">{{ $errors->first() }}</div>
@endif
<form method="POST" action="{{ url('/user/confirm-password') }}" class="auth-form" novalidate>
  @csrf
  <label class="field">
    <span class="field-label">Contraseña</span>
    <span class="pw-wrap"><input id="password" type="password" name="password" autocomplete="current-password" required autofocus><button type="button" class="pw-eye" data-pw-toggle aria-controls="password" aria-pressed="false" aria-label="Mostrar contraseña" hidden><svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="eye-shut" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A10.6 10.6 0 0 1 12 5c6.4 0 10 7 10 7a17.8 17.8 0 0 1-3.2 4.2M6.6 6.6C3.9 8.3 2 12 2 12s3.6 7 10 7a9.9 9.9 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg></button></span>
  </label>
  <button type="submit" class="btn btn-primary">Confirmar</button>
</form>
@endsection
