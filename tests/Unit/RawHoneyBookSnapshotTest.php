<?php
namespace Tests\Unit;
use App\Services\MigrationVerification\RawHoneyBookSnapshot;
use PHPUnit\Framework\TestCase;
class RawHoneyBookSnapshotTest extends TestCase
{
    private string $dir;
    protected function setUp():void {
        $this->dir=sys_get_temp_dir().'/raw-hb-'.bin2hex(random_bytes(8));mkdir($this->dir,0700);
        foreach(['projects.json'=>['projects'=>[],'has_more'=>false],'contacts-pipeline-files.json'=>['contacts'=>[],'has_more'=>false],
            'workspaces.json'=>['workspaces'=>[],'files'=>[],'has_more'=>false],'file-responses.json'=>['responses'=>[],'has_more'=>false]] as $file=>$value)$this->put($file,$value);
    }
    protected function tearDown():void {foreach(glob($this->dir.'/*') as $file)unlink($file);rmdir($this->dir);}
    private function put(string $file,array $data):void {file_put_contents($this->dir.'/'.$file,json_encode($data,JSON_THROW_ON_ERROR));}
    public function test_raw_mcp_structured_and_named_call_envelopes_preserve_provenance():void {
        $this->put('projects.json',['content'=>[['type'=>'text','text'=>json_encode(['projects'=>[['projectId'=>'project-a']],'has_more'=>false,'total_count'=>1])]]]);
        $this->put('contacts-pipeline-files.json',['structuredContent'=>['contacts'=>[['contactId'=>'contact-a','name'=>'María González']],'has_more'=>false]]);
        $this->put('workspaces.json',['listWorkspaces'=>['workspaces'=>[],'has_more'=>false],'listWorkspaceSmartFiles'=>['files'=>[],'has_more'=>false]]);
        $s=(new RawHoneyBookSnapshot)->read($this->dir);$this->assertSame([],$s['issues']);$this->assertCount(4,$s['snapshot_sha256']);
        $this->assertSame('project-a',$s['records']['projects'][0]['value']['projectId']);
        $this->assertSame('/content/0/text/projects/0',$s['records']['projects'][0]['path']);
        $this->assertSame('María González',$s['records']['contacts'][0]['value']['name']);
    }
    public function test_inconsistent_totals_and_unknown_pipeline_metadata_are_not_discarded():void {
        $this->put('projects.json',['projects'=>[['id'=>'project-a']],'has_more'=>false,'total_count'=>2,'pipelines'=>['label'=>'Información adicional']]);
        $s=(new RawHoneyBookSnapshot)->read($this->dir);$codes=array_column($s['issues'],'code');
        $this->assertContains('declared_total_not_reconciled',$codes);$this->assertContains('unverified_response_metadata',$codes);
        $this->put('projects.json',['projects'=>[['id'=>'project-a']],'pagination'=>['has_more'=>false,'total_count'=>2]]);
        $s=(new RawHoneyBookSnapshot)->read($this->dir);$this->assertContains('declared_total_not_reconciled',array_column($s['issues'],'code'));
        $this->put('projects.json',['projects'=>[['id'=>'project-a']],'has_more'=>false,'next_cursor'=>'another-page']);
        $s=(new RawHoneyBookSnapshot)->read($this->dir);$this->assertContains('pagination_metadata_conflict',array_column($s['issues'],'code'));

    }
    public function test_source_tool_error_and_unstructured_text_never_mean_a_complete_empty_list():void {
        $this->put('projects.json',['isError'=>true,'content'=>[['type'=>'text','text'=>'Private error']]]);
        $s=(new RawHoneyBookSnapshot)->read($this->dir);$this->assertContains('source_tool_error',array_column($s['issues'],'code'));
        $this->put('projects.json',['content'=>[['type'=>'text','text'=>'Unstructured response']]]);
        $s=(new RawHoneyBookSnapshot)->read($this->dir);$this->assertContains('unsupported_tool_text_response',array_column($s['issues'],'code'));
    }
    public function test_snapshot_cannot_read_a_symlink_outside_the_private_capture():void {
        symlink(__FILE__,$this->dir.'/escape.pdf');$this->expectException(\RuntimeException::class);
        (new RawHoneyBookSnapshot)->bytes($this->dir,'escape.pdf');
    }
}
