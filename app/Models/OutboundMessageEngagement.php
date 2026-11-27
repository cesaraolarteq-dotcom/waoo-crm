<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Owner 2026-11-26: one row per Resend open/click event (no personal data: the link is stored only as a kind). */
class OutboundMessageEngagement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    /** Which button of the anniversary email a clicked link belongs to. */
    public static function linkKind(?string $url): string
    {
        $u = mb_strtolower((string) $url);
        return match (true) {
            str_contains($u, 'wa.me/') || str_contains($u, 'whatsapp') => 'whatsapp',
            str_contains($u, '/contact') => 'book',
            str_contains($u, '/packages') => 'packages',
            str_starts_with($u, 'tel:') => 'phone',
            str_contains($u, 'google.') || str_contains($u, 'maps.') => 'google',
            default => 'other',
        };
    }
}
