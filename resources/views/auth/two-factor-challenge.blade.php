@extends('auth.layout')
@section('title', 'Código de verificación')
@section('content')
<h1 class="auth-title">Tu <em>código</em></h1>
<p class="auth-sub">Abre tu app de autenticación y escribe el código de 6 dígitos de Waoo CRM.</p>
@if ($errors->any())
  <div class="auth-alert" role="alert">{{ $errors->first() }}</div>
@endif
<form method="POST" action="{{ url('/two-factor-challenge') }}" class="auth-form" novalidate>
  @csrf
  <label class="field">
    <span class="field-label">Código de 6 dígitos</span>
    <input class="code-input" type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" autofocus>
  </label>
  <button type="submit" class="btn btn-primary">Verificar y entrar</button>
</form>
<details class="auth-more" @if ($errors->has('recovery_code')) open @endif>
  <summary>¿No tienes el teléfono? Usa un código de recuperación</summary>
  <form method="POST" action="{{ url('/two-factor-challenge') }}" class="auth-form" novalidate>
    @csrf
    <label class="field">
      <span class="field-label">Código de recuperación</span>
      <input type="text" name="recovery_code" autocomplete="off" spellcheck="false">
      <span class="field-help">Cada código de recuperación sirve una sola vez.</span>
    </label>
    <button type="submit" class="btn btn-quiet">Entrar con código de recuperación</button>
  </form>
</details>
@endsection
