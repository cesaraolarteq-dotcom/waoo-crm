<?php

namespace App\Services\WhatsApp;

use App\Models\{Customer, WhatsappMessage};
use Illuminate\Support\Facades\{DB, Http};
use RuntimeException;

/**
 * Owner 2026-11-26: WhatsApp from the business number (346) 851-8830 through Waoo's own Meta app — no intermediary.
 * Receives messages and statuses (signed by Meta with the app secret), and sends replies/templates with the system-user
 * token. Off unless WAOO_WHATSAPP=true; outbound to clients also needs WAOO_CLIENT_MESSAGES (switch day) unless it is
 * a reply inside the 24-hour window that César or the agent writes on purpose.
 */
final class WhatsAppCloud
{
    public const GRAPH = 'https://graph.facebook.com/v23.0/';

    public static function enabled(): bool
    {
        return config('waoo.whatsapp.enabled') === true && filled(config('waoo.whatsapp.app_secret'))
            && filled(config('waoo.whatsapp.token')) && filled(config('waoo.whatsapp.phone_number_id'));
    }

    /** Meta signs every POST with HMAC-SHA256 of the raw body using the app secret. */
    public static function validSignature(string $raw, ?string $header): bool
    {
        $secret = (string) config('waoo.whatsapp.app_secret');
        if ($secret === '' || ! is_string($header) || ! str_starts_with($header, 'sha256=')) return false;
        return hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $header);
    }

    /** Stores incoming messages, phone-app echoes and delivery/read statuses. Idempotent by wamid. */
    public function receive(array $payload): array
    {
        $out = ['messages' => 0, 'statuses' => 0, 'echoes' => 0];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $v = (array) ($change['value'] ?? []);
                if (($v['metadata']['phone_number_id'] ?? null) !== null && (string) $v['metadata']['phone_number_id'] !== (string) config('waoo.whatsapp.phone_number_id')) continue;
                $field = (string) ($change['field'] ?? 'messages');
                foreach ((array) ($v['messages'] ?? []) as $m) {
                    $echo = $field === 'smb_message_echoes';
                    $who = $echo ? (string) ($m['to'] ?? '') : (string) ($m['from'] ?? '');
                    if ($this->store($echo ? 'out' : 'in', $echo ? 'app' : 'client', $who, $m, $echo ? 'sent' : 'received')) $out[$echo ? 'echoes' : 'messages']++;
                }
                foreach ((array) ($v['message_echoes'] ?? []) as $m) {
                    if ($this->store('out', 'app', (string) ($m['to'] ?? ''), $m, 'sent')) $out['echoes']++;
                }
                foreach ((array) ($v['statuses'] ?? []) as $s) {
                    $out['statuses'] += $this->status($s);
                }
            }
        }
        return $out;
    }

    private function store(string $direction, string $source, string $waId, array $m, string $status): bool
    {
        $waId = preg_replace('/\D/', '', $waId);
        $id = (string) ($m['id'] ?? '');
        if ($waId === '' || $id === '' || strlen($id) > 191) return false;
        $type = (string) ($m['type'] ?? 'other');
        $body = match ($type) {
            'text' => (string) ($m['text']['body'] ?? ''),
            'button' => (string) ($m['button']['text'] ?? ''),
            'interactive' => (string) ($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? ''),
            'image', 'video', 'document', 'audio', 'sticker' => (string) ($m[$type]['caption'] ?? '['.$type.']'),
            'location' => '[ubicación]',
            default => '['.$type.']',
        };
        return DB::transaction(function () use ($direction, $source, $waId, $id, $type, $body, $status, $m) {
            if (WhatsappMessage::where('provider_id', $id)->lockForUpdate()->exists()) return false;
            WhatsappMessage::create(['direction' => $direction, 'source' => $source, 'wa_id' => $waId, 'customer_id' => self::customerFor($waId),
                'type' => in_array($type, ['text', 'image', 'audio', 'document', 'template'], true) ? $type : 'other',
                'body' => mb_substr($body, 0, 4000), 'status' => $status, 'provider_id' => $id,
                'provider_at' => isset($m['timestamp']) && ctype_digit((string) $m['timestamp']) ? \Carbon\CarbonImmutable::createFromTimestamp((int) $m['timestamp']) : now()]);
            return true;
        });
    }

    private function status(array $s): int
    {
        $map = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
        $new = (string) ($s['status'] ?? '');
        if (! isset($map[$new])) return 0;
        return DB::transaction(function () use ($s, $new, $map) {
            $row = WhatsappMessage::where('provider_id', (string) ($s['id'] ?? ''))->where('direction', 'out')->lockForUpdate()->first();
            if (! $row) return 0;
            if (($map[$row->status] ?? 0) >= $map[$new] && $new !== 'failed') return 0; // never step back (read stays read)
            $row->fill(['status' => $new, 'error_code' => $new === 'failed' ? mb_substr((string) ($s['errors'][0]['code'] ?? 'failed'), 0, 40) : $row->error_code])->save();
            return 1;
        });
    }

    /** Sends a plain text (only valid inside the 24-hour window the client opened). */
    public function sendText(string $to, string $text, ?int $customerId = null): WhatsappMessage
    {
        return $this->send($to, ['type' => 'text', 'text' => ['preview_url' => true, 'body' => mb_substr($text, 0, 4096)]], 'text', $text, null, $customerId);
    }

    /** Sends an approved template (any time). $params fill {{1}}, {{2}}… of the body in order. */
    public function sendTemplate(string $to, string $name, string $lang, array $params = [], ?int $customerId = null): WhatsappMessage
    {
        $components = $params === [] ? [] : [['type' => 'body', 'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], array_values($params))]];
        return $this->send($to, ['type' => 'template', 'template' => ['name' => $name, 'language' => ['code' => $lang], 'components' => $components]],
            'template', implode(' · ', array_map('strval', $params)), $name, $customerId);
    }

    private function send(string $to, array $message, string $type, string $body, ?string $template, ?int $customerId): WhatsappMessage
    {
        if (! self::enabled()) throw new RuntimeException('whatsapp_off');
        $waId = preg_replace('/\D/', '', $to);
        if (strlen($waId) === 10) $waId = '1'.$waId;
        if (! preg_match('/^\d{11,15}$/', $waId)) throw new RuntimeException('whatsapp_bad_number');
        $r = Http::withToken((string) config('waoo.whatsapp.token'))->acceptJson()->asJson()->connectTimeout(3)->timeout(10)
            ->post(self::GRAPH.config('waoo.whatsapp.phone_number_id').'/messages', ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $waId, ...$message]);
        $id = $r->json('messages.0.id');
        $row = WhatsappMessage::create(['direction' => 'out', 'source' => 'api', 'wa_id' => $waId, 'customer_id' => $customerId ?? self::customerFor($waId),
            'type' => $type, 'body' => mb_substr($body, 0, 4000), 'template_name' => $template, 'status' => is_string($id) ? 'sent' : 'failed',
            'error_code' => is_string($id) ? null : mb_substr((string) ($r->json('error.code') ?? $r->status()), 0, 40), 'provider_id' => is_string($id) ? $id : null, 'provider_at' => now()]);
        return $row;
    }

    /** Matches a WhatsApp number to a client by the last 10 digits of their phone. */
    public static function customerFor(string $waId): ?int
    {
        $ten = substr(preg_replace('/\D/', '', $waId), -10);
        if (strlen($ten) !== 10) return null;
        return Customer::query()->whereNotNull('phone')->get(['id', 'phone'])->first(fn ($c) => substr(preg_replace('/\D/', '', (string) $c->phone), -10) === $ten)?->id;
    }
}
