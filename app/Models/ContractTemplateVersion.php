<?php
namespace App\Models;

class ContractTemplateVersion extends ImmutableRecord
{
    protected $guarded = ['id'];
    protected $hidden = ['source_html', 'terms_html'];
    protected function casts(): array { return ['version' => 'integer', 'approved_at' => 'immutable_datetime']; }
}
