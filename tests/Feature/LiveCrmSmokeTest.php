<?php
namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Route;
use Tests\Support\QuoteScenario;
use Tests\TestCase;

// Owner 2026-11-05: the real CRM (APP_ENV=production, WAOO_LIVE, no Stripe) must open every admin screen without an error.
class LiveCrmSmokeTest extends TestCase
{
    use DatabaseMigrations, QuoteScenario;

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing'; // the rollback refuses to run unconfirmed in production
        parent::tearDown();
    }

    public function test_every_admin_screen_opens_on_the_real_crm_without_stripe(): void
    {
        $this->scenario();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->app['env'] = 'production';
        $flags = ['finance', 'documents', 'imports', 'routes', 'lead_intake', 'quotes', 'staff', 'cutover', 'immediate_contracts', 'quick_stripe', 'quick_payments',
            'quick_payment_notifications', 'history', 'honeybook_supplement', 'history_verification', 'company', 'anniversary', 'analytics', 'changes', 'web_availability', 'extras'];
        config(['waoo.live' => true, 'waoo.stripe_live' => false, 'waoo.stripe_test_secret' => null, 'waoo.stripe_test_account' => null, 'app.url' => 'https://localhost',
            ...collect($flags)->mapWithKeys(fn ($f) => ["waoo.sandbox_$f" => true])->all()]);

        $failures = []; $ok = [];
        $urls = collect(Route::getRoutes())->filter(fn ($r) => in_array('GET', $r->methods(), true) && str_starts_with($r->uri(), 'admin')
            && ! str_contains($r->uri(), '{') && ! str_contains($r->uri(), 'api/') && ! str_contains($r->uri(), 'logout'))->map(fn ($r) => '/'.$r->uri())->values();
        $id = $this->event->id;
        $urls = $urls->merge(["/admin/events/$id", "/admin/events/$id/edit", "/admin/events/$id/money", "/admin/events/$id/contract", "/admin/customers/{$this->event->customer_id}"]);
        foreach ($urls->unique() as $url) {
            $status = $this->withServerVariables(['HTTPS' => 'on'])->get($url)->getStatusCode();
            if ($status >= 500) $failures[] = "$url → $status";
            $ok[] = $status;
        }
        $this->assertGreaterThan(15, count(array_filter($ok, fn ($c) => $c === 200)), json_encode(array_count_values($ok)));
        $this->assertSame([], $failures, implode("\n", $failures));
    }
}
