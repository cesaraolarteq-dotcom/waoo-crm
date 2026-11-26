<x-filament::section>
    <x-slot name="heading">Margen por paquete</x-slot>

    <div class="wp waoo-package-margin">
        <label for="package-margin-year">Año</label>
        <x-filament::input.wrapper>
            <x-filament::input.select id="package-margin-year" wire:model.live="marginYear" wire:loading.attr="disabled" wire:target="marginYear">
                @for ($year = \App\Services\Reports\ReadPackageMargin::FIRST_YEAR; $year <= \App\Services\Reports\ReadPackageMargin::LAST_YEAR; $year++)
                    <option value="{{ $year }}" @selected($year === $margin['year'])>{{ $year }}</option>
                @endfor
            </x-filament::input.select>
        </x-filament::input.wrapper>

        <div aria-live="polite">
            <p>{{ $margin['included_events'] }} incluidas · {{ $margin['pending_events'] }} pendientes</p>
            @if ($margin['pending_events'] > 0)
                <p>Las fiestas con costos por revisar o con varios paquetes quedan pendientes.</p>
            @endif
        </div>

        @if ($margin['packages'])
            <div class="wp-duo">
                @foreach ($margin['packages'] as $package)
                    <article class="wp-card" wire:key="package-margin-{{ $package['service_variant_id'] }}">
                        <h3>{{ $package['name'] }}</h3>
                        <ul class="wp-list">
                            <li>Fiestas <span>{{ $package['events'] }}</span></li>
                            <li>Ingresos <span>{{ \App\Filament\Support\Labels::money($package['revenue_cents']) }}</span></li>
                            <li>Costos <span>{{ \App\Filament\Support\Labels::money($package['cost_cents']) }}</span></li>
                            <li><strong>Margen</strong> <strong>{{ \App\Filament\Support\Labels::money($package['margin_cents']) }}</strong></li>
                        </ul>
                    </article>
                @endforeach
            </div>
        @else
            <p>No hay fiestas con margen calculado para {{ $margin['year'] }}.</p>
        @endif
    </div>
</x-filament::section>
