<?php
namespace App\Services\Billing;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Contract,Event,Quote,QuoteRevision,User};
use App\Services\Audit;
use App\Services\Finance\{MutationKey,Sandbox};
use App\Services\Records\RecordInput;
final class BillingCompany
{
    public static function rules(): array {return ['billing_company'=>'sometimes|nullable|array:name,email,address',
        'billing_company.name'=>'required_with:billing_company|string|max:255','billing_company.email'=>'sometimes|nullable|email|max:255','billing_company.address'=>'sometimes|nullable|string|max:1000'];}
    public static function input(array $input): array {
        if(!array_key_exists('billing_company',$input))return $input;
        Sandbox::require('sandbox_company');$data=RecordInput::validate($input,self::rules());
        $value=$data['billing_company'];
        if($value!==null){RecordWriteRejected::unless(is_string($value['name']??null)&&trim($value['name'])!=='',422,'Escribe el nombre de la empresa. / Enter the company name.');$value['name']=trim($value['name']);RecordWriteRejected::unless($value['name']!=='',422,'Escribe el nombre de la empresa. / Enter the company name.');
            foreach(['email','address'] as $key)if(empty($value[$key]))unset($value[$key]);ksort($value);}
        $input['billing_company']=$value;return $input;
    }
    /** Caller holds Event lock. Billing metadata never invalidates capacity assignments. */
    public static function apply(Event $event,?array $company,?User $actor): void {
        Sandbox::require('sandbox_company');
        if($actor)\App\Services\Records\RecordActor::authorize($actor);
        if(MutationKey::hash(['company'=>$event->billing_company])===MutationKey::hash(['company'=>$company]))return;
        foreach(Contract::where('event_id',$event->id)->whereIn('state',['issued','signed'])->orderByDesc('revision')->limit(1)->get() as $contract){
            RecordWriteRejected::unless(($contract->commercial_snapshot['billing_company']??null)===$company,409,'La empresa difiere del contrato vigente. Revisa el documento antes de cambiarla.');
        }
        foreach(Quote::where('event_id',$event->id)->where('status','accepted')->get() as $quote){
            $revision=QuoteRevision::where('quote_id',$quote->id)->where('revision',$quote->current_revision)->firstOrFail();
            RecordWriteRejected::unless(($revision->event_snapshot['billing_company']??null)===$company,409,'La empresa difiere de la cotización aceptada.');
        }
        $event->forceFill(['billing_company'=>$company,'billing_company_version'=>(int)$event->billing_company_version+1])->save();
        $metadata=['event_id'=>$event->id,'billing_company_version'=>$event->billing_company_version,'has_company'=>$company!==null];
        if($actor)Audit::recordFor($actor,'event.billing_company_saved',$event,[],$metadata);else Audit::recordSystem('event.billing_company_saved',$event,[],$metadata);
    }
    public static function person(Event $event): string {
        if(filled($event->customer?->name))return $event->customer->name;
        $signed=Contract::where('event_id',$event->id)->where('state','signed')->orderByDesc('revision')->first();
        $client=$signed?->commercial_snapshot['client']??[];
        return trim(($client['first_name']??'').' '.($client['last_name']??'')) ?: 'Cliente por completar / Customer details pending';
    }
    public static function snapshot(?array $company): array {return $company===null?[]:['billing_company'=>$company];}
}
