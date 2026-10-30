<?php

namespace App\Services\Sms;

use App\Models\{Customer, SmsConsent, SmsMessage, User};
use App\Services\Audit;
use App\Services\Records\RecordActor;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Sends one owner-written text to a client through the Twilio Messaging Service. Refused while texting is off, when the
 * client has no valid US mobile, or when that number never opted in or said STOP. The first text to a number carries the
 * HELP/STOP/rates line registered with the A2P campaign. No retries: an uncertain provider outcome stays "failed" for review.
 */
final class SendSms
{
    public const FOOTER = ['es' => 'Waoo Party: responde HELP para ayuda o STOP para no recibir más textos. Pueden aplicar tarifas de mensajes y datos.',
        'en' => 'Waoo Party: reply HELP for help, STOP to opt out. Msg & data rates may apply.'];

    public function execute(User $actor, int $customerId, ?int $eventId, string $body): SmsMessage
    {
        RecordActor::authorize($actor);
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 640) throw ValidationException::withMessages(['body' => 'Escribe el texto (máximo 640 letras).']);
        if (! SmsGate::enabled()) throw ValidationException::withMessages(['body' => 'Los textos están apagados. Se prenden cuando tú lo decidas.']);
        $customer = Customer::findOrFail($customerId);
        $to = PhoneNumber::e164($customer->phone);
        if (! $to) throw ValidationException::withMessages(['body' => 'Este cliente no tiene un celular de EE. UU. válido.']);
        if (! SmsConsent::allows($to)) throw ValidationException::withMessages(['body' => 'Este número no aceptó recibir textos (o escribió STOP). Escríbele por WhatsApp.']);
        if (! SmsMessage::where('phone_e164', $to)->where('direction', 'out')->whereIn('status', ['sent', 'delivered'])->exists()) {
            $body .= "\n\n".self::FOOTER[$customer->locale === 'en' ? 'en' : 'es'];
        }
        $message = SmsMessage::create(['direction' => 'out', 'phone_e164' => $to, 'customer_id' => $customer->id, 'event_id' => $eventId,
            'body' => $body, 'status' => 'queued', 'created_by' => $actor->id]);
        $c = config('services.twilio');
        try {
            $response = Http::asForm()->withBasicAuth($c['api_key_sid'], $c['api_key_secret'])->acceptJson()->connectTimeout(3)->timeout(8)
                ->withOptions(['allow_redirects' => false])
                ->post('https://api.twilio.com/2010-04-01/Accounts/'.$c['account_sid'].'/Messages.json', [
                    'MessagingServiceSid' => $c['messaging_service_sid'], 'To' => $to, 'Body' => $body,
                    'StatusCallback' => rtrim((string) config('app.url'), '/').'/webhooks/twilio/status',
                ]);
            $sid = $response->json('sid');
            $ok = $response->successful() && is_string($sid) && preg_match('/^(SM|MM)[0-9a-f]{32}$/D', $sid);
            $message->fill($ok ? ['status' => 'sent', 'provider_sid' => $sid] : ['status' => 'failed', 'error_code' => 'twilio_'.substr((string) ($response->json('code') ?? $response->status()), 0, 20)])->save();
        } catch (\Throwable) {
            $message->fill(['status' => 'failed', 'error_code' => 'twilio_unreachable'])->save();
        }
        Audit::recordFor($actor, 'sms.sent', $message, [], ['status' => $message->status]);

        return $message;
    }
}
