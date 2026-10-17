<?php
namespace Tests\Unit;
use App\Services\Imports\{ImportCsv,ImportInput,ImportPreview};
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
class ImportNormalizationTest extends TestCase
{
    private function profile():array{return ImportInput::profile(['schema_version'=>1,'timezone'=>'America/Chicago','encoding'=>'utf-8','delimiter'=>',','date_format'=>'Y-m-d','money_format'=>'usd_dot','columns'=>[],'status_map'=>['confirmed'=>['Booked'],'cancelled'=>['Cancelled']],'payment_state_map'=>['received'=>['Paid'],'refunded'=>['Refund']]]);}
    public static function moneyCases():array{return [['0',0],['0.01',1],['1.1',110],['1,234.56',123456],['$9,999,999,999.99',999999999999],['',null],[null,null],['-0.01',-1,true]];}
    #[DataProvider('moneyCases')]
    public function test_integer_money_is_exact_and_missing_stays_unknown(?string $raw,?int $expected,bool $signed=false):void{$this->assertSame($expected,ImportPreview::money($raw,$signed));}
    public static function badMoney():array{return array_map(fn($v)=>[$v],['-1','1.001','1e3','NaN','(1.00)','1,23.00','00.50','10,000,000,000.00','USD 2.00','1.234,56','=1+1','+1','1 000.00']);}
    #[DataProvider('badMoney')]
    public function test_ambiguous_and_rounded_money_never_become_funds(string $raw):void{$this->expectException(\InvalidArgumentException::class);ImportPreview::money($raw);}
    public function test_multiline_rfc_csv_preserves_values_line_numbers_bom_and_escaped_quotes():void
    {
        $p=$this->profile();$csv="\xEF\xBB\xBFa,b\r\n\"hello,\"\"world\"\"\",\"line1\nline2\"\r\nnext,last\r\n";
        $out=app(ImportCsv::class)->parse($csv,'projects',$p);$this->assertSame(['a','b'],$out['headers']);$this->assertSame(['hello,"world"',"line1\nline2"],$out['rows'][0]['cells']);$this->assertSame(4,$out['rows'][1]['line']);
    }
    public function test_explicit_quoted_empty_record_is_not_silently_skipped():void
    {
        $out=app(ImportCsv::class)->parse("header\n\n\"\"\n",'projects',$this->profile());$this->assertCount(1,$out['rows']);$this->assertSame([''],$out['rows'][0]['cells']);$this->assertSame(3,$out['rows'][0]['line']);
    }
    public static function brokenCsv():array{return [['a,b\n"unclosed'],["a,b\na\"b,c"],["a,b\n\"a\"x,c"],["a,b\na,b,c"],["a,b\na\0,b"],["a,b\n\xFF,b"],["%PDF-fixture"],["a\n".str_repeat('x',32769)],[str_repeat('h,',100)."h\n"]];}
    #[DataProvider('brokenCsv')]
    public function test_invalid_csv_has_safe_errors_without_source_cells(string $csv):void
    {
        $csv=str_replace('\\n',"\n",$csv);try{app(ImportCsv::class)->parse($csv,'projects',$this->profile());$this->fail('Expected malformed CSV denial');}
        catch(ValidationException $e){$this->assertStringNotContainsString('unclosed source secret',$e->getMessage());$this->assertArrayHasKey('files.projects',array_fill_keys(array_map(fn($k)=>implode('.',array_slice(explode('.',$k),0,2)),array_keys($e->errors())),true));}
    }
    public function test_row_and_raw_size_limits_apply_while_reading():void
    {
        foreach(["h\n".str_repeat("x\n",2001),str_repeat('x',ImportCsv::BYTES+1)] as $csv){try{app(ImportCsv::class)->parse($csv,'projects',$this->profile());$this->fail('Expected bound');}catch(ValidationException $e){$this->assertNotEmpty($e->errors());}}
    }
    public function test_declared_windows_encoding_and_delimiter_are_not_guessed():void
    {
        $p=$this->profile();$p['delimiter']=';';$p['encoding']='windows-1252';$r=app(ImportCsv::class)->parse("a;b\nCaf\xe9;test\n",'customers',$p);$this->assertSame('Café',$r['rows'][0]['cells'][0]);
        $p['encoding']='utf-8';$this->expectException(ValidationException::class);app(ImportCsv::class)->parse("a;b\nCaf\xe9;test\n",'customers',$p);
    }
    private function preview(array $source,array $extraFiles=[]):array
    {
        $p=$this->profile();$p['columns']['projects']=array_flip(array_keys($source));
        foreach($extraFiles as $role=>$f)$p['columns'][$role]=array_flip($f['headers']);
        $files=['projects'=>['rows'=>[['line'=>2,'cells'=>array_values($source)]]],...$extraFiles];
        return app(ImportPreview::class)->build($files,$p,CarbonImmutable::parse('2026-10-17T12:00:00Z'));
    }
    private function source():array{return ['project_id'=>'P-1','project_status'=>'Booked','customer_id'=>'C-1','event_date'=>'2026-12-20','start_time'=>'15:00','duration_minutes'=>'60','address'=>'Fictional venue','package_text'=>'Historical source label','total_amount'=>'100.00','paid_amount'=>'60.00','balance_amount'=>'40.00','travel_amount'=>'10.00'];}
    public function test_joined_customer_and_itemized_refund_reconcile_without_double_credit():void
    {
        $c=['headers'=>['customer_id','customer_name','customer_email'],'rows'=>[['line'=>2,'cells'=>['C-1','Fictional Joined Customer','joined@example.test']]]];
        $p=['headers'=>['project_id','payment_id','amount','state'],'rows'=>[['line'=>2,'cells'=>['P-1','PAY-1','70.00','Paid']],['line'=>3,'cells'=>['P-1','PAY-2','10.00','Refund']]]];
        $out=$this->preview($this->source(),['customers'=>$c,'payments'=>$p]);$r=$out['rows'][0];$this->assertSame('ready',$r['status']);$this->assertSame('Fictional Joined Customer',$r['normalized']['customer_name']);$this->assertSame(6000,$r['normalized']['itemized_paid_cents']);$this->assertSame(6000,$out['reconciliation']['totals']['paid_cents']['sum_cents']);
        $p['rows'][1]['cells'][1]='PAY-1';$out=$this->preview($this->source(),['customers'=>$c,'payments'=>$p]);$this->assertSame('conflict',$out['rows'][0]['status']);$this->assertNull($out['rows'][0]['normalized']['itemized_paid_cents']);
    }
    public function test_unknown_payment_or_unmatched_project_never_reconciles():void
    {
        $p=['headers'=>['project_id','payment_id','amount','state'],'rows'=>[['line'=>2,'cells'=>['P-1','PAY-1','60.00','Unknown']],['line'=>3,'cells'=>['OTHER','PAY-2','1.00','Paid']]]];
        $out=$this->preview($this->source(),['payments'=>$p]);$this->assertSame('conflict',$out['rows'][0]['status']);$this->assertSame('conflict',$out['rows'][2]['status']);
    }
    public function test_dst_ambiguity_gap_invalid_dates_and_disagreeing_duration_require_review():void
    {
        foreach([['event_date'=>'2026-11-01','start_time'=>'01:30'],['event_date'=>'2027-03-14','start_time'=>'02:30'],['event_date'=>'2027-02-30'],['end_time'=>'16:30']] as $change){
            $r=$this->preview([...$this->source(),...$change])['rows'][0];$this->assertContains('invalid_or_ambiguous_window',array_column($r['errors'],'code'));}
        $r=$this->preview([...$this->source(),'event_date'=>'2026-11-01','start_time'=>'01:30','start_choice'=>'cst'])['rows'][0];$this->assertSame('2026-11-01T07:30:00+00:00',$r['normalized']['starts_at']);
    }
    public function test_next_day_end_is_explicit_and_source_keys_remain_case_sensitive():void
    {
        $s=$this->source();unset($s['duration_minutes']);$s['start_time']='23:00';$s['end_time']='01:00';$r=$this->preview($s)['rows'][0];$this->assertContains('invalid_or_ambiguous_window',array_column($r['errors'],'code'));
        $r=$this->preview([...$s,'end_date'=>'2026-12-21'])['rows'][0];$this->assertSame('2026-12-21T07:00:00+00:00',$r['normalized']['ends_at']);
    }
    public function test_unknown_columns_and_ambiguous_status_profiles_are_rejected():void
    {
        $p=$this->profile();$p['status_map']=['confirmed'=>['Same'],'cancelled'=>['Same']];$this->expectException(ValidationException::class);ImportInput::profile($p);
    }
    public function test_invalid_contact_data_requires_review_and_valid_international_phone_is_retained():void
    {
        $r=$this->preview([...$this->source(),'customer_name'=>'Fictional','customer_email'=>'not-an-email','customer_phone'=>'text extension 123'])['rows'][0];
        $this->assertContains('invalid_email',array_column($r['errors'],'code'));$this->assertContains('invalid_phone',array_column($r['errors'],'code'));
        $r=$this->preview([...$this->source(),'customer_name'=>'Fictional','customer_phone'=>'+15555550199'])['rows'][0];$this->assertSame('ready',$r['status']);$this->assertSame('+15555550199',$r['normalized']['customer_phone']);
    }

    public function test_plain_text_pk_header_is_csv_not_a_zip_signature():void
    {
        $out=app(ImportCsv::class)->parse("PK,Name\nfixture,Fictional\n",'projects',$this->profile());$this->assertSame(['PK','Name'],$out['headers']);$this->assertCount(1,$out['rows']);
    }

}
