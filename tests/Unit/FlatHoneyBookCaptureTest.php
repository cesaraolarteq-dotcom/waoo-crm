<?php
namespace Tests\Unit;

use App\Services\MigrationVerification\FlatHoneyBookCapture;
use PHPUnit\Framework\TestCase;

class FlatHoneyBookCaptureTest extends TestCase
{
    public static function capture(): array
    {
        return [
            'projects.json'=>[['id'=>'project-a','name'=>'Cumpleaños de María','project_date'=>'2025-07-10','project_time_start'=>"3:00\u{202f}pm",'project_time_end'=>'5:00 pm','created_at'=>'2025-06-01']],
            'contacts-pipeline-files.json'=>['contacts'=>[['id'=>'contact-a','full_name'=>'María González','email'=>'maria@example.test','phone_number'=>'+12025550123','sms_consent_confirmed'=>true,'workspaces'=>['data'=>[['id'=>'workspace-a','project_id'=>'project-a']],'total_items'=>1]]],'smart_files'=>[['id'=>'file-a','project_id'=>'project-a','workspace_id'=>'workspace-a']],'stages'=>[]],
            'workspaces.json'=>['workspaces'=>[['id'=>'workspace-a','project_id'=>'project-a','name'=>null,'status'=>'client','archived'=>true,'created_at'=>'2025-05-01']],
                'workspace_files'=>['workspace-a'=>[['id'=>'file-a','project_id'=>'project-a','workspace_id'=>'workspace-a','payment_variables'=>[['value'=>['amount'=>50,'amount_paid'=>50,'is_paid'=>true,'charge_date'=>'2025-07-01','payment_status_label'=>'Paid','payment_method_name'=>'Cash']]]]]]],
            'file-responses.json'=>['file-a'=>['payments'=>['total'=>['amount'=>12000,'currency'=>'USD'],'amount_paid'=>['amount'=>5000,'currency'=>'USD'],'balance_due'=>['amount'=>7000,'currency'=>'USD'],
                'line_items'=>[['title'=>'Celebración','price'=>['amount'=>6000,'currency'=>'USD'],'quantity'=>2,'children'=>[]]]],
                'signatures'=>['signatures'=>[['signee_type'=>'client','required'=>true,'signed'=>true],['signee_type'=>'vendor','required'=>true,'signed'=>false]]]]],
        ];
    }
    public function test_project_authority_contact_join_cent_units_and_required_client_signatures(): void
    {
        $s=(new FlatHoneyBookCapture)->decode(self::capture());
        $this->assertCount(1,$s['records']['projects']);$this->assertCount(1,$s['records']['contacts']);$this->assertCount(1,$s['records']['responses']);
        $related=$s['project_relations']['project-a'];$this->assertSame('contact-a',$related[0]['value']['contact_id']);
        $this->assertSame(['project_status'=>'client'],$related[1]['value']);
        $this->assertSame(['amount'=>12000,'currency'=>'USD'],$related[2]['value']['total']);
        $this->assertTrue($related[2]['value']['contract_signed']);$this->assertSame(12000,$related[2]['value']['line_items'][0]['amount']['amount_cents']);
        $this->assertSame('50.00',$related[3]['value']['payments'][0]['amount']);
        $this->assertSame(['payments/total'],$related[2]['origins']['total']);
        $this->assertSame('unverified_response_metadata',$s['issues'][0]['code']);
    }
    public function test_two_eligible_agreements_remain_ambiguous_even_if_values_agree(): void
    {
        $d=self::capture();$second=$d['workspaces.json']['workspace_files']['workspace-a'][0];$second['id']='file-b';
        $d['workspaces.json']['workspace_files']['workspace-a'][]=$second;$d['file-responses.json']['file-b']=$d['file-responses.json']['file-a'];
        $s=(new FlatHoneyBookCapture)->decode($d);$this->assertContains('ambiguous_project_agreement',array_column($s['issues'],'code'));
        $this->assertCount(2,$s['project_relations']['project-a']);
    }
    public function test_cancelled_and_unknown_payment_variables_never_establish_an_agreement(): void
    {
        foreach(['Cancelled','Some future status'] as $label){
            $d=self::capture();$d['workspaces.json']['workspace_files']['workspace-a'][0]['payment_variables'][0]['value']['payment_status_label']=$label;
            $s=(new FlatHoneyBookCapture)->decode($d);$this->assertContains('agreement_eligibility_unverified',array_column($s['issues'],'code'));$this->assertCount(2,$s['project_relations']['project-a']);
        }
    }
    public function test_no_required_client_signature_does_not_mean_signed_and_nested_items_stay_raw(): void
    {
        $d=self::capture();$d['file-responses.json']['file-a']['signatures']['signatures'][0]['required']=false;
        $d['file-responses.json']['file-a']['payments']['line_items'][0]['children']=[['title'=>'Detalle']];
        $s=(new FlatHoneyBookCapture)->decode($d);$r=$s['project_relations']['project-a'][2]['value'];
        $this->assertArrayNotHasKey('contract_signed',$r);$this->assertArrayNotHasKey('line_items',$r);
        $this->assertSame($d['file-responses.json']['file-a'],$s['records']['responses'][0]['value']);
    }
    public function test_payment_dollars_are_exact_and_timestamp_days_use_houston(): void
    {
        $d=self::capture();$x=&$d['workspaces.json']['workspace_files']['workspace-a'][0]['payment_variables'][0]['value'];
        $x['amount_paid']='50.1';$x['charge_date']='2025-07-01T01:00:00Z';
        $s=(new FlatHoneyBookCapture)->decode($d);$payment=$s['project_relations']['project-a'][3]['value']['payments'][0];
        $this->assertSame('50.10',$payment['amount']);$this->assertSame('2025-06-30',$payment['date']);
        foreach(['50.001','1e2'] as $bad){$x['amount_paid']=$bad;$s=(new FlatHoneyBookCapture)->decode($d);$this->assertContains('unsupported_payment_variable',array_column($s['issues'],'code'));$this->assertCount(2,$s['project_relations']['project-a']);}
        $x['amount_paid']=50;$x['charge_date']='2025-02-30';$s=(new FlatHoneyBookCapture)->decode($d);$this->assertContains('unsupported_payment_variable',array_column($s['issues'],'code'));
    }
    public function test_unknown_required_signature_cannot_be_ignored_beside_a_signed_client(): void
    {
        $d=self::capture();$d['file-responses.json']['file-a']['signatures']['signatures'][]=['signee_type'=>'client','signed'=>false];
        $s=(new FlatHoneyBookCapture)->decode($d);$this->assertArrayNotHasKey('contract_signed',$s['project_relations']['project-a'][2]['value']);
    }
    public function test_file_binding_conflict_and_partial_unpaid_payment_fail_closed(): void
    {
        $d=self::capture();$d['contacts-pipeline-files.json']['smart_files'][0]['project_id']='another-project';
        $s=(new FlatHoneyBookCapture)->decode($d);$this->assertContains('response_project_unresolved',array_column($s['issues'],'code'));
        $d=self::capture();$d['workspaces.json']['workspace_files']['workspace-a'][0]['payment_variables'][0]['value']['is_paid']=false;
        $s=(new FlatHoneyBookCapture)->decode($d);$this->assertContains('unsupported_payment_variable',array_column($s['issues'],'code'));
    }
    public function test_unrecognized_capture_uses_existing_strict_decoder_instead(): void
    {
        $d=self::capture();unset($d['workspaces.json']['workspace_files']);$this->assertNull((new FlatHoneyBookCapture)->decode($d));
    }
}
