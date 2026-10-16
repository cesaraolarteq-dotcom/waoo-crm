<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>@yield('title') · Waoo CRM</title>
<link rel="icon" href="{{ asset('img/favicon.png') }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bodoni+Moda:ital,opsz,wght@1,6..96,500&family=Bricolage+Grotesque:opsz,wght@12..96,400..800&display=swap">
<link rel="stylesheet" href="{{ asset('css/waoo-tokens.css') }}">
<link rel="stylesheet" href="{{ asset('css/waoo-auth.css') }}">
</head>
<body class="auth">
<main class="auth-main">
  <div class="auth-card">
    <img class="auth-logo" src="{{ asset('img/waoo-logo.png') }}" alt="Waoo Party" width="132" height="89">
    @yield('content')
  </div>
  <p class="auth-foot">Waoo CRM · solo para administración de Waoo Party</p>
</main>
@stack('scripts')
</body>
</html>
