<?php
namespace App\Services\Portal;

use App\Models\{Customer,ImportedProjectRevision};
use App\Services\Finance\OpeningFinanceSummary;
use App\Services\Imports\ImportPersistence;

final class ReadOpeningPortalViewModel
{
    public function read(PortalContext $client,string $lang='es'):array
    {
        abort_unless(in_array($lang,['es','en'],true),422);
        return ImportPersistence::transaction(function()use($client,$lang){
            $a=OpeningPortalAccess::resolve($client);$e=$a['event'];$f=$a['finance'];$o=$a['opening']['opening'];
            $c=Customer::whereKey($e->customer_id)->sharedLock()->firstOrFail();
            $source=ImportedProjectRevision::whereKey($o['source_revision_id'])->sharedLock()->firstOrFail()->source_snapshot;
            $money=OpeningFinanceSummary::forEvent($e);
            return ['schema_version'=>1,'kind'=>'imported_agreement','lang'=>$lang,'test_mode'=>true,
                'first_name'=>preg_split('/[\s\p{Z}]+/u',trim($c->name),2)[0]??'',
                'event'=>$e->only(['title','starts_at','ends_at','timezone','address']),
                'agreement'=>['origin'=>'honeybook','package_description'=>$source['services'],'characters_description'=>$source['characters'],
                    'currency'=>$f->currency,'package_cents'=>$f->subtotal_cents,'travel_fee_cents'=>$f->travel_fee_cents,'discount_cents'=>$f->discount_cents,
                    'original_total_cents'=>$f->total_cents,'original_difference_cents'=>$f->total_cents-($f->subtotal_cents+$f->travel_fee_cents-$f->discount_cents),
                    'document'=>['sha256'=>$o['document_sha256'],'source_contract_signed'=>$o['source_contract_signed'],
                        'owner_attests_signed_original'=>$o['owner_attests_signed_original'],'pdf_url'=>'/portal/api/original-agreement/pdf']],
                'money'=>['state'=>($money['collection_blocked']??true)?'review_required':'known','recorded_paid_cents'=>$money['net_received_cents']??null,
                    'balance_cents'=>$money['balance_cents']??null,'credit_cents'=>$money['credit_cents']??null,
                    'reviewed_due_at'=>$f->balance_due_at?->toIso8601String(),'source_due_date'=>$o['source_balance_due_date'],'source_due_precision'=>$o['source_due_precision']],
                'actions'=>['can_sign'=>false,'can_checkout'=>false,'can_submit_details'=>false,'can_report_zelle'=>false]];
        });
    }
}
