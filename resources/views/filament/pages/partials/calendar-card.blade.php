{{-- Audit 2026-11-02: hour, party and package only; a confirmed party shows a dot instead of a long badge. Details open on tap. --}}
<article class="wcal-card" style="--pkg: {{ $e->pkg }}">
    <div class="wcal-card-top">
        <b class="wcal-time">{{ $e->time }}</b>
        @if ($e->status_key === 'capacity_reserved')
            <span class="wcal-dot" title="{{ $e->status }}"><span class="wsr">{{ $e->status }}</span></span>
        @else
            <x-filament::badge :color="$e->status_color" size="sm">{{ $e->status }}</x-filament::badge>
        @endif
    </div>
    @if ($e->url)
        <a href="{{ $e->url }}" class="wcal-name">{{ $e->title }}</a>
    @else
        <span class="wcal-name">{{ $e->title }}</span>
    @endif
    @php($meta = collect([$e->client && ! str_contains(mb_strtolower($e->title), mb_strtolower($e->client)) ? $e->client : null, str_contains(mb_strtolower($e->title), mb_strtolower($e->services)) ? null : $e->services])->filter()->implode(' · '))
    @if ($meta !== '')<span class="wcal-meta">{{ $meta }}</span>@endif
    @if ($e->travel_missing ?? false)<span class="wcal-warn" role="note">⚠ Falta el costo de transporte</span>@endif
    @foreach ($e->warnings as $w)
        <span class="wcal-warn" role="note">⚠ {{ $w }}</span>
    @endforeach
</article>
