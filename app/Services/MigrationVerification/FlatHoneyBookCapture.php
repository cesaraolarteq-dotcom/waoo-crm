<?php
namespace App\Services\MigrationVerification;

/** Documented full-list API capture. Reference rows never become project rows. */
final class FlatHoneyBookCapture
{
    private function paymentDay(string $value): ?string
    {
        if(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$m))return checkdate((int)$m[2],(int)$m[3],(int)$m[1])?$value:null;
        if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D',$value))return null;
        try{$d=new \DateTimeImmutable($value);$errors=\DateTimeImmutable::getLastErrors();if($errors&&($errors['warning_count']||$errors['error_count']))return null;return $d->setTimezone(new \DateTimeZone('America/Chicago'))->format('Y-m-d');}catch(\Throwable){return null;}
    }
    public function decode(array $documents): ?array
    {
        $p=$documents['projects.json'];$c=$documents['contacts-pipeline-files.json'];$w=$documents['workspaces.json'];$f=$documents['file-responses.json'];
        if(!array_is_list($p)||!isset($c['contacts'],$w['workspaces'],$w['workspace_files'])||!is_array($c['contacts'])||!array_is_list($c['contacts'])||!is_array($w['workspaces'])||!array_is_list($w['workspaces'])||!is_array($w['workspace_files'])||(isset($c['smart_files'])&&(!is_array($c['smart_files'])||!array_is_list($c['smart_files']))))return null;
        $records=array_fill_keys(['projects','contacts','workspaces','files','responses'],[]);$issues=[];$relations=[];
        $add=function(string $kind,string $file,string $path,array $value)use(&$records):array{$r=compact('file','path','value');$records[$kind][]=$r;return $r;};
        $related=function(string $project,array $r,array $value,array $origins=[],array $leafOrigins=[])use(&$relations):void{$relations[$project][]=['file'=>$r['file'],'path'=>$r['path'],'value'=>$value,'origins'=>$origins,'leaf_origins'=>$leafOrigins];};
        foreach($p as $i=>$v)if(is_array($v))$add('projects','projects.json','/'.$i,$v);else $issues[]=['code'=>'unsupported_project_row','path'=>'/'.$i,'raw_value'=>$v];
        foreach($c['contacts'] as $i=>$v){
            if(!is_array($v)){$issues[]=['code'=>'unsupported_contact_row','path'=>'/contacts/'.$i,'raw_value'=>$v];continue;}
            $r=$add('contacts','contacts-pipeline-files.json','/contacts/'.$i,$v);
            foreach(is_array($v['workspaces']['data']??null)?$v['workspaces']['data']:[] as $ref)if(is_string($ref['project_id']??null)&&is_string($v['id']??null)){
                $related($ref['project_id'],$r,['contact_id'=>$v['id'],'sms_consent_honeybook'=>$v['sms_consent_confirmed']??null],['contact_id'=>['id'],'sms_consent_honeybook'=>['sms_consent_confirmed']]);
            }
        }
        foreach($w['workspaces'] as $i=>$v){
            if(!is_array($v)){$issues[]=['code'=>'unsupported_workspace_row','path'=>'/workspaces/'.$i,'raw_value'=>$v];continue;}
            $r=$add('workspaces','workspaces.json','/workspaces/'.$i,$v);
            if(is_string($v['project_id']??null))$related($v['project_id'],$r,['project_status'=>$v['status']??null],['project_status'=>['status']]);
        }
        $files=[];
        foreach($c['smart_files']??[] as $i=>$v)if(is_array($v)){$add('files','contacts-pipeline-files.json','/smart_files/'.$i,$v);if(is_string($v['id']??null))$files[$v['id']][]=$v;}else $issues[]=['code'=>'unsupported_smart_file','path'=>'/smart_files/'.$i,'raw_value'=>$v];
        $eligible=[];
        foreach($w['workspace_files'] as $workspace=>$list){
            if(!is_array($list)||!array_is_list($list)){$issues[]=['code'=>'unsupported_workspace_files','path'=>'/workspace_files/'.$workspace,'raw_value'=>$list];continue;}
            foreach($list as $i=>$v){
                if(!is_array($v)){$issues[]=['code'=>'unsupported_workspace_file','path'=>'/workspace_files/'.$workspace.'/'.$i,'raw_value'=>$v];continue;}
                $r=$add('files','workspaces.json','/workspace_files/'.$workspace.'/'.$i,$v);
                if(!is_string($v['id']??null)||!is_string($v['project_id']??null))continue;
                $files[$v['id']][]=$v;$paid=[];$active=false;$invalid=!is_array($v['payment_variables']??[]);
                foreach(is_array($v['payment_variables']??null)?$v['payment_variables']:[] as $j=>$variable){
                    $x=$variable['value']??null;if(!is_array($x)){$invalid=true;continue;}
                    $label=mb_strtolower(trim((string)($x['payment_status_label']??'')));
                    if(in_array($label,['cancelled','canceled'],true))continue;
                    // Unrecognized/missing status cannot establish which agreement is active.
                    if(!in_array($label,['paid','unpaid','pending','overdue','due','partially paid','scheduled'],true)){$invalid=true;continue;}
                    $active=true;
                    if(($x['is_paid']??null)===true){
                        $amount=$x['amount_paid']??null;$date=$x['charge_date']??null;$method=$x['payment_method_name']??null;
                        if((!is_int($amount)&&!is_float($amount)&&!is_string($amount))||!is_string($date)||!is_string($method)||($x['is_refunded']??false)===true||(isset($x['currency'])&&$x['currency']!=='USD')){$invalid=true;continue;}
                        $text=(string)$amount;$day=$this->paymentDay($date);
                        if(!preg_match('/^(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,2}))?$/D',$text,$parts)||$day===null){$invalid=true;continue;}
                        $paid[]=['date'=>$day,'amount'=>$parts[1].'.'.str_pad($parts[2]??'',2,'0'),'method'=>$method];
                    }elseif(($x['is_paid']??null)!==false||!in_array($x['amount_paid']??null,[0,0.0,'0','0.00'],true))$invalid=true;
                }
                if($invalid)$issues[]=['code'=>'unsupported_payment_variable','file'=>$r['file'],'path'=>$r['path']];
                if($active&&!$invalid)$eligible[$v['id']][]=['record'=>$r,'payments'=>$paid];
            }
        }
        $agreements=[];
        foreach($eligible as $id=>$choices){
            $projects=array_values(array_unique(array_filter(array_column($files[$id]??[],'project_id'),'is_string')));
            if(count($projects)===1)$agreements[$projects[0]][]=(string)$id;
        }
        foreach($f as $id=>$v){
            if(!is_array($v)||($v['isError']??false)===true||($v['success']??true)===false){$issues[]=['code'=>'unsupported_file_response','path'=>'/'.$id,'raw_value'=>$v];continue;}
            $r=$add('responses','file-responses.json','/'.$id,$v);
            $projects=array_values(array_unique(array_filter(array_column($files[$id]??[],'project_id'),'is_string')));
            if(count($projects)!==1){$issues[]=['code'=>'response_project_unresolved','path'=>'/'.$id];continue;}
            $project=$projects[0];$choices=$eligible[$id]??[];
            if(count($agreements[$project]??[])>1){$issues[]=['code'=>'ambiguous_project_agreement','path'=>'/'.$id];continue;}
            if(count($choices)!==1){$issues[]=['code'=>'agreement_eligibility_unverified','path'=>'/'.$id];continue;}
            $values=[];$origins=[];$leaves=[];
            if(isset($v['payments'])&&!is_array($v['payments'])){$issues[]=['code'=>'unsupported_smart_file_payments','path'=>'/'.$id];continue;}
            foreach(['total'=>'total','paid_total'=>'amount_paid','balance'=>'balance_due','discount'=>'discount'] as $field=>$key)if(array_key_exists($key,$v['payments']??[])){
                $values[$field]=$v['payments'][$key];$origins[$field]=['payments/'.$key];
            }
            $items=$v['payments']['line_items']??null;
            if(is_array($items)&&array_is_list($items)){
                $flat=[];$valid=true;$itemLeaves=[];
                foreach($items as $index=>$item){
                    if(!is_array($item)||!is_string($item['title']??null)||!is_int($item['quantity']??null)||$item['quantity']<1||!is_int($item['price']['amount']??null)||($item['price']['currency']??null)!=='USD'||!empty($item['children'])){$valid=false;break;}
                    $cents=$item['price']['amount']*$item['quantity'];
                    if(!is_int($cents)){$valid=false;break;}
                    $itemLeaves=[...$itemLeaves,...array_map(fn($key)=>'payments/line_items/'.$index.'/'.$key,['title','price/amount','price/currency','quantity'])];
                    $flat[]=['title'=>$item['title'],'amount'=>['amount_cents'=>$cents,'currency'=>'USD']];
                }
                if($valid){$values['line_items']=$flat;$origins['line_items']=[];$leaves['line_items']=$itemLeaves;}
            }
            $signatures=$v['signatures']['signatures']??null;
            if(is_array($signatures)&&array_is_list($signatures)&&!array_filter($signatures,fn($s)=>!is_array($s)||!in_array($s['signee_type']??null,['client','vendor'],true)||($s['signee_type']==='client'&&!is_bool($s['required']??null)))){
                $required=array_values(array_filter($signatures,fn($s)=>is_array($s)&&($s['signee_type']??null)==='client'&&($s['required']??null)===true));
                if($required&&!array_filter($required,fn($s)=>!is_bool($s['signed']??null))){$values['contract_signed']=!in_array(false,array_column($required,'signed'),true);$origins['contract_signed']=[];
                    foreach($signatures as $i=>$signature){$origins['contract_signed'][]='signatures/signatures/'.$i.'/signee_type';
                        if($signature['signee_type']==='client'){$origins['contract_signed'][]='signatures/signatures/'.$i.'/required';if($signature['required'])$origins['contract_signed'][]='signatures/signatures/'.$i.'/signed';}}}
            }
            // Preserve all raw signature/questionnaire/item fields for private examination.
            $related($project,$r,$values,$origins,$leaves);
            $choice=$choices[0];$related($project,$choice['record'],['payments'=>$choice['payments']],['payments'=>[]]);
        }
        foreach($c as $key=>$value)if(!in_array($key,['contacts','smart_files'],true))$issues[]=['file'=>'contacts-pipeline-files.json','path'=>'/'.$key,'code'=>'unverified_response_metadata','raw_value'=>$value];
        foreach($w as $key=>$value)if(!in_array($key,['workspaces','workspace_files'],true))$issues[]=['file'=>'workspaces.json','path'=>'/'.$key,'code'=>'unverified_response_metadata','raw_value'=>$value];
        return ['records'=>$records,'issues'=>$issues,'project_relations'=>$relations,'capture_shape'=>'flat-lists-v1'];
    }
}
