@php($t = fn ($k, $r = []) => \App\Support\PortalCopy::get($vm['lang'], $k, $r))
<!doctype html>
<html lang="{{ $vm['lang'] }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Waoo Party</title>
<link rel="icon" href="{{ asset('img/favicon.png') }}">
{{-- No third-party resources on portal pages (personal links are secrets). --}}
<link rel="stylesheet" href="{{ asset('css/waoo-tokens.css') }}">
<link rel="stylesheet" href="{{ asset('css/waoo-portal.css') }}">
</head>
<body class="portal">
<header class="p-top">
  <img src="{{ asset('img/waoo-logo.png') }}" alt="Waoo Party" width="96" height="65">
  <nav class="p-lang" aria-label="Idioma / Language">
    <a href="{{ $vm['lang_url']['es'] }}" @if ($vm['lang'] === 'es') aria-current="true" @endif>ES</a>
    <a href="{{ $vm['lang_url']['en'] }}" @if ($vm['lang'] === 'en') aria-current="true" @endif>EN</a>
  </nav>
</header>
<main class="p-main">
  @if ($vm['test_mode'])<p class="p-test">{{ $t('test') }}</p>@endif
  @yield('content')
  <p class="p-help">{{ $t('help') }}</p>
</main>
</body>
</html>
