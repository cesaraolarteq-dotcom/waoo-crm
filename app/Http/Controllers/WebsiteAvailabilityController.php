<?php
namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateWebsiteAvailability as Boundary;
use App\Models\ServiceVariant;
use App\Services\Availability\ReadWebsiteDay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class WebsiteAvailabilityController extends Controller
{
    public function day(Request $request, ReadWebsiteDay $service)
    {
        if($request->getContent()!=='')return Boundary::error('invalid_request',422);
        $input=[];$query=explode('?',$request->getRequestUri(),2)[1]??'';
        foreach(explode('&',$query) as $pair){
            if($pair===''||preg_match('/%(?![0-9a-f]{2})/i',$pair)||str_contains($pair,';'))return Boundary::error('invalid_request',422);
            $parts=explode('=',$pair,2);
            if(count($parts)!==2)return Boundary::error('invalid_request',422);
            $key=urldecode($parts[0]);$value=urldecode($parts[1]);
            if(!in_array($key,['date','zip','service','alts'],true)||array_key_exists($key,$input))return Boundary::error('invalid_request',422);
            $input[$key]=$value;
        }
        $today=now()->setTimezone('America/Chicago')->toDateString();
        $validation=Validator::make($input,[
            'date'=>['required','date_format:Y-m-d','after_or_equal:'.$today,'before:2101-01-01'],
            'zip'=>['sometimes','regex:/^[0-9]{5}$/D'],
            'service'=>['sometimes','regex:/^[1-9][0-9]{0,9}$/D'],
            'alts'=>['sometimes','in:0,1'],
        ]);
        if($validation->fails())return Boundary::error('invalid_request',422);
        if(isset($input['service'])&&!ServiceVariant::whereKey($input['service'])->where('status','active')->exists())return Boundary::error('invalid_request',422);
        return response()->json($service->execute($input));
    }
    public function candidates(Request $request)
    {
        if(str_contains($request->getRequestUri(),'?'))return Boundary::error('invalid_request',422);
        if(strtolower(trim(explode(';',(string)$request->header('Content-Type'))[0]))!=='application/json')return Boundary::error('unsupported_media_type',415);
        $raw=$request->getContent();
        if(strlen($raw)>8192)return Boundary::error('invalid_request',422);
        try{$input=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}catch(\JsonException){return Boundary::error('invalid_request',422);}
        if(!is_array($input)||array_is_list($input))return Boundary::error('invalid_request',422);
        try{
            return response()->json(app(\App\Services\Availability\CheckWebsiteCandidates::class)->execute($input));
        }catch(\Illuminate\Validation\ValidationException){
            return Boundary::error('invalid_request',422);
        }catch(\Throwable $e){
            report($e);
            return Boundary::error('evaluation_unavailable',503);
        }
    }
}
