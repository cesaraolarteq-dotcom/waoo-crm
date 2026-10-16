<?php
namespace Tests\Feature;

use App\Jobs\FinalizeSignedContract;
use App\Models\{Contract, ContractTemplateVersion, Customer, Event, FinancialRevision, PriceVersion, ServiceVariant, SignatureEvidence, User};
use App\Services\Documents\{ActivateTemplateVersion, ArtifactStore, CreateContract, CreateTemplateVersion, IssueContract};
use App\Services\Finance\CreateFinancialRevision;
use App\Services\Portal\IssuePortalGrant;
use App\Services\Records\SaveEventDraft;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Queue, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class SandboxDocumentsTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private Event $event;
    private FinancialRevision $finance;
    private ContractTemplateVersion $template;
    private Contract $contract;
    protected function setUp(): void
    {
        parent::setUp(); config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true]);
        Storage::fake('local'); Queue::fake();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        $customer = Customer::create(['name' => 'Fixture Client', 'email' => 'client@example.test', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fixture package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 40000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $start = now()->addDays(20)->startOfHour();
        $this->event = app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $customer->id, 'title' => 'Fixture party', 'address' => 'Fixture event address', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'timezone' => 'America/Chicago', 'travel_fee_cents' => 5500, 'services' => [['price_version_id' => $price->id]]]);
        $this->finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
        $this->template = app(CreateTemplateVersion::class)->execute($this->owner, ['terms_html' => '<h3>Fixture legal terms</h3><p>Test agreement only. This is not the Waoo legal contract.</p>', 'change_note' => 'Fixture-only approval test'], (string) Str::uuid());
        $this->template = app(ActivateTemplateVersion::class)->execute($this->owner, $this->template->id, ['expected_source_sha256' => $this->template->source_sha256, 'approval_note' => 'Activate fictitious conditions in isolated test only.']);
        $this->contract = $this->draft();
    }
    private function draft(): Contract
    {
        return app(CreateContract::class)->execute($this->owner, $this->event->id, ['expected_version' => 1, 'financial_revision_id' => $this->finance->id, 'template_version_id' => $this->template->id,
            'client' => ['first_name' => 'Fixture', 'last_name' => 'Client', 'address' => 'Fixture billing address', 'email' => 'client@example.test', 'phone' => '+15555550123']], (string) Str::uuid());
    }
    private function issue(): Contract
    {
        $this->contract = app(IssueContract::class)->execute($this->owner, $this->contract->id, ['expected_version' => 1], (string) Str::uuid());
        return $this->contract;
    }
    private function clientSession(array $scopes = ['contract.read','contract.sign','payments.read']): int
    {
        $grant = app(IssuePortalGrant::class)->execute($this->owner, $this->event->id, ['customer_id' => $this->event->customer_id, 'expires_at' => now()->addDay()->toIso8601String(), 'scopes' => $scopes]);
        $this->assertFalse(DB::table('portal_grants')->where('token_hash', $grant->token)->exists());
        auth()->logout();
        $this->postJson('/portal/api/session', ['token' => $grant->token])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        return $grant->id;
    }
    private function signInput(array $extra = []): array
    {
        return [...['expected_version' => $this->contract->version, 'presented_pdf_sha256' => $this->contract->presented_pdf_sha256,
            'signer_name' => 'Fixture Client', 'consent' => true, 'intent_to_sign' => true, 'consent_version' => 'esign-consent-v1'], ...$extra];
    }
    public function test_revoke_service_reauthorizes_is_idempotent_and_invalidates_existing_session(): void
    {
        $this->issue(); $grantId = $this->clientSession();
        $this->get('/portal/api/contracts/'.$this->contract->id.'/pdf')->assertOk();
        $service = app(\App\Services\Portal\RevokePortalGrant::class);
        $this->owner->forceFill(['active' => false])->save();
        try { $service->execute($this->owner, $grantId); $this->fail('Inactive owner accepted.'); }
        catch (\Illuminate\Auth\Access\AuthorizationException) { $this->assertNull(\App\Models\PortalGrant::findOrFail($grantId)->revoked_at); }
        $this->owner->forceFill(['active' => true])->save();
        $first = $service->execute($this->owner, $grantId);
        $again = $service->execute($this->owner, $grantId);
        $this->assertSame($first->revoked_at->toIso8601String(), $again->revoked_at->toIso8601String());
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'portal.grant_revoked')->where('actor_id', $this->owner->id)->count());
        $this->getJson('/portal/api/event')->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/portal/api/contracts/'.$this->contract->id.'/sign', $this->signInput())->assertForbidden();
        $this->assertDatabaseCount('signature_evidence', 0);
    }
    public function test_revoke_audit_failure_rolls_back_and_sandbox_off_denies_direct_call(): void
    {
        $grantId = $this->clientSession();
        $service = app(\App\Services\Portal\RevokePortalGrant::class);
        $fail = true;
        DB::connection()->beforeExecuting(function ($query, $bindings) use (&$fail) {
            if ($fail && str_contains($query, 'audit_entries') && in_array('portal.grant_revoked', $bindings, true)) throw new \RuntimeException('Fixture revoke audit failure');
        });
        try { $service->execute($this->owner, $grantId); $this->fail('Audit failure ignored.'); }
        catch (\RuntimeException $e) { $this->assertSame('Fixture revoke audit failure', $e->getMessage()); }
        finally { $fail = false; }
        $this->assertNull(\App\Models\PortalGrant::findOrFail($grantId)->revoked_at);
        config(['waoo.sandbox_documents' => false]);
        try { $service->execute($this->owner, $grantId); $this->fail('Disabled sandbox accepted.'); }
        catch (\Illuminate\Auth\Access\AuthorizationException) { $this->assertFalse(config('waoo.sandbox_documents')); }
        $this->assertNull(\App\Models\PortalGrant::findOrFail($grantId)->revoked_at);
    }
    public function test_revoke_and_signature_concurrency_serializes_on_same_grant_row(): void
    {
        $this->issue(); $grantId = $this->clientSession();
        $this->get('/portal/api/contracts/'.$this->contract->id.'/pdf')->assertOk();
        $request = \Illuminate\Http\Request::create('/portal/api/contracts/'.$this->contract->id.'/sign', 'POST');
        $request->setLaravelSession($this->app['session.store']); $request->setUserResolver(fn () => null);
        $context = \App\Services\Portal\PortalContext::fromRequest($request);
        $signatureRequest = \App\Services\Documents\SignatureRequestContext::fromRequest($request);
        $input = $this->signInput(); $id = $this->contract->id; $actor = $this->owner;
        $results = \Tests\Support\ConcurrentWorkers::run([
            function () use ($actor, $grantId) { app(\App\Services\Portal\RevokePortalGrant::class)->execute($actor, $grantId); return 'revoked'; },
            function () use ($context, $signatureRequest, $input, $id) {
                try { app(\App\Services\Documents\AcceptSignature::class)->execute($context, $id, $input, $signatureRequest, (string) Str::uuid()); return 'signed'; }
                catch (\Illuminate\Auth\Access\AuthorizationException) { return 'revocation_won'; }
            },
        ]);
        $this->assertSame('revoked', $results[0]);
        $this->assertContains($results[1], ['signed', 'revocation_won']);
        $this->assertNotNull(\App\Models\PortalGrant::findOrFail($grantId)->revoked_at);
        $this->assertDatabaseCount('signature_evidence', $results[1] === 'signed' ? 1 : 0);
        $this->assertSame($results[1] === 'signed' ? 'signed' : 'issued', $this->contract->fresh()->state);
        $this->getJson('/portal/api/event')->assertForbidden();
    }
    public function test_catalog_description_is_audited_and_frozen_in_finance_and_pdf(): void
    {
        $variant = ServiceVariant::firstOrFail();
        $input = ['name' => $variant->name, 'status' => 'active', 'duration_minutes' => 60, 'requirements' => $variant->requirements];
        $description = 'Fixture owner description & details <not executable>.';
        $service = app(\App\Services\Records\SaveVariant::class);
        $variant = $service->execute($this->owner, [...$input, 'expected_version' => 1, 'long_description' => $description], $variant->id);
        $variant = $service->execute($this->owner, [...$input, 'expected_version' => 2], $variant->id);
        $this->assertSame($description, $variant->long_description);
        $this->finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
        $this->assertSame($description, $this->finance->lines_snapshot[0]['long_description']);
        $this->contract = $this->draft();
        $service->execute($this->owner, [...$input, 'expected_version' => 3, 'long_description' => null], $variant->id);
        $this->assertNull($variant->fresh()->long_description);
        $contract = $this->issue();
        $html = app(ArtifactStore::class)->read($contract->presented_html_path, $contract->presented_html_sha256);
        $this->assertStringContainsString('Fixture owner description &amp; details &lt;not executable&gt;.', $html);
        $this->assertStringContainsString('1 × Character performer', $html);
        $this->assertStringNotContainsString('1 × performer', $html);
        $this->assertSame($description, $contract->commercial_snapshot['services'][0]['long_description']);
        $this->assertDatabaseHas('audit_entries', ['action' => 'variants.updated', 'subject_id' => (string) $variant->id]);
    }
    public function test_pdf_requirements_use_friendly_labels_without_leaking_custom_internal_codes(): void
    {
        $snapshot = $this->contract->commercial_snapshot;
        $snapshot['payments'] = app(\App\Services\Documents\ContractPrintContext::class)->paymentSnapshot($this->event);
        $snapshot['services'][0]['requirements_snapshot'] = [
            ['kind' => 'staff', 'quantity' => 1, 'skill' => 'face_painter'],
            ['kind' => 'staff', 'quantity' => 1, 'skill' => 'internal_custom_role'],
            ['kind' => 'equipment', 'quantity' => 2, 'equipment_kind' => 'foam_cannon'],
            ['kind' => 'equipment', 'quantity' => 1, 'equipment_kind' => 'private_internal_gear'],
        ];
        $this->contract->commercial_snapshot = $snapshot;
        $context = app(\App\Services\Documents\ContractPrintContext::class)->build($this->contract, $this->template);
        $this->assertSame(['1 × Face painter', '1 × Staff member', '2 × Foam cannon', '1 × Equipment unit'], $context['services'][0]['includes']);
        $this->assertSame('', $context['services'][0]['long_description']);
    }
    public function test_approved_terms_fields_use_frozen_issue_values_and_preserve_legal_hash(): void
    {
        $terms = '<p>{{ client.first_name }} {{client.last_name}} owes {{payments.balance_amount}}.</p>';
        $this->template = app(CreateTemplateVersion::class)->execute($this->owner, ['terms_html' => $terms, 'change_note' => 'Fixture merge fields only'], (string) Str::uuid());
        app(ActivateTemplateVersion::class)->execute($this->owner, $this->template->id, ['expected_source_sha256' => $this->template->source_sha256, 'approval_note' => 'Approve fixture version with allowed merge fields only.']);
        $this->contract = $this->draft();
        $contract = $this->issue();
        $html = app(ArtifactStore::class)->read($contract->presented_html_path, $contract->presented_html_sha256);
        $this->assertStringContainsString('<p>Fixture Client owes $455.00.</p>', $html);
        $this->assertSame($terms, $this->template->fresh()->terms_html);
        $this->assertSame(hash('sha256', $terms), $this->template->fresh()->terms_sha256);
    }
    public function test_terms_fields_are_escaped_once_without_template_evaluation(): void
    {
        $renderer = app(\App\Services\Documents\PrintRenderer::class);
        $html = $renderer->html('<html><body>{{terms_html}}</body></html>', '<p>{{client.first_name}} {{client.last_name}}: {{payments.balance_amount}}</p>', [
            'client' => ['first_name' => '<script>&', 'last_name' => '{{payments.balance_amount}}'], 'payments' => ['balance_amount' => '$1.25']]);
        $this->assertStringContainsString('&lt;script&gt;&amp;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&#123;&#123;payments.balance_amount&#125;&#125;', $html);
        foreach (['{{client.email}}', '{{{client.first_name}}}', '{{#client.first_name}}x{{/client.first_name}}', '{{>client.first_name}}', '{{&client.first_name}}', '{{= <% %> =}}', '{{client.first_name', '{{ client.first_name }} }}}'] as $field) {
            $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/admin/api/contract-templates', ['terms_html' => '<p>'.$field.'</p>', 'change_note' => 'Reject fixture unsafe merge expression'])->assertUnprocessable();
        }
    }
    public function test_issued_pdf_is_private_hashed_and_uses_pinned_template(): void
    {
        $contract = $this->issue();
        $this->assertSame('issued', $contract->state);
        $bytes = app(ArtifactStore::class)->read($contract->presented_pdf_path, $contract->presented_pdf_sha256);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $html = app(ArtifactStore::class)->read($contract->presented_html_path, $contract->presented_html_sha256);
        $this->assertStringContainsString('Fixture legal terms', $html);
        $this->assertStringContainsString('Fixture billing address', $html);
        $this->assertStringContainsString('Fixture event address', $html);
        $this->assertStringContainsString('Provider acceptance pending', $html);
        $this->assertStringNotContainsString('{{', $html);
        $this->get('/admin/api/contracts/'.$contract->id.'/pdf')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        auth()->logout();
        $this->getJson('/admin/api/contracts/'.$contract->id.'/pdf')->assertUnauthorized();
        $this->getJson('/portal/api/contracts/'.$contract->id.'/pdf')->assertForbidden();
    }
    public function test_signature_binds_presented_bytes_and_retry_does_not_sign_again(): void
    {
        $contract = $this->issue(); $this->clientSession();
        $this->get('/portal/api/contracts/'.$contract->id.'/pdf')->assertOk();
        $key = (string) Str::uuid(); $data = $this->signInput();
        $url = '/portal/api/contracts/'.$contract->id.'/sign';
        $this->withHeader('Idempotency-Key', $key)->postJson($url, $data)->assertOk()->assertJsonPath('signature.presented_pdf_sha256', $contract->presented_pdf_sha256)->assertJsonPath('final_pdf_ready', false);
        $this->withHeader('Idempotency-Key', $key)->postJson($url, $data)->assertOk();
        $this->withHeader('Idempotency-Key', $key)->postJson($url, [...$data, 'signer_name' => 'Different name'])->assertConflict();
        $this->assertDatabaseCount('signature_evidence', 1);
        $this->assertSame('signed', $contract->fresh()->state);
        $this->assertSame('draft', $this->event->fresh()->status);
        $this->assertDatabaseCount('ledger_entries', 0);
        Queue::assertPushed(FinalizeSignedContract::class, 1);
        (new FinalizeSignedContract($contract->id))->handle();
        $final = $contract->fresh();
        $this->assertNotNull($final->final_pdf_path);
        $this->assertFalse(hash_equals($final->presented_pdf_sha256, $final->final_pdf_sha256));
        $this->assertSame(hash('sha256', Storage::disk('local')->get($final->presented_pdf_path)), $contract->presented_pdf_sha256);
        $evidence = SignatureEvidence::firstOrFail();
        $this->assertSame('scoped_link_email_unverified', $evidence->identity_method);
        $portal = $this->getJson('/portal/api/event')->assertOk()->assertJsonPath('contract.id', $contract->id)
            ->assertJsonPath('services.0.name', 'Fixture package');
        $this->assertArrayNotHasKey('requirements_snapshot', $portal->json('services.0'));
        $this->assertArrayNotHasKey('lines_snapshot', $portal->json('finance.financial_revision'));
        $this->assertArrayNotHasKey('presented_pdf_path', $portal->json('contract'));
        $this->assertSame($this->template->terms_sha256, $evidence->template_terms_sha256);
    }
    public function test_hash_consent_and_pdf_presentation_are_required(): void
    {
        $this->issue(); $this->clientSession(); $url = '/portal/api/contracts/'.$this->contract->id.'/sign';
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, $this->signInput())->assertUnprocessable()->assertJsonValidationErrors(['contract']);
        $this->get('/portal/api/contracts/'.$this->contract->id.'/pdf')->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, $this->signInput(['consent' => false]))->assertUnprocessable();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, $this->signInput(['presented_pdf_sha256' => str_repeat('0', 64)]))->assertConflict();
        $this->assertDatabaseCount('signature_evidence', 0);
    }
    public function test_expired_revoked_and_cross_customer_access_are_denied(): void
    {
        $this->issue(); $grantId = $this->clientSession();
        $this->getJson('/portal/api/contracts/'.$this->contract->id)->assertOk();
        DB::table('portal_grants')->where('id', $grantId)->update(['revoked_at' => now()]);
        $this->getJson('/portal/api/contracts/'.$this->contract->id)->assertForbidden();
        DB::table('portal_grants')->where('id', $grantId)->update(['revoked_at' => null, 'expires_at' => now()->subSecond()]);
        $this->getJson('/portal/api/contracts/'.$this->contract->id)->assertForbidden();
        DB::table('portal_grants')->where('id', $grantId)->update(['expires_at' => now()->addDay(), 'customer_id' => Customer::create(['name' => 'Other fixture', 'locale' => 'es'])->id]);
        $this->getJson('/portal/api/contracts/'.$this->contract->id)->assertNotFound();
    }
    public function test_admin_session_cannot_impersonate_customer_signature(): void
    {
        $this->issue(); $this->clientSession();
        $this->get('/portal/api/contracts/'.$this->contract->id.'/pdf')->assertOk();
        $this->actingAs($this->owner);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/portal/api/contracts/'.$this->contract->id.'/sign', $this->signInput())->assertForbidden();
        $this->assertDatabaseCount('signature_evidence', 0);
    }
    public function test_new_template_never_mutates_issued_document_and_new_contract_supersedes_unsigned(): void
    {
        $old = $this->issue();
        $newTemplate = app(CreateTemplateVersion::class)->execute($this->owner, ['terms_html' => '<p>Second fixture terms only.</p>', 'change_note' => 'Fixture-only second revision'], (string) Str::uuid());
        $this->assertSame($old->presented_pdf_sha256, $old->fresh()->presented_pdf_sha256);
        $this->assertSame($this->template->id, $old->fresh()->template_version_id);
        $this->contract = $this->draft(); $this->issue();
        $this->assertSame('superseded', $old->fresh()->state);
        $this->assertSame('draft', $newTemplate->state);
        $this->assertDatabaseCount('signature_evidence', 0);
    }
    public function test_active_content_in_legal_html_is_rejected_without_version_creation(): void
    {
        $count = ContractTemplateVersion::count();
        foreach (['<script>alert(1)</script>', '<img src="https://example.test/secret">', '<p style="background:url(file:///etc/passwd)">bad</p>'] as $html) {
            $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/admin/api/contract-templates', ['terms_html' => $html, 'change_note' => 'Fixture unsafe input only'])->assertUnprocessable();
        }
        $this->assertSame($count, ContractTemplateVersion::count());
    }
    public function test_two_real_signature_requests_store_one_acceptance(): void
    {
        $this->issue(); $grantId = $this->clientSession();
        $this->get('/portal/api/contracts/'.$this->contract->id.'/pdf')->assertOk();
        $request = \Illuminate\Http\Request::create('/portal/api/contracts/'.$this->contract->id.'/sign', 'POST');
        $request->setLaravelSession($this->app['session.store']); $request->setUserResolver(fn () => null);
        $context = \App\Services\Portal\PortalContext::fromRequest($request);
        $requestContext = \App\Services\Documents\SignatureRequestContext::fromRequest($request);
        $input = $this->signInput(); $id = $this->contract->id;
        $worker = function () use ($context, $requestContext, $input, $id) {
            try { app(\App\Services\Documents\AcceptSignature::class)->execute($context, $id, $input, $requestContext, (string) Str::uuid()); return 'signed'; }
            catch (\App\Exceptions\RecordWriteRejected $e) { return $e->status === 409 ? 'conflict' : 'unexpected'; }
        };
        $results = \Tests\Support\ConcurrentWorkers::run([$worker, $worker]); sort($results);
        $this->assertSame(['conflict','signed'], $results);
        $this->assertDatabaseCount('signature_evidence', 1);
        $this->assertSame('signed', $this->contract->fresh()->state);
    }

    public function test_final_pdf_failure_preserves_acceptance_and_can_retry_without_resigning(): void
    {
        $this->issue(); $this->clientSession();
        $this->get('/portal/api/contracts/'.$this->contract->id.'/pdf')->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/portal/api/contracts/'.$this->contract->id.'/sign', $this->signInput())->assertOk();
        $renderer = app(\App\Services\Documents\PrintRenderer::class);
        $this->app->instance(\App\Services\Documents\PrintRenderer::class, new class($renderer) {
            public function __construct(private object $renderer) {}
            public function signedHtml(...$args): string { return $this->renderer->signedHtml(...$args); }
            public function pdf(string $html): string { throw new \RuntimeException('Fixture renderer failure'); }
        });
        try { (new FinalizeSignedContract($this->contract->id))->handle(); $this->fail('Renderer failure was hidden.'); }
        catch (\RuntimeException $e) { $this->assertSame('Fixture renderer failure', $e->getMessage()); }
        $this->assertSame('signed', $this->contract->fresh()->state);
        $this->assertNull($this->contract->fresh()->final_pdf_path);
        $this->assertDatabaseCount('signature_evidence', 1);
        $this->app->instance(\App\Services\Documents\PrintRenderer::class, $renderer);
        (new FinalizeSignedContract($this->contract->id))->handle();
        $this->assertNotNull($this->contract->fresh()->final_pdf_path);
        $this->assertDatabaseCount('signature_evidence', 1);
    }

    public function test_portal_session_in_staging_requires_https_secure_cookie_and_real_csrf(): void
    {
        $grant = app(IssuePortalGrant::class)->execute($this->owner, $this->event->id, ['customer_id' => $this->event->customer_id, 'expires_at' => now()->addDay()->toIso8601String()]);
        auth()->logout();
        $this->app->instance('env', 'staging');
        config(['session.secure' => true]);
        // Staging disables Laravel's testing CSRF bypass; this exercises the actual middleware.
        $this->withSession(['_token' => 'fixture-csrf'])->postJson('https://localhost/portal/api/session', ['token' => $grant->token])
            ->assertStatus(419)->assertHeader('Cache-Control', 'no-store, private');
        $this->withSession(['_token' => 'fixture-csrf'])->withHeader('X-CSRF-TOKEN', 'fixture-csrf')
            ->postJson('http://localhost/portal/api/session', ['token' => $grant->token])->assertForbidden();
        config(['session.secure' => false]);
        $this->withSession(['_token' => 'fixture-csrf'])->postJson('https://localhost/portal/api/session', ['token' => $grant->token])->assertForbidden();
        config(['session.secure' => true]);
        $response = $this->withSession(['_token' => 'fixture-csrf'])->postJson('https://localhost/portal/api/session', ['token' => $grant->token])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNotSame('fixture-csrf', $response->json('csrf_token'));
    }

    public function test_read_only_grant_cannot_sign_or_read_payments(): void
    {
        $this->issue(); $this->clientSession(['contract.read']);
        $this->get('/portal/api/contracts/'.$this->contract->id.'/pdf')->assertOk();
        $this->getJson('/portal/api/event')->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/portal/api/contracts/'.$this->contract->id.'/sign', $this->signInput())->assertForbidden();
        $this->assertDatabaseCount('signature_evidence', 0);
    }

    public function test_final_pdf_retry_is_owner_only_and_does_not_create_another_signature(): void
    {
        $this->issue(); $this->clientSession();
        $this->get('/portal/api/contracts/'.$this->contract->id.'/pdf')->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/portal/api/contracts/'.$this->contract->id.'/sign', $this->signInput())->assertOk();
        $url = '/admin/api/contracts/'.$this->contract->id.'/final-pdf/retry';
        $this->postJson($url, ['reason' => 'Retry fixture worker after failure'])->assertUnauthorized();
        $this->actingAs($this->owner);
        $this->postJson($url, ['reason' => 'short'])->assertUnprocessable();
        $this->postJson($url, ['reason' => 'Retry fixture worker after failure'])->assertOk()->assertJsonPath('queued', true);
        $this->assertDatabaseCount('signature_evidence', 1);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'contract.final_pdf_retry_requested')->count());
    }

}
