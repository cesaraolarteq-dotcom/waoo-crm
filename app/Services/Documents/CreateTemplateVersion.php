<?php
namespace App\Services\Documents;

use App\Models\{ContractTemplateVersion, User};
use App\Services\Audit;
use App\Services\Finance\{MutationKey, Sandbox};
use App\Services\Records\{RecordActor, RecordInput};
use Illuminate\Support\Facades\DB;

final class CreateTemplateVersion
{
    public function execute(User $actor, array $input, string $key): ContractTemplateVersion
    {
        Sandbox::require('sandbox_documents'); $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate($input, ['source_version_id' => 'sometimes|nullable|integer|exists:contract_template_versions,id', 'terms_html' => 'required|string|max:200000', 'change_note' => 'required|string|min:10|max:2000']);
        app(PrintRenderer::class)->validateTerms($data['terms_html']);
        $scope = "owner:{$actor->id}:contract-template"; $hash = MutationKey::hash($data);
        return DB::transaction(function () use ($actor, $data, $key, $scope, $hash) {
            $registry = DB::table('contract_template_registry')->where('id', 1)->lockForUpdate()->first();
            if ($saved = MutationKey::find($scope, $key, $hash)) return ContractTemplateVersion::findOrFail($saved->result_id);
            $source = file_get_contents(base_path('docs/templates/html/contract-print.html'));
            $template = ContractTemplateVersion::create(['version' => $registry->next_version, 'state' => 'draft', 'locale' => 'en',
                'source_html' => $source, 'source_sha256' => hash('sha256', $source), 'terms_html' => $data['terms_html'], 'terms_sha256' => hash('sha256', $data['terms_html']),
                'change_note' => $data['change_note'], 'created_by' => $actor->id]);
            DB::table('contract_template_registry')->where('id', 1)->update(['next_version' => $registry->next_version + 1]);
            Audit::recordFor($actor, 'contract.template_drafted', $template, [], ['version' => $template->version, 'source_sha256' => $template->source_sha256, 'terms_sha256' => $template->terms_sha256, 'change_note' => $data['change_note'], 'source_version_id' => $data['source_version_id'] ?? null]);
            MutationKey::store($scope, $key, $hash, 'contract_template', $template->id);
            return $template;
        }, 3);
    }
}
