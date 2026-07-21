<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MpesaTransaction extends Model
{
    protected $fillable = [
        'merchant_request_id',
        'checkout_request_id',
        'phone',
        'amount',
        'status',
        'mpesa_receipt',
        'result_desc',
    ];
}
