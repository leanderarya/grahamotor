<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Asset extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'purchase_date',
        'price',
        'condition',
        'location',
        'note',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'price' => 'decimal:2',
    ];

    public function expense(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Expense::class);
    }

    protected static function booted()
    {
        static::created(function (Asset $asset): void {
            $asset->expense()->create([
                'date_expense' => $asset->purchase_date,
                'name' => 'Pembelian Aset: ' . $asset->name,
                'category' => 'asset',
                'amount' => $asset->price,
                'notes' => 'Otomatis dari aset (ID: ' . $asset->id . ')',
            ]);
        });

        static::updated(function (Asset $asset): void {
            // Only sync if the relevant fields actually changed
            $relevantFields = ['name', 'purchase_date', 'price'];
            $dirty = array_intersect($relevantFields, array_keys($asset->getDirty()));

            if (empty($dirty) || ! $asset->expense) {
                return;
            }

            $asset->expense->update([
                'date_expense' => $asset->purchase_date,
                'name' => 'Pembelian Aset: ' . $asset->name,
                'amount' => $asset->price,
            ]);
        });

        static::deleted(function (Asset $asset): void {
            $asset->expense?->delete();
        });
    }
}
