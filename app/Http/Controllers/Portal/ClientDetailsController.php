<?php
namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Exceptions\{RecordWriteRejected, ResponsibilityRejected};
use App\Models\Contract;
use App\Services\Documents\PrepareClientContract;
use App\Services\Finance\Sandbox;
use App\Services\Portal\SubmitClientDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ClientDetailsController extends Controller
{
    public function store(Request $request, SubmitClientDetails $service)
    {
        $submission = $service->execute($request->attributes->get('portal_context'), $request->all(), (string) $request->header('Idempotency-Key'));
        $event = \App\Models\Event::findOrFail($submission->event_id);
        $result = ['submission' => $submission->only(['id','revision','data','submitted_at']),
            'reviewed' => \App\Services\Booking\ClientDetailsState::portal($event)['reviewed']];
        $quick=\App\Services\Booking\QuickPaymentProtocol::booking($event->id,false);
        if ($quick) $result['payment_required']=true;
        if (!$quick && config('waoo.sandbox_immediate_contracts')) {
            // execute() above committed the submission. Artifact failure must not undo it.
            try {
                $contract = app(PrepareClientContract::class)->execute($request->attributes->get('portal_context'),
                    ['expected_event_version' => $submission->event_version, 'expected_revision' => $submission->revision], (string) $request->header('Idempotency-Key'));
                $result['contract_preparation'] = self::ready($contract);
            } catch (RecordWriteRejected|ResponsibilityRejected|AuthorizationException|ModelNotFoundException|ValidationException) {
                $result['contract_preparation'] = ['status' => 'review_required'];
            } catch (\Throwable) {
                // Do not log submitted identity, SQL bindings, private paths or artifact exception text.
                $result['contract_preparation'] = ['status' => 'retry_required'];
            }
        }
        return response()->json($result, 201);
    }

    public function prepare(Request $request, PrepareClientContract $service)
    {
        abort_unless(config('waoo.sandbox_immediate_contracts') || \App\Services\Booking\QuickPaymentProtocol::enabled(), 404);
        Sandbox::require(); Sandbox::require('sandbox_documents');
        try {
            return response()->json(['contract_preparation' => self::ready($service->execute($request->attributes->get('portal_context'),
                $request->all(), (string) $request->header('Idempotency-Key')))]);
        } catch (RecordWriteRejected|ResponsibilityRejected|AuthorizationException|ValidationException $error) {
            throw $error;
        } catch (ModelNotFoundException) {
            throw new RecordWriteRejected(409, 'La reserva requiere revisión del dueño. / The booking needs owner review.');
        } catch (\Throwable) {
            // Deliberately not logged: a failure here may carry the client's submitted details (SameVisitContractTest).
            return response()->json(['message' => 'Tus datos siguen guardados. Intenta preparar el contrato de nuevo. / Your details are saved. Try preparing the contract again.'], 503);
        }
    }

    private static function ready(Contract $contract): array
    {
        return ['status' => 'ready', 'contract' => $contract->only(['id','version','presented_pdf_sha256'])];
    }
}
