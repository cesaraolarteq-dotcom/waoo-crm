<?php
namespace App\Services\Campaigns;

use App\Models\{AnniversaryCode, Customer};
use App\Services\Mail\{MailDeliveryGate, MailTransport, OutboundEnvelope};
use Illuminate\Support\Facades\{DB, URL};

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

    /** Sends due main emails and day-6 reminders. @return array{sent:int,reminders:int,held:int} */
    public function sendDue(): array
    {
        $out = ['sent' => 0, 'reminders' => 0, 'held' => 0];
        $due = AnniversaryCode::whereIn('state', ['ready', 'sent'])->where('expires_at', '>', now())->orderBy('id')->limit(200)->get();
        foreach ($due as $row) {
            $kind = $row->sent_at === null ? 'main' : ($row->reminder_sent_at === null && now()->greaterThanOrEqualTo($row->expires_at->setTimezone('America/Chicago')->subDay()->startOfDay()) ? 'reminder' : null);
            if ($kind === null) continue;
            $customer = Customer::find($row->customer_id);
            if (! $customer || blank($customer->email) || $customer->promo_opt_out_at !== null) continue;
            if (! self::enabled()) { $out['held']++; continue; }
            // Claim the row first so two runs never send the same email twice.
            $claimed = DB::transaction(function () use ($row, $kind) {
                $fresh = AnniversaryCode::whereKey($row->id)->lockForUpdate()->first();
                if (! $fresh || ($kind === 'main' ? $fresh->sent_at !== null : $fresh->reminder_sent_at !== null) || $fresh->state === 'redeemed') return false;
                $fresh->forceFill($kind === 'main' ? ['sent_at' => now(), 'state' => 'sent'] : ['reminder_sent_at' => now()])->save();
                return true;
            });
            if (! $claimed) continue;
            $mail = self::render($row->fresh(), $kind);
            try {
                app(MailTransport::class)->send(new OutboundEnvelope($row->id, $customer->email, $mail['subject'], $mail['html'], $mail['text']));
                $out[$kind === 'main' ? 'sent' : 'reminders']++;
            } catch (\Throwable) {
                // Undo the claim so the next run retries; never log the address or provider payload.
                AnniversaryCode::whereKey($row->id)->update($kind === 'main' ? ['sent_at' => null, 'state' => 'ready'] : ['reminder_sent_at' => null]);
            }
        }
        return $out;
    }
}
