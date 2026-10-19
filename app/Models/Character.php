<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Character extends Model
{
    protected $fillable = ['name', 'active', 'outfits', 'group', 'category', 'audience'];
    protected function casts(): array { return ['outfits' => 'array', 'active' => 'boolean', 'version' => 'integer']; }
}
