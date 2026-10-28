<?php
namespace App\Services\Portal;

use App\Exceptions\RecordWriteRejected;
use App\Models\{ZelleNotice, ZelleNoticeScreenshot};
use App\Services\Audit;
use App\Services\Finance\Sandbox;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage, Validator};
use Illuminate\Support\Str;

/** Owner 2026-10-28: optional screenshot for the client's own, still unreviewed Zelle notice. One per notice; it is evidence, not payment. */
final class AttachZelleScreenshot
{
    public const MIMES = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'pdf'];

    public function execute(PortalContext $client, int $noticeId, mixed $file): ZelleNoticeScreenshot
    {
        Sandbox::require(); Sandbox::require('sandbox_documents');
        if ($client->adminAuthenticated) throw new AuthorizationException('Solo el cliente puede adjuntar su comprobante.');
        Validator::make(['screenshot' => $file], ['screenshot' => ['required', 'file', 'mimes:'.implode(',', self::MIMES), 'max:8192']],
            ['screenshot.mimes' => 'Sube una foto (JPG, PNG, HEIC) o un PDF.', 'screenshot.max' => 'La imagen pesa más de 8 MB.'])->validate();
        /** @var UploadedFile $file */
        $client->authorize($client->eventId, 'checkout.create', true);
        $notice = ZelleNotice::where('event_id', $client->eventId)->where('customer_id', $client->customerId)->whereDoesntHave('review')->find($noticeId);
        RecordWriteRejected::when($notice === null, 404, 'No encontramos ese aviso pendiente.');
        RecordWriteRejected::when(ZelleNoticeScreenshot::where('notice_id', $notice->id)->exists(), 409, 'Ese aviso ya tiene su comprobante.');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $path = Storage::disk('local')->putFileAs('zelle-screenshots/'.$notice->event_id, $file, Str::uuid().'.'.$ext);
        try {
            return DB::transaction(function () use ($notice, $file, $path, $client) {
                $shot = ZelleNoticeScreenshot::create(['notice_id' => $notice->id, 'path' => $path, 'mime' => (string) $file->getMimeType(),
                    'size_bytes' => (int) $file->getSize(), 'sha256' => hash_file('sha256', Storage::disk('local')->path($path)), 'uploaded_at' => now()]);
                Audit::recordSystem('portal.zelle_screenshot_attached', $notice, [], ['screenshot_id' => $shot->id, 'customer_id' => $client->customerId, 'portal_grant_id' => $client->grantId]);
                return $shot;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }
}
