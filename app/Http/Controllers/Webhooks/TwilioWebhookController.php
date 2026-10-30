<?php
namespace App\Http\Controllers\Webhooks;

use App\Services\Sms\ReceiveTwilioWebhook;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class TwilioWebhookController
{
    public function inbound(Request $request, ReceiveTwilioWebhook $receiver)
    {
        return $this->handle(fn () => $receiver->inbound($request));
    }

    public function status(Request $request, ReceiveTwilioWebhook $receiver)
    {
        return $this->handle(fn () => $receiver->status($request));
    }

    private function handle(\Closure $work)
    {
        try { $work(); $status = 200; }
        catch (HttpExceptionInterface $error) { $status = $error->getStatusCode(); }
        catch (\Throwable) { $status = 503; }

        // Empty TwiML: Waoo never auto-replies from here (Twilio's Advanced Opt-Out answers STOP/HELP).
        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', $status)->header('Content-Type', 'text/xml');
    }
}
