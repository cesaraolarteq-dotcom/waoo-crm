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
            'unsubscribeUrl' => URL::signedRoute('promo.unsubscribe', ['customer' => $customer->id]),
            'address' => (string) config('waoo.postal_address'),
            'parade' => [['src' => self::SITE.'/wall-photos/EC11A3FB-C4E6-4DB6-99AC-75D37530677D.jpg', 'alt' => 'Chase from Paw Patrol', 'ring' => '#15D1C4'],
                ['src' => self::SITE.'/wall-photos/00195F7D-191E-4F28-8867-B1096BC01F65.jpg', 'alt' => 'Rapunzel', 'ring' => '#FF017C'],
                ['src' => self::SITE.'/wall-photos/IMG_2596.jpg', 'alt' => 'Mickey, Minnie and Olaf', 'ring' => '#F9C930'],
                ['src' => self::SITE.'/wall-photos/7ea8fd24-0fda-44f5-ab8a-fa64f0c7f136.jpg', 'alt' => 'Elsa and Anna', 'ring' => '#8D53D9'],
                ['src' => self::SITE.'/wall-photos/16C253F2-96E6-406E-B906-22B6EB0EE6FF.jpg', 'alt' => 'Skye from Paw Patrol', 'ring' => '#15D1C4']]];
        $html = view('mail.anniversary', $vars)->render();
        $text = $kind === 'reminder'
            ? "Hi {$first},\n\nJust a friendly reminder: your 10% gift for your next celebration ends tomorrow.\n\nYour code: {$row->code}\nValid until {$expires} (Houston time).\n\nCheck your date: {$vars['bookUrl']}\n\nWith love, the Waoo Party team"
            : "Hi {$first},\n\nAlmost a year ago we were part of your celebration, and it was a joy to share it with you. That's why we have this gift for you:\n\n10% off the packages and add-ons of your next party.\nYour code: {$row->code}\nValid until {$expires} (Houston time). Regular prices only; can't be combined with any other promotion. The travel fee is charged in full.\n\nCheck your date: {$vars['bookUrl']}\n\nWith love, the Waoo Party team";
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
}
