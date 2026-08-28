<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PinAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ip_address',
        'pin_used',
        'success',
    ];

    protected $casts = [
        'success' => 'boolean',
    ];
}
