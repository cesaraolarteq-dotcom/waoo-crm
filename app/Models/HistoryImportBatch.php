<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistoryImportBatch extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['artifact', 'manifest', 'source_sha256', 'active_fingerprint'];
    protected function casts(): array
    {
        return ['version' => 'integer', 'byte_count' => 'integer', 'artifact' => 'encrypted:array',
            'manifest' => 'encrypted:array', 'cutoff' => 'immutable_datetime'];
    }
}
