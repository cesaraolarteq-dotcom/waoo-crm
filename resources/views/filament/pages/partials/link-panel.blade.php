{{-- Client link panel (quick booking result + Contrato y enlace). $panel: url, expires, msg_es, msg_en, phone, locale, heading.
     Copy is acknowledged only when the clipboard really worked; otherwise the link stays selectable for manual copy.
     Opening WhatsApp/SMS is not sending: nothing here records "sent". --}}
@php
    $waEs = \App\Filament\Support\ContactLinks::whatsapp($panel['phone'] ?? null, $panel['msg_es']);
    $waEn = \App\Filament\Support\ContactLinks::whatsapp($panel['phone'] ?? null, $panel['msg_en']);
    $smsEs = \App\Filament\Support\ContactLinks::sms($panel['phone'] ?? null, $panel['msg_es']);
    $smsEn = \App\Filament\Support\ContactLinks::sms($panel['phone'] ?? null, $panel['msg_en']);
@endphp
<div class="wqb-done" x-data="{ lang: @js($panel['locale'] ?? 'es'), copied: '', manual: false,
        copy(text, what) { (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(() => { this.copied = what; this.manual = false }).catch(() => { this.manual = true }) } }">
    <h2>{{ $panel['heading'] }} <span>Vence el {{ rtrim($panel['expires'], '.') }}. {{ $panel['hint'] ?? 'Puedes volver a copiarlo desde el evento → Contrato y enlace mientras esté activo.' }}</span></h2>
    <div class="wqb-link"><code class="wqb-url">{{ $panel['url'] }}</code>
        <button type="button" class="wcal-btn" x-on:click="copy(@js($panel['url']), 'link')" x-text="copied === 'link' ? 'Copiado' : 'Copiar enlace'"></button></div>
    <p class="wmoney-warn" x-show="manual" x-cloak role="alert">No se pudo copiar automáticamente: selecciona el enlace o el mensaje y cópialo a mano (Cmd + C).</p>
    <div class="wqb-msg">
        <div class="wcal-modes"><button type="button" class="wcal-btn" :class="lang==='es' && 'on'" x-on:click="lang='es'">Español</button><button type="button" class="wcal-btn" :class="lang==='en' && 'on'" x-on:click="lang='en'">English</button></div>
        <p class="wqb-url" x-show="lang==='es'">{{ $panel['msg_es'] }}</p>
        <p class="wqb-url" x-show="lang==='en'" x-cloak>{{ $panel['msg_en'] }}</p>
        <button type="button" class="wcal-btn on" x-on:click="copy(lang==='es' ? @js($panel['msg_es']) : @js($panel['msg_en']), 'msg')" x-text="copied === 'msg' ? 'Mensaje copiado' : 'Copiar mensaje'"></button>
        @if ($waEs)
            <div class="wqb-send">
                <a class="wcal-btn on" target="_blank" rel="noopener" :href="lang==='es' ? @js($waEs) : @js($waEn)">Abrir WhatsApp con el mensaje</a>
                <a class="wcal-btn" :href="lang==='es' ? @js($smsEs) : @js($smsEn)">Abrir SMS con el mensaje</a>
            </div>
            <small>Se abre tu WhatsApp o tu app de mensajes con el texto listo; revisa y toca enviar tú.</small>
        @elseif (filled($panel['phone'] ?? null))
            <small>El teléfono guardado ({{ $panel['phone'] }}) no es un número claro para enviar. Corrígelo en su ficha, o copia el mensaje.</small>
        @else
            <small>No tiene teléfono guardado: copia el mensaje y pégalo en WhatsApp o en un SMS.</small>
        @endif
    </div>
    {{ $slot ?? '' }}
</div>
