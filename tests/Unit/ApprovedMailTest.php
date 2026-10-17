<?php
namespace Tests\Unit;
use App\Services\Mail\ApprovedMail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class ApprovedMailTest extends TestCase
{
    private function definition():array{return ['version'=>'FIXTURE','approval_reference'=>'TEST_ONLY','subject'=>'Fixture {first_name}','paragraphs'=>['Hello {first_name}','__totals__'],'button'=>null,'closing'=>null,'required_variables'=>['first_name']];}
    public function test_bilingual_strict_approved_render_preserves_exact_text_and_plain_totals_without_raw_html():void
    {
        config(['app.url'=>'https://localhost']);foreach(['es','en'] as $lang){config(['waoo.mail_templates.week_before.'.$lang=>$this->definition()]);$r=ApprovedMail::render('week_before',$lang,['first_name'=>'<b>Fixture</b>'],[['label'=>'Visible discount','amount'=>'−$0.01']]);$this->assertStringContainsString('&lt;b&gt;Fixture&lt;/b&gt;',$r['html']);$this->assertStringContainsString('Visible discount: −$0.01',$r['text']);$this->assertSame('Fixture <b>Fixture</b>',$r['subject']);$this->assertStringNotContainsString('<b>Fixture</b>',$r['html']);}
    }
    public function test_long_or_header_injected_subject_unknown_placeholder_and_absent_approval_fail_closed():void
    {
        foreach([['subject'=>str_repeat('a',61)],['subject'=>"Hello\r\nBcc: x@example.test"],['paragraphs'=>['Unknown {internal_reason}']],['approval_reference'=>'']] as $change){config(['waoo.mail_templates.week_before.es'=>[...$this->definition(),...$change]]);try{ApprovedMail::render('week_before','es',['first_name'=>'Fixture'],[]);$this->fail('Expected invalid approved renderer input');}catch(ValidationException){$this->addToAssertionCount(1);}}
        config(['waoo.mail_templates.week_before.es'=>$this->definition()]);try{ApprovedMail::render('week_before','es',[],[]);$this->fail('Missing required variable');}catch(ValidationException){$this->addToAssertionCount(1);}
        $this->expectException(ValidationException::class);ApprovedMail::render('week_before','es',['first_name'=>'Fixture','private_reason'=>'Not allowed'],[]);
    }
}
