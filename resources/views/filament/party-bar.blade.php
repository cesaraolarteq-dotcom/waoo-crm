{{-- Owner 2026-11-02 audit: under a party's name, what is still missing (colored) and its tabs. Same on every tab. --}}
<div class="wparty">
    <div class="wparty-chips">
        @foreach ($chips as $c)
            <span class="wparty-chip {{ $c['tone'] }}" @isset($c['title']) title="{{ $c['title'] }}" @endisset>{{ $c['label'] }}</span>
        @endforeach
    </div>
    @if (count($tabs) > 1)
        <nav class="wsec-tabs wparty-tabs" aria-label="Partes de la fiesta">
            @foreach ($tabs as $tab)
                <a href="{{ $tab['url'] }}" @class(['wsec-tab', 'on' => $tab['active']]) @if ($tab['active']) aria-current="page" @endif>{{ $tab['label'] }}</a>
            @endforeach
        </nav>
    @endif
</div>
