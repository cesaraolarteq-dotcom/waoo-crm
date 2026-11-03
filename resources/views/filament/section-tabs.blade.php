{{-- Owner 2026-11-02: tabs of the current section (App\Filament\Support\Sections). Plain links: URLs never change. --}}
@foreach ($rows as $i => $row)
    <nav class="wsec-tabs {{ $i > 0 ? 'wsec-sub' : '' }}" aria-label="Secciones">
        @foreach ($row as $tab)
            <a href="{{ $tab['url'] }}" @class(['wsec-tab', 'on' => $tab['active']]) @if ($tab['active']) aria-current="page" @endif>{{ $tab['label'] }}</a>
        @endforeach
    </nav>
@endforeach
{{-- On a phone the tabs scroll sideways: keep the current one in view (only the tab strip moves, never the page). --}}
<script>document.querySelectorAll('.wsec-tabs .wsec-tab.on').forEach(function (a) { var n = a.parentNode; if (n.scrollWidth > n.clientWidth) n.scrollLeft = a.offsetLeft - n.offsetLeft - 16; });</script>
