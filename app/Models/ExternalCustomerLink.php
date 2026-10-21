<?php
namespace App\Models;

class ExternalCustomerLink extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected $hidden = ['payload', 'contact_key'];
    protected function casts(): array { return ['version' => 'integer', 'payload' => 'encrypted:array']; }
}
