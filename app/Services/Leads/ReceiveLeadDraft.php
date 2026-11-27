<?php

namespace App\Services\Leads;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Owner 2026-11-26: the website quote form sends what a visitor typed (name + a valid US phone at least) when they
 * leave without sending it. Same signed door as the full request; nothing is ever sent to the visitor automatically.
 */
final class ReceiveLeadDraft
{
    public const KEEP_DAYS = 60;

    public function execute(string $body, string $timestamp, string $key, string $signature): void
    {
        ReceiveWebsiteLead::authenticate($body, $timestamp, $key, $signature);
        try { $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { abort(422, 'Invalid JSON payload.'); }
        $d = Validator::make(['p' => $payload], [
            'p' => 'required|array:draft_key,name,phone,email,event_date,start_time,zip,package,event_type,locale,attribution',
            'p.draft_key' => 'required|uuid', 'p.name' => 'required|string|min:2|max:255', 'p.phone' => 'required|string|max:40',
            'p.email' => 'sometimes|nullable|email|max:255', 'p.event_date' => 'sometimes|nullable|date_format:Y-m-d',
            'p.start_time' => 'sometimes|nullable|date_format:H:i', 'p.zip' => ['sometimes', 'nullable', 'regex:/^[0-9]{5}$/'],
            'p.package' => 'sometimes|nullable|string|max:100', 'p.event_type' => 'sometimes|nullable|string|max:100',
            'p.locale' => ['required', Rule::in(['en', 'es'])],
            'p.attribution' => 'sometimes|nullable|array:channel,gclid,gbraid,wbraid,fbclid,click_at,campaign,term',
            'p.attribution.channel' => ['sometimes', 'nullable', Rule::in(array_keys(LeadSource::LABELS))],
            'p.attribution.*' => 'nullable',
        ])->validate()['p'];
        $digits = preg_replace('/\D/', '', $d['phone']);
        if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);
        abort_unless(preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $digits), 422, 'Invalid US phone.');
        $attr = array_filter(array_intersect_key((array) ($d['attribution'] ?? []), array_flip(['channel', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'click_at', 'campaign', 'term'])),
            fn ($v) => is_scalar($v) && preg_match('/^[A-Za-z0-9_\-.~ ]{1,200}$/', (string) $v));
        $fields = array_filter(['event_date' => $d['event_date'] ?? null, 'start_time' => $d['start_time'] ?? null, 'zip' => $d['zip'] ?? null,
            'package' => $d['package'] ?? null, 'event_type' => $d['event_type'] ?? null, 'locale' => $d['locale'], 'attribution' => $attr ?: null]);
        // A full request already sent with this phone: nothing to recover.
        if (self::completedLeadFor($digits)) return;
        DB::table('lead_drafts')->updateOrInsert(['draft_key' => $d['draft_key']], ['name' => trim($d['name']), 'phone_digits' => $digits,
            'email' => $d['email'] ?? null, 'fields' => json_encode($fields), 'updated_at' => now(), 'created_at' => now()]);
    }

    /** A full website request from this phone in the last 60 days (drafts are then closed). */
    public static function completedLeadFor(string $digits): ?int
    {
        $since = now()->subDays(self::KEEP_DAYS);
        foreach (\App\Models\Lead::with('submission')->where('created_at', '>=', $since)->orderByDesc('id')->limit(500)->get() as $lead) {
            $p = preg_replace('/\D/', '', (string) ($lead->submission?->payload['phone'] ?? ''));
            if (strlen($p) === 11 && $p[0] === '1') $p = substr($p, 1);
            if ($p === $digits) return $lead->id;
        }
        return null;
    }

    /** Called when a full request arrives: its open drafts are marked as completed. */
    public static function closeFor(\App\Models\Lead $lead): void
    {
        $p = preg_replace('/\D/', '', (string) ($lead->submission?->payload['phone'] ?? ''));
        if (strlen($p) === 11 && $p[0] === '1') $p = substr($p, 1);
        if ($p !== '') DB::table('lead_drafts')->where('phone_digits', $p)->whereNull('completed_lead_id')->update(['completed_lead_id' => $lead->id, 'updated_at' => now()]);
    }

    /** Open drafts (not completed, not dismissed), newest first. */
    public static function open(): array
    {
        return DB::table('lead_drafts')->whereNull('completed_lead_id')->whereNull('dismissed_at')
            ->where('updated_at', '>=', now()->subDays(self::KEEP_DAYS))->orderByDesc('updated_at')->limit(100)->get()
            ->map(fn ($r) => (object) [...(array) $r, 'fields' => json_decode($r->fields, true) ?: []])->all();
    }

    public static function prune(): int
    {
        return DB::table('lead_drafts')->where('updated_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }
}
