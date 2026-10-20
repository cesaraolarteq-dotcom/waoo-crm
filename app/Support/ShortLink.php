<?php

namespace App\Support;

use App\Models\PortalGrant;
use Illuminate\Support\Facades\{Crypt, DB, Schema};
use Illuminate\Support\Str;

// Owner 2026-10-20: the client link is shown and sent as https://<host>/r/<12 characters> (~71 bits). The long portal link
// keeps working; /r/ only forwards to it while its grant is active (same expiry and revocation). Never logs the code.
final class ShortLink
{
    public const LENGTH = 12;

    /** Short form of a portal link with #token=…; returns the original URL when it can't be shortened. */
    public static function for(?string $url): ?string
    {
        if (! $url || ! preg_match('~/portal(?:/[a-z-]+)?#token=([A-Za-z0-9_\-]{20,200})$~', $url, $m) || ! Schema::hasTable('portal_short_links')) return $url;
        $grant = PortalGrant::where('token_hash', hash('sha256', $m[1]))->first();
        if (! $grant || ! $grant->canRevealLink()) return $url;
        $code = self::codeFor($grant);

        return $code ? url('/r/'.$code) : $url;
    }

    /** Replace every long portal link inside a message by its short form. */
    public static function inText(?string $text): ?string
    {
        if (! $text) return $text;

        return preg_replace_callback('~https?://\S+/portal(?:/[a-z-]+)?#token=[A-Za-z0-9_\-]{20,200}~', fn ($m) => self::for($m[0]), $text);
    }

    public static function codeFor(PortalGrant $grant): ?string
    {
        $row = DB::table('portal_short_links')->where('portal_grant_id', $grant->id)->first();
        if ($row) {
            try { return Crypt::decryptString($row->encrypted_code); } catch (\Throwable) { return null; }
        }
        for ($i = 0; $i < 3; $i++) {
            $code = Str::random(self::LENGTH);
            try {
                DB::table('portal_short_links')->insert(['portal_grant_id' => $grant->id, 'code_hash' => hash('sha256', $code),
                    'encrypted_code' => Crypt::encryptString($code), 'created_at' => now(), 'updated_at' => now()]);

                return $code;
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                $row = DB::table('portal_short_links')->where('portal_grant_id', $grant->id)->first(); // a concurrent request won
                if ($row) return Crypt::decryptString($row->encrypted_code);
            }
        }

        return null;
    }

    /** The active grant behind a code, or null (unknown, revoked, expired). */
    public static function grant(string $code): ?PortalGrant
    {
        if (! preg_match('/^[A-Za-z0-9]{'.self::LENGTH.'}$/', $code) || ! Schema::hasTable('portal_short_links')) return null;
        $row = DB::table('portal_short_links')->where('code_hash', hash('sha256', $code))->first();
        $grant = $row ? PortalGrant::find($row->portal_grant_id) : null;

        return $grant && $grant->canRevealLink() ? $grant : null;
    }
}
