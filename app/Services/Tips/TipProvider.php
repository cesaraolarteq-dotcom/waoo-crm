<?php
namespace App\Services\Tips;
use App\Models\TipCheckoutIntent;
final class TipProvider {
    public static function url(mixed $url):bool {return is_string($url)&&filter_var($url,FILTER_VALIDATE_URL)&&parse_url($url,PHP_URL_SCHEME)==='https'&&parse_url($url,PHP_URL_HOST)==='checkout.stripe.com'&&!parse_url($url,PHP_URL_USER)&&!parse_url($url,PHP_URL_PASS)&&(!parse_url($url,PHP_URL_PORT)||parse_url($url,PHP_URL_PORT)===443);}
    public static function binding(TipCheckoutIntent $i,array $s):bool {
        $m=$s['metadata']??[];return is_string($s['id']??null)&&preg_match('/^cs_test_[A-Za-z0-9_]{1,220}$/D',$s['id'])&&($s['livemode']??null)===false
            &&is_string($s['_verified_account']??null)&&$s['_verified_account']===config('waoo.stripe_test_account')&&str_starts_with($s['_verified_account'],'acct_')
            &&(!$i->provider_account||$i->provider_account===$s['_verified_account'])&&(!$i->provider_session_id||$i->provider_session_id===$s['id'])
            &&($s['client_reference_id']??null)===$i->id&&($m['kind']??null)==='tip'&&($m['local_tip_intent_id']??null)===$i->id&&!isset($m['local_intent_id'])
            &&(string)($m['event_id']??'')===(string)$i->event_id&&(string)($m['grant_id']??'')===(string)$i->tip_grant_id
            &&($s['currency']??null)==='usd'&&is_int($s['amount_total']??null)&&$s['amount_total']===$i->amount_cents&&$i->environment==='test'&&$i->currency==='USD';
    }
}
