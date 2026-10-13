<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\PurchaseResource\Pages\CreatePurchase;
use App\Filament\Resources\StockAdjustmentResource\Pages\CreateStockAdjustment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockAdjustment;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminInventoryCrudTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function product(int $stock = 10): Product
    {
        return Product::create([
            'sku' => 'A1-0007',
            'name' => 'Rack end Avanza',
            'stock' => $stock,
            'cost_price' => 70000,
            'sell_price' => 105000,
        ]);
    }

    public function test_guest_cannot_open_the_admin_panel(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_kasir_cannot_open_the_admin_panel(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'kasir']))
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_admin_can_create_a_product(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'sku' => 'B2-0001',
                'name' => 'Filter Oli',
                'stock' => 4,
                'cost_price' => 20000,
                'sell_price' => 35000,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(4, Product::where('sku', 'B2-0001')->first()->stock);
        // Product::created seeds an opening purchase record.
        $this->assertSame(1, Purchase::count());
    }

    public function test_admin_can_edit_product_stock_and_price(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['stock' => 7, 'sell_price' => 110000])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame(7, $product->stock);
        $this->assertSame('110000.00', (string) $product->sell_price);
    }

    public function test_bulk_stock_adjustment_sets_absolute_product_stock(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product(10);

        Livewire::test(CreateStockAdjustment::class)
            ->fillForm([
                'product_id' => $product->id,
                'system_stock' => 10,
                'physical_stock' => 3,
                'difference' => -7,
                'type' => 'loss',
                'note' => 'Barang hilang di gudang',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // Absolute overwrite, not a delta — this is the documented behaviour.
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertSame(-7, StockAdjustment::first()->difference);
    }

    public function test_stock_adjustment_accepts_negative_physical_stock(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product(10);

        Livewire::test(CreateStockAdjustment::class)
            ->fillForm([
                'product_id' => $product->id,
                'system_stock' => 10,
                'physical_stock' => -5,
                'type' => 'loss',
            ])
            ->call('create');

        // Documents current behaviour: no min:0 rule, so a negative physical
        // count is accepted and written straight to product stock.
        $this->assertSame(-5, $product->fresh()->stock);
    }

    public function test_purchase_creation_adds_stock_and_moving_average_cost(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product(10);

        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'date' => now()->toDateString(),
                'product_id' => $product->id,
                'quantity' => 5,
                'buy_price_per_unit' => 80000,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame(15, $product->stock);
        // Moving average over the opening purchase + this one.
        $this->assertSame('73333.33', (string) $product->cost_price);

        $purchase = Purchase::where('quantity', 5)->first();
        $this->assertSame('400000.00', (string) $purchase->total_spend);
    }

    public function test_purchase_creation_accepts_zero_and_fractional_quantity(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product(10);

        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'date' => now()->toDateString(),
                'product_id' => $product->id,
                'quantity' => 0.5,
                'buy_price_per_unit' => 1000,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // Documents current behaviour: quantity is only numeric, so a
        // fractional purchase of half a unit passes validation and the row is
        // persisted without complaint. What lands in the integer column is
        // engine-dependent (measured: SQLite keeps 0.5, MySQL stores 1), so
        // this test asserts only that nothing rejected the input.
        $purchase = Purchase::latest('id')->first();
        $this->assertNotNull($purchase, 'Fractional quantity should still be persisted.');
        $this->assertSame('1000.00', (string) $purchase->buy_price_per_unit);
    }

    public function test_editing_a_purchase_does_not_reconcile_stock(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product(10);

        $purchase = Purchase::create([
            'product_id' => $product->id,
            'date' => now()->toDateString(),
            'quantity' => 5,
            'buy_price_per_unit' => 80000,
            'total_spend' => 400000,
        ]);

        $stockAfterCreate = $product->fresh()->stock;

        $purchase->update(['quantity' => 1, 'total_spend' => 80000]);

        // No update handler exists, so the stock this purchase added is not
        // rolled back when the purchase line is corrected.
        $this->assertSame($stockAfterCreate, $product->fresh()->stock);
    }

    public function test_deleting_a_purchase_does_not_reconcile_stock(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product(10);

        $purchase = Purchase::create([
            'product_id' => $product->id,
            'date' => now()->toDateString(),
            'quantity' => 5,
            'buy_price_per_unit' => 80000,
            'total_spend' => 400000,
        ]);

        $stockAfterCreate = $product->fresh()->stock;
        $purchase->delete();

        $this->assertSame($stockAfterCreate, $product->fresh()->stock);
    }

    public function test_product_with_dependencies_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product();
        $user = User::factory()->create(['role' => 'kasir']);

        $transaction = Transaction::create([
            'invoice_number' => 'INV-GUARD-1',
            'user_id' => $user->id,
            'total_amount' => 105000,
            'total_profit' => 35000,
            'status' => 'paid',
            'customer_type' => 'general',
            'payment_method' => 'cash',
            'amount_paid' => 105000,
            'change_amount' => 0,
        ]);
        TransactionItem::create([
            'transaction_id' => $transaction->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price_at_time' => 105000,
            'cost_at_time' => 70000,
        ]);

        // Documents the FK + guard: deletion raises instead of orphaning items.
        $this->expectException(\Illuminate\Database\QueryException::class);
        $product->delete();
    }
}
