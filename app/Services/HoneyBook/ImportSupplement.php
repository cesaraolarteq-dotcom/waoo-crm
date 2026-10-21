<?php
namespace App\Services\HoneyBook;
use App\Models\{HoneyBookSupplement, User};
use App\Services\Audit;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;
final class ImportSupplement
{
    public function execute(User $actor, string $json): array {
        SupplementAccess::check($actor);
        try {
            if (strlen($json) > 2097152 || ! mb_check_encoding($json, 'UTF-8')) throw new \RuntimeException();
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data)) throw new \RuntimeException();
            $v = Validator::make(['document' => $data], [
                'document' => 'required|array:profile,extracted_at,records',
                'document.profile' => 'required|in:honeybook-supplement-v1',
                'document.extracted_at' => 'required|date_format:Y-m-d\TH:i:s\Z|before_or_equal:now',
                'document.records' => 'required|array|min:1|max:500',
                'document.records.*' => 'required|array:source_type,source_id,category,field,value',
                'document.records.*.source_type' => 'required|in:project,contact',
                'document.records.*.source_id' => 'required|string|max:200',
                'document.records.*.category' => 'required|in:note,form,message,document_reference,custom_field,other',
                'document.records.*.field' => 'required|string|max:120',
                'document.records.*.value' => 'required|string|max:16000',
            ]);
            if ($v->fails()) throw new \RuntimeException();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'Formato inválido; revisa el perfil y los límites del archivo.']);
        }
        return DB::transaction(function () use ($actor, $data) {
            // Serialize both owners without taking missing-key gap locks for duplicate content.
            DB::table('users')->where('role', 'owner_admin')->orderBy('id')->lockForUpdate()->get(['id']);
            $created = 0; $unchanged = 0;
            foreach ($data['records'] as $row) {
                $hash = HoneyBookSupplement::fingerprintFor($row);
                if (HoneyBookSupplement::where('fingerprint', $hash)->exists()) { $unchanged++; continue; }
                $record = HoneyBookSupplement::create(['fingerprint' => $hash,
                    'payload' => ['profile' => 'honeybook-supplement-v1', 'extracted_at' => $data['extracted_at'], 'record' => $row],
                    'created_by' => $actor->id, 'created_at' => now()]);
                Audit::recordFor($actor, 'honeybook.supplement.preserved', $record, [], ['profile' => 'honeybook-supplement-v1']);
                $created++;
            }
            return ['created' => $created, 'unchanged' => $unchanged];
        });
    }
}
