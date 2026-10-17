<?php
namespace App\Models;
class QuotePresentation extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected $hidden = ['pdf_path'];
    protected function casts(): array { return ['payload'=>'array','consents'=>'array']; }
}
