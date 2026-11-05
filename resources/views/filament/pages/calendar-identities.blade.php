<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Revisa la identidad de cada fiesta</x-slot>
        <p class="wqb-hint">Una hora parecida no demuestra que sea la misma fiesta. Compara el evento importado con HoneyBook antes de asociarlo. No se cobra, confirma ni envía ningún mensaje.</p>
        <form wire:submit="inspect" class="wimp-form wci-form">
            <label for="calendar-party">Fiesta importada</label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="calendar-party" wire:model="eventId">
                    <option value="">Selecciona una fiesta</option>
                    @foreach($this->parties() as $party)
                        <option value="{{ $party->id }}">{{ $party->title }} · {{ $party->starts_at->setTimezone('America/Chicago')->format('d/m/Y g:i A') }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
            <button type="submit" class="wcal-btn on" wire:loading.attr="disabled">Revisar calendario</button>
        </form>
    </x-filament::section>
    @if($review)
        <x-filament::section>
            <x-slot name="heading">Resultado de la revisión</x-slot>
            @if($review['state']==='unknown')
                <p role="status">No se pudo comprobar el calendario. Las entradas siguen contando por separado.</p>
            @else
                <p class="wqb-hint">{{ count($review['options']) }} identidades con el mismo horario. {{ $review['conflicts'] }} ya asociadas a otra fiesta.</p>
                @if($review['binding_id'])
                    <p role="status">{{ $review['binding_current']?'La fiesta tiene una asociación vigente.':'La asociación necesita revisión porque cambió el horario.' }}</p>
                @endif
                <form wire:submit="associate" class="wimp-form wci-form">
                    <label for="calendar-identity">Entrada de HoneyBook que verificaste</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="calendar-identity" wire:model="identity">
                            <option value="">Selecciona la identidad que revisaste</option>
                            @foreach($review['options'] as $option)
                                <option value="{{ $option['uid_sha256'] }}">{{ $option['summary'] ?: 'Entrada sin título' }} · {{ $option['identity_hint'] }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <label class="wci-attest"><input type="checkbox" wire:model="verified"><span>Revisé HoneyBook y confirmé que es la misma fiesta, además del horario.</span></label>
                    <label for="calendar-reason">Qué verificaste o por qué retiras la asociación</label>
                    <x-filament::input.wrapper><x-filament::input id="calendar-reason" wire:model="reason" maxlength="1000" /></x-filament::input.wrapper>
                    @if(count($review['options']))<button type="submit" class="wcal-btn on" wire:loading.attr="disabled">Guardar asociación verificada</button>@endif
                    @if($review['binding_id'])<button type="button" class="wcal-btn" wire:click="remove" wire:loading.attr="disabled">Retirar asociación</button>@endif
                </form>
            @endif
        </x-filament::section>
    @endif
    @if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
</x-filament-panels::page>
