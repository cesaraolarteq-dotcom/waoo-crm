<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistoryImportRow extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['payload'];
    protected function casts(): array
    {
        return ['version' => 'integer', 'source_line' => 'integer', 'payload' => 'encrypted:array'];
    }
}
