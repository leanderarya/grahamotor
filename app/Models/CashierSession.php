<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashierSession extends Model
{
    protected $fillable = [
        'user_id',
        'opening_cash',
        'opened_at',
        'cash_sales_total',
        'non_cash_sales_total',
        'transactions_count',
        'closing_cash_physical',
        'expected_cash',
        'cash_difference',
        'closed_at',
        'opening_notes',
        'closing_notes',
    ];

    protected $casts = [
        'opening_cash' => 'decimal:2',
        'cash_sales_total' => 'decimal:2',
        'non_cash_sales_total' => 'decimal:2',
        'closing_cash_physical' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'cash_difference' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function overrideAudits(): HasMany
    {
        return $this->hasMany(ShiftOverrideAudit::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function getIsOpenAttribute(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * Recalculate session totals from actual paid transactions.
     * Uses SQL aggregates instead of loading all records into memory.
     */
    public function recalculateTotals(): void
    {
        $totals = $this->transactions()
            ->where('status', 'paid')
            ->selectRaw('COUNT(*) as txn_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN payment_method = \'cash\' THEN total_amount ELSE 0 END), 0) as cash_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN payment_method != \'cash\' THEN total_amount ELSE 0 END), 0) as non_cash_total')
            ->first();

        $this->update([
            'transactions_count' => (int) $totals->txn_count,
            'cash_sales_total' => (float) $totals->cash_total,
            'non_cash_sales_total' => (float) $totals->non_cash_total,
        ]);
    }
}
