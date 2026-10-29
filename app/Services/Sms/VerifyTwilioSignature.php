<?php

namespace App\Services\Sms;

use Illuminate\Http\Request;

/**
 * Twilio's X-Twilio-Signature: base64(HMAC-SHA1(auth token, public URL + each POST field name and value, sorted by name)).
 * The URL is the configured public origin plus the path, never a host taken from the request.
 */
final class VerifyTwilioSignature
{
    public function execute(Request $request): void
    {
        $token = config('services.twilio.auth_token');
        abort_unless(is_string($token) && preg_match('/^[0-9a-f]{32}$/D', $token), 503);
        $signature = (string) $request->header('X-Twilio-Signature');
        abort_unless($signature !== '' && strlen($signature) <= 256, 401);
        abort_unless(hash_equals(self::sign($token, rtrim((string) config('app.url'), '/').$request->getPathInfo(), $request->post()), $signature), 401);
    }

    public static function sign(#[\SensitiveParameter] string $token, string $url, array $params): string
    {
        ksort($params, SORT_STRING);
        $data = $url;
        foreach ($params as $key => $value) $data .= $key.(is_array($value) ? implode('', $value) : (string) $value);

        return base64_encode(hash_hmac('sha1', $data, $token, true));
    }
}
