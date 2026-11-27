<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerGoogleContact extends Model
{
    protected $fillable = ['customer_id', 'resource_name', 'google_email', 'status', 'error'];
}
