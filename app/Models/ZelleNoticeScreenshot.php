<?php
namespace App\Models;

// Private file under storage/app; only the owner reads it (admin route), never served publicly.
class ZelleNoticeScreenshot extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['size_bytes' => 'integer', 'uploaded_at' => 'immutable_datetime']; }
}
