@if ($warnings !== [])
    <x-filament::section heading="Traslados entre fiestas">
        <ul role="status">
            @foreach ($warnings as $warning)
                <li>{{ $warning }}</li>
            @endforeach
        </ul>
    </x-filament::section>
@endif
