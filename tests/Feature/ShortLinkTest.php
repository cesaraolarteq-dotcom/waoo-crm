<?php
namespace Tests\Feature;

use App\Models\PortalGrant;
use App\Services\Portal\PortalTokenVault;
use App\Support\ShortLink;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\QuoteScenario;
use Tests\TestCase;

// Owner 2026-10-20: send /r/<12 chars> instead of the long /portal#token=… link; same grant, expiry and revocation.
class ShortLinkTest extends TestCase
{
    use DatabaseMigrations, QuoteScenario;

    private function grant(string $token, array $over = []): PortalGrant
    {
        $g = PortalGrant::create([...['event_id' => $this->event->id, 'customer_id' => $this->event->customer_id, 'token_hash' => hash('sha256', $token),
            'scopes' => ['details.submit'], 'expires_at' => now()->addDays(14), 'issued_by' => $this->owner->id], ...$over]);
        $g->forceFill(['encrypted_token' => PortalTokenVault::seal($g, $token)])->save();

        return $g;
    }

    public function test_long_link_becomes_a_short_one_that_forwards_with_the_token_in_the_fragment(): void
    {
        $this->scenario();
        $token = str_repeat('Ab3_-', 8).'xyz';
        $this->grant($token);
        $long = url('/portal').'#token='.$token;
        $short = ShortLink::for($long);
        $this->assertMatchesRegularExpression('~/r/[A-Za-z0-9]{12}$~', $short);
        $this->assertSame($short, ShortLink::for($long), 'Same grant, same short link.');
        $this->assertSame("Hola María, aquí: {$short} ¡Gracias!", ShortLink::inText("Hola María, aquí: {$long} ¡Gracias!"));
        $code = substr($short, -12);
        $this->get('/r/'.$code)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('location.replace', false)
            ->assertSee(json_encode($long, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP), false);
        $this->assertDatabaseMissing('portal_short_links', ['encrypted_code' => $code]); // never stored in clear
    }

    public function test_revoked_expired_or_unknown_codes_show_the_expired_page_and_bad_links_stay_long(): void
    {
        $this->scenario();
        $token = str_repeat('Zz9', 15);
        $g = $this->grant($token);
        $code = substr(ShortLink::for(url('/portal').'#token='.$token), -12);
        $g->forceFill(['revoked_at' => now()])->save();
        $this->get('/r/'.$code)->assertNotFound()->assertSee('Este enlace venció o ya no es válido')->assertDontSee($token);
        $this->get('/r/AAAAAAAAAAAA')->assertNotFound();
        $this->get('/r/short')->assertNotFound();
        $this->assertSame('https://crm.example.test/p/x', ShortLink::for('https://crm.example.test/p/x'));
        $this->assertSame(url('/portal').'#token='.$token, ShortLink::for(url('/portal').'#token='.$token), 'Revoked grant: no new short link.');
    }
}
