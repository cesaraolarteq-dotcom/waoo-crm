<?php
namespace App\Services\Quotes;

use App\Models\{Event, Quote};
use App\Services\Audit;
use App\Services\Finance\Sandbox;
use App\Services\Records\RecordInput;
use Illuminate\Support\Facades\DB;

final class ExpireQuotes
{
    public function execute(int $limit = 100): int
    {
        Sandbox::require('sandbox_quotes'); Sandbox::require();
        RecordInput::validate(['limit' => $limit], ['limit' => 'integer|min:1|max:500']); $count = 0;
        $candidates = Quote::whereIn('status', ['draft','sent'])->where('valid_until', '<=', now())->orderBy('id')->limit($limit)->get(['id','event_id']);
        foreach ($candidates as $candidate) $count += DB::transaction(function () use ($candidate) {
            Event::whereKey($candidate->event_id)->lockForUpdate()->firstOrFail();
            $quote = Quote::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if (!in_array($quote->status, ['draft','sent'], true) || $quote->valid_until->isFuture()) return 0;
            $before = $quote->only(['status','version']);
            $quote->forceFill(['status' => 'expired', 'version' => $quote->version + 1])->save();
            Audit::recordSystem('quote.expired', $quote, $before, $quote->only(['status','version'])); return 1;
        }, 3);
        return $count;
    }
}
