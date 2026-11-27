<?php

namespace App\Filament\Pages;

use App\Models\{AutoMessageLog, Customer, OutboundMessage, OutboundMessageState, SmsMessage};
use App\Services\Mail\{CampaignOutbox, MailGuard, MailTransport};
use App\Services\Records\RecordActor;
use App\Services\Sms\{SendSms, SmsGate};
use App\Support\AutoMessages;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Owner 2026-11-23 "Mensajes automáticos": every automatic client message in the order the client receives it, with the
 * owner's on/off per channel (email / text), a live preview (ES/EN, sample data), approval of proposed texts and
 * "Enviarme una prueba" to his own email and phone. Sending itself is RunAutoMessages + waoo:mail-due.
 */
class EmailTemplates extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;
    protected static string|\UnitEnum|null $navigationGroup = 'Vender';
    protected static ?string $navigationLabel = 'Mensajes automáticos';
    protected static ?int $navigationSort = 40;
    protected static ?string $slug = 'correos';
    protected static ?string $title = 'Mensajes automáticos';
    protected string $view = 'filament.pages.email-templates';

    public string $selected = 'inquiry_received';
    public string $channel = 'email';
    public string $lang = 'es';

    public const REASONS = [
        'too_old' => 'Llegó antes de encender este mensaje', 'owner_off' => 'Estaba apagado', 'test_mode' => 'Modo prueba: el cliente no está en tu lista de prueba',
        'transport_or_suppressed' => 'El proveedor de correo no respondió o el correo rebotó antes', 'recipient_required' => 'El cliente no tiene un correo válido',
        'no_consent' => 'No aceptó recibir textos (o respondió STOP)', 'no_mobile' => 'No tiene un celular de EE. UU. válido', 'sms_off' => 'Los textos están apagados en el servidor',
        'event_not_confirmed' => 'La fiesta no está confirmada', 'event_paused' => 'Pausaste los mensajes de esa fiesta', 'no_balance' => 'Ya no tenía saldo',
        'responsible_system_honeybook' => 'Esa fiesta la maneja HoneyBook', 'missing_template_variable' => 'Faltaba un dato (por ejemplo, el enlace del cliente)',
        'invalid_message_link' => 'El enlace del cliente no es válido', 'no_text_version' => 'Este mensaje no tiene versión de texto',
        'recipient_not_allowed' => 'Modo prueba: no está en tu lista de prueba', 'recipient_suppressed' => 'Ese correo rebotó antes',
        'source_changed_or_late' => 'La fiesta cambió o ya pasó el día del envío', 'source_configuration_required' => 'Faltaba un dato de la fiesta',
        'transport_unconfigured' => 'No hay proveedor de correo', 'source_unavailable' => 'No se pudo leer la fiesta',
    ];

    public function getSubheading(): ?string
    {
        return 'Lo que le llega a cada cliente, en el orden en que lo recibe. Toca uno para verlo, probarlo o apagarlo.';
    }

    public function select(string $key): void
    {
        abort_unless(AutoMessages::known($key), 404);
        $this->selected = $key;
        if ($this->channel === 'sms' && ! AutoMessages::FLOW[$key]['sms']) $this->channel = 'email';
    }

    public function focusOn(string $key, string $channel): void
    {
        $this->select($key);
        $this->setChannel($channel);
    }

    public function setChannel(string $channel): void
    {
        $this->channel = $channel === 'sms' && AutoMessages::FLOW[$this->selected]['sms'] ? 'sms' : 'email';
    }

    public function setLang(string $lang): void { $this->lang = $lang === 'en' ? 'en' : 'es'; }

    public function toggle(string $key, string $channel): void
    {
        $actor = RecordActor::authorize(Filament::auth()->user());
        try {
            $on = AutoMessages::toggle($actor, $key, $channel);
        } catch (\Illuminate\Validation\ValidationException) {
            $this->selected = $key; $this->channel = $channel;
            Notification::make()->warning()->title('Primero aprueba el texto')->body('Revísalo abajo y toca «Aprobar este texto».')->send();
            return;
        }
        $what = AutoMessages::FLOW[$key]['name'].' · '.($channel === 'email' ? 'correo' : 'texto');
        Notification::make()->{$on ? 'success' : 'info'}()->title($on ? 'Encendido' : 'Apagado')->body($what)->send();
    }

    public function approve(string $channel): void
    {
        $actor = RecordActor::authorize(Filament::auth()->user());
        AutoMessages::approve($actor, $this->selected, $channel);
        $on = $channel === 'email' ? AutoMessages::emailOn($this->selected) : AutoMessages::smsOn($this->selected);
        Notification::make()->success()->title('Texto aprobado')->body($on ? 'Ya está encendido: sale solo desde ahora.' : 'Enciéndelo en su interruptor cuando quieras.')->send();
    }

    /** One sample of the selected message, in the selected language, to the owner's own test email and phone only. */
    public function sendTest(): void
    {
        $actor = RecordActor::authorize(Filament::auth()->user());
        if (! RateLimiter::attempt('auto-messages-test:'.$actor->id, 6, fn () => null, 60)) {
            Notification::make()->warning()->title('Espera un minuto')->body('Ya mandaste varias pruebas seguidas.')->send();
            return;
        }
        $key = $this->selected; $lang = $this->lang; $done = []; $problems = [];
        $email = $this->testEmail($actor);
        if ($email && app(MailTransport::class)->configured()) {
            try {
                $mail = AutoMessages::renderEmail($key, $lang, AutoMessages::sample($lang), AutoMessages::sampleTotals($lang));
                $mail['subject'] = '[Prueba] '.$mail['subject'];
                $m = MailGuard::transaction(fn () => CampaignOutbox::plan('test_'.$key, 'owner-test|'.\Illuminate\Support\Str::uuid(), $email, $lang, $mail, ['created_by' => $actor->id]));
                $status = app(CampaignOutbox::class)->dispatch($m->id);
                $status === 'sent' ? $done[] = 'el correo' : $problems[] = 'el correo no salió ('.(\App\Filament\Support\MailUi::STATUS[$status] ?? $status).')';
            } catch (\Throwable $e) { report($e); $problems[] = 'el correo no se pudo preparar'; }
        } else {
            $problems[] = 'el correo no está conectado';
        }
        $phone = $this->testPhone();
        if (AutoMessages::FLOW[$key]['sms'] && $phone) {
            try {
                $sms = app(SendSms::class)->testToOwner($actor, $phone, AutoMessages::renderSms($key, $lang, AutoMessages::sample($lang)), $lang);
                in_array($sms->status, ['sent', 'delivered'], true) ? $done[] = 'el texto' : $problems[] = 'el texto no salió';
            } catch (\Illuminate\Validation\ValidationException $e) { $problems[] = mb_strtolower((string) collect($e->errors())->flatten()->first()); }
            catch (\Throwable $e) { report($e); $problems[] = 'el texto no se pudo enviar'; }
        }
        $n = Notification::make()->title($done ? 'Te mandé '.implode(' y ', $done) : 'No salió la prueba');
        $n->{$problems ? ($done ? 'warning' : 'danger') : 'success'}()
            ->body(trim(($done ? 'Revisa tu '.($phone && in_array('el texto', $done, true) ? 'correo y tu teléfono' : 'correo').'. ' : '').($problems ? 'Ojo: '.implode('; ', $problems).'.' : '')))->send();
    }

    private function testEmail(\App\Models\User $actor): ?string
    {
        $allowed = MailGuard::allowlist();
        $tests = array_values(array_intersect(array_map('mb_strtolower', (array) config('waoo.mail_test_recipients', [])), $allowed));
        return $tests[0] ?? (in_array(mb_strtolower($actor->email), $allowed, true) ? mb_strtolower($actor->email) : null);
    }

    private function testPhone(): ?string
    {
        return SmsGate::enabled() ? (((array) config('waoo.sms_allowlist', []))[0] ?? null) : null;
    }

    private static function maskEmail(?string $e): ?string
    {
        if (! $e || ! str_contains($e, '@')) return null;
        [$u, $d] = explode('@', $e, 2);
        return mb_substr($u, 0, 1).'•••'.mb_substr($u, -1).'@'.$d;
    }

    private static function maskPhone(?string $p): ?string
    {
        return $p ? '(•••) •••-'.substr($p, -4) : null;
    }

    public function status(): array
    {
        $mailOk = app(MailTransport::class)->configured();
        $smsOn = SmsGate::enabled();
        $smsTestOnly = (array) config('waoo.sms_allowlist', []) !== [];
        $email = Filament::auth()->user() ? $this->testEmail(Filament::auth()->user()) : null;
        return ['mail' => $mailOk, 'sms' => $smsOn, 'sms_test_only' => $smsTestOnly,
            'to_email' => self::maskEmail($email), 'to_phone' => self::maskPhone($this->testPhone())];
    }

    public function rows(): array
    {
        $settings = \App\Models\MessageSetting::whereIn('key', AutoMessages::keys())->get()->keyBy('key');
        $last = AutoMessageLog::whereIn('status', ['sent', 'delivered'])->selectRaw('`key`, max(created_at) as at')->groupBy('key')->pluck('at', 'key');
        $rows = []; $i = 0;
        foreach (AutoMessages::FLOW as $key => $m) {
            $s = $settings->get($key) ?? AutoMessages::setting($key);
            $rows[] = (object) ['key' => $key, 'step' => ++$i, 'name' => $m['name'], 'when' => $m['when'],
                'email' => (object) ['approved' => AutoMessages::emailApproved($key, $s), 'on' => $s->email_enabled && AutoMessages::emailApproved($key, $s)],
                'sms' => $m['sms'] ? (object) ['approved' => AutoMessages::smsApproved($key, $s), 'on' => $s->sms_enabled && AutoMessages::smsApproved($key, $s)] : null,
                'last' => isset($last[$key]) ? \Carbon\Carbon::parse($last[$key])->locale('es')->diffForHumans() : null];
        }
        return $rows;
    }

    public function preview(): object
    {
        $key = $this->selected; $lang = $this->lang;
        $s = AutoMessages::setting($key);
        $p = (object) ['key' => $key, 'name' => AutoMessages::FLOW[$key]['name'], 'when' => AutoMessages::FLOW[$key]['when'],
            'has_sms' => AutoMessages::FLOW[$key]['sms'], 'channel' => $this->channel, 'lang' => $lang,
            'email_approved' => AutoMessages::emailApproved($key, $s), 'sms_approved' => AutoMessages::smsApproved($key, $s),
            'email_on' => $s->email_enabled, 'sms_on' => $s->sms_enabled,
            'proposed_email' => in_array($key, AutoMessages::PAGE_APPROVED_EMAILS, true), 'subject' => null, 'html' => null, 'sms' => null, 'segments' => 0];
        try {
            $mail = AutoMessages::renderEmail($key, $lang, AutoMessages::sample($lang), AutoMessages::sampleTotals($lang));
            $p->subject = $mail['subject']; $p->html = $mail['html'];
        } catch (\Throwable) { $p->html = null; }
        if ($p->has_sms) {
            try { $p->sms = AutoMessages::renderSms($key, $lang, AutoMessages::sample($lang)); } catch (\Throwable) { $p->sms = null; }
            $len = mb_strlen((string) $p->sms); $ucs = preg_match('/[^\x00-\x7F]/u', (string) $p->sms); // accents (á, ¡) make it a 70-letter text
            $p->segments = $len === 0 ? 0 : (int) ceil($len / ($ucs ? ($len > 70 ? 67 : 70) : ($len > 160 ? 153 : 160)));
        }
        return $p;
    }

    /** Last decisions and sends, newest first: who, which message, which channel, what happened. */
    public function activity(): array
    {
        $names = [...\App\Filament\Support\MailUi::MESSAGE_NAMES, ...array_map(fn ($m) => $m['name'], AutoMessages::FLOW)];
        $items = [];
        $emails = OutboundMessage::orderByDesc('id')->limit(15)->get();
        $states = OutboundMessageState::whereIn('outbound_message_id', $emails->modelKeys())->get()->keyBy('outbound_message_id');
        $customers = Customer::whereIn('id', $emails->pluck('customer_id')->filter())->pluck('name', 'id');
        foreach ($emails as $m) {
            $test = str_starts_with($m->template_key, 'test_'); $key = $test ? substr($m->template_key, 5) : $m->template_key;
            $st = $states->get($m->id);
            $items[] = ['at' => $m->created_at, 'channel' => 'email', 'name' => $names[$key] ?? $key, 'who' => $test ? 'Tú (prueba)' : ($customers[$m->customer_id] ?? 'Solicitud de la web'),
                'status' => $st?->status, 'label' => \App\Filament\Support\MailUi::STATUS[$st?->status] ?? $st?->status, 'reason' => self::REASONS[$st?->error_code] ?? null];
        }
        $texts = SmsMessage::where('direction', 'out')->orderByDesc('id')->limit(15)->get(['id', 'customer_id', 'status', 'created_at', 'error_code']);
        $tc = Customer::whereIn('id', $texts->pluck('customer_id')->filter())->pluck('name', 'id');
        foreach ($texts as $t) {
            $items[] = ['at' => $t->created_at, 'channel' => 'sms', 'name' => 'Texto', 'who' => $t->customer_id ? ($tc[$t->customer_id] ?? 'Cliente') : 'Tú (prueba)',
                'status' => $t->status, 'label' => ['sent' => 'Enviado', 'delivered' => 'Entregado', 'failed' => 'Falló', 'unknown' => 'No se sabe si salió', 'queued' => 'Enviándose'][$t->status] ?? $t->status, 'reason' => null];
        }
        $held = AutoMessageLog::whereIn('status', ['held', 'skipped'])->where('reason', '!=', 'too_old')->orderByDesc('id')->limit(10)->get();
        $hc = Customer::whereIn('id', \App\Models\Event::whereIn('id', $held->pluck('event_id')->filter())->pluck('customer_id'))->pluck('name', 'id');
        $ec = \App\Models\Event::whereIn('id', $held->pluck('event_id')->filter())->pluck('customer_id', 'id');
        foreach ($held as $h) {
            $items[] = ['at' => $h->created_at, 'channel' => $h->channel, 'name' => $names[$h->key] ?? $h->key, 'who' => $h->event_id ? ($hc[$ec[$h->event_id] ?? 0] ?? 'Cliente') : 'Solicitud de la web',
                'status' => $h->status, 'label' => 'No salió', 'reason' => self::REASONS[$h->reason] ?? $h->reason];
        }
        usort($items, fn ($a, $b) => $b['at'] <=> $a['at']);
        return array_map(function ($i) {
            $i['ago'] = $i['at'] ? \Carbon\Carbon::parse($i['at'])->locale('es')->diffForHumans() : '';
            $i['tone'] = match ($i['status']) { 'sent', 'delivered' => 'ok', 'held', 'skipped', 'blocked' => 'muted', 'scheduled', 'dispatching', 'queued' => 'info', default => 'bad' };
            return $i;
        }, array_slice($items, 0, 12));
    }
}
