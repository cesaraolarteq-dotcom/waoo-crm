<?php
namespace App\Services\Mail;
use App\Services\Finance\{MutationKey,Sandbox};
use Illuminate\Validation\ValidationException;
final class ApprovedMail
{
    public const KEYS=['inquiry_received','quote_sent','details_request','contract_ready','payment_received','zelle_notice_pending','balance_reminder','week_before','thank_you_review'];
    public const VARIABLES=['first_name','event_date','address','package','package_price','travel_fee','subtotal','discount_total','total','deposit','amount','paid','balance','has_balance','due','balance_due','valid_until','link','review_link'];
    public static function definition(string $key,string $locale):array
    {
        $d=config('waoo.mail_templates.'.$key.'.'.$locale);
        if(!in_array($key,self::KEYS,true)||!in_array($locale,['es','en'],true)||!is_array($d))self::fail('approved_template_required');
        if(array_diff(array_keys($d),['version','approval_reference','subject','paragraphs','button','closing','required_variables'])||!is_string($d['version']??null)||trim($d['version'])===''||strlen($d['version'])>100
            ||!is_string($d['approval_reference']??null)||trim($d['approval_reference'])===''||!is_string($d['subject']??null)||!is_array($d['paragraphs']??null)||!array_is_list($d['paragraphs'])||count($d['paragraphs'])>30
            ||!is_array($d['required_variables']??null)||!array_is_list($d['required_variables'])||array_diff($d['required_variables'],self::VARIABLES))self::fail('invalid_approved_template');
        foreach($d['paragraphs'] as $p)if(!is_string($p)||strlen($p)>20000)self::fail('invalid_approved_template');
        if(!array_key_exists('closing',$d)||($d['closing']!==null&&!is_string($d['closing']))||!array_key_exists('button',$d))self::fail('invalid_approved_template');
        if($d['button']!==null&&(!is_array($d['button'])||array_diff(array_keys($d['button']),['label','variable'])||!is_string($d['button']['label']??null)||!in_array($d['button']['variable']??null,['link','review_link'],true)))self::fail('invalid_approved_template');
        return $d;
    }
    public static function fail(string $code):never{throw ValidationException::withMessages(['mail'=>$code]);}
    public static function render(string $key,string $locale,#[\SensitiveParameter] array $vars,array $totals):array
    {
        $d=self::definition($key,$locale);
        if(array_diff(array_keys($vars),self::VARIABLES))self::fail('unknown_template_variable');
        foreach($d['required_variables'] as $name)if(!array_key_exists($name,$vars)||$vars[$name]===null||$vars[$name]==='')self::fail('missing_template_variable');
        $fill=function(string $text)use($vars){return preg_replace_callback('/\{([^{}]+)\}/',function($m)use($vars){if(!in_array($m[1],self::VARIABLES,true)||!array_key_exists($m[1],$vars)||$vars[$m[1]]===null)self::fail('missing_or_unknown_template_variable');return is_bool($vars[$m[1]])?($vars[$m[1]]?'true':'false'):(string)$vars[$m[1]];},$text);};
        $subject=$fill($d['subject']);if(mb_strlen($subject)>60||trim($subject)===''||preg_match('/[\r\n\x00-\x1F\x7F]/',$subject))self::fail('invalid_subject');
        $button=null;if($d['button']){$name=$d['button']['variable'];$url=$vars[$name]??null;self::url($url,$name==='link');$button=['label'=>$fill($d['button']['label']),'url'=>$url];}
        $mail=['subject'=>$subject,'paragraphs'=>array_map(fn($p)=>$p==='__totals__'?$p:$fill($p),$d['paragraphs']),'closing'=>$d['closing']===null?null:$fill($d['closing']),'button'=>$button];
        $text=[];foreach($mail['paragraphs'] as $p){if($p==='__totals__')foreach($totals as $r)$text[]=$r['label'].': '.$r['amount'];else$text[]=$p;}
        if($button)$text[]=$button['label'].': '.$button['url'];if($mail['closing'])$text[]=$mail['closing'];$text[]='— Waoo Party';
        $html=view('mail.layout',['lang'=>$locale,'mail'=>$mail,'totals'=>$totals,'logo'=>Sandbox::origin().'/img/waoo-logo.png'])->render();
        return ['subject'=>$subject,'html'=>$html,'text'=>implode("\n\n",$text),'template_version'=>$d['version'],'template_sha256'=>MutationKey::hash($d)];
    }
    public static function url(mixed $url,bool $portal):void
    {
        if(!is_string($url)||preg_match('/[\r\n\x00-\x20\x7F]/',$url))self::fail('invalid_message_link');$u=parse_url($url);
        if(!$u||($u['scheme']??null)!=='https'||isset($u['user'])||isset($u['pass'])||empty($u['host']))self::fail('invalid_message_link');
        if($portal){$base=Sandbox::origin();if(!str_starts_with($url,$base.'/portal#token='))self::fail('invalid_message_link');}
        elseif(!hash_equals((string)config('waoo.mail_review_url'),$url))self::fail('unapproved_review_link');
    }
}
