{{-- Owner 2026-11-02: tabs of the current section (App\Filament\Support\Sections). Plain links: URLs never change. --}}
@foreach ($rows as $i => $row)
    <nav class="wsec-tabs {{ $i > 0 ? 'wsec-sub' : '' }}" aria-label="Secciones">
        @foreach ($row as $tab)
            <a href="{{ $tab['url'] }}" @class(['wsec-tab', 'on' => $tab['active']]) @if ($tab['active']) aria-current="page" @endif>{{ $tab['label'] }}</a>
        @endforeach
    </nav>
@endforeach
