<?php
namespace App\Services\Tips;
use Illuminate\Validation\ValidationException;
final class TipAmounts {
    public static function options(int $total):array {
        $result=[];foreach([5,10,15] as $p){$c=intdiv($total*$p+5000,10000)*100;if($c>0)$result[]=['key'=>'pct_'.$p,'percent'=>$p,'amount_cents'=>$c,'amount'=>self::money($c)];}return $result;
    }
    public static function amount(int $total,array $d):int {
        if($d['option']==='custom'){
            $v=$d['custom_amount']??null;
            if(!is_string($v)||!preg_match('/^(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,2})?$/D',$v))self::bad();
            $parts=explode('.',$v);$c=(int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');if($c<100||$c>200000)self::bad();return $c;
        }
        if(array_key_exists('custom_amount',$d))self::bad();
        foreach(self::options($total) as $o)if($o['key']===$d['option'])return $o['amount_cents'];self::bad();
    }
    public static function money(int $c):string{return '$'.number_format(intdiv($c,100),0,'.',',').'.'.str_pad((string)($c%100),2,'0',STR_PAD_LEFT);}
    private static function bad():never{throw ValidationException::withMessages(['custom_amount'=>'Revisa el importe de la propina (USD1–2000).']);}
}
