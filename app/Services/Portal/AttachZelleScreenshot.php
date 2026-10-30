<?php
namespace App\Services\Portal;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, ZelleNotice, ZelleNoticeScreenshot};
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
        // The original filename is untrusted even when its contents pass MIME validation.
        $ext = strtolower((string)$file->extension());
        $path = null;
        try {
            return DB::transaction(function () use ($noticeId, $file, &$path, $client, $ext) {
                // Same grant → event → notice protocol as submission/revocation/review.
                // Authorization outside the transaction would not retain its locks.
                Sandbox::require(); Sandbox::require('sandbox_documents');
                $client->authorize($client->eventId, 'checkout.create', true);
                $event = Event::whereKey($client->eventId)->where('customer_id', $client->customerId)->lockForUpdate()->firstOrFail();
                $notice = ZelleNotice::where('event_id', $event->id)->where('customer_id', $client->customerId)->lockForUpdate()->find($noticeId);
                RecordWriteRejected::when($notice === null || $notice->review()->exists(), 404, 'No encontramos ese aviso pendiente.');
                RecordWriteRejected::when(ZelleNoticeScreenshot::where('notice_id', $notice->id)->lockForUpdate()->exists(), 409, 'Ese aviso ya tiene su comprobante.');
                $path = 'zelle-screenshots/'.$event->id.'/'.Str::uuid().'.'.$ext;
                $stored = Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
                if ($stored !== $path) throw new \RuntimeException('Private attachment storage unavailable.');
                $shot = ZelleNoticeScreenshot::create(['notice_id' => $notice->id, 'path' => $path, 'mime' => (string) $file->getMimeType(),
                    'size_bytes' => (int) $file->getSize(), 'sha256' => hash_file('sha256', Storage::disk('local')->path($path)), 'uploaded_at' => now()]);
                Audit::recordSystem('portal.zelle_screenshot_attached', $notice, [], ['screenshot_id' => $shot->id, 'customer_id' => $client->customerId, 'portal_grant_id' => $client->grantId]);
                return $shot;
            });
        } catch (\Throwable $e) {
            if ($path !== null) Storage::disk('local')->delete($path);
            // Query bindings can include a private evidence path. Never retain the SQL chain.
            if ($e instanceof \Illuminate\Database\QueryException) throw new \RuntimeException('Private attachment persistence unavailable.');
            throw $e;
        }
    }
}
