<?php
namespace App\Services\Imports;
use App\Services\Finance\MutationKey;
use App\Support\ChicagoTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
final class HoneyBookFutureSource
{
    public const HEADERS=['honeybook_project_id','project_name','status','client_first_name','client_last_name','client_phone','client_email','event_date','start_time','end_time','event_address','event_zip','services','characters','package_total','travel_fee','discount','total','amount_paid','balance','balance_due_date','contract_signed','payments','notes'];
    public static function profile():array{return ['schema_version'=>1,'timezone'=>'America/Chicago','encoding'=>'utf-8','delimiter'=>',','date_format'=>'Y-m-d','money_format'=>'usd_dot','columns'=>['projects'=>[]],'status_map'=>[],'payment_state_map'=>[],'source_format'=>'honeybook_future_v1'];}
    public static function cents(string $raw,bool $signed=false):?int
    {
        if($raw==='')return null;
        if(!preg_match($signed?'/^-?(?:0|[1-9][0-9]{0,9})\.[0-9]{2}$/D':'/^(?:0|[1-9][0-9]{0,9})\.[0-9]{2}$/D',$raw))throw new \InvalidArgumentException();
        $negative=str_starts_with($raw,'-');$raw=ltrim($raw,'-');[$a,$b]=explode('.',$raw);return ((int)$a*100+(int)$b)*($negative?-1:1);
    }
    public static function date(string $v):string
    {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new \InvalidArgumentException();return $v;
    }
    public function build(#[\SensitiveParameter] array $files,CarbonImmutable $at):array
    {
        if(array_keys($files)!==['projects'])ImportInput::fail('files','one_future_events_csv_required');
        $rows=[];$seen=[];$totals=[];$counts=array_fill_keys(['ready','needs_mapping','conflict','skipped'],0);
        foreach($files['projects']['rows'] as $csv){
            $s=array_combine(self::HEADERS,$csv['cells']);$n=['source_format'=>'honeybook_future_v1','source_sha256'=>MutationKey::hash($s),'project_id'=>$s['honeybook_project_id'],
                'customer_name'=>trim($s['client_first_name'].' '.$s['client_last_name']),'customer_email'=>$s['client_email']?:null,'customer_phone'=>$s['client_phone']?:null,
                'source_status'=>$s['status']==='confirmed'?'confirmed':($s['status']==='cancelled'?'cancelled':null),'payments'=>[],'payment_warnings'=>[]];$errors=[];
            $error=function($field,$code)use(&$errors){$errors[]=['field'=>$field,'code'=>$code];};
            foreach($s as $field=>$v)if(mb_strlen($v)>(in_array($field,['services','characters','notes','payments'],true)?32768:255))$error($field,'field_length');
            foreach(['honeybook_project_id','project_name','event_address','event_zip','services','balance_due_date'] as $field)if($s[$field]==='')$error($field,'source_required');
            if($n['customer_name']===''||mb_strlen($n['customer_name'])>255)$error('client','invalid_name');
            if(!$n['customer_email']&&!$n['customer_phone'])$error('client','contact_missing');
            if($n['customer_email']&&!filter_var($n['customer_email'],FILTER_VALIDATE_EMAIL))$error('client_email','invalid_email');
            if($n['customer_phone']&&!preg_match('/^\+?[0-9][0-9 ()\-.]{5,29}$/D',$n['customer_phone']))$error('client_phone','invalid_phone');
            if(!preg_match('/^[0-9]{5}(?:-[0-9]{4})?$/D',$s['event_zip']))$error('event_zip','invalid_zip');
            if($n['source_status']===null)$error('status','owner_status_review_required');
            if(!in_array($s['contract_signed'],['yes','no'],true))$error('contract_signed','yes_no_required');
            $n['contract_signed']=$s['contract_signed']==='yes';
            foreach(['package_total'=>'package_cents','travel_fee'=>'travel_cents','discount'=>'discount_cents','total'=>'total_cents','amount_paid'=>'paid_cents','balance'=>'balance_cents'] as $field=>$out){
                try{$n[$out]=self::cents($s[$field],$field==='balance');if($n[$out]===null)$error($field,'source_required');}catch(\InvalidArgumentException){$n[$out]=null;$error($field,'invalid_two_decimal_money');}
            }
            if(isset($n['package_cents'],$n['travel_cents'],$n['discount_cents'],$n['total_cents'])&&$n['package_cents']+$n['travel_cents']-$n['discount_cents']!==$n['total_cents'])$error('total','package_equation_mismatch');
            if(isset($n['total_cents'],$n['paid_cents'],$n['balance_cents'])&&$n['total_cents']-$n['paid_cents']!==$n['balance_cents'])$error('balance','balance_equation_mismatch');
            try{
                $date=self::date($s['event_date']);foreach(['start_time','end_time'] as $f)if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$s[$f]))throw new \InvalidArgumentException();
                $start=CarbonImmutable::parse(ChicagoTime::toIso($date,$s['start_time'],null,'time'));$end=CarbonImmutable::parse(ChicagoTime::toIso($date,$s['end_time'],null,'time'));
                if(!$end->greaterThan($start))throw new \InvalidArgumentException();$n['starts_at']=$start->utc()->toIso8601String();$n['ends_at']=$end->utc()->toIso8601String();
            }catch(\InvalidArgumentException|ValidationException){$error('event_time','invalid_or_ambiguous_houston_window');}
            try{$n['balance_due_date']=self::date($s['balance_due_date']);}catch(\InvalidArgumentException){$n['balance_due_date']=null;$error('balance_due_date','invalid_source_date');}
            $sum=0;$tuples=[];
            if($s['payments']!=='')foreach(explode(';',$s['payments']) as $index=>$raw){
                try{$tuple=trim($raw);$parts=explode('|',$tuple);if(count($parts)!==3||trim($parts[2])===''||mb_strlen($parts[2])>120)throw new \InvalidArgumentException();
                    $day=self::date($parts[0]);if($day>$at->setTimezone(ChicagoTime::TZ)->format('Y-m-d'))throw new \InvalidArgumentException();$cents=self::cents($parts[1],true);if($cents===null||$cents===0)throw new \InvalidArgumentException();
                    $n['payments'][]=['source_position'=>$index+1,'received_date'=>$day,'amount_cents'=>$cents,'method'=>$parts[2],'source'=>'honeybook','verified'=>false];$sum+=$cents;
                    if(isset($tuples[$tuple]))$n['payment_warnings'][]='repeated_source_tuple_review';$tuples[$tuple]=true;
                }catch(\InvalidArgumentException){$error('payments','invalid_source_payment');}
            }
            $n['itemized_paid_cents']=$sum;if(isset($n['paid_cents'])&&$n['paid_cents']!==$sum)$error('payments','payments_equation_mismatch');
            $status=$errors===[]?'ready':'needs_mapping';if(array_intersect(array_column($errors,'code'),['package_equation_mismatch','balance_equation_mismatch','payments_equation_mismatch']))$status='conflict';
            $skip=null;if($n['source_status']==='cancelled'||(isset($n['starts_at'])&&CarbonImmutable::parse($n['starts_at'])->lessThanOrEqualTo($at))){$skip=$n['source_status']==='cancelled'?'cancelled':'past';$status='skipped';}
            $key=$s['honeybook_project_id']!==''?hash('sha256',$s['honeybook_project_id']):null;
            $rows[]=['role'=>'projects','source_line'=>$csv['line'],'source'=>$s,'normalized'=>$n,'errors'=>$errors,'status'=>$status,'source_key'=>$key,'skip_reason'=>$skip,'duplicate_hints'=>[]];if($key!==null)$seen[$key][]=count($rows)-1;
        }
        foreach($seen as $group)if(count($group)>1)foreach($group as $i){$rows[$i]['status']='conflict';$rows[$i]['errors'][]=['field'=>'honeybook_project_id','code'=>'duplicate_source_key'];}
        foreach(['package_cents','travel_cents','discount_cents','total_cents','paid_cents','balance_cents'] as $f)$totals[$f]=['sum_cents'=>0,'known_count'=>0,'unknown_count'=>0,'conflict_count'=>0];
        foreach($rows as $r){$counts[$r['status']]++;foreach($totals as $f=>&$t){$v=$r['normalized'][$f]??null;if($v===null)$t['unknown_count']++;else{$t['known_count']++;$t['sum_cents']+=$v;}if($r['status']==='conflict')$t['conflict_count']++;}unset($t);}
        return ['rows'=>$rows,'reconciliation'=>['source_format'=>'honeybook_future_v1','comparison_at'=>$at->toIso8601String(),'counts'=>$counts,'source_counts'=>['projects'=>count($rows)],'totals'=>$totals,'domain_committed'=>false,'capacity_confirmed'=>false]];
    }
}
