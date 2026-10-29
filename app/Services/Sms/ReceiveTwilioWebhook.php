<?php

namespace App\Services\Sms;

use App\Models\{Customer, SmsConsent, SmsMessage};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Signed Twilio callbacks. Incoming texts are kept (encrypted) and STOP/START keywords change the number's consent;
 * Twilio's Advanced Opt-Out answers the client itself. Status callbacks move a sent text to delivered/undelivered/failed.
 */
final class ReceiveTwilioWebhook
{
    public const STOP = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'REVOKE', 'OPTOUT', 'PARAR'];
    public const START = ['START', 'UNSTOP', 'YES'];
    private const SID = '/^(SM|MM)[0-9a-f]{32}$/D';

    public function inbound(Request $request): void
    {
        $this->guard($request);
        $sid = (string) $request->post('MessageSid');
        $from = PhoneNumber::e164((string) $request->post('From'));
        $body = mb_substr((string) $request->post('Body'), 0, 1600);
        abort_unless(preg_match(self::SID, $sid) && $from, 422);
        DB::transaction(function () use ($sid, $from, $body) {
            if (SmsMessage::where('provider_sid', $sid)->lockForUpdate()->exists()) return; // Twilio retried: already kept.
            $customer = self::customerFor($from);
            SmsMessage::create(['direction' => 'in', 'phone_e164' => $from, 'customer_id' => $customer, 'body' => $body, 'status' => 'received', 'provider_sid' => $sid]);
            $word = strtoupper(trim($body));
            $status = in_array($word, self::STOP, true) ? 'opted_out' : (in_array($word, self::START, true) ? 'opted_in' : null);
            if ($status) SmsConsent::updateOrCreate(['phone_e164' => $from], ['status' => $status, 'source' => 'inbound_keyword', 'evidence' => $word,
                'changed_at' => now(), 'customer_id' => $customer]);
        });
    }

    public function status(Request $request): void
    {
        $this->guard($request);
        $sid = (string) $request->post('MessageSid');
        $status = (string) $request->post('MessageStatus');
        abort_unless(preg_match(self::SID, $sid), 422);
        $map = ['sent' => 'sent', 'delivered' => 'delivered', 'undelivered' => 'undelivered', 'failed' => 'failed'];
        if (! isset($map[$status])) return; // queued/sending/accepted add nothing.
        DB::transaction(function () use ($sid, $map, $status, $request) {
            $m = SmsMessage::where('provider_sid', $sid)->where('direction', 'out')->lockForUpdate()->first();
            if (! $m || $m->status === 'delivered') return; // never step back from delivered
            $code = preg_replace('/\D/', '', (string) $request->post('ErrorCode'));
            $m->fill(['status' => $map[$status], 'error_code' => $code !== '' ? 'twilio_'.substr($code, 0, 20) : $m->error_code])->save();
        });
    }

    private function guard(Request $request): void
    {
        abort_unless($request->isSecure(), 403);
        abort_if(strlen($request->getContent()) > 65536, 413);
        app(VerifyTwilioSignature::class)->execute($request);
    }

    /** The client whose phone matches, only when exactly one does. */
    public static function customerFor(string $e164): ?int
    {
        $last10 = substr($e164, 2);
        $ids = Customer::whereNotNull('phone')->get(['id', 'phone'])->filter(fn ($c) => PhoneNumber::e164($c->phone) === '+1'.$last10)->pluck('id');

        return $ids->count() === 1 ? $ids->first() : null;
    }
}
