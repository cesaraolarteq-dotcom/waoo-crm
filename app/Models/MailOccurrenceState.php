<?php
namespace App\Models;

class MailOccurrenceState extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['version' => 'integer']; }
}
