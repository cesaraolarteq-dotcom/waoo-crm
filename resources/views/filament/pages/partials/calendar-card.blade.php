<article class="wcal-card" style="--pkg: {{ $e->pkg }}">
    <div class="wcal-card-top">
        <b class="wcal-time">{{ $e->time }}</b>
        <x-filament::badge :color="$e->status_color" size="sm">{{ $e->status }}</x-filament::badge>
    </div>
    @if ($e->url)
        <a href="{{ $e->url }}" class="wcal-name">{{ $e->title }}</a>
    @else
        <span class="wcal-name">{{ $e->title }}</span>
    @endif
    <span class="wcal-meta">{{ $e->client }} · {{ $e->services }}</span>
    <span class="wcal-meta">ZIP {{ $e->zip ?? '—' }} · Transporte {{ $e->travel }}@if ($e->units) · {{ $e->units }}@endif</span>
    @foreach ($e->warnings as $w)
        <span class="wcal-warn" role="note">⚠ {{ $w }}</span>
    @endforeach
</article>
