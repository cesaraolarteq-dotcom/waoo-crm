<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_cannot_be_framed_and_https_is_remembered(): void
    {
        $this->get('/login')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://crm.example.test/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->get('/portal')->assertHeader('Referrer-Policy', 'no-referrer'); // the stricter portal policy stays
    }
}
