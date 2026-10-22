<?php
namespace App\Services\Mail;
use Carbon\CarbonImmutable;
final class MailDates
{
    public static function full($at,string $locale):string
    {
        return self::local($at,$locale)->translatedFormat($locale==='es'?'l j \d\e F \d\e Y, g:i a':'D, M j, Y g:i A');
    }
    public static function due($at,string $locale):string
    {
        return self::local($at,$locale)->translatedFormat($locale==='es'?'j \d\e F \d\e Y':'M j, Y');
    }
    public static function subject($at,string $locale):string
    {
        return self::local($at,$locale)->translatedFormat($locale==='es'?'l j \d\e F':'D, M j');
    }
    private static function local($at,string $locale):CarbonImmutable
    {
        if(!in_array($locale,['es','en'],true))ApprovedMail::fail('missing_recipient_locale');
        return CarbonImmutable::parse($at)->setTimezone('America/Chicago')->locale($locale);
    }
}
