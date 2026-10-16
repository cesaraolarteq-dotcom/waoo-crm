<x-filament-panels::page>
    {{ $this->content }}
    @if ($result)
        <div class="wqb-done" x-data="{ lang: @js($result['locale']), copied: '', sent: @js(empty($result['link'])) }" x-init="window.addEventListener('beforeunload', e => { if (!sent) { e.preventDefault(); e.returnValue = ''; } })">
            <h2>¡Reserva guardada! <span>{{ $result['summary'] }}</span></h2>
            @if ($result['link_pending'] ?? false)
                <p class="wqb-warn">El pago y el evento están guardados. Falta crear el enlace: genera otro desde el evento; no vuelvas a registrar el depósito.</p>
            @elseif ($result['shown_before'])
                <p class="wqb-warn">Este enlace ya se mostró antes. Por seguridad no se muestra otra vez: anúlalo y crea uno nuevo desde el evento.</p>
            @else
                <p class="wqb-hint">Copia el enlace ahora: <b>solo se muestra esta vez</b>. Vence el {{ rtrim($result['expires'], '.') }}.</p>
                <div class="wqb-link"><code>{{ $result['link'] }}</code>
                    <button type="button" class="wcal-btn" x-on:click="navigator.clipboard.writeText(@js($result['link'])).then(() => { copied = 'link'; sent = true })" x-text="copied === 'link' ? 'Copiado' : 'Copiar enlace'"></button></div>
                <div class="wqb-msg">
                    <div class="wcal-modes"><button type="button" class="wcal-btn" :class="lang==='es' && 'on'" x-on:click="lang='es'">Español</button><button type="button" class="wcal-btn" :class="lang==='en' && 'on'" x-on:click="lang='en'">English</button></div>
                    <p x-show="lang==='es'">{{ $result['msg_es'] }}</p>
                    <p x-show="lang==='en'" x-cloak>{{ $result['msg_en'] }}</p>
                    <button type="button" class="wcal-btn on" x-on:click="navigator.clipboard.writeText(lang==='es' ? @js($result['msg_es']) : @js($result['msg_en'])).then(() => { copied = 'msg'; sent = true })" x-text="copied === 'msg' ? 'Mensaje copiado' : 'Copiar mensaje para WhatsApp'"></button>
                    @php
                        $waEs = \App\Filament\Support\ContactLinks::whatsapp($result['phone'] ?? null, $result['msg_es']);
                        $waEn = \App\Filament\Support\ContactLinks::whatsapp($result['phone'] ?? null, $result['msg_en']);
                        $smsEs = \App\Filament\Support\ContactLinks::sms($result['phone'] ?? null, $result['msg_es']);
                        $smsEn = \App\Filament\Support\ContactLinks::sms($result['phone'] ?? null, $result['msg_en']);
                    @endphp
                    @if ($waEs)
                        <div class="wqb-send">
                            <a class="wcal-btn on" target="_blank" rel="noopener" :href="lang==='es' ? @js($waEs) : @js($waEn)" x-on:click="sent = true">Abrir WhatsApp con el mensaje</a>
                            <a class="wcal-btn" :href="lang==='es' ? @js($smsEs) : @js($smsEn)" x-on:click="sent = true">Enviar por SMS</a>
                        </div>
                        <small>Se abre en tu WhatsApp o en Mensajes con el texto listo: solo toca enviar.</small>
                    @else
                        <small>No tiene teléfono guardado: copia el mensaje y pégalo en WhatsApp o en un SMS.</small>
                    @endif
                    
                </div>
            @endif
            <div class="wqb-next">
                <a class="wcal-btn" href="{{ \App\Filament\Resources\Events\EventResource::getUrl('money', ['record' => $result['event_id']]) }}">Ver el dinero de este evento</a>
                <button type="button" class="wcal-btn" wire:click="startOver">Nueva reserva</button>
            </div>
        </div>
    @endif
</x-filament-panels::page>
