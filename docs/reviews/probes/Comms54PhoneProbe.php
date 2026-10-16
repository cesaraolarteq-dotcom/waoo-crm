<?php
namespace Tests\Review;

use App\Filament\Support\ContactLinks;
use Tests\TestCase;

// Diagnostic only: proves unsafe input is accepted by b30d209; passing is NOT approval.
class Comms54PhoneProbe extends TestCase
{
    public function test_phone_extension_is_merged_into_a_different_international_recipient(): void
    {
        $this->assertSame('3465550100123', ContactLinks::digits('(346) 555-0100 ext 123'));
        $this->assertSame('https://wa.me/3465550100123?text=fixture', ContactLinks::whatsapp('(346) 555-0100 ext 123', 'fixture'));
    }
    public function test_alphabetic_and_zero_numbers_are_accepted_despite_missing_a_valid_recipient(): void
    {
        $this->assertSame('13465550100', ContactLinks::digits('DO NOT TEXT 346-555-0100'));
        $this->assertNotNull(ContactLinks::whatsapp('DO NOT TEXT 346-555-0100', 'fixture'));
        $this->assertSame('00000000000', ContactLinks::digits('00000000000'));
    }
}
