<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentConfiguration extends Model {
    use HasFactory;

    /*
    |----------------------------------------------------------------------
    | Properties & Casts
    |----------------------------------------------------------------------
    */

    protected $fillable = [
        'payment_method',
        'api_key',
        'secret_key',
        'webhook_secret_key',
        'currency_code',
        'status',
        'additional_data_1',
        'additional_data_2',
        'payment_mode',
        'username',
        'password'
    ];

    protected function casts(): array
    {
        return [
            // boolean columns cast to integer: the API returns 1/0 on both MySQL and PostgreSQL
            'status' => 'integer',
        ];
    }
}
