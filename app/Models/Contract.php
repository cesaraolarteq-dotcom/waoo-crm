<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class Contract extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['presented_html_path', 'presented_pdf_path', 'final_pdf_path'];
    protected function casts(): array { return ['commercial_snapshot' => 'array', 'event_version' => 'integer', 'version' => 'integer', 'revision' => 'integer', 'issued_at' => 'immutable_datetime', 'signed_at' => 'immutable_datetime']; }
    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Contract history cannot be deleted.'));
        static::updating(function (self $row): void {
            if ($row->getRawOriginal('state') !== 'draft' && array_intersect(array_keys($row->getDirty()), ['event_id','financial_revision_id','template_version_id','event_version','revision','signer_customer_id','signer_email','commercial_snapshot','presented_html_path','presented_html_sha256','presented_pdf_path','presented_pdf_sha256'])) {
                throw new \LogicException('Issued contract content is immutable.');
            }
        });
    }
}
