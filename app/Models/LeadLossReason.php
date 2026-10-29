<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Why a website lead was lost (owner 2026-10-28). Kept only while the lead stays "lost". */
class LeadLossReason extends Model
{
    public const LABELS = ['date_full' => 'Fecha llena', 'price' => 'Precio', 'no_reply' => 'No respondió', 'other_vendor' => 'Eligió a otro', 'other' => 'Otro motivo'];

    protected $guarded = ['id'];

    public static function sync(int $leadId, string $status, ?string $reason, ?int $userId): void
    {
        if ($status !== 'lost') {
            self::where('lead_id', $leadId)->delete();

            return;
        }
        if (isset(self::LABELS[(string) $reason])) self::updateOrCreate(['lead_id' => $leadId], ['reason' => $reason, 'set_by' => $userId]);
    }
}
