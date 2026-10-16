<?php
namespace App\Services\Documents;

use App\Exceptions\RecordWriteRejected;
use App\Models\{ContractTemplateVersion, User};
use App\Services\Audit;
use App\Services\Finance\Sandbox;
use App\Services\Records\{RecordActor, RecordInput};
use Illuminate\Support\Facades\DB;

final class ActivateTemplateVersion
{
    public function execute(User $actor, int $templateId, array $input): ContractTemplateVersion
    {
        Sandbox::require('sandbox_documents'); $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate($input, ['expected_source_sha256' => ['required','string','regex:/^[a-f0-9]{64}$/'], 'approval_note' => 'required|string|min:10|max:2000']);
        return DB::transaction(function () use ($actor, $templateId, $data) {
            $registry = DB::table('contract_template_registry')->where('id', 1)->lockForUpdate()->first();
            $template = ContractTemplateVersion::whereKey($templateId)->lockForUpdate()->firstOrFail();
            RecordWriteRejected::unless(hash_equals($template->source_sha256, $data['expected_source_sha256']), 409, 'La plantilla no corresponde a la versión revisada.');
            if ($template->state === 'active') return $template;
            RecordWriteRejected::unless($template->state === 'draft', 409, 'Crea una versión nueva para volver a usar esas condiciones.');
            if ($registry->active_version_id) ContractTemplateVersion::whereKey($registry->active_version_id)->update(['state' => 'retired', 'updated_at' => now()]);
            ContractTemplateVersion::whereKey($template->id)->update(['state' => 'active', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_at' => now()]);
            DB::table('contract_template_registry')->where('id', 1)->update(['active_version_id' => $template->id]);
            Audit::recordFor($actor, 'contract.template_activated', $template, ['active_version_id' => $registry->active_version_id], ['active_version_id' => $template->id, 'terms_sha256' => $template->terms_sha256, 'approval_note' => $data['approval_note']]);
            return $template->fresh();
        }, 3);
    }
}
