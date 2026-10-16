<?php
namespace App\Services\Portal;

use App\Models\ZelleNotice;

final class ZelleNoticeView
{
    public static function client(ZelleNotice $notice): array
    {
        $review = $notice->review;
        return [...$notice->only(['id','financial_revision_id','amount_cents','reference','payer_name','sent_at','note','submitted_at']),
            'state' => $review?->decision ?? 'pending', 'reviewed_at' => $review?->reviewed_at,
            'verified_amount_cents' => $review?->decision === 'verified' ? $review->ledgerEntry?->amount_cents : null];
    }
}
