<?php
namespace App\Models;
class SignatureEvidence extends ImmutableRecord
{
    protected $table = 'signature_evidence';
    protected $guarded = ['id'];
    protected function casts(): array { return ['accepted_at' => 'immutable_datetime']; }
}
