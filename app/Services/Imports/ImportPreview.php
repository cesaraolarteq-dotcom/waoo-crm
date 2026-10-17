<?php
namespace App\Services\Imports;

use App\Support\ChicagoTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ImportPreview
{
    public static function money(?string $value,bool $signed=false):?int
    {
        if($value===null||trim($value)==='')return null;
        $value=trim($value);$minus=false;
        if($signed&&str_starts_with($value,'-')){$minus=true;$value=substr($value,1);}
        if(!preg_match('/^\$?(?:0|[1-9][0-9]*|[1-9][0-9]{0,2}(?:,[0-9]{3})+)(?:\.[0-9]{1,2})?$/D',$value))throw new \InvalidArgumentException('invalid_money');
        $clean=str_replace(['$',','],'',$value);[$whole,$frac]=array_pad(explode('.',$clean),2,'');
        if(strlen($whole)>10)throw new \InvalidArgumentException('money_limit');
        $cents=(int)$whole*100+(int)str_pad($frac,2,'0');return $minus?-$cents:$cents;
    }
    public function build(#[\SensitiveParameter] array $files,array $profile,CarbonImmutable $at):array
    {
        $rows=[];
        foreach($files as $role=>$file)foreach($file['rows'] as $csv){
            $source=[];foreach($profile['columns'][$role]??[] as $field=>$index)$source[$field]=$csv['cells'][$index];
            $r=['role'=>$role,'source_line'=>$csv['line'],'source'=>$source,'normalized'=>[],'errors'=>[],'status'=>'ready','duplicate_hints'=>[]];
            foreach($source as $field=>$value)if(mb_strlen($value)>($field==='address'||$field==='package_text'||$field==='contract_reference'?2000:255))$this->error($r,$field,'field_length');
            if($role==='projects')$this->project($r,$profile,$at);
            elseif($role==='customers'){
                foreach(['customer_id','customer_name'] as $field)$this->required($r,$field);
                $r['normalized']=$this->identity($source);
            }else{$this->payment($r,$profile);}
            if($role==='customers')$this->validateIdentity($r);
            $r['source_key']=$this->key($role==='projects'?($source['project_id']??null):($role==='customers'?($source['customer_id']??null):($source['payment_id']??null)));
            $rows[]=$r;
        }
        $indexes=['projects'=>[],'customers'=>[],'payments'=>[]];
        foreach($rows as $i=>$r)if($r['source_key']!==null)$indexes[$r['role']][$r['source_key']][]=$i;
        foreach($indexes as $role=>$groups)foreach($groups as $group)if(count($group)>1)foreach($group as $i)$this->error($rows[$i],'identity','duplicate_source_key',true);
        foreach($rows as $i=>&$r){
            if($r['role']==='projects'&&isset($files['customers'])&&filled($r['source']['customer_id']??null)){
                $matches=$indexes['customers'][$this->key($r['source']['customer_id'])]??[];
                if(count($matches)!==1){$this->error($r,'customer_id','customer_join_ambiguous',true);continue;}
                $customer=$rows[$matches[0]];
                if($customer['errors']!==[])$this->error($r,'customer_id','customer_source_invalid',true);
                foreach(['customer_name','customer_email','customer_phone'] as $field){
                    $a=trim($r['source'][$field]??'');$b=trim($customer['source'][$field]??'');
                    if($a!==''&&$b!==''&&$a!==$b)$this->error($r,$field,'customer_source_mismatch',true);
                    if($a===''&&$b!=='')$r['normalized'][$field]=$b;
                }
            }
        }unset($r);
        // Join children only by explicit source identity. No customer/domain writes or last-row-wins behavior.
        $paymentSums=[];
        foreach($rows as &$r)if($r['role']==='payments'){
            $key=$this->key($r['source']['project_id']??null);$matches=$key===null?[]:($indexes['projects'][$key]??[]);
            if(count($matches)!==1)$this->error($r,'project_id','project_join_ambiguous',true);
            if($key!==null){$paymentSums[$key]??=['sum'=>0,'valid'=>true];if($r['errors']!==[])$paymentSums[$key]['valid']=false;
                elseif(in_array($r['normalized']['state'],['received','refunded'],true))$paymentSums[$key]['sum']+=($r['normalized']['state']==='refunded'?-1:1)*$r['normalized']['amount_cents'];}
        }unset($r);
        foreach($rows as &$r)if($r['role']==='projects'){
            $this->validateIdentity($r);
            foreach(['customer_name','address','package_text','ends_at','total_cents','paid_cents','balance_cents','travel_cents'] as $field)if(!isset($r['normalized'][$field])||$r['normalized'][$field]==='')$this->error($r,$field,'source_required');
            if(empty($r['normalized']['customer_email'])&&empty($r['normalized']['customer_phone']))$this->error($r,'customer','contact_missing');
            $n=$r['normalized'];
            if(isset($n['total_cents'],$n['paid_cents'],$n['balance_cents'])&&$n['total_cents']-$n['paid_cents']!==$n['balance_cents'])$this->error($r,'balance_amount','totals_mismatch',true);
            if(isset($files['payments'])&&$r['source_key']!==null){
                $sum=$paymentSums[$r['source_key']]??['sum'=>0,'valid'=>true];$r['normalized']['itemized_paid_cents']=$sum['valid']?$sum['sum']:null;
                if(!$sum['valid'])$this->error($r,'paid_amount','payment_source_invalid',true);
                elseif(isset($n['paid_cents'])&&$n['paid_cents']!==$sum['sum'])$this->error($r,'paid_amount','payments_mismatch',true);
            }
            // Skipping is only valid after date/status were understood; malformed skipped sources remain visible for review.
            if(($r['normalized']['source_status']??null)==='cancelled'||(isset($n['starts_at'])&&CarbonImmutable::parse($n['starts_at'])->lessThanOrEqualTo($at))){
                if($r['status']!=='conflict')$r['status']='skipped';
                $r['skip_reason']=($r['normalized']['source_status']??null)==='cancelled'?'cancelled':'past';
            }
        }unset($r);
        $counts=array_fill_keys(['ready','needs_mapping','conflict','skipped'],0);$totals=[];
        foreach(['total_cents','paid_cents','balance_cents','travel_cents'] as $field)$totals[$field]=['sum_cents'=>0,'known_count'=>0,'unknown_count'=>0,'conflict_count'=>0];
        foreach($rows as $r){$counts[$r['status']]++;if($r['role']!=='projects')continue;
            foreach($totals as $field=>&$total){$v=$r['normalized'][$field]??null;if($v===null)$total['unknown_count']++;else{$total['known_count']++;$total['sum_cents']+=$v;}if($r['status']==='conflict')$total['conflict_count']++;}unset($total);}
        return ['rows'=>$rows,'reconciliation'=>['comparison_at'=>$at->toIso8601String(),'comparison_date_houston'=>$at->setTimezone(ChicagoTime::TZ)->format('Y-m-d'),
            'counts'=>$counts,'source_counts'=>array_map(fn($f)=>count($f['rows']),$files),'totals'=>$totals,'domain_committed'=>false,'capacity_confirmed'=>false]];
    }
    private function project(array &$r,array $p,CarbonImmutable $at):void
    {
        $s=$r['source'];$r['normalized']=$this->identity($s);
        foreach(['project_id','project_status','event_date','start_time'] as $field)$this->required($r,$field);
        $r['normalized']['source_status']=$this->state($s['project_status']??null,$p['status_map']);
        if($r['normalized']['source_status']===null||$r['normalized']['source_status']==='other')$this->error($r,'project_status','unknown_status');
        foreach(['total_amount'=>'total_cents','paid_amount'=>'paid_cents','balance_amount'=>'balance_cents','travel_amount'=>'travel_cents'] as $field=>$out){
            try{$r['normalized'][$out]=self::money($s[$field]??null,$field==='balance_amount');}catch(\InvalidArgumentException){$r['normalized'][$out]=null;$this->error($r,$field,'invalid_money');}
        }
        foreach(['address','postal_code','package_text','title','contract_reference'] as $field)$r['normalized'][$field]=filled($s[$field]??null)?trim($s[$field]):null;
        try{
            $date=$this->date($s['event_date']??'',$p['date_format']);$time=$this->time($s['start_time']??'');
            $start=CarbonImmutable::parse(ChicagoTime::toIso($date,$time,$s['start_choice']??null,'time'));
            $r['normalized']['starts_at']=$start->utc()->toIso8601String();$end=null;
            if(filled($s['end_time']??null)){$ed=filled($s['end_date']??null)?$this->date($s['end_date'],$p['date_format']):$date;
                $end=CarbonImmutable::parse(ChicagoTime::toIso($ed,$this->time($s['end_time']),$s['end_choice']??null,'time'));if(!$end->greaterThan($start))throw new \InvalidArgumentException();}
            if(filled($s['duration_minutes']??null)){
                if(!preg_match('/^[1-9][0-9]{0,4}$/D',$s['duration_minutes'])||(int)$s['duration_minutes']>10080)throw new \InvalidArgumentException();
                $durationEnd=$start->addMinutes((int)$s['duration_minutes']);if($end&&!$end->equalTo($durationEnd))throw new \InvalidArgumentException();$end=$durationEnd;
            }
            $r['normalized']['ends_at']=$end?->utc()->toIso8601String();
        }catch(ValidationException|\InvalidArgumentException){$this->error($r,'event_time','invalid_or_ambiguous_window');}
    }
    private function payment(array &$r,array $p):void
    {
        foreach(['project_id','payment_id','amount','state'] as $f)$this->required($r,$f);
        $s=$r['source'];$state=$this->state($s['state']??null,$p['payment_state_map']);$r['normalized']=['state'=>$state,'received_at'=>null];
        if($state===null)$this->error($r,'state','unknown_payment_state');
        try{$r['normalized']['amount_cents']=self::money($s['amount']??null);}catch(\InvalidArgumentException){$r['normalized']['amount_cents']=null;$this->error($r,'amount','invalid_money');}
        if(filled($s['received_at']??null)){
            $v=$s['received_at'];
            try{if(!preg_match('/^(\d{4}-\d{2}-\d{2})T([0-9]{2}:[0-9]{2}):[0-5][0-9](Z|[+-](?:0[0-9]|1[0-4]):[0-5][0-9])$/D',$v,$m))throw new \InvalidArgumentException();
                $this->date($m[1],'Y-m-d');$this->time($m[2]);$r['normalized']['received_at']=CarbonImmutable::parse($v)->utc()->toIso8601String();
            }catch(\InvalidArgumentException){$this->error($r,'received_at','explicit_timestamp_required');}
        }
    }
    private function validateIdentity(array &$r):void
    {
        $n=$r['normalized'];
        if(filled($n['customer_email']??null)&&!filter_var($n['customer_email'],FILTER_VALIDATE_EMAIL))$this->error($r,'customer_email','invalid_email');
        if(filled($n['customer_phone']??null)&&!preg_match('/^\\+?[0-9][0-9 ()\\-.]{5,29}$/D',$n['customer_phone']))$this->error($r,'customer_phone','invalid_phone');
    }
    private function identity(array $s):array
    {
        $out=[];foreach(['customer_id','customer_name','customer_email','customer_phone'] as $f)$out[$f]=filled($s[$f]??null)?trim($s[$f]):null;return $out;
    }
    private function date(string $v,string $format):string
    {
        $d=\DateTimeImmutable::createFromFormat('!'.$format,$v);if(!$d||$d->format($format)!==$v)throw new \InvalidArgumentException();return $d->format('Y-m-d');
    }
    private function time(string $v):string
    {
        if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$v))throw new \InvalidArgumentException();return $v;
    }
    private function state(?string $value,array $map):?string
    {
        foreach($map as $state=>$values)if(in_array($value,$values,true))return $state;return null;
    }
    private function key(?string $value):?string{return filled($value)?hash('sha256',$value):null;}
    private function required(array &$r,string $field):void{if(!filled($r['source'][$field]??null))$this->error($r,$field,'source_required');}
    private function error(array &$r,string $field,string $code,bool $conflict=false):void
    {
        $r['errors'][]=['field'=>$field,'code'=>$code];if($conflict)$r['status']='conflict';elseif($r['status']==='ready')$r['status']='needs_mapping';
    }
}
