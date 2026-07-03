<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustment extends Model
{
    protected $fillable = [
        'product_id',
        'user_id',
        'adjustment_date',
        'system_stock',
        'physical_stock',
        'difference',
        'type',
        'note',
    ];

    // Relasi
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // --- MAGIC LOGIC ---
    // Saat data audit disimpan, stok produk ikut berubah otomatis
    protected static function booted()
    {
        static::created(function ($adjustment) {
            \DB::transaction(function () use ($adjustment) {
                $product = \App\Models\Product::lockForUpdate()->find($adjustment->product_id);

                if (! $product) {
                    return;
                }

                // Update stok produk menjadi angka fisik terbaru
                $product->update([
                    'stock' => $adjustment->physical_stock,
                ]);

                \Cache::forget('dashboard_asset_value');
            });
        });
    }
}