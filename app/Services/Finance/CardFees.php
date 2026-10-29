<?php

namespace App\Services\Finance;

use App\Models\PaymentIntent;
use Illuminate\Validation\ValidationException;

final class CardFees
{
    public const POLICY='credit-3pct-v1';

    public static function enabled(): bool { return config('waoo.regular_card_fee_enabled')===true; }

    public static function ready(): bool
    {
        $account=config('waoo.stripe_test_account');
        return self::enabled() && is_string($account) && str_starts_with($account,'acct_')
            && config('waoo.stripe_credit_only_account')===$account;
    }

    public static function quote(int $principal): array
    {
        if($principal<1 || $principal>99_999_999)self::reject();
        $fee=intdiv($principal*3+50,100);$gross=$principal+$fee;
        if($gross>99_999_999)self::reject();
        return ['policy'=>self::POLICY,'currency'=>'USD','principal_cents'=>$principal,'customer_fee_cents'=>$fee,'payable_cents'=>$gross];
    }

    public static function publicQuote(int $eventId,int $revisionId,string $purpose,int $principal): ?array
    {
        if($principal<=0)return null;
        try{$terms=self::quote($principal);}catch(ValidationException){return null;}
        return [...$terms,'card_principal'=>self::money($principal),'card_fee'=>self::money($terms['customer_fee_cents']),
            'card_total'=>self::money($terms['payable_cents']),'card_quote_sha256'=>MutationKey::hash(['event_id'=>$eventId,'revision_id'=>$revisionId,'purpose'=>$purpose,...$terms]),
            'enabled'=>self::enabled(),'credit_only_ready'=>self::ready()];
    }

    public static function fingerprint(PaymentIntent $intent): string
    {
        return MutationKey::hash($intent->only(['id','event_id','financial_revision_id','environment','purpose','currency','principal_cents','customer_fee_cents','payable_cents','card_fee_policy']));
    }

    public static function valid(PaymentIntent $intent): bool
    {
        if($intent->currency!=='USD' || $intent->environment!=='test' || $intent->principal_cents<1)return false;
        if($intent->card_fee_policy===null)return $intent->card_fee_terms_sha256===null && $intent->customer_fee_cents===0 && $intent->principal_cents===$intent->payable_cents;
        try{$terms=self::quote($intent->principal_cents);}catch(ValidationException){return false;}
        return $intent->card_fee_policy===self::POLICY && $intent->currency==='USD' && $intent->customer_fee_cents===$terms['customer_fee_cents']
            && $intent->payable_cents===$terms['payable_cents'] && is_string($intent->card_fee_terms_sha256)
            && hash_equals(self::fingerprint($intent),$intent->card_fee_terms_sha256);
    }

    public static function reject(): never
    {
        throw ValidationException::withMessages(['amount_cents'=>'Revisa el saldo y el importe de este abono. No se cobra de más.']);
    }
    private static function money(int $cents): string { return '$'.number_format($cents/100,2,'.',','); }
}
