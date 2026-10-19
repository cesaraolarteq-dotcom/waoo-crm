<?php

namespace App\Models;

class OwnerReportSnapshot extends ImmutableRecord
{
    protected $guarded = ['id'];

    protected function casts(): array { return ['schema_version' => 'integer']; }
}
