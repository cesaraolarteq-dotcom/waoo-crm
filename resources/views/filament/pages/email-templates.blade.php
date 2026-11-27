<x-filament-panels::page>
    @php($st = $this->status())
    @php($rows = $this->rows())
    @php($p = $this->preview())
    <div class="wmsg">
        {{-- Who receives messages right now --}}
        <section class="wmsg-mode" aria-label="Modo de envío">
            <div class="wmsg-mode-main">
                <span class="wmsg-dot" aria-hidden="true"></span>
                <div>
                    <b>Modo prueba</b>
                    <p>Los correos salen solo a ti{{ $st['to_email'] ? ' ('.$st['to_email'].')' : '' }}@if ($st['sms']){{ $st['sms_test_only'] ? ' y los textos solo a tu número'.($st['to_phone'] ? ' '.$st['to_phone'] : '') : ' · los textos sí salen a los clientes que aceptaron' }}@endif. Ningún cliente recibe nada mientras sigas en prueba.</p>
                </div>
            </div>
            <div class="wmsg-health">
                <span class="wmsg-pill {{ $st['mail'] ? 'is-ok' : 'is-off' }}"><x-filament::icon icon="heroicon-m-envelope" class="wmsg-ico" />Correo {{ $st['mail'] ? 'conectado' : 'sin conectar' }}</span>
                <span class="wmsg-pill {{ $st['sms'] ? 'is-ok' : 'is-off' }}"><x-filament::icon icon="heroicon-m-chat-bubble-left" class="wmsg-ico" />Textos {{ $st['sms'] ? 'conectados' : 'apagados' }}</span>
            </div>
        </section>

        <div class="wmsg-grid">
            {{-- The client journey: one card per automatic message --}}
            <ol class="wmsg-list" aria-label="Mensajes en el orden en que los recibe el cliente">
                @foreach ($rows as $r)
                    <li wire:key="row-{{ $r->key }}" @class(['wmsg-card', 'is-selected' => $r->key === $p->key])>
                        <button type="button" class="wmsg-card-open" wire:click="select('{{ $r->key }}')" x-on:click="if (window.innerWidth < 1100) setTimeout(() => document.getElementById('wmsg-panel')?.scrollIntoView({ behavior: 'smooth' }), 300)" aria-pressed="{{ $r->key === $p->key ? 'true' : 'false' }}">
                            <span class="wmsg-step" aria-hidden="true">{{ $r->step }}</span>
                            <span class="wmsg-card-text">
                                <b>{{ $r->name }}</b>
                                <small>{{ $r->when }}</small>
                                @if ($r->last)<small class="wmsg-last">Último envío {{ $r->last }}</small>@endif
                            </span>
                        </button>
                        <div class="wmsg-switches">
                            @foreach (['email' => 'Correo', 'sms' => 'Texto'] as $ch => $label)
                                @php($c = $r->{$ch})
                                @if ($c === null)
                                    <span class="wmsg-switch is-none"><span>{{ $label }}</span><em>—</em></span>
                                @elseif (! $c->approved)
                                    <button type="button" class="wmsg-switch is-pending" wire:click="focusOn('{{ $r->key }}', '{{ $ch }}')">
                                        <span>{{ $label }}</span><em>Por aprobar</em>
                                    </button>
                                @else
                                    <button type="button" role="switch" aria-checked="{{ $c->on ? 'true' : 'false' }}" aria-label="{{ $label }} de {{ $r->name }}"
                                        @class(['wmsg-switch', 'is-on' => $c->on]) wire:click="toggle('{{ $r->key }}', '{{ $ch }}')" wire:loading.attr="disabled">
                                        <span>{{ $label }}</span><i class="wmsg-knob" aria-hidden="true"></i>
                                    </button>
                                @endif
                            @endforeach
                        </div>
                    </li>
                @endforeach
                <li class="wmsg-soon">
                    <b>Por ahora los mandas tú</b>
                    <small>Tu cotización y «Termina tu reserva» salen desde la cotización y la reserva rápida (WhatsApp o texto).</small>
                </li>
            </ol>

            {{-- Preview of the selected message --}}
            <section id="wmsg-panel" class="wmsg-panel" aria-live="polite" wire:key="panel-{{ $p->key }}-{{ $p->channel }}-{{ $p->lang }}">
                <header class="wmsg-panel-head">
                    <div>
                        <h2>{{ $p->name }}</h2>
                        <p>{{ $p->when }}</p>
                    </div>
                </header>

                <div class="wmsg-controls">
                    <div class="wmsg-seg" role="group" aria-label="Canal">
                        <button type="button" @class(['is-active' => $p->channel === 'email']) wire:click="setChannel('email')"><x-filament::icon icon="heroicon-m-envelope" class="wmsg-ico" />Correo</button>
                        <button type="button" @class(['is-active' => $p->channel === 'sms']) wire:click="setChannel('sms')" @disabled(! $p->has_sms)><x-filament::icon icon="heroicon-m-chat-bubble-left" class="wmsg-ico" />Texto</button>
                    </div>
                    <div class="wmsg-seg" role="group" aria-label="Idioma">
                        <button type="button" @class(['is-active' => $p->lang === 'es']) wire:click="setLang('es')">Español</button>
                        <button type="button" @class(['is-active' => $p->lang === 'en']) wire:click="setLang('en')">English</button>
                    </div>
                </div>

                @php($approved = $p->channel === 'email' ? $p->email_approved : $p->sms_approved)
                @if (! $approved && $p->channel === 'email' && ! $p->proposed_email)
                    <div class="wmsg-approve"><div><b>Falta el texto aprobado</b><p>Este correo no tiene su texto aprobado en español e inglés. Díselo a Claude.</p></div></div>
                @elseif (! $approved)
                    <div class="wmsg-approve">
                        <div>
                            <b>Texto propuesto por Claude</b>
                            <p>Léelo en español y en inglés. Si te gusta, apruébalo; si quieres cambiar algo, dímelo y lo ajusto.</p>
                        </div>
                        <x-filament::button color="success" icon="heroicon-m-check" wire:click="approve('{{ $p->channel }}')">Aprobar este texto</x-filament::button>
                    </div>
                @endif

                @if ($p->channel === 'email')
                    <div class="wmsg-mail">
                        <div class="wmsg-mail-bar">
                            <span class="wmsg-from">Waoo Party &lt;sales@waooparty.com&gt;</span>
                            <b>{{ $p->subject ?? 'Vista previa no disponible' }}</b>
                        </div>
                        @if ($p->html)
                            <iframe sandbox title="Vista previa del correo {{ $p->name }}" srcdoc="{{ $p->html }}" class="wmsg-frame"></iframe>
                        @else
                            <p class="wmsg-empty">No se pudo preparar la vista previa de este correo.</p>
                        @endif
                    </div>
                @else
                    <div class="wmsg-phone" aria-label="Vista previa del texto">
                        <div class="wmsg-phone-top"><span>Waoo Party</span><small>(346) 480-3680</small></div>
                        <div class="wmsg-thread">
                            <p class="wmsg-bubble">{{ $p->sms ?? 'Vista previa no disponible' }}</p>
                            <small class="wmsg-meta">{{ mb_strlen((string) $p->sms) }} letras · {{ $p->segments }} {{ $p->segments === 1 ? 'mensaje' : 'mensajes' }} · al primer texto se le agrega «responde STOP para no recibir más»</small>
                        </div>
                    </div>
                @endif

                <footer class="wmsg-actions">
                    <x-filament::button icon="heroicon-m-paper-airplane" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">
                        <span wire:loading.remove wire:target="sendTest">Enviarme una prueba</span>
                        <span wire:loading wire:target="sendTest">Enviando…</span>
                    </x-filament::button>
                    <small>Te llega {{ $p->has_sms ? 'el correo y el texto' : 'el correo' }} en {{ $p->lang === 'en' ? 'inglés' : 'español' }}, con datos de ejemplo (María González) y «[Prueba]» en el asunto.</small>
                </footer>
            </section>
        </div>

        {{-- What happened lately --}}
        <section class="wmsg-activity" aria-label="Últimos envíos">
            <h3>Últimos envíos</h3>
            @php($activity = $this->activity())
            @if ($activity === [])
                <p class="wmsg-empty">Todavía no ha salido ningún mensaje automático.</p>
            @else
                <ul>
                    @foreach ($activity as $a)
                        <li>
                            <span class="wmsg-act-ico is-{{ $a['tone'] }}"><x-filament::icon :icon="$a['channel'] === 'sms' ? 'heroicon-m-chat-bubble-left' : 'heroicon-m-envelope'" class="wmsg-ico" /></span>
                            <span class="wmsg-act-text"><b>{{ $a['name'] }}</b> · {{ $a['who'] }}@if ($a['reason'])<small>{{ $a['reason'] }}</small>@endif</span>
                            <span class="wmsg-act-state"><span class="wmsg-tag is-{{ $a['tone'] }}">{{ $a['label'] }}</span><small>{{ $a['ago'] }}</small></span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-filament-panels::page>
