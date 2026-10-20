<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Cómo funcionan</x-slot>
        <p class="wmoney-p">Cada correo lleva un solo botón y va en el idioma de la clienta. Como muchos correos terminan en spam, los mensajes de texto requieren su propia activación. Para cambiar un texto, díselo a Claude; los cambios se aprueban antes de activarse.</p>
    </x-filament::section>

    @if ($ready = $this->readiness())
        <x-filament::section>
            <x-slot name="heading">¿Listos para enviar?</x-slot>
            <x-slot name="afterHeader"><x-filament::badge :color="$ready['missing'] === [] ? 'success' : 'warning'">{{ $ready['missing'] === [] ? 'Falta solo tu activación' : 'Todavía no' }}</x-filament::badge></x-slot>
            <dl class="wfile-dl">
                <div><dt>Correos de prueba</dt><dd>{{ $ready['enabled'] ? 'Encendidos (solo entorno de prueba)' : 'Apagados' }}</dd></div>
                <div><dt>Proveedor de correo</dt><dd>{{ $ready['transport_configured'] ? 'Configurado' : 'Sin configurar' }}</dd></div>
                <div><dt>Lista de prueba</dt><dd>{{ $ready['allowlist'] ? implode(', ', $ready['allowlist']) : 'Vacía' }}</dd></div>
                @foreach ($ready['rows'] as $r)
                    <div><dt>{{ $r->label }}</dt><dd>Español: {{ $r->es ? 'aprobado' : 'sin aprobar' }} · Inglés: {{ $r->en ? 'aprobado' : 'sin aprobar' }}</dd></div>
                @endforeach
            </dl>
            @if ($ready['missing'])
                <p class="wmoney-p"><b>Falta:</b></p>
                <ul class="wimp-list">@foreach ($ready['missing'] as $m)<li>• {{ $m }}</li>@endforeach</ul>
            @endif
            <p class="wmoney-p">Aunque todo esté listo, ningún correo sale hasta que tú lo actives. Mientras tanto solo pueden ir a la lista de prueba.</p>
        </x-filament::section>
    @endif

    @foreach ($this->templates() as $t)
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">{{ $t->subject_es }}</x-slot>
            <x-slot name="description">{{ $t->when }}</x-slot>
            <x-slot name="afterHeader"><x-filament::badge color="success">Texto aprobado</x-filament::badge></x-slot>
            <div x-data="{ lang: 'es' }">
                <p class="wmoney-p">Asunto en inglés: {{ $t->subject_en }}</p>
                <div style="display:flex;gap:8px;margin:10px 0">
                    <x-filament::button size="sm" x-on:click="lang = 'es'" x-bind:aria-pressed="lang === 'es'" x-bind:style="lang === 'es' && 'outline:2px solid #FF017C'" color="gray">Español</x-filament::button>
                    <x-filament::button size="sm" x-on:click="lang = 'en'" x-bind:aria-pressed="lang === 'en'" x-bind:style="lang === 'en' && 'outline:2px solid #FF017C'" color="gray">English</x-filament::button>
                </div>
                @foreach (['es', 'en'] as $lang)
                    <iframe x-show="lang === '{{ $lang }}'" @if ($lang === 'en') x-cloak @endif sandbox title="Vista previa {{ $t->key }} {{ $lang }}"
                        srcdoc="{{ $this->previewHtml($t->key, $lang) }}" loading="lazy"
                        style="width:100%;max-width:600px;height:720px;border:1px solid rgba(127,127,127,.25);border-radius:12px;background:#F3F4FA"></iframe>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
