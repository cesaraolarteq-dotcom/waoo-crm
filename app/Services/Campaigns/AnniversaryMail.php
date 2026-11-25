<?php
namespace App\Services\Campaigns;

use App\Models\{AnniversaryCode, Customer};
use App\Services\Mail\{CampaignOutbox, MailDeliveryGate, MailGuard};
use Illuminate\Support\Facades\URL;

/**
 * ANNIV-01 delivery (César 2026-10-29: design option B, English only, no child name, signed by the team; reminder on day 6).
 * Sends only when BOTH the general mail gate and the campaign switch (WAOO_ANNIVERSARY_MAIL_ENABLED) are on; with either
 * off nothing leaves the CRM and the codes stay "ready". Never to a client who unsubscribed or has no email.
 */
final class AnniversaryMail
{
    public const SITE = 'https://waooparty.com';

    public static function enabled(): bool
    {
        return MailDeliveryGate::enabled() && (bool) config('waoo.anniversary_mail_enabled', false);
    }

    /** @return array{subject:string,html:string,text:string} */
    public static function render(AnniversaryCode $row, string $kind): array
    {
        return self::renderFor($row, Customer::findOrFail($row->customer_id), $kind);
    }

    /** @return array{subject:string,html:string,text:string} */
    public static function renderFor(AnniversaryCode $row, Customer $customer, string $kind): array
    {
        $first = trim(strtok(trim((string) $customer->name), ' ') ?: '') ?: 'there';
        $expires = $row->expires_at->setTimezone('America/Chicago')->locale('en')->translatedFormat('l, F j').', 11:59 p.m.';
        $subject = $kind === 'reminder' ? 'Your Waoo gift ends tomorrow' : 'We’d love to celebrate together again';
        $vars = ['kind' => $kind, 'subject' => $subject, 'firstName' => $first, 'code' => $row->code, 'expires' => $expires,
            'preheader' => $kind === 'reminder' ? "Your 10% code {$row->code} ends tomorrow." : 'A gift from us for your next celebration.',
            'site' => self::SITE, 'bookUrl' => self::SITE.'/contact?promo='.rawurlencode($row->code),
            // Owner 2026-11-25: one tap to WhatsApp with the code already written, and a link to compare packages.
            'waUrl' => 'https://wa.me/13468518830?text='.rawurlencode("Hi Waoo Party! I'd like to use my gift code {$row->code} for my next celebration. / ¡Hola! Quiero usar mi código de regalo {$row->code} para mi próxima fiesta. 🎉"),
            'packagesUrl' => self::SITE.'/packages',
            // Owner 2026-11-25 (sell more with real data only): the party month, the live Google rating, holiday characters in season.
            'partyMonth' => $row->anchor_date ? \Carbon\CarbonImmutable::parse($row->anchor_date)->locale('en')->monthName : null,
            'rating' => self::rating(),
            'seasonal' => in_array((int) now('America/Chicago')->month, [11, 12, 1], true),
            'lastPackage' => self::lastPackage($row),
            'font' => self::FONTS[config('waoo.anniversary_font', 'poppins')][0] ?? self::FONTS['poppins'][0],
            'accentFont' => self::FONTS[config('waoo.anniversary_font', 'poppins')][1] ?? self::FONTS['poppins'][1],
            'accentStyle' => self::FONTS[config('waoo.anniversary_font', 'poppins')][2] ?? 'italic',
            'unsubscribeUrl' => URL::signedRoute('promo.unsubscribe', ['customer' => $customer->id]),
            'address' => (string) config('waoo.postal_address'),
            'parade' => [['src' => self::SITE.'/wall-photos/EC11A3FB-C4E6-4DB6-99AC-75D37530677D.jpg', 'alt' => 'Chase from Paw Patrol', 'ring' => '#15D1C4'],
                ['src' => self::SITE.'/wall-photos/00195F7D-191E-4F28-8867-B1096BC01F65.jpg', 'alt' => 'Rapunzel', 'ring' => '#FF017C'],
                ['src' => self::SITE.'/wall-photos/IMG_2596.jpg', 'alt' => 'Mickey, Minnie and Olaf', 'ring' => '#F9C930'],
                ['src' => self::SITE.'/wall-photos/7ea8fd24-0fda-44f5-ab8a-fa64f0c7f136.jpg', 'alt' => 'Elsa and Anna', 'ring' => '#8D53D9'],
                ['src' => self::SITE.'/wall-photos/16C253F2-96E6-406E-B906-22B6EB0EE6FF.jpg', 'alt' => 'Skye from Paw Patrol', 'ring' => '#15D1C4']]];
        $html = view('mail.anniversary', $vars)->render();
        $text = $kind === 'reminder'
            ? "Hi {$first},\n\nJust a friendly reminder: your 10% gift for your next celebration ends tomorrow.\n\nYour code: {$row->code}\nValid until {$expires} (Houston time).\n\nCheck your date: {$vars['bookUrl']}\nBook on WhatsApp: {$vars['waUrl']}\n\nWith love, the Waoo Party team"
            : "Hi {$first},\n\n".($vars['partyMonth'] ? 'Last '.$vars['partyMonth'] : 'Almost a year ago')." we were part of your celebration, and it was a joy to share it with you.".($vars['lastPackage'] ? " You chose our {$vars['lastPackage']['name']}. Book it again and your gift saves you {$vars['lastPackage']['saving']}." : '')." That's why we have this gift for you:\n\n10% off the packages and add-ons of your next party.\nYour code: {$row->code}\nValid until {$expires} (Houston time). Regular prices only; can't be combined with any other promotion. The travel fee is charged in full.\n\nCheck your date: {$vars['bookUrl']}\nBook on WhatsApp: {$vars['waUrl']}\nNot sure which package to choose? {$vars['packagesUrl']}\n\nWith love, the Waoo Party team";
        $text .= "\n\n{$vars['address']}\nUnsubscribe from promotions: {$vars['unsubscribeUrl']}";
        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    /**
     * Plans and sends due main emails and day-6 reminders through the durable outbox (CampaignOutbox, Codex review #163).
     * Each code + kind is one outbox message, planned together with the code's claim; an unknown provider outcome is
     * never reset or resent. @return array{sent:int,reminders:int,held:int,unknown:int,blocked:int}
     */
    public function sendDue(): array
    {
        $out = ['sent' => 0, 'reminders' => 0, 'held' => 0, 'unknown' => 0, 'blocked' => 0];
        $due = AnniversaryCode::whereIn('state', ['ready', 'sent'])->where('expires_at', '>', now())->orderBy('id')->limit(200)->get();
        foreach ($due as $row) {
            $kind = $row->sent_at === null ? 'main' : ($row->reminder_sent_at === null && now()->greaterThanOrEqualTo($row->expires_at->setTimezone('America/Chicago')->subDay()->startOfDay()) ? 'reminder' : null);
            if ($kind === null) continue;
            $customer = Customer::find($row->customer_id);
            if (! $customer || blank($customer->email) || ! filter_var($customer->email, FILTER_VALIDATE_EMAIL) || $customer->promo_opt_out_at !== null) continue;
            if (! self::enabled() || ! CampaignOutbox::canPlan($customer->email)) { $out['held']++; continue; }
            // Plan the message and claim the code in one transaction: two runs never plan the same email twice.
            $message = MailGuard::transaction(function () use ($row, $kind) {
                $fresh = AnniversaryCode::whereKey($row->id)->lockForUpdate()->first();
                $customer = $fresh ? Customer::whereKey($fresh->customer_id)->lockForUpdate()->first() : null;
                if (! $fresh || ! $customer || $customer->promo_opt_out_at !== null || $fresh->state === 'redeemed'
                    || ($kind === 'main' ? $fresh->sent_at !== null : $fresh->reminder_sent_at !== null)) return null;
                $m = CampaignOutbox::plan('anniversary_'.$kind, 'anniversary|'.$fresh->id.'|'.$kind.'|email', $customer->email, 'en',
                    self::renderFor($fresh, $customer, $kind), ['customer_id' => $customer->id]);
                $fresh->forceFill($kind === 'main' ? ['sent_at' => now(), 'state' => 'sent'] : ['reminder_sent_at' => now()])->save();
                return $m;
            });
            if ($message === null) continue;
            $status = app(CampaignOutbox::class)->dispatch($message->id, function () use ($row, $kind, $message) {
                if (! self::enabled()) return 'campaign_held';
                $customer = Customer::whereKey($row->customer_id)->lockForUpdate()->first();
                if (! $customer || $customer->promo_opt_out_at !== null) return 'promo_opt_out';
                $fresh = AnniversaryCode::find($row->id);
                if (! $fresh || $fresh->customer_id !== $customer->id || $fresh->state !== 'sent' || ! $fresh->expires_at->isFuture()) return 'code_no_longer_due';
                $payload = ['recipient' => mb_strtolower((string) $customer->email), ...self::renderFor($fresh, $customer, $kind)];
                return hash_equals($message->source_sha256, \App\Services\Finance\MutationKey::hash($payload)) ? null : 'source_changed';
            });
            match ($status) {
                'sent' => $out[$kind === 'main' ? 'sent' : 'reminders']++,
                'blocked' => $out['blocked']++,
                default => $out['unknown']++,
            };
        }
        return $out;
    }

    /** Email font choices (owner 2026-11-25): [body stack, accent stack, accent style]. Gmail shows the fallback (Arial). */
    public const FONTS = [
        'poppins' => ["'Poppins','Helvetica Neue',Helvetica,Arial,sans-serif", "'Poppins','Helvetica Neue',Arial,sans-serif", 'italic'],
        'fredoka' => ["'Fredoka','Trebuchet MS','Helvetica Neue',Arial,sans-serif", "'Fredoka','Trebuchet MS',Arial,sans-serif", 'normal'],
    ];

    /** Live Google rating from the website (cached 12 h); null when unavailable, so the email simply leaves it out. */
    public static function rating(): ?array
    {
        return \Illuminate\Support\Facades\Cache::remember('waoo.anniversary.google_rating', 43200, function () {
            try {
                $r = \Illuminate\Support\Facades\Http::timeout(4)->get(self::SITE.'/google-rating.php')->json();
            } catch (\Throwable) {
                return null;
            }
            return is_array($r) && is_numeric($r['rating'] ?? null) && (int) ($r['count'] ?? 0) > 0 && is_string($r['url'] ?? null) && str_starts_with($r['url'], 'https://')
                ? ['rating' => (float) $r['rating'], 'count' => (int) $r['count'], 'url' => $r['url']] : null;
        }) ?: null;
    }

    /**
     * Owner 2026-11-25: «show the saving on the package they chose last time». The package of the anchor party (HoneyBook
     * line items or CRM services) and 10 % of its CURRENT catalog price; null when it cannot be matched, so the line is left out.
     * @return array{name:string, saving:string}|null
     */
    public static function lastPackage(AnniversaryCode $row): ?array
    {
        try {
            [$source, $id] = array_pad(explode(':', (string) $row->anchor_key, 2), 2, null);
            $titles = [];
            if ($source === 'history' && ($event = \App\Models\HistoricalEvent::find((int) $id))) {
                $p = \App\Services\History\HistoricalIntegrity::revision($event, \App\Models\HistoricalEventRevision::findOrFail($event->current_revision_id));
                foreach ((array) ($p['source']['line_items'] ?? []) as $item) $titles[] = is_array($item) ? (string) ($item['title'] ?? $item['name'] ?? '') : (string) $item;
                if (count($titles) === 1 && str_contains($titles[0], ';')) $titles = explode(';', $titles[0]);
            } elseif ($source === 'crm' && ($event = \App\Models\Event::find((int) $id))) {
                $titles = $event->services()->get()->map(fn ($s) => (string) \App\Models\ServiceVariant::find($s->service_variant_id)?->name_en ?: (string) \App\Models\ServiceVariant::find($s->service_variant_id)?->name)->all();
            }
            foreach ($titles as $title) {
                $name = trim(explode('|', $title)[0]);
                $key = \Illuminate\Support\Str::ascii(mb_strtolower(trim(preg_replace('/\s+/u', ' ', preg_replace('/\(.*\)/u', '', $name)))));
                $crm = config('honeybook_aliases.packages')[$key] ?? null;
                $variant = $crm ? \App\Models\ServiceVariant::where('name', $crm)->where('status', 'active')->first()
                    : \App\Models\ServiceVariant::where(fn ($q) => $q->where('name', $name)->orWhere('name_en', $name))->where('status', 'active')->first();
                if (! $variant || (int) $variant->character_quantity === 0 && ! preg_match('/package|paquete|waoo|star|magic/i', $name)) continue;
                $price = \App\Models\PriceVersion::where('service_variant_id', $variant->id)->orderByDesc('id')->value('amount_cents');
                if (! $price) continue;
                $saving = intdiv((int) $price * self::PERCENT_FOR_SAVING + 50, 100);
                return ['name' => $name, 'saving' => '$'.number_format($saving / 100, $saving % 100 === 0 ? 0 : 2)];
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }

    private const PERCENT_FOR_SAVING = AnniversaryCampaign::PERCENT;
}
