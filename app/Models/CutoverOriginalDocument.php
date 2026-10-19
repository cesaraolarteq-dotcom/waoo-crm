<?php
namespace App\Models;
class CutoverOriginalDocument extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected $hidden = ['document_ciphertext'];
    protected function casts(): array { return ['byte_count' => 'integer']; }
}
