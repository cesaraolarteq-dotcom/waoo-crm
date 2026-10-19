<?php
namespace App\Services\Mail;

use App\Models\{OutboundMessage, OutboundMessageState};
use App\Rules\ExplicitOffset;
use App\Services\Finance\Sandbox;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ReceivePostmarkBounce
{
    public function execute(Request $request): void
    {
        abort_unless(app()->environment(['local', 'testing', 'staging']) && config('waoo.sandbox_mail_bounces') === true, 404);
        abort_unless($request->isSecure(), 403);
        $user = config('waoo.postmark_bounce_username');
        $secret = config('waoo.postmark_bounce_password');
        $server = config('waoo.postmark_server_id');
        $stream = config('waoo.postmark_message_stream');
        abort_unless(is_string($user) && $user !== '' && strlen($user) <= 128 && is_string($secret)
            && strlen($secret) >= 32 && strlen($secret) <= 512 && is_numeric($server) && (int) $server > 0
            && is_string($stream) && $stream !== '', 503, 'Bounce receiver unavailable.');
        // Dedicated native Postmark HTTP Basic credentials, never a fabricated signature header.
        $validUser = hash_equals($user, (string) $request->getUser());
        $validPassword = hash_equals($secret, (string) $request->getPassword());
        abort_unless($validUser && $validPassword, 401, 'Invalid webhook authentication.');
        abort_if((int) $request->header('Content-Length', 0) > 65536, 413);
        $body = $request->getContent();
        abort_if(strlen($body) > 65536, 413);
        abort_unless($request->isJson(), 422, 'JSON required.');
        try { $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { abort(422, 'Invalid webhook.'); }
        // Allow provider-added fields but persist only normalized receipt metadata below.
        $data = Validator::make(['record' => $payload], [
            'record' => 'required|array',
            'record.RecordType' => ['required', Rule::in(['Bounce', 'SpamComplaint'])],
            'record.ID' => ['required', 'integer', 'min:1'],
            'record.MessageID' => 'required|string|max:255',
            'record.ServerID' => ['required', 'integer', Rule::in([(int) $server])],
            'record.MessageStream' => ['required', 'string', Rule::in([$stream])],
            'record.Email' => 'required|email|max:255',
            'record.BouncedAt' => ['required', 'date', new ExplicitOffset, 'before_or_equal:'.CarbonImmutable::now()->addMinutes(5)->toIso8601String()],
            'record.Type' => ['required_if:record.RecordType,Bounce', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_-]{0,79}$/D'],
            'record.Inactive' => ['sometimes', function ($attribute, $value, $fail) { if (!is_bool($value)) $fail('Inactive must be a boolean.'); }],
        ])->validate()['record'];
        $hash = hash('sha256', 'postmark'."\0".$data['MessageID']);
        $state = OutboundMessageState::where('provider', 'postmark')->where('provider_message_sha256', $hash)->first();
        // Early webhook: provider can retry after the send receipt has committed.
        abort_unless($state && $state->provider_message_id === $data['MessageID'], 503, 'Delivery mapping not available.');
        $message = OutboundMessage::findOrFail($state->outbound_message_id);
        $recipient = mb_strtolower(trim($data['Email']));
        abort_unless($message->environment === 'sandbox' && hash_equals($message->recipient_sha256, hash('sha256', $recipient)), 422, 'Recipient binding mismatch.');
        try { $intendedRecipient = $message->payload['recipient'] ?? null; }
        catch (\Throwable) { abort(409, 'Message evidence unavailable.'); }
        abort_unless(is_string($intendedRecipient) && hash_equals($intendedRecipient, $recipient), 422, 'Recipient binding mismatch.');
        $type = $data['Type'] ?? 'SpamComplaint';
        $inactive = $data['Inactive'] ?? null;
        $outcome = $data['RecordType'] === 'SpamComplaint' || $type === 'SpamComplaint' ? 'complained'
            : ($inactive === true || in_array($type, ['HardBounce', 'BadEmailAddress', 'ManuallyDeactivated'], true) ? 'bounced' : 'provider_notice');
        app(RecordMailDelivery::class)->authenticatedObservation('postmark', $data['MessageID'], (string) $data['ID'],
            $outcome, CarbonImmutable::parse($data['BouncedAt'])->utc(), ['type' => $type, 'inactive' => $inactive]);
    }
}
