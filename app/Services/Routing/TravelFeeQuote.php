<?php
namespace App\Services\Routing;

use App\Models\{TravelFeeQuoteRecord, User};
use App\Services\Finance\Sandbox;
use App\Services\Records\{RecordActor, RecordInput};
use Illuminate\Support\Facades\DB;

final class TravelFeeQuote
{
    public function forAddress(User $actor, string $address, ?string $zip): array
    {
        Sandbox::require('sandbox_routes'); $actor = RecordActor::authorize($actor);
        RecordInput::validate(['address' => $address, 'zip' => $zip], ['address' => 'required|string|min:10|max:255', 'zip' => ['nullable','string','regex:/^\d{5}(?:-\d{4})?$/']]);
        $inputs = TravelFeeRules::normalize($address, $zip); $hash = TravelFeeRules::inputHash($inputs); $rulesHash = TravelFeeRules::hash();
        $cached = TravelFeeQuoteRecord::where('input_hash', $hash)->where('rules_hash', $rulesHash)->whereIn('status', ['ok','outside','review'])->where('expires_at', '>', now())->orderByDesc('id')->first();
        if ($cached) return $this->present($cached, true);
        $cfg = TravelFeeRules::rules(); $fee = null; $status = 'unavailable'; $breakdown = ['reason' => 'not_configured', 'special_zip' => false];
        if ($inputs['reason']) { $status = 'review'; $breakdown['reason'] = $inputs['reason']; }
        elseif ($inputs['outside']) { $status = 'outside'; $breakdown['reason'] = 'outside_texas'; }
        elseif (array_key_exists($inputs['zip'], $cfg['special_zips'])) { $status = 'ok'; $fee = (int) ($cfg['special_zips'][$inputs['zip']] * 100); $breakdown = ['special_zip' => true, 'route_type' => 'fixed_zip']; }
        elseif (is_string(config('waoo.google_routes_key')) && config('waoo.google_routes_key') !== '') {
            if (!$this->takeCall((int) $cfg['google_calls_per_day'])) $breakdown['reason'] = 'daily_cap';
            elseif (!$route = app(GoogleTravelRoute::class)->forAddress($address)) $breakdown['reason'] = 'provider_unavailable';
            else {
                $breakdown = [...TravelFeeRules::calculate($route['miles'], $route['toll_cents']), 'route_type' => $route['route_type'], 'special_zip' => false];
                if ($route['miles'] * 2 > $cfg['max_round_trip_miles']) { $status = 'review'; $breakdown['reason'] = 'distance_limit'; }
                else { $status = 'ok'; $fee = $breakdown['fee_cents']; }
                unset($breakdown['fee_cents']);
            }
        }
        $quote = TravelFeeQuoteRecord::create(['input_hash' => $hash, 'rules_hash' => $rulesHash, 'inputs' => $inputs, 'rules' => $cfg,
            'status' => $status, 'fee_cents' => $fee, 'breakdown' => $breakdown, 'expires_at' => $status === 'unavailable' ? now()->addMinutes(5) : now()->addDays($cfg['cache_days']), 'created_by' => $actor->id]);
        return $this->present($quote, false);
    }
    private function takeCall(int $limit): bool
    {
        $day = now()->setTimezone('America/Chicago')->toDateString();
        return DB::transaction(function () use ($day, $limit) {
            DB::table('travel_route_daily_usage')->insertOrIgnore(['day' => $day, 'calls' => 0]);
            $row = DB::table('travel_route_daily_usage')->where('day', $day)->lockForUpdate()->first();
            if ($row->calls >= $limit) return false;
            DB::table('travel_route_daily_usage')->where('day', $day)->increment('calls'); return true;
        }, 3);
    }
    private function present(TravelFeeQuoteRecord $quote, bool $cached): array
    {
        return ['quote_id' => $quote->id, 'fee_cents' => $quote->fee_cents, 'status' => $quote->status, 'breakdown' => $quote->breakdown,
            'rules_version' => $quote->rules['version'], 'quoted_at' => $quote->created_at->toIso8601String(), 'expires_at' => $quote->expires_at->toIso8601String(), 'cached' => $cached];
    }
}
