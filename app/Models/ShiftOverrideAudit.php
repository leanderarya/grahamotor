<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftOverrideAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'cashier_session_id',
        'actor_id',
        'reason',
        'closing_cash_physical',
        'expected_cash',
        'cash_difference',
        'created_at',
    ];

    protected $casts = [
        'closing_cash_physical' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'cash_difference' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function cashierSession(): BelongsTo
    {
        return $this->belongsTo(CashierSession::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
