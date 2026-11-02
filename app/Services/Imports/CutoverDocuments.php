<?php
namespace App\Services\Imports;

use App\Exceptions\RecordWriteRejected;
use App\Models\CutoverOriginalDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;

final class CutoverDocuments
{
    public function upload(#[\SensitiveParameter] UploadedFile $file): string
    {
        if (!$file->isValid()) ImportInput::fail('file', 'original_pdf_required');
        $handle = @fopen($file->getRealPath(), 'rb');
        if (!$handle) ImportInput::fail('file', 'original_pdf_unreadable');
        try { $bytes = stream_get_contents($handle, ImportCsv::BYTES + 1); } finally { fclose($handle); }
        if ($bytes === false || strlen($bytes) > ImportCsv::BYTES) ImportInput::fail('file', 'original_pdf_size_limit');
        if (!str_starts_with($bytes, '%PDF-') || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'application/pdf') {
            ImportInput::fail('file', 'original_pdf_required');
        }
        return $bytes;
    }
    public function verify(CutoverOriginalDocument $document): void
    {
        $this->read($document);
    }
    public function read(CutoverOriginalDocument $document): string
    {
        try {
            $cipher = CutoverOriginalDocument::whereKey($document->id)->sharedLock()->value('document_ciphertext');
            $bytes = Crypt::decryptString($cipher);
            $valid = strlen($bytes) === $document->byte_count && strlen($bytes) <= ImportCsv::BYTES
                && hash_equals($document->sha256, hash('sha256', $bytes)) && str_starts_with($bytes, '%PDF-');
        } catch (\Illuminate\Database\QueryException $e) { throw $e; }
        catch (\Throwable) { $valid = false; }
        RecordWriteRejected::unless($valid, 409, 'El documento original requiere revisar su integridad.');
        return $bytes;
    }
}
