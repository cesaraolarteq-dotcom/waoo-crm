<?php
namespace App\Services\Imports;

use App\Models\User;
use App\Services\Finance\Sandbox;
use App\Services\Portal\PortalContext;
use App\Services\Records\{RecordActor,RecordInput};
use Illuminate\Validation\ValidationException;

final class ImportInput
{
    public const FIELDS = [
        'projects'=>['project_id','project_status','customer_id','customer_name','customer_email','customer_phone','title','event_date','start_time','start_choice','end_date','end_time','end_choice','duration_minutes','address','postal_code','package_text','total_amount','paid_amount','balance_amount','travel_amount','contract_reference'],
        'customers'=>['customer_id','customer_name','customer_email','customer_phone'],
        'payments'=>['project_id','payment_id','amount','state','received_at'],
    ];
    public static function actor(User $actor):User
    {
        Sandbox::require('sandbox_imports');PortalContext::requireTransport(request());return RecordActor::authorize($actor);
    }
    public static function lock():void { \Illuminate\Support\Facades\DB::table('import_staging_locks')->where('id',1)->lockForUpdate()->firstOrFail(); }
    public static function input(array $input,array $rules):array
    {
        foreach(array_diff(array_keys($input),array_keys($rules)) as $key)self::fail((string)$key,'unknown_field');
        return RecordInput::validate($input,$rules);
    }
    public static function fail(string $field,string $code):never
    {
        throw ValidationException::withMessages([$field=>$code]);
    }
    public static function profile(mixed $value):array
    {
        if(!is_array($value))self::fail('profile','invalid_profile');
        $p=self::input($value,['schema_version'=>'required|integer|in:1','timezone'=>'required|in:America/Chicago',
            'encoding'=>'required|in:utf-8,windows-1252','delimiter'=>'required|string','date_format'=>'required|in:Y-m-d,m/d/Y',
            'money_format'=>'required|in:usd_dot','columns'=>'present|array','status_map'=>'present|array','payment_state_map'=>'present|array']);
        if($p['delimiter']==='\\t')$p['delimiter']="\t";
        if(!in_array($p['delimiter'],[',',';',"\t"],true))self::fail('profile.delimiter','unsupported_delimiter');
        foreach($p['columns'] as $role=>$map){
            if(!isset(self::FIELDS[$role])||!is_array($map))self::fail('profile.columns','invalid_role');
            foreach($map as $field=>$index){
                if(!in_array($field,self::FIELDS[$role],true)||!is_int($index)||$index<0||$index>99)self::fail('profile.columns','invalid_column');
            }
        }
        foreach(['status_map'=>['confirmed','cancelled','other'],'payment_state_map'=>['received','refunded','pending','void']] as $key=>$allowed){
            $seen=[];
            foreach($p[$key] as $state=>$values){
                if(!in_array($state,$allowed,true)||!is_array($values)||!array_is_list($values)||count($values)>50)self::fail('profile.'.$key,'invalid_states');
                foreach($values as $v){if(!is_string($v)||trim($v)===''||strlen($v)>255||in_array($v,$seen,true))self::fail('profile.'.$key,'ambiguous_state');$seen[]=$v;}
            }
        }
        $p['schema_version']=1;return $p;
    }
}
