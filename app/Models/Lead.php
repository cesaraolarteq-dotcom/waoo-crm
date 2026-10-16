<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['version' => 'integer']; }
    public function submission() { return $this->belongsTo(LeadSubmission::class, 'lead_submission_id'); }
}
