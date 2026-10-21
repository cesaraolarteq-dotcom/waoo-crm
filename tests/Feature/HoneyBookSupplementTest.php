<?php
namespace Tests\Feature;
use App\Models\{HoneyBookSupplement, User};
use App\Services\HoneyBook\ImportSupplement;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
final class HoneyBookSupplementTest extends TestCase
{
    use DatabaseMigrations;
    protected function setUp(): void {
        parent::setUp(); config(['waoo.sandbox_honeybook_supplement' => true]);
    }
    protected function tearDown(): void {
        // Only synthetic rows in the disposable database, before migration rollback.
        if ($this->app && \Illuminate\Support\Facades\Schema::hasTable('honeybook_supplements')) DB::table('honeybook_supplements')->delete();
        parent::tearDown();
    }
    private function owner(): User {
        return User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
    }
    private function source(string $value = 'María González: actividades al aire libre.'): string {
        return json_encode(['profile'=>'honeybook-supplement-v1','extracted_at'=>'2026-01-01T12:00:00Z','records'=>[
            ['source_type'=>'project','source_id'=>'synthetic-1','category'=>'form','field'=>'preferences','value'=>$value]
        ]], JSON_THROW_ON_ERROR);
    }
    public function test_encrypted_repeatable_copy_does_not_create_operating_records(): void {
        $actor=$this->owner(); $service=app(ImportSupplement::class);
        $tables=['events','customers','payments','reservations','portal_grants']; $before=[];
        foreach($tables as $table) if(\Illuminate\Support\Facades\Schema::hasTable($table)) $before[$table]=DB::table($table)->count();
        $this->assertSame(['created'=>1,'unchanged'=>0],$service->execute($actor,$this->source()));
        $this->assertSame(['created'=>0,'unchanged'=>1],$service->execute($actor,$this->source()));
        $raw=DB::table('honeybook_supplements')->first();
        $this->assertStringNotContainsString('González',$raw->payload);
        $this->assertStringNotContainsString('synthetic-1',$raw->payload);
        $this->assertSame('synthetic-1',HoneyBookSupplement::first()->payload['record']['source_id']);
        $this->assertArrayNotHasKey('payload',HoneyBookSupplement::first()->toArray());
        $this->assertStringNotContainsString('González',json_encode(DB::table('audit_entries')->get()));
        foreach($before as $table=>$count) $this->assertSame($count,DB::table($table)->count(),$table);
    }
    public function test_changed_value_is_retained_as_new_evidence(): void {
        $actor=$this->owner(); $service=app(ImportSupplement::class);
        $service->execute($actor,$this->source()); $service->execute($actor,$this->source('Prefiere actividades interiores.'));
        $this->assertSame(2,HoneyBookSupplement::count());
    }
    public function test_invalid_second_record_is_atomic_and_errors_do_not_echo_contents(): void {
        $source=json_decode($this->source(),true); $source['records'][]=['value'=>'private-contact@example.test'];
        try { app(ImportSupplement::class)->execute($this->owner(),json_encode($source)); $this->fail('Expected rejection'); }
        catch(\Illuminate\Validation\ValidationException $e) { $this->assertStringNotContainsString('private-contact',json_encode($e->errors())); }
        $this->assertSame(0,HoneyBookSupplement::count());
    }
    public function test_flag_off_blocks_service_and_page(): void {
        $actor=$this->owner(); $this->actingAs($actor); config(['waoo.sandbox_honeybook_supplement'=>false]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->get('/admin/honeybook-informacion')->assertForbidden();
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(ImportSupplement::class)->execute($actor,$this->source());
    }
    public function test_owner_page_escapes_source_and_prevents_caching(): void {
        $actor=$this->owner(); app(ImportSupplement::class)->execute($actor,$this->source('<script>alert(1)</script>'));
        $this->actingAs($actor); Filament::setCurrentPanel(Filament::getPanel('admin'));
        $response=$this->get('/admin/honeybook-informacion')->assertOk()->assertSee('Información de HoneyBook por organizar');
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;',false)->assertDontSee('<script>alert(1)</script>',false);
        $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
    }
    public function test_staff_and_guest_cannot_read(): void {
        $this->get('/admin/honeybook-informacion')->assertRedirect();
        $staff=$this->owner(); $staff->forceFill(['role'=>'staff'])->save(); $this->actingAs($staff);
        $this->get('/admin/honeybook-informacion')->assertForbidden();
    }
    public function test_audit_failure_rolls_back_copied_evidence(): void {
        $actor=$this->owner();
        DB::listen(function ($query) { if (str_contains($query->sql, 'insert into `audit_entries`')) throw new \RuntimeException('Synthetic audit failure'); });
        try { app(ImportSupplement::class)->execute($actor,$this->source()); $this->fail('Expected rollback'); }
        catch (\RuntimeException $e) { $this->assertSame('Synthetic audit failure',$e->getMessage()); }
        $this->assertSame(0,HoneyBookSupplement::count());
    }
    public function test_swapped_encrypted_payload_is_rejected(): void {
        $actor=$this->owner(); $service=app(ImportSupplement::class);
        $service->execute($actor,$this->source()); $service->execute($actor,$this->source('Otra preferencia.'));
        $rows=DB::table('honeybook_supplements')->orderBy('id')->get();
        DB::table('honeybook_supplements')->where('id',$rows[0]->id)->update(['payload'=>$rows[1]->payload]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        HoneyBookSupplement::findOrFail($rows[0]->id)->evidence();
    }
    public function test_source_evidence_cannot_be_changed_through_model(): void {
        app(ImportSupplement::class)->execute($this->owner(),$this->source());
        $this->expectException(\LogicException::class);
        HoneyBookSupplement::first()->delete();
    }
    public function test_command_only_reports_counts(): void {
        $owner=$this->owner(); $file=tempnam(sys_get_temp_dir(),'hb-synthetic-');
        try {
            file_put_contents($file,$this->source());
            $this->artisan('waoo:honeybook:supplement',['file'=>$file,'--owner'=>$owner->id])
                ->expectsOutput('Conservados: 1. Sin cambios: 0.')->assertSuccessful();
        } finally { unlink($file); }
    }
    public function test_unknown_keys_and_future_extracts_are_rejected(): void {
        $actor=$this->owner(); $data=json_decode($this->source(),true); $data['extracted_at']='2099-01-01T00:00:00Z'; $data['secret']='not-allowed';
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ImportSupplement::class)->execute($actor,json_encode($data));
    }
    public function test_owner_excluded_fields_reject_the_entire_file_before_any_copy(): void {
        $actor=$this->owner();
        foreach(['contact_address','Private Notes',' SALES TAX '] as $field){
            $data=json_decode($this->source(),true);$excluded=$data['records'][0];$excluded['field']=$field;$excluded['value']='Dato excluido sintético.';$data['records'][]=$excluded;
            try{app(ImportSupplement::class)->execute($actor,json_encode($data));$this->fail('Expected owner exclusion');}
            catch(\Illuminate\Validation\ValidationException $e){$this->assertStringNotContainsString('Dato excluido',json_encode($e->errors()));}
            $this->assertDatabaseCount('honeybook_supplements',0);
        }
    }

}
