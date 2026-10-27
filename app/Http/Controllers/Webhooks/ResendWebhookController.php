<?php
namespace App\Http\Controllers\Webhooks;

use App\Exceptions\RecordWriteRejected;
use App\Services\Mail\ReceiveResendWebhook;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ResendWebhookController
{
    public function __invoke(Request $request,ReceiveResendWebhook $receiver)
    {
        try{$receiver->execute($request);return response()->json(['received'=>true]);}
        catch(ValidationException){$status=422;}
        catch(RecordWriteRejected $error){$status=$error->getStatusCode();}
        catch(HttpExceptionInterface $error){$status=$error->getStatusCode();}
        catch(\Throwable){$status=503;}
        return response()->json(['error'=>'resend_webhook_rejected'],$status);
    }
}
