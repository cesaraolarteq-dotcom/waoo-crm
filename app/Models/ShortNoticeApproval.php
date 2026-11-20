<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Owner confirmed a party with less than 24 h of notice (2026-11-20). Never edited or deleted. */
final class ShortNoticeApproval extends Model
{
    protected $guarded = ['id'];
}
