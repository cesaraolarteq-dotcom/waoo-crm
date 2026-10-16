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
    <input type="password" name="password" autocomplete="current-password" required autofocus>
  </label>
  <button type="submit" class="btn btn-primary">Confirmar</button>
</form>
@endsection
