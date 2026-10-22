<?php
namespace App\Services\MigrationVerification;

use App\Models\{Customer,Event,HistoricalEvent,HistoricalEventRevision,HoneyBookEventFacts,HoneyBookOriginal,ImportedLedgerLine,ImportedProjectLink,ImportedProjectRevision,LedgerEntry};
use Illuminate\Support\Facades\DB;

/** Deliberately independent: reads raw API fields and stored evidence, not importer mapping code. */
final class VerifyHoneyBookMigration
{
    private array $issues=[];
    private array $projects=[];
    private array $source=[];
    private array $used=[];
    private int $matches=0;
    private int $mismatches=0;
    private int $unverified=0;
    public function execute(#[\SensitiveParameter] array $snapshot,?string $directory=null): array
    {
        $this->issues=$snapshot['issues'];$this->projects=[];$this->source=[];$this->used=[];
        $this->matches=$this->mismatches=$this->unverified=0;
        return DB::transaction(function()use($snapshot,$directory){
            $destination=[];$duplicates=[];
            foreach(HistoricalEvent::orderBy('id')->get() as $head){ try {
                $r=HistoricalEventRevision::findOrFail($head->current_revision_id);$p=$r->payload;
                if(!$this->envelope($p,$r->payload_sha256)||($p['customer_id']??null)!==$head->customer_id||$r->customer_id!==$head->customer_id
                    ||$r->version!==$head->version||$r->historical_event_id!==$head->id||$p['history_id']!==$head->id
                    ||$this->hash($p['source'])!==$r->source_sha256||$this->hash($p['facts'])!==$r->facts_sha256){
                    $this->issues[]=['code'=>'history_integrity','history_id'=>$head->id];continue;
                }
                $key=hash('sha256',$p['source']['project_id']);
                if($key!==$head->project_key)$this->issues[]=['code'=>'history_project_binding','history_id'=>$head->id];
                $destination[$key][]=['kind'=>'history','id'=>$head->id,'customer_id'=>$head->customer_id,'source'=>$p['source'],'facts'=>$p['facts'],'revision_id'=>$r->id,'classification_date'=>$r->created_at?->setTimezone('America/Chicago')->toDateString()];
            }catch(\Throwable){$this->issues[]=['code'=>'history_unreadable','history_id'=>$head->id];} }
            foreach(ImportedProjectLink::orderBy('id')->get() as $head){ try {
                $r=ImportedProjectRevision::findOrFail($head->current_revision_id);$event=Event::findOrFail($head->event_id);
                if($r->imported_project_link_id!==$head->id||$r->revision!==$head->version||$this->hash($r->source_snapshot)!==$r->source_sha256||($r->normalized['source_sha256']??null)!==$r->source_sha256)$this->issues[]=['code'=>'operating_revision_binding','link_id'=>$head->id];
                $destination[$head->project_key][]=['kind'=>'operating','id'=>$event->id,'customer_id'=>$event->customer_id,
                    'source'=>$r->source_snapshot,'facts'=>$r->normalized,'revision_id'=>$r->id,'classification_date'=>$r->created_at?->setTimezone('America/Chicago')->toDateString(),'event'=>$event];
            }catch(\Throwable){$this->issues[]=['code'=>'operating_unreadable','link_id'=>$head->id];} }
            foreach($destination as $key=>$rows)if(count($rows)!==1)$duplicates[]=$key;
            $excluded=[];
            foreach($snapshot['exclusions'] as $row){
                if(!is_string($row['project_id']??null)||trim($row['reason']??'')===''){$this->issues[]=['code'=>'invalid_exclusion'];continue;}
                $key=hash('sha256',$row['project_id']);
                if(isset($excluded[$key]))$this->issues[]=['code'=>'duplicate_exclusion','project_key'=>$key];
                $excluded[$key]=$row;
            }
            foreach($snapshot['records']['projects'] as $record){
                $raw=$record['value'];$id=$this->pick($raw,['id','_id','project_id','projectId'],$record,'project_id');
                if(!is_string($id)&&!is_int($id)){$this->issues[]=['code'=>'project_id_unverified','source'=>$record];continue;}
                $id=(string)$id;$key=hash('sha256',$id);
                if(isset($this->source[$key])){$this->issues[]=['code'=>'duplicate_source_project','project_id'=>$id];continue;}
                $this->source[$key]=$id;$rows=$destination[$key]??[];
                $entry=['project_id'=>$id,'project_key'=>$key,'destination_count'=>count($rows),'fields'=>[]];
                if(isset($excluded[$key])){
                    $entry['exclusion']=$excluded[$key]; if(($excluded[$key]['owner_approved']??false)!==true)$this->issues[]=['code'=>'exclusion_owner_evidence_unverified','project_id'=>$id];
                    if($rows)$this->issues[]=['code'=>'excluded_but_present','project_id'=>$id];
                }elseif(count($rows)!==1){$this->issues[]=['code'=>$rows?'duplicate_destination':'missing_destination','project_id'=>$id];}
                else {
                    $record['related']=$snapshot['project_relations'][$id]??[];
                    $record['capture_shape']=$snapshot['capture_shape']??'envelopes-v1';
                    foreach(($record['capture_shape']==='flat-lists-v1'?[]:$snapshot['records']['workspaces']) as $workspace){
                        $v=$workspace['value'];$wid=$v['id']??$v['_id']??null;
                        if((isset($v['project_id'])&&(string)$v['project_id']===$id)||($wid!==null&&(string)($raw['workspace_id']??$id)===(string)$wid)){
                            $record['related'][]=$workspace;
                            foreach(['id','_id','project_id'] as $identity)if(isset($v[$identity]))$this->used[$this->key($workspace,$identity)]=true;
                        }
                    }
                    if(isset($raw['workspace_id'])&&$record['related'])$this->used[$this->key($record,'workspace_id')]=true;
                    try{$entry=$this->compareProject($entry,$record,$rows[0],$snapshot);}catch(\Throwable){$this->issues[]=['code'=>'project_comparison_incomplete','project_id'=>$id];}
                }
                $entry['unverified_source_fields']=isset($entry['exclusion'])&&($entry['exclusion']['owner_approved']??false)===true?[]:$this->unread($raw,$record);
                $this->unverified+=count($entry['unverified_source_fields']);
                $this->projects[]=$entry;
            }
            $extras=array_values(array_diff(array_keys($destination),array_keys($this->source)));
            foreach(array_diff(array_keys($excluded),array_keys($this->source)) as $key)$this->issues[]=['code'=>'exclusion_not_in_source','project_key'=>$key];
            foreach($extras as $key)$this->issues[]=['code'=>'extra_destination_project','project_key'=>$key];
            $operatingIds=ImportedProjectLink::pluck('event_id')->all();
            foreach(HoneyBookEventFacts::select('event_id')->distinct()->get() as $fact)if(!in_array($fact->event_id,$operatingIds,true))$this->issues[]=['code'=>'extra_source_facts_event','event_id'=>$fact->event_id];
            $originals=$this->originals($snapshot,$directory);
            $unread=[];
            // Preserve every unrecognized field for private review, including contact/workspace/file responses.
            foreach(['contacts','workspaces','files','responses'] as $kind)foreach($snapshot['records'][$kind] as $record){
                $fields=$this->unread($record['value'],$record);
                if($fields){$unread[]=['kind'=>$kind,'file'=>$record['file'],'path'=>$record['path'],'fields'=>$fields];$this->unverified+=count($fields);}
            }
            $rawUnverified=array_sum(array_map(fn($p)=>count($p['unverified_source_fields']??[]),$this->projects))+array_sum(array_map(fn($r)=>count($r['fields']),$unread));
            $summary=['unverified_comparisons'=>$this->unverified-$rawUnverified,'unverified_raw_fields'=>$rawUnverified,'database_history_heads'=>HistoricalEvent::count(),'database_operating_links'=>ImportedProjectLink::count(),'source_projects'=>count($this->source),'historical_projects'=>count(array_filter($destination,fn($r)=>count($r)===1&&$r[0]['kind']==='history')),
                'operating_projects'=>count(array_filter($destination,fn($r)=>count($r)===1&&$r[0]['kind']==='operating')),
                'missing_projects'=>count(array_filter($this->issues,fn($i)=>$i['code']==='missing_destination')),'duplicate_source_projects'=>count(array_filter($this->issues,fn($i)=>$i['code']==='duplicate_source_project')),'excluded_projects'=>count($excluded),'duplicate_destination_keys'=>count($duplicates),'extra_projects'=>count($extras),
                'matched_fields'=>$this->matches,'different_fields'=>$this->mismatches,'unverified_fields'=>$this->unverified,
                'issues'=>count($this->issues),'originals'=>$originals['summary']];
            return ['verifier'=>'codex-independent-honeybook-v2','generated_at'=>now()->toIso8601String(),
                'snapshot_sha256'=>$snapshot['snapshot_sha256'],'summary'=>$summary,
                'verdict'=>!$this->issues&&!$this->mismatches&&!$this->unverified?'verified':'review_required',
                'human_double_review_complete'=>false,'projects'=>$this->projects,'issues'=>$this->issues,'unverified_source'=>$unread,'originals'=>$originals['details']];
        });
    }
    private function compareProject(array &$entry,array $record,array $target,array $snapshot): array
    {
        $entry['destination']=['kind'=>$target['kind'],'id'=>$target['id'],'revision_id'=>$target['revision_id']];
        $s=$target['source'];$f=$target['facts'];$raw=$record['value'];$history=$target['kind']==='history';
        $entry['capture_shape']=$record['capture_shape']??'envelopes-v1';
        $entry['raw_project_date']=$raw['project_date']??null;
        $entry['status_classification_date']=$target['classification_date']??null;
        $checks=[
            'project_status'=>[['project_status','status'], $s['project_status']??$s['status']??null],
            'start_time'=>[['start_time','project_time_start'], $s['start_time']??null],
            'end_time'=>[['end_time','project_time_end'], $s['end_time']??null],
            'end_date'=>[['end_date','project_end_date'], $s['end_date']??null],
            'project_name'=>[['title','name','project_name'], $s['project_name']??$s['title']??null],
            'event_date'=>[['event_date','eventDate','event.date','project_date'], $f['source_date']??($history?null:$target['event']->starts_at?->setTimezone('America/Chicago')->toDateString())],
            'postal_code'=>[['postal_code','event_zip','event.postal_code'], $s['postal_code']??$s['event_zip']??null],
            'event_type'=>[['event_type','eventType'], $s['event_type']??null],
            'lead_source'=>[['lead_source','lead_source_text','lead_source_label'], $s['lead_source_text']??$s['lead_source']??null],
            'created_at'=>[['created_at','createdAt'], $s['created_at']??null],
        ];
        foreach($checks as $field=>[$paths,$actual])$this->field($entry,$field,$this->pick($raw,$paths,$record,$field),$actual);
        foreach(['total'=>['total','booked_total','total_amount'],'paid'=>['paid_total','amount_paid'],'balance'=>['balance','balance_due']] as $field=>$paths){
            $value=$this->pick($raw,$paths,$record,$field);
            $expected=$value===null?null:$this->cents($value);
            if($expected!==null)$this->moneyPaths($record,$paths);
            $actual=$f[match($field){'total'=>$history?'booked_total_cents':'total_cents','paid'=>$history?'paid_total_cents':'paid_cents',default=>'balance_cents'}]??null;
            $this->field($entry,$field.'_cents',$expected,$actual,$value!==null&&$expected===null?'unsupported_money_units':null);
            $entry['fields'][$field.'_cents']['raw_source']=$value;
        }
        $signed=$this->pick($raw,['contract_signed','contractSigned'],$record,'contract_signed');
        $storedSigned=$history?($s['contract_signed']??null):($f['contract_signed']??null);
        $bool=fn($v)=>match($v){true,'true','yes'=>true,false,'false','no'=>false,default=>null};
        $this->field($entry,'contract_signed',$bool($signed),$bool($storedSigned),$signed!==null&&$bool($signed)===null?'unsupported_signature_status':null);
        $items=$this->pick($raw,['line_items'],$record,'line_items');
        if($items!==null){
            $expected=[];$unsupported=!is_array($items)||!array_is_list($items);
            foreach(is_array($items)?$items:[] as $item){
                if(!is_array($item)||!is_string($item['title']??null)||$this->cents($item['amount']??null)===null){$unsupported=true;continue;}
                if(array_diff(array_keys($item),['title','amount']))$unsupported=true;
                $expected[]=['title'=>$item['title'],'amount_cents'=>$this->cents($item['amount'])];
            }
            $actual=$history?array_map(fn($i)=>['title'=>$i['title'],'amount_cents'=>$this->cents($i['amount_text'])],$f['line_items']??[]):null;
            $this->field($entry,'line_items',$expected,$actual,!$history?'source_line_items_not_retained':($unsupported?'unsupported_line_item_fields_or_units':null));
            if(!$unsupported)foreach([$record,...($record['related']??[])] as $r)if(isset($r['value']['line_items'])&&!array_key_exists('line_items',$r['origins']??[]))$this->used[$this->key($r,'line_items')]=true;
        }
        $payments=$this->pick($raw,['payments'],$record,'payments');
        if($payments!==null){
            $expected=[];$unsupported=!is_array($payments)||!array_is_list($payments);
            foreach(is_array($payments)?$payments:[] as $payment){
                if(!is_array($payment)||!isset($payment['date'],$payment['amount'],$payment['method'])||$this->cents($payment['amount'])===null){$unsupported=true;continue;}
                $expected[]=['date'=>$payment['date'],'amount_cents'=>$this->cents($payment['amount']),'method'=>$payment['method']];
                if(array_diff(array_keys($payment),['date','amount','method']))$unsupported=true;
            }
            $actual=$history?array_map(fn($p)=>['date'=>$p['date'],'amount_cents'=>$this->cents($p['amount_text']),'method'=>$p['method']],$f['payments']??[])
                :ImportedLedgerLine::where('imported_project_revision_id',$target['revision_id'])->orderBy('source_position')->get()
                    ->map(fn($p)=>['date'=>$p->received_date->toDateString(),'amount_cents'=>$p->amount_cents,'method'=>$p->method])->all();
            $this->field($entry,'payments',$expected,$actual,$unsupported?'unsupported_payment_fields_or_units':null);
            if(!$unsupported)foreach([$record,...($record['related']??[])] as $r)if(isset($r['value']['payments'])&&!array_key_exists('payments',$r['origins']??[]))$this->used[$this->key($r,'payments')]=true;
        }
        if($payments===null&&(!$history||!empty($f['payments'])))$this->field($entry,'payment_details',null,$history?($f['payments']??[]):ImportedLedgerLine::where('imported_project_revision_id',$target['revision_id'])->get()->toArray(),'source_payment_details_absent');
        $contactId=$this->pick($raw,['contact_id','contactId','client.id','client._id','contact.id'],$record,'contact_id');
        if($history)$this->field($entry,'source_contact_id',$contactId,$s['contact_id']??null);
        else {
            $binding=$contactId===null?null:\App\Models\ExternalCustomerLink::where('source_system','honeybook')->where('contact_key',hash('sha256',(string)$contactId))->first();
            $this->field($entry,'source_contact_binding',$contactId===null?null:$target['customer_id'],$binding?->customer_id,$binding?null:'contact_binding_evidence_missing');
        }
        if($history && $contactId!==null){
            $binding=\App\Models\ExternalCustomerLink::where('source_system','honeybook')->where('contact_key',hash('sha256',(string)$contactId))->first();
            $actual=$binding?->customer_id;$basis='global_source_contact';$problem=$binding?null:'contact_binding_evidence_missing';
            if($binding){$p=$binding->payload;
                if(!$this->envelope($p,$binding->payload_sha256)||($p['contact_id']??null)!==(string)$contactId||($p['customer_id']??null)!==$binding->customer_id||($p['version']??null)!==$binding->version)$problem='contact_binding_integrity';
            }
            $correction=\App\Models\HistoricalCustomerRelink::where('historical_event_id',$target['id'])->orderByDesc('version')->first();
            if($correction){
                $p=$correction->payload;$r=HistoricalEventRevision::findOrFail($correction->revision_id);
                if($this->envelope($p,$correction->sha256)&&$p['history_id']===$target['id']&&$p['created_by']===$correction->created_by
                    &&$p['result']['customer_id']===$target['customer_id']&&$r->customer_id===$target['customer_id']&&$r->historical_event_id===$target['id']
                    &&$this->envelope($r->payload,$r->payload_sha256)&&$p['version']===$r->version){$actual=$p['result']['customer_id'];$basis='owner_project_correction';$problem=null;}
                else $problem='customer_correction_integrity';
            }
            $this->field($entry,'customer_binding',$target['customer_id'],$actual,$problem);
            $entry['fields']['customer_binding']['basis']=$basis;
        }
        $contacts=[];
        foreach($snapshot['records']['contacts'] as $contact){
            $id=$contact['value']['id']??$contact['value']['_id']??$contact['value']['contact_id']??$contact['value']['contactId']??null;
            if($id!==null&&(string)$id===(string)$contactId)$contacts[]=$contact;
        }
        if($contactId===null||count($contacts)!==1){$this->field($entry,'customer_contact',null,null,'contact_unresolved');}
        else{
            $contact=$contacts[0];$this->pick($contact['value'],['id','_id','contact_id','contactId'],$contact,'contact_id');
            $customer=Customer::findOrFail($target['customer_id']);
            $record['contact_record']=$contact;
            foreach(['name'=>['name','full_name','fullName'],'email'=>['email'],'phone'=>['phone','phone_number']] as $field=>$paths){
                $value=$this->pick($contact['value'],$paths,$contact,$field);
                $actual=$customer->$field;$problem=$value!==null&&!is_string($value)?'unsupported_contact_type':null;
                if($field==='name'){$value=$this->name($value);$actual=$this->name($actual);}
                if($field==='email'){$value=$value===null?null:mb_strtolower(trim($value));$actual=$actual===null?null:mb_strtolower(trim($actual));}
                if($field==='phone'){$original=$value;$value=$this->phone($value);$actual=$this->phone($actual);if($original!==null&&$original!==''&&$value===null)$problem='unsupported_source_phone';}
                $this->field($entry,'customer_'.$field,$value,$actual,$problem);
            }
        }
        $facts=$history?$s:[];
        if(!$history){
            $recordFacts=HoneyBookEventFacts::where('event_id',$target['id'])->orderByDesc('version')->first();
            if($recordFacts){
                $payload=$recordFacts->payload;
                if(!$this->envelope($payload,$recordFacts->sha256)||$payload['event_id']!==$target['id']||$payload['customer_id']!==$target['customer_id']||$payload['imported_revision_id']!==$target['revision_id']||$payload['version']!==$recordFacts->version||$payload['created_by']!==$recordFacts->created_by)$this->issues[]=['code'=>'facts_integrity_or_stale','event_id'=>$target['id']];
                else $facts=$payload['fields'];
            }
            $ledger=ImportedLedgerLine::where('imported_project_revision_id',$target['revision_id'])->get();
            $this->field($entry,'imported_ledger_paid_cents',$f['paid_cents']??null,(int)$ledger->sum('amount_cents'));
            if($target['event']->responsible_system==='honeybook'&&LedgerEntry::where('event_id',$target['id'])->exists())$this->issues[]=['code'=>'native_money_before_cutover','event_id'=>$target['id']];
        }
        foreach(['child_name','child_age','children_count','setup_area','event_language','notes','sms_consent_honeybook'] as $field){
            $actual=$facts[$field]??($field==='child_age'?($facts['celebrant_age']??null):null);
            $value=$this->pick($raw,[$field,'event.'.$field],$record,$field);
            // Explicit question keys only; never guess from similar human-language labels.
            if($value===null)foreach($snapshot['records']['responses'] as $response){
                $v=$response['value'];
                if((string)($v['project_id']??'')===$entry['project_id']&&($v['key']??null)===$field){
                    $value=$v['value']??null;$this->used[$this->key($response,'value')]=true;
                    $this->used[$this->key($response,'key')]=true;$this->used[$this->key($response,'project_id')]=true;
                }
            }
            $this->field($entry,$field,$value,$actual);
        }
        $this->sourceEvidence($entry,$record,$target);
        return $entry;
    }
    /** Reverse field coverage: populated CRM source evidence must also be corroborated, not just its typed facts. */
    private function sourceEvidence(array &$entry,array $record,array $target): void
    {
        $paths=[
            'project_name'=>['project_name','title','name'],'title'=>['title','name','project_name'],
            'project_status'=>['project_status','status'],'status'=>['status','project_status'],
            'event_date'=>['event_date','eventDate','event.date','project_date'],'start_time'=>['start_time','project_time_start'],'end_time'=>['end_time','project_time_end'],'end_date'=>['end_date','project_end_date'],
            'postal_code'=>['postal_code','event_zip','event.postal_code'],'event_zip'=>['event_zip','postal_code','event.postal_code'],
            'city'=>['city','event.city'],'currency'=>['currency','total.currency','booked_total.currency','total_amount.currency'],'archive_reason'=>['archive_reason'],
            'event_type'=>['event_type','eventType'],'lead_source'=>['lead_source','lead_source_text','lead_source_label'],'lead_source_text'=>['lead_source_text','lead_source','lead_source_label'],
            'created_at'=>['created_at','createdAt'],'child_name'=>['child_name','celebrant_name'],'celebrant_name'=>['celebrant_name','child_name'],
            'celebrant_age'=>['celebrant_age','child_age'],'children_count'=>['children_count'],'setup_area'=>['setup_area'],
            'event_language'=>['event_language'],'special_requests'=>['special_requests','notes'],'notes'=>['notes','special_requests'],
            'sms_consent_honeybook'=>['sms_consent_honeybook'],'event_address'=>['event_address','event.address'],
            'services'=>['services'],'characters'=>['characters'],
        ];
        $money=['booked_total'=>['booked_total','total','total_amount'],'total'=>['total','booked_total','total_amount'],
            'paid_total'=>['paid_total','amount_paid'],'amount_paid'=>['amount_paid','paid_total'],'balance'=>['balance','balance_due'],
            'package_total'=>['package_total'],'travel_fee'=>['travel_fee'],'discount'=>['discount']];
        $contact=$record['contact_record']??null;$source=$target['source'];
        foreach($source as $key=>$actual){
            if($actual===null||$actual==='')continue;
            $expected=null;$reason=null;
            if(in_array($key,['project_id','honeybook_project_id'],true))$expected=$entry['project_id'];
            elseif($key==='contact_id')$expected=$this->pick($record['value'],['contact_id','contactId','client.id','client._id','contact.id'],$record,'source_contact_id');
            elseif(in_array($key,['client_name','client_first_name','client_last_name','client_email','client_phone'],true)&&$contact){
                $field=match($key){'client_email'=>'email','client_phone'=>'phone',default=>'name'};
                $expected=$this->pick($contact['value'],match($field){'name'=>['name','full_name','fullName'],'phone'=>['phone','phone_number'],default=>['email']},$contact,$key);
                if($field==='name'){
                    if($key!=='client_name')$actual=trim(($source['client_first_name']??'').' '.($source['client_last_name']??''));
                    $expected=$this->name($expected);$actual=$this->name($actual);
                }elseif($field==='email'){$expected=is_string($expected)?mb_strtolower(trim($expected)):null;$actual=mb_strtolower(trim($actual));}
                else{$raw=$expected;$expected=$this->phone($expected);$actual=$this->phone($actual);if($raw!==null&&$expected===null)$reason='unsupported_source_phone';}
            }elseif(isset($money[$key])){
                $raw=$this->pick($record['value'],$money[$key],$record,$key);$expected=$this->cents($raw);$actual=$this->cents($actual);
                if($expected!==null)$this->moneyPaths($record,$money[$key]);
                if($raw!==null&&$expected===null)$reason='unsupported_money_units';
            }elseif($key==='contract_signed'){
                $raw=$this->pick($record['value'],['contract_signed','contractSigned'],$record,$key);
                $bool=fn($v)=>match($v){true,'true','yes'=>true,false,'false','no'=>false,default=>null};
                $expected=$bool($raw);$actual=$bool($actual);if($raw!==null&&$expected===null)$reason='unsupported_signature_status';
            }elseif(in_array($key,['payments','line_items'],true)){
                $rows=$this->pick($record['value'],[$key],$record,$key);
                if(is_array($rows)&&array_is_list($rows)){
                    $parts=[];$valid=true;
                    foreach($rows as $row){
                        $amount=is_array($row)?$this->cents($row['amount']??null):null;
                        if($amount===null||!isset($row[$key==='payments'?'date':'title'])||($key==='payments'&&!isset($row['method']))){$valid=false;break;}
                        $text=($amount<0?'-':'').intdiv(abs($amount),100).'.'.str_pad((string)(abs($amount)%100),2,'0',STR_PAD_LEFT);
                        $parts[]=$key==='payments'?$row['date'].'|'.$text.'|'.$row['method']:$row['title'].'|'.$text;
                    }
                    if($valid)$expected=implode(';',$parts);else $reason='unsupported_original_source_sequence';
                }else $reason='original_source_sequence_unverified';
            }elseif(isset($paths[$key]))$expected=$this->pick($record['value'],$paths[$key],$record,$key);
            else $reason='crm_source_field_not_corroborated';
            $this->field($entry,'source_snapshot.'.$key,$expected,$actual,$reason);
        }
    }
    private function moneyPaths(array $record,array $paths): void
    {
        foreach([$record,...($record['related']??[])] as $r)foreach($paths as $path){
            $value=$r['value'];foreach(explode('.',$path) as $part)$value=is_array($value)?($value[$part]??null):null;
            if(is_array($value)&&$this->cents($value)!==null){
                foreach(['amount_cents','amount','currency'] as $field)if(array_key_exists($field,$value))foreach($r['origins'][$path]??[str_replace('.','/',$path)] as $origin)$this->used[$this->key($r,$origin.'/'.$field)]=true;
            }
        }
    }
    private function field(array &$entry,string $name,mixed $expected,mixed $actual,?string $reason=null): void
    {
        if(($entry['capture_shape']??null)==='flat-lists-v1'){
            if(in_array($name,['project_status','source_snapshot.project_status','source_snapshot.status'],true)){
                if($expected==='client'&&in_array($actual,['completed','confirmed'],true)){
                    $date=$entry['raw_project_date']??null;
                    $temporal=is_string($date)&&isset($entry['status_classification_date'])&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)?($date<($entry['status_classification_date']??'')?'completed':'confirmed'):null;
                    $this->matches++;
                    $entry['fields'][$name]=['status'=>'matched','source'=>$expected,'crm'=>$actual,'basis'=>'honeybook-booked-vocabulary-v1','temporal_classification'=>$temporal===null?'unverified':($temporal===$actual?'matched':'different')];
                    if($temporal===null){$this->unverified++;$entry['fields'][$name]['reason']='source_date_absent_for_status_classification';}
                    elseif($temporal!==$actual){$this->matches--;$this->mismatches++;$entry['fields'][$name]['status']='different';}
                    return;
                }
                if($expected==='client'&&$actual==='cancelled')$reason='owner_cancellation_evidence_required';
                if($expected!==null&&!in_array($expected,['client','lead','lead_sent'],true))$reason='unsupported_source_status';
                if(in_array($expected,['lead','lead_sent'],true)&&in_array($actual,['lead','prospect'],true)){
                    $this->matches++;$entry['fields'][$name]=['status'=>'matched','source'=>$expected,'crm'=>$actual,'basis'=>'honeybook-lead-vocabulary-v1'];return;
                }
            }
            if(in_array($name,['sms_consent_honeybook','source_snapshot.sms_consent_honeybook'],true)){
                $bool=fn($v)=>match($v){true,'true','yes'=>true,false,'false','no'=>false,default=>null};
                $sourceConsent=$bool($expected);$storedConsent=$bool($actual);
                if(($expected!==null&&$sourceConsent===null)||($actual!==null&&$actual!==''&&$storedConsent===null))$reason='unsupported_origin_consent_vocabulary';
                else {$expected=$sourceConsent;$actual=$storedConsent;}
            }
            if(in_array($name,['start_time','end_time','source_snapshot.start_time','source_snapshot.end_time'],true)){
                $expected=$this->time($expected);$actual=$this->time($actual);
            }
        }
        if($expected==='')$expected=null; if($actual==='')$actual=null;
        $status=$reason?'unverified':($expected===null&&$actual===null?'absent_in_both':($expected===null?'unverified':($expected===$actual?'matched':'different')));
        match($status){'matched'=>$this->matches++,'different'=>$this->mismatches++,'absent_in_both'=>null,default=>$this->unverified++};
        $entry['fields'][$name]=['status'=>$status,'source'=>$expected,'crm'=>$actual,'reason'=>$reason??($expected===null?'source_field_absent':null)];
    }
    private function pick(array $raw,array $paths,array $record,string $field): mixed
    {
        $values=[];
        foreach([['value'=>$raw,'file'=>$record['file'],'path'=>$record['path'],'origins'=>$record['origins']??[]],...($record['related']??[])] as $candidate)foreach($paths as $path){$v=$candidate['value'];$found=true;foreach(explode('.',$path) as $part){if(!is_array($v)||!array_key_exists($part,$v)){$found=false;break;}$v=$v[$part];}
            if($found){$values[]=$v;foreach($candidate['leaf_origins'][$path]??[] as $origin)$this->used[$this->key($candidate,$origin)]=true;if(!is_array($v))foreach($candidate['origins'][$path]??[str_replace('.','/',$path)] as $origin)$this->used[$this->key($candidate,$origin)]=true;}}
        if(count(array_unique(array_map(fn($v)=>json_encode($v),$values)))>1){$this->issues[]=['code'=>'ambiguous_source_field','values'=>$values,'field'=>$field,'file'=>$record['file'],'path'=>$record['path']];return null;}
        return $values[0]??null;
    }
    private function unread(array $raw,array $record,string $prefix=''): array
    {
        $out=[];
        foreach($raw as $key=>$value){$path=$prefix===''?(string)$key:$prefix.'/'.$key;
            if(isset($this->used[$this->key($record,$path)]))continue;
            if(is_array($value))$out=[...$out,...$this->unread($value,$record,$path)];else $out[]=['path'=>$path,'value'=>$value];}
        return $out;
    }
    private function key(array $record,string $path): string {return $record['file'].':'.$record['path'].'/'.$path;}
    private function envelope(array $p,string $hash): bool {return hash_equals($hash,$this->hash($p));}
    private function hash(array $value): string {
        $sort=function(array $a)use(&$sort):array{if(!array_is_list($a))ksort($a);foreach($a as $k=>$v)if(is_array($v))$a[$k]=$sort($v);return $a;};
        return hash('sha256',json_encode($sort($value),JSON_THROW_ON_ERROR));
    }
    private function cents(mixed $value): ?int {
        if(is_array($value)&&($value['currency']??null)==='USD'){
            $keys=array_intersect(['amount_cents','amount'],array_keys($value));
            if(count($keys)===1&&is_int($value[current($keys)]))return $value[current($keys)];
        }
        if(!is_string($value)||!preg_match('/^-?(?:0|[1-9][0-9]{0,8})\.[0-9]{2}$/D',$value))return null;
        $sign=str_starts_with($value,'-')?-1:1;return $sign*(int)str_replace('.','',ltrim($value,'-'));
    }
    private function time(mixed $value): mixed {
        if(!is_string($value)||$value==='')return $value;
        $text=mb_strtolower(trim(preg_replace('/[\s\x{202f}\x{a0}]+/u',' ',$value)));
        if(preg_match('/^(0?[1-9]|1[0-2]):([0-5][0-9]) ?(am|pm)$/D',$text,$m))return sprintf('%02d:%s',((int)$m[1]%12)+($m[3]==='pm'?12:0),$m[2]);
        if(preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(?::00)?$/D',$text,$m))return $m[1].':'.$m[2];
        return $value;
    }
    private function name(mixed $value): ?string {return is_string($value)?mb_strtolower(preg_replace('/\s+/u',' ',trim(\Normalizer::normalize($value)))):null;}
    private function phone(mixed $value): ?string {
        if(!is_string($value)||!preg_match('/^\+?[0-9 ().-]+$/D',trim($value)))return null;
        $digits=preg_replace('/[^0-9]/','',$value);
        if(strlen($digits)===10&&preg_match('/^[2-9][0-9]{2}[2-9][0-9]{6}$/D',$digits))return '+1'.$digits;
        if(strlen($digits)===11&&str_starts_with($digits,'1'))return '+'.$digits;
        return str_starts_with(trim($value),'+')&&strlen($digits)>=8&&strlen($digits)<=15?'+'.$digits:null;
    }
    private function originals(array $snapshot,?string $directory): array
    {
        $details=[];$database=[];$valid=0;$matched=0;$missing=0;$extra=0;
        foreach(HoneyBookOriginal::orderBy('id')->lazyById(1) as $record){
            try{
                $p=$record->payload;$bytes=base64_decode($p['bytes_base64'],true);
                $identity=$this->hash(['profile'=>'honeybook-original-v1','project_key'=>$p['project_key'],'kind'=>$p['kind'],'file_sha256'=>$p['file_sha256']]);
                $okay=$this->envelope($p,$record->sha256)&&$p['project_key']===$record->project_key
                    &&hash('sha256',$p['project_id'])===$record->project_key&&is_string($bytes)
                    &&strlen($bytes)===$p['byte_count']&&hash('sha256',$bytes)===$p['file_sha256']
                    &&$identity===$record->identity_sha256&&$identity===$p['identity_sha256'];
                $entry=['id'=>$record->id,'project_id'=>$p['project_id'],'kind'=>$p['kind'],'source_reference'=>$p['source_reference'],
                    'sha256'=>$p['file_sha256'],'bytes'=>$p['byte_count'],'database_integrity'=>$okay];
                $key=$p['project_id'].'|'.$p['kind'].'|'.$p['source_reference'];$database[$key][]=$entry;
                if($okay)$valid++;else $this->issues[]=['code'=>'original_integrity','original_id'=>$record->id];
            }catch(\Throwable){$this->issues[]=['code'=>'original_unreadable','original_id'=>$record->id];}
        }
        if($snapshot['originals']===null||$directory===null){
            $this->issues[]=['code'=>'independent_original_bytes_not_supplied'];
            return ['summary'=>['database_count'=>HoneyBookOriginal::count(),'database_hash_valid'=>$valid,'source_pdf_matched'=>0,'missing'=>null,'extra'=>null],
                'details'=>['database'=>array_merge([], ...array_values($database)),'source_comparison'=>'unverified']];
        }
        $seen=[];$references=[];
        foreach($snapshot['records']['files'] as $record)foreach(['id','_id','file_id','fileId','url','download_url','source_reference'] as $key){
            if(is_string($record['value'][$key]??null))$references[$record['value'][$key]]=true;
        }
        foreach($snapshot['originals'] as $expected){
            foreach(['project_id','kind','source_reference','path','sha256'] as $key)if(!is_string($expected[$key]??null))throw new \RuntimeException('invalid_original_manifest');
            if(!in_array($expected['kind'],['contract','invoice'],true)||!preg_match('/^[a-f0-9]{64}$/D',$expected['sha256']))throw new \RuntimeException('invalid_original_manifest');
            $key=$expected['project_id'].'|'.$expected['kind'].'|'.$expected['source_reference'];
            if(isset($seen[$key])){$this->issues[]=['code'=>'duplicate_source_original','reference'=>$key];continue;}$seen[$key]=true;
            if(!isset($references[$expected['source_reference']]))$this->issues[]=['code'=>'original_reference_not_in_raw_api','reference'=>$expected['source_reference']];
            if(!isset($this->source[hash('sha256',$expected['project_id'])]))$this->issues[]=['code'=>'original_project_not_in_source','project_id'=>$expected['project_id']];
            $bytes=app(RawHoneyBookSnapshot::class)->bytes($directory,$expected['path']);
            $hash=hash('sha256',$bytes);$rows=$database[$key]??[];
            $status='different';
            if($hash!==$expected['sha256'])$this->issues[]=['code'=>'source_pdf_hash_mismatch','reference'=>$key];
            elseif(count($rows)!==1){$missing++;$this->issues[]=['code'=>$rows?'duplicate_original':'missing_original','reference'=>$key];}
            elseif($rows[0]['database_integrity']&&$rows[0]['sha256']===$hash&&$rows[0]['bytes']===strlen($bytes)){$matched++;$status='matched';}
            else $this->issues[]=['code'=>'original_source_bytes_differ','reference'=>$key];
            if($status==='matched')foreach($snapshot['records']['files'] as $sourceFile){
                $v=$sourceFile['value'];$ref=$v['source_reference']??$v['id']??$v['_id']??$v['file_id']??$v['fileId']??null;
                if($ref!==$expected['source_reference'])continue;
                if(($v['project_id']??null)!==$expected['project_id']){$this->issues[]=['code'=>'original_source_project_differs','reference'=>$ref];continue;}
                foreach(['id','_id','source_reference','project_id'] as $field)if(isset($v[$field]))$this->used[$this->key($sourceFile,$field)]=true;
                foreach(['type','kind'] as $field)if(isset($v[$field])){
                    if($v[$field]===$expected['kind'])$this->used[$this->key($sourceFile,$field)]=true;
                    else $this->issues[]=['code'=>'original_source_kind_differs','reference'=>$ref];
                }
            }
            $details[]=['source'=>$expected,'status'=>$status,'crm'=>$rows];
        }
        foreach($database as $key=>$rows)if(!isset($seen[$key])){$extra+=count($rows);$this->issues[]=['code'=>'extra_database_original','reference'=>$key];}
        foreach($snapshot['records']['files'] as $record){
            $v=$record['value'];$ref=$v['source_reference']??$v['id']??$v['_id']??$v['file_id']??$v['fileId']??null;$kind=$v['kind']??$v['type']??null;
            if(in_array($kind,['contract','invoice'],true)&&is_string($ref)){
                $covered=false;foreach($snapshot['originals'] as $row)if($row['source_reference']===$ref&&$row['kind']===$kind)$covered=true;
                if(!$covered)$this->issues[]=['code'=>'source_file_missing_original_manifest','reference'=>$ref];
            }
        }
        return ['summary'=>['database_count'=>HoneyBookOriginal::count(),'database_hash_valid'=>$valid,
            'source_pdf_matched'=>$matched,'missing'=>$missing,'extra'=>$extra],'details'=>$details];
    }
}
