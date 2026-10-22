<?php
namespace Tests\Unit;

use App\Services\Documents\InHouseSignatureProvider;
use App\Services\Mail\ApprovedMail;
use App\Support\{ApprovedClientTexts, MailTemplatesDraft, PortalCopy};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApprovedClientTextsTest extends TestCase
{
    public function test_runtime_snapshot_exactly_matches_the_owners_approved_source_and_versions(): void
    {
        $source = json_decode(file_get_contents(base_path('docs/templates/APPROVED-CLIENT-TEXTS-2026-10-20.json')), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($source, config('client-texts'));
        foreach (['es', 'en'] as $locale) {
            foreach (['accepted' => 'quote_accept', 'rejected' => 'quote_reject'] as $outcome => $key) {
                self::assertSame($source['texts'][$key][$locale], config('waoo.quote_acceptance_consent.'.$locale.'.'.$outcome));
            }
            self::assertSame($source['texts']['sms_consent'][$locale], config('waoo.sms_consent_versions.client-texts-2026-10-20-sms-'.$locale));
            self::assertSame($source['texts']['sms_consent'][$locale], PortalCopy::get($locale, 'sms'));
            $consent = InHouseSignatureProvider::consent($locale);
            self::assertSame($source['texts']['esign_consent'][$locale], $consent['text']);
            self::assertSame($source['texts']['esign_intent'][$locale], $consent['intent']);
            self::assertSame($consent, InHouseSignatureProvider::consentForVersion($consent['version']));
        }
        self::assertCount(7, ApprovedMail::KEYS);
        self::assertSame(ApprovedMail::KEYS, MailTemplatesDraft::KEYS);
    }

    public function test_addendum_matches_its_owner_approved_source_and_the_unpaid_link_message_names_no_amount(): void
    {
        $source = json_decode(file_get_contents(base_path('docs/templates/APPROVED-CLIENT-TEXTS-2026-10-22.json')), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($source, config('client-texts-addendum'));
        self::assertNull(config('client-texts.texts.quick_unpaid_link_msg'));
        foreach (['es', 'en'] as $locale) {
            $text = ApprovedClientTexts::text('quick_unpaid_link_msg', $locale);
            self::assertStringNotContainsString('{amount}', $text);
            self::assertStringNotContainsString('3', $text);
            self::assertStringContainsString('{link}', $text);
        }
    }

    public function test_seven_approved_definitions_render_the_actual_bilingual_words_with_all_buttons_and_safe_inline_links(): void
    {
        config(['app.url' => 'https://localhost']);
        $vars = ['first_name' => 'María', 'zip' => '77493', 'event_date' => '21 noviembre', 'subject_date' => '21 noviembre',
            'package' => 'Celebración', 'deposit' => '$200', 'valid_until' => '20 noviembre', 'paid' => '$200', 'amount' => '$200',
            'balance' => '$200', 'balance_due' => '', 'due' => '20 noviembre', 'address' => '123 Oak Street',
            'link' => 'https://localhost/portal#token='.str_repeat('a',43), 'review_link' => config('waoo.mail_review_url'),
            'tip_link' => 'https://localhost/portal/tip#'.str_repeat('b',43)];
        foreach (ApprovedMail::KEYS as $key) foreach (['es', 'en'] as $locale) {
            $definition = ApprovedMail::definition($key, $locale);
            self::assertSame('client-texts-2026-10-20', $definition['version']);
            $rendered = ApprovedMail::render($key, $locale, $vars, []);
            self::assertStringNotContainsString('{first_name}', $rendered['html']);
            self::assertStringNotContainsString('[Botón:', $rendered['html']);
            self::assertStringNotContainsString('[Button:', $rendered['html']);
            if ($key === 'inquiry_received') self::assertStringContainsString('href="https://waooparty.com/'.($locale === 'es' ? 'es/' : '').'travel-fee?zip=77493"', $rendered['html']);
            if ($key === 'thank_you_review') {
                self::assertStringContainsString('https://localhost/portal/tip#', $rendered['html']);
                self::assertStringContainsString($vars['tip_link'], $rendered['text']);
                self::assertStringContainsString($vars['review_link'], $rendered['text']);
            }
        }
    }

    public function test_payment_preview_and_real_mail_separate_balance_from_a_bare_due_date(): void
    {
        config(['app.url' => 'https://localhost']);
        foreach (['es','en'] as $locale) {
            $vars = ['first_name'=>'María', 'amount'=>'$197.50', 'paid'=>'$197.50', 'balance'=>'$197.50', 'balance_due'=>'20 de noviembre'];
            $real = ApprovedMail::render('payment_received', $locale, $vars, []);
            $preview = MailTemplatesDraft::render('payment_received', $locale, $vars);
            self::assertStringContainsString('$197.50 20 de noviembre', $real['text']);
            self::assertStringContainsString('$197.50 20 de noviembre', implode(' ', $preview['paragraphs']));
            self::assertStringNotContainsString('$197.5020', $real['html']);
            $vars['balance_due'] = '';
            self::assertStringNotContainsString('20 de noviembre', ApprovedMail::render('payment_received', $locale, $vars, [])['text']);
        }
    }

    public function test_requested_copy_stays_pending_and_does_not_override_approved_mail(): void
    {
        foreach (['mail_quote_sent','quote_link_msg'] as $key) {
            self::assertSame('pending_review',config('client-texts-addendum.texts.'.$key.'.status'));
            foreach (['es','en'] as $locale) self::assertStringContainsString('48',config('client-texts-addendum.texts.'.$key.'.'.$locale));
        }
        self::assertSame('client-texts-2026-10-20',ApprovedMail::definition('quote_sent','es')['version']);
        self::assertSame('Estamos confirmando tu pago con Stripe. Esto tarda unos segundos; esta página se actualiza sola.',ApprovedClientTexts::text('portal_stripe_confirming','es'));
    }

    public function test_inquiry_zip_and_inline_html_are_not_an_injection_path(): void
    {
        $html = ApprovedClientTexts::paragraphHtml('<img src=x onerror=alert(1)> [travel fee](javascript:alert(1)) [travel fee](https://waooparty.com/travel-fee?zip=77493)');
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
        self::assertSame(1, substr_count($html, '<a '));
        config(['app.url' => 'https://localhost']);
        $this->expectException(ValidationException::class);
        ApprovedMail::render('inquiry_received', 'en', ['first_name'=>'María','event_date'=>'21 Nov','zip'=>'77493\") onmouseover=alert(1)'], []);
    }

    public function test_removed_email_cannot_be_rendered_even_with_old_configuration_present(): void
    {
        foreach (ApprovedMail::RETIRED as $key) {
            config(['waoo.mail_templates.'.$key.'.es' => config('waoo.mail_templates.week_before.es')]);
            try { ApprovedMail::definition($key, 'es'); self::fail('Retired mail remained renderable.'); }
            catch (ValidationException) { $this->addToAssertionCount(1); }
        }
        self::assertFalse(config('waoo.sandbox_mail'));
        self::assertSame([], config('waoo.mail_allowlist'));
    }
}
