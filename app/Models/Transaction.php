<?php

namespace App\Models;

use App\Services\MonthlyReportService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Transaction extends Model
{
    protected $fillable = [
        'invoice_number',
        'user_id',
        'cashier_session_id',
        'total_amount',
        'customer_type',
        'status',
        'total_profit',
        'payment_method',
        'amount_paid',
        'change_amount',
        'void_reason',
        'voided_by',
        'voided_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'total_profit' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'change_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(function (Transaction $transaction): void {
            // Skip monthly report sync for draft transactions.
            // Drafts are created/updated every 800ms via auto-save —
            // syncing would trigger expensive SUM queries on every keystroke.
            if ($transaction->status === 'draft') {
                return;
            }

            // Only sync if status or financial fields actually changed.
            // This prevents the double-trigger on processPayment() where
            // the transaction is first created with totals=0, then updated.
            $financialFields = ['status', 'total_amount', 'total_profit'];
            $dirtyFinancials = array_intersect($financialFields, array_keys($transaction->getDirty()));

            if (empty($dirtyFinancials)) {
                return;
            }

            $transaction->syncMonthlyReports();
        });

        static::deleted(function (Transaction $transaction): void {
            $transaction->syncMonthlyReports();
        });
    }

    // Relasi: Nota ini dibuat oleh User siapa?
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cashierSession(): BelongsTo
    {
        return $this->belongsTo(CashierSession::class);
    }

    public function transactionItems()
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function items()
    {
        return $this->transactionItems();
    }

    public function reportMonth(): Carbon
    {
        return Carbon::parse($this->created_at)->startOfMonth();
    }

    public function isInFinalizedMonth(): bool
    {
        if (! Schema::hasTable('monthly_reports')) {
            return false;
        }

        return app(MonthlyReportService::class)->isFinalized($this->reportMonth());
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function scopeVoided($query)
    {
        return $query->where('status', 'voided');
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }

    private function syncMonthlyReports(): void
    {
        if (! Schema::hasTable('monthly_reports')) {
            return;
        }

        $service = app(MonthlyReportService::class);
        $currentCreatedAt = $this->created_at ?? now();

        $service->syncMonth(Carbon::parse($currentCreatedAt));

        $originalCreatedAt = $this->getOriginal('created_at');

        if ($originalCreatedAt !== null) {
            $service->syncMonth(Carbon::parse($originalCreatedAt));
        }
    }
}
